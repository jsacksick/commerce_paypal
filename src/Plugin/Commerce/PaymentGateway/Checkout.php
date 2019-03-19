<?php

namespace Drupal\commerce_paypal\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\commerce_paypal\CheckoutSdkFactoryInterface;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\profile\Entity\ProfileInterface;
use GuzzleHttp\Exception\ClientException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Zend\Diactoros\Response\JsonResponse;

/**
 * Provides the Paypal Checkout payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "paypal_checkout",
 *   label = @Translation("PayPal (Checkout)"),
 *   display_label = @Translation("PayPal"),
 *   payment_method_types = {"paypal_checkout"},
 *   forms = {
 *     "add-payment-method" = "Drupal\commerce_paypal\PluginForm\Checkout\PaymentMethodAddForm",
 *   },
 *   credit_card_types = {
 *     "amex", "discover", "mastercard", "visa",
 *   },
 * )
 */
class Checkout extends OnsitePaymentGatewayBase implements CheckoutInterface {

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The PayPal Checkout SDK factory.
   *
   * @var \Drupal\commerce_paypal\CheckoutSdkFactoryInterface
   */
  protected $checkoutSdkFactory;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface $logger
   */
  protected $logger;

  /**
   * Constructs a new Checkout object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\commerce_payment\PaymentTypeManager $payment_type_manager
   *   The payment type manager.
   * @param \Drupal\commerce_payment\PaymentMethodTypeManager $payment_method_type_manager
   *   The payment method type manager.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\commerce_paypal\CheckoutSdkFactoryInterface $checkout_sdk_factory
   *   The PayPal Checkout SDK factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time, ModuleHandlerInterface $module_handler, CheckoutSdkFactoryInterface $checkout_sdk_factory, LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);
    $this->moduleHandler = $module_handler;
    // Don't instantiate the client from there to be able to test the
    // connectivity after updating the client_id & secret.
    $this->checkoutSdkFactory = $checkout_sdk_factory;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('datetime.time'),
      $container->get('module_handler'),
      $container->get('commerce_paypal.checkout_sdk_factory'),
      $container->get('logger.channel.commerce_paypal')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'client_id' => '',
      'secret' => '',
      'intent' => 'capture',
      'shipping_preference' => 'get_from_file',
      'update_billing_profile' => TRUE,
      'update_shipping_profile' => TRUE,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#default_value' => $this->configuration['client_id'],
      '#maxlength' => 255,
      '#required' => TRUE,
    ];
    $form['secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Secret'),
      '#maxlength' => 255,
      '#default_value' => $this->configuration['secret'],
      '#required' => TRUE,
    ];
    $form['intent'] = [
      '#type' => 'radios',
      '#title' => $this->t('Intent'),
      '#options' => [
        'capture' => $this->t('Capture'),
        'authorize' => $this->t('Authorize'),
      ],
      '#default_value' => $this->configuration['intent'],
    ];
    $shipping_enabled = $this->moduleHandler->moduleExists('commerce_shipping');
    $form['shipping_preference'] = [
      '#type' => 'radios',
      '#title' => $this->t('Shipping address collection'),
      '#options' => [
        'no_shipping' => $this->t('Do not ask for a shipping address at PayPal.'),
        'get_from_file' => $this->t('Ask for a shipping address at PayPal even if the order already has one.'),
        'set_provided_address' => $this->t('Ask for a shipping address at PayPal if the order does not have one yet.'),
      ],
      '#default_value' => $this->configuration['shipping_preference'],
      '#access' => $shipping_enabled,
    ];
    $form['update_billing_profile'] = [
      '#type' => 'checkbox',
      '#title' => t('Update the billing customer profile with address information the customer enters at PayPal.'),
      '#default_value' => $this->configuration['update_billing_profile'],
    ];
    $form['update_shipping_profile'] = [
      '#type' => 'checkbox',
      '#title' => t('Update shipping customer profiles with address information the customer enters at PayPal.'),
      '#default_value' => $this->configuration['update_shipping_profile'],
      '#access' => $shipping_enabled,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    if ($form_state->getErrors()) {
      return;
    }
    $values = $form_state->getValue($form['#parents']);
    $sdk = $this->checkoutSdkFactory->get($values);
    // Make sure we query for a fresh access token.
    \Drupal::state()->delete('commerce_paypal.oauth2_token');
    try {
      $sdk->getAccessToken();
      $this->messenger()->addMessage($this->t('Connectivity to PayPal successfully verified.'));
    }
    catch (ClientException $exception) {
      $this->messenger()->addError($this->t('Invalid client_id or secret specified.'));
      $form_state->setError($form['client_id']);
      $form_state->setError($form['secret']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if ($form_state->getErrors()) {
      return;
    }
    $values = $form_state->getValue($form['#parents']);
    $keys = [
      'client_id',
      'secret',
      'intent',
      'shipping_preference',
      'update_billing_profile',
      'update_shipping_profile',
    ];
    foreach ($keys as $key) {
      if (!isset($values[$key])) {
        continue;
      }
      $this->configuration[$key] = $values[$key];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    $sdk = $this->checkoutSdkFactory->get($this->configuration);
    $paypal_order_id = $payment->getPaymentMethod()->getRemoteId();
    try {
      $sdk->updateOrder($paypal_order_id, $payment->getOrder());
      $request = $sdk->getOrder($paypal_order_id);
      $paypal_order = Json::decode($request->getBody()->getContents());
    }
    catch (ClientException $exception) {
      $this->logger->error($exception->getMessage());
      throw new PaymentGatewayException('Could not retrieve the order in PayPal.');
    }
    if (!in_array($paypal_order['status'], ['APPROVED', 'SAVED'])) {
      throw new PaymentGatewayException('Wrong remote order status.');
    }
    $intent = strtolower($paypal_order['intent']);
    try {
      if ($intent == 'capture') {
        $response = $sdk->captureOrder($paypal_order_id);
        $paypal_order = Json::decode($response->getBody()->getContents());
        $remote_payment = $paypal_order['purchase_units'][0]['payments']['captures'][0];
        $payment->setRemoteId($remote_payment['id']);
      }
      else {
        $response = $sdk->authorizeOrder($paypal_order_id);
        $paypal_order = Json::decode($response->getBody()->getContents());
        $remote_payment = $paypal_order['purchase_units'][0]['payments']['authorizations'][0];

        if (isset($remote_payment['expiration_time'])) {
          $expiration = new \DateTime($remote_payment['expiration_time']);
          $payment->setExpiresTime($expiration->getTimestamp());
        }
      }
    }
    catch (ClientException $exception) {
      $this->logger->error($exception->getMessage());
      throw new PaymentGatewayException('The provided payment method is no longer valid.');
    }
    $remote_state = strtolower($remote_payment['status']);
    $state = $this->mapPaymentState($intent, $remote_state);

    // If we couldn't find a state to map to, stop here.
    if (!$state) {
      throw new PaymentGatewayException('The PayPal payment is in a state we cannot handle.');
    }

    if (in_array($remote_state, ['denied', 'expired', 'declined'])) {
      throw new HardDeclineException(sprintf('Could not %s the payment.', $intent));
    }
    $payment_amount = Price::fromArray([
      'number' => $remote_payment['amount']['value'],
      'currency_code' => $remote_payment['amount']['currency_code'],
    ]);
    $payment->setAmount($payment_amount);
    $payment->setState($state);
    $payment->setRemoteId($remote_payment['id']);
    $payment->setRemoteState($remote_state);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    // Note that we don't actually call the PayPal API for setting up the
    // transaction (i.e creating the order) as this is being handled by the
    // CheckoutController which is called by the JS sdk.
    // We only do that once the actual Smart payment buttons are clicked.
    $payment_method->set('flow', 'mark');
    /** @var \Drupal\profile\Entity\ProfileInterface $shipping_profile $profile */
    // Create an empty profile in order for PaymentInformation not to crash.
    $profile = $this->entityTypeManager->getStorage('profile')->create([
      'type' => 'customer',
    ]);
    $payment_method->setBillingProfile($profile);
    $payment_method->setReusable(FALSE);
    $payment_method->save();
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {}

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    $this->assertPaymentState($payment, ['authorization']);
    // If not specified, capture the entire amount.
    $amount = $amount ?: $payment->getAmount();
    $remote_id = $payment->getRemoteId();
    $params = [
      'amount' => [
        'value' => Calculator::trim($amount->getNumber()),
        'currency_code' => $amount->getCurrencyCode(),
      ],
    ];

    if ($amount->equals($payment->getAmount())) {
      $params['final_capture'] = TRUE;
    }

    try {
      $sdk = $this->checkoutSdkFactory->get($this->configuration);

      // If the payment was authorized more than 3 days ago, attempt to
      // reauthorize it.
      if (($this->time->getRequestTime() >= ($payment->getAuthorizedTime() + (86400 * 3))) && !$payment->isExpired()) {
        $sdk->reAuthorizePayment($remote_id, ['amount' => $params['amount']]);
      }

      $response = $sdk->capturePayment($remote_id, $params);
      $response = Json::decode($response->getBody()->getContents());
    }
    catch (ClientException $exception) {
      $this->logger->error($exception->getMessage());
      throw new PaymentGatewayException('An error occurred while capturing the authorized payment.');
    }
    $remote_state = strtolower($response['status']);
    $state = $this->mapPaymentState('capture', $remote_state);

    if (!$state) {
      throw new PaymentGatewayException('Unhandled payment state.');
    }
    $payment->setState('completed');
    $payment->setAmount($amount);
    $payment->setRemoteState($remote_state);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    $this->assertPaymentState($payment, ['authorization']);
    try {
      $sdk = $this->checkoutSdkFactory->get($this->configuration);
      $response = $sdk->voidPayment($payment->getRemoteId());
    }
    catch (ClientException $exception) {
      $this->logger->error($exception->getMessage());
      throw new PaymentGatewayException('An error occurred while voiding the payment.');
    }
    if ($response->getStatusCode() == Response::HTTP_NO_CONTENT) {
      $payment->setState('authorization_voided');
      $payment->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = null) {
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();
    $this->assertRefundAmount($payment, $amount);

    $old_refunded_amount = $payment->getRefundedAmount();
    $new_refunded_amount = $old_refunded_amount->add($amount);
    $params = [
      'amount' => [
        'value' => Calculator::trim($amount->getNumber()),
        'currency_code' => $amount->getCurrencyCode(),
      ],
    ];
    if ($new_refunded_amount->lessThan($payment->getAmount())) {
      $payment->setState('partially_refunded');
    }
    else {
      $payment->setState('refunded');
    }
    try {
      $sdk = $this->checkoutSdkFactory->get($this->configuration);
      $response = $sdk->refundPayment($payment->getRemoteId(), $params);
      $response = Json::decode($response->getBody()->getContents());
    }
    catch (ClientException $exception) {
      $this->logger->error($exception->getMessage());
      throw new PaymentGatewayException('An error occurred while refunding the payment.');
    }

    if (strtolower($response['status']) !== 'completed') {
      throw new PaymentGatewayException(sprintf('Invalid state returned by PayPal. Expected: ("%s"), Actual: ("%s").', 'COMPLETED', $response['status']));
    }
    $payment->setRefundedAmount($new_refunded_amount);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function onApprove(OrderInterface $order, array $paypal_order) {
    $paypal_amount = $paypal_order['purchase_units'][0]['amount'];
    $paypal_total = Price::fromArray(['number' => $paypal_amount['value'], 'currency_code' => $paypal_amount['currency_code']]);

    // Make sure the order total matches the total we get from PayPal.
    if (!$paypal_total->equals($order->getTotalPrice()) || !in_array($paypal_order['status'], ['APPROVED', 'COMPLETED'])) {
      return new Response('', Response::HTTP_BAD_REQUEST);
    }
    $payer = $paypal_order['payer'];

    if (empty($order->getEmail())) {
      $order->setEmail($payer['email_address']);
    }

    if ($this->configuration['update_billing_profile']) {
      $this->updateProfile($order, 'billing', $paypal_order);
    }
    if (!empty($this->configuration['update_shipping_profile']) && $order->hasField('shipments')) {
      $this->updateProfile($order, 'shipping', $paypal_order);
    }
    // We should enter the condition only if a payment method is already
    // referenced by the order (It's created when the PaymentInformation pane
    // is submitted, that happens in the "mark" flow).
    // Up until this point, the remote_id is unknown,
    if (!$order->get('payment_method')->isEmpty() &&
      $order->get('payment_method')->entity->bundle() == 'paypal_checkout') {
      /**
       * @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method
       */
      $payment_method = $order->get('payment_method')->entity;
      if ($payment_method->getRemoteId() != $paypal_order['id']) {
        $payment_method->setRemoteId($paypal_order['id']);
        $payment_method->save();
      }
      /**
       * @var \Drupal\commerce_checkout\Entity\CheckoutFlowInterface $checkout_flow
       */
      $checkout_flow = $order->get('checkout_flow')->entity;
      $current_checkout_step = $order->get('checkout_step')->value;
      $order->set('payment_gateway', $this->entityId);
      $order->set('checkout_step', $checkout_flow->getPlugin()->getNextStepId($current_checkout_step));
      $order->save();
    }
    else {
      /**
       * @var \Drupal\commerce_payment\PaymentMethodStorageInterface $payment_method_storage
       */
      $payment_method_storage = $this->entityTypeManager->getStorage('commerce_payment_method');
      // The payment method is only created on onApprove() when in the
      // "shortcut" flow.
      $payment_method = $payment_method_storage->create([
        'payment_gateway' => $this->entityId,
        'type' => 'paypal_checkout',
        'flow' => 'shortcut',
        'reusable' => FALSE,
        'remote_id' => $paypal_order['id'],
      ]);
      $payment_method->save();
      // Force the checkout flow to PayPal checkout which is the flow the module
      // defines for the "shortcut" flow.
      $order->set('checkout_flow', 'paypal_checkout');
      $order->set('payment_gateway', $this->entityId);
      $order->set('payment_method', $payment_method->id());
      $order->save();
    }
    // @todo: Display a successful message to the customer?
    // @todo: Investigate if possible to pass a "return_url" to PayPal via
    // the "application_context" instead of custom code to redirect the user.
    $options = [
      'commerce_order' => $order->id(),
    ];
    $redirect_uri = Url::fromRoute('commerce_checkout.form', $options);
    return new JsonResponse(['redirectUri' => $redirect_uri->toString()]);
  }

  /**
   * Map a PayPal payment state to a local one.
   *
   * @param string $type
   *   The payment type. One of "authorize" or "capture"
   * @param string $remote_state
   *   The PayPal remote payment state.
   *
   * @return string
   *   The corresponding local payment state.
   */
  protected function mapPaymentState($type, $remote_state) {
    $mapping = [
      'authorize' => [
        'created' => 'authorization',
        'voided' => 'authorization_voided',
        'expired' => 'authorization_expired',
      ],
      'capture' => [
        'completed' => 'completed',
        'partially_refunded' => 'partially_refunded',
      ],
    ];
    return isset($mapping[$type][$remote_state]) ? $mapping[$type][$remote_state] : '';
  }

  /**
   * Updates the profile of the given type using the response returned by PayPal.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param $type
   *   The type (billing|profile).
   * @param array $paypal_order
   *   The PayPal order.
   */
  protected function updateProfile(OrderInterface $order, $type, array $paypal_order) {
    if ($type == 'billing') {
      /** @var \Drupal\profile\Entity\ProfileInterface $profile */
      $profile = $order->getBillingProfile() ?: $this->buildCustomerProfile($order);
      $profile->address->given_name = $paypal_order['payer']['name']['given_name'];
      $profile->address->family_name =  $paypal_order['payer']['name']['surname'];
      if (isset($paypal_order['payer']['address'])) {
        $this->populateProfile($profile, $paypal_order['payer']['address']);
      }
      $profile->save();
      $order->setBillingProfile($profile);
    }
    elseif ($type == 'shipping' && !empty($paypal_order['purchase_units'][0]['shipping'])) {
      $shipping_info = $paypal_order['purchase_units'][0]['shipping'];
      $shipments = $order->shipments->referencedEntities();
      if (!$shipments) {
        /** @var \Drupal\commerce_shipping\PackerManagerInterface $packer_manager */
        $packer_manager = \Drupal::service('commerce_shipping.packer_manager');
        list($shipments) = $packer_manager->packToShipments($order, $this->buildCustomerProfile($order), $shipments);
      }
      /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $first_shipment */
      $first_shipment = $shipments[0];
      /** @var \Drupal\profile\Entity\ProfileInterface $profile */
      $profile = $first_shipment->getShippingProfile() ?: $this->buildCustomerProfile($order);

      // This is a hack but shipments with empty amounts is crashing other
      // contrib modules.
      // Ideally, we shouldn't have to pack the shipments ourselves...
      if (!$first_shipment->getAmount()) {
        $shipment_amount = Price::fromArray([
          'number' => 0,
          'currency_code' => $order->getTotalPrice()->getCurrencyCode(),
        ]);
        $first_shipment->setAmount($shipment_amount);
      }

      // We only get the full name from PayPal, so we need to "guess" the given
      // name and the family name.
      $names = explode(' ', $shipping_info['name']['full_name']);
      $given_name = array_shift($names);
      $family_name = implode(' ', $names);
      $profile->address->given_name = $given_name;
      $profile->address->family_name =  $family_name;
      if (!empty($shipping_info['address'])) {
        $this->populateProfile($profile, $shipping_info['address']);
      }
      $profile->save();
      $first_shipment->setShippingProfile($profile);
      $first_shipment->save();
      $order->set('shipments', $shipments);
    }
  }

  /**
   * Builds a customer profile, assigned to the order's owner.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Drupal\profile\Entity\ProfileInterface
   *   The customer profile.
   */
  protected function buildCustomerProfile(OrderInterface $order) {
    return $this->entityTypeManager->getStorage('profile')->create([
      'uid' => $order->getCustomerId(),
      'type' => 'customer',
    ]);
  }

  /**
   * Populate the given profile with the given PayPal address.
   *
   * @param \Drupal\profile\Entity\ProfileInterface $profile
   *   The profile to populate.
   * @param array $address
   *   The PayPal address.
   */
  protected function populateProfile(ProfileInterface $profile, array $address) {
    // Map PayPal address keys to keys expected by AddressItem.
    $mapping = [
      'address_line_1' => 'address_line1',
      'address_line_2' => 'address_line2',
      'admin_area_1' => 'administrative_area',
      'admin_area_2' => 'locality',
      'postal_code' => 'postal_code',
      'country_code' => 'country_code',
    ];
    foreach ($address as $key => $value) {
      if (!isset($mapping[$key])) {
        continue;
      }
      // PayPal address fields have a higher maximum length than ours.
      $value = $key == 'country_code' ? $value : mb_substr($value, 0, 255);
      $profile->address->{$mapping[$key]} = $value;
    }
  }

}
