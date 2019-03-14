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
use GuzzleHttp\Exception\ClientException;
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
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time, ModuleHandlerInterface $module_handler, CheckoutSdkFactoryInterface $checkout_sdk_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);
    $this->moduleHandler = $module_handler;
    // Don't instantiate the client from there to be able to test the
    // connectivity after updating the client_id & secret.
    $this->checkoutSdkFactory = $checkout_sdk_factory;
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
      $container->get('commerce_paypal.checkout_sdk_factory')
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
    $form['shipping_preference'] = [
      '#type' => 'radios',
      '#title' => $this->t('Shipping address collection'),
      '#options' => [
        'no_shipping' => $this->t('Do not ask for a shipping address at PayPal.'),
        'get_from_file' => $this->t('Ask for a shipping address at PayPal even if the order already has one.'),
        'set_provided_address' => $this->t('Ask for a shipping address at PayPal if the order does not have one yet.'),
      ],
      '#default_value' => $this->configuration['shipping_preference'],
      '#access' => $this->moduleHandler->moduleExists('commerce_shipping'),
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
    $this->configuration['client_id'] = $values['client_id'];
    $this->configuration['secret'] = $values['secret'];
    $this->configuration['intent'] = $values['intent'];
    $this->configuration['shipping_preference'] = $values['shipping_preference'];
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
  public function onApprove(OrderInterface $order, array $paypal_order) {
    $paypal_amount = $paypal_order['purchase_units'][0]['amount'];
    $paypal_total = Price::fromArray(['number' => $paypal_amount['value'], 'currency_code' => $paypal_amount['currency_code']]);

    // Make sure the order total matches the total we get from PayPal.
    if (!$paypal_total->equals($order->getTotalPrice()) || !in_array($paypal_order['status'], ['APPROVED', 'COMPLETED'])) {
      return new Response('', Response::HTTP_BAD_REQUEST);
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

}
