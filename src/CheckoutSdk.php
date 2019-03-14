<?php

namespace Drupal\commerce_paypal;

use Drupal\address\AddressInterface;
use Drupal\commerce_order\AdjustmentTransformerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use GuzzleHttp\ClientInterface;

/**
 * Provides a replacement of the PayPal SDK.
 */
class CheckoutSdk implements CheckoutSdkInterface {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $client;

  /**
   * The adjustment transformer.
   *
   * @var \Drupal\commerce_order\AdjustmentTransformerInterface
   */
  protected $adjustmentTransformer;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The payment gateway plugin configuration.
   *
   * @var array
   */
  protected $config;

  /**
   * Constructs a new CheckoutSdk object.
   *
   * @param \GuzzleHttp\ClientInterface $client
   *   The client.
   * @param \Drupal\commerce_order\AdjustmentTransformerInterface $adjustment_transformer
   *   The adjustment transformer.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param array $config
   *   The payment gateway plugin configuration array.
   */
  public function __construct(ClientInterface $client, AdjustmentTransformerInterface $adjustment_transformer, ModuleHandlerInterface $module_handler, array $config) {
    $this->client = $client;
    $this->adjustmentTransformer = $adjustment_transformer;
    $this->moduleHandler = $module_handler;
    $this->config = $config;
  }

  /**
   * {@inheritdoc}
   */
  public function getAccessToken() {
    return $this->client->post('/v1/oauth2/token', [
      'auth' => [$this->config['client_id'], $this->config['secret']],
      'form_params' => [
        'grant_type' => 'client_credentials',
      ],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function createOrder(OrderInterface $order) {
    $params = $this->prepareOrderRequest($order);
    return $this->client->post('/v2/checkout/orders', ['json' => $params]);
  }

  /**
   * {@inheritdoc}
   */
  public function getOrder($remote_id) {
    return $this->client->get(sprintf('/v2/checkout/orders/%s', $remote_id));
  }

  /**
   * {@inheritdoc}
   */
  public function updateOrder($remote_id, OrderInterface $order) {
    $params = $this->prepareOrderRequest($order);
    $update_params = [
      [
        'op' => 'replace',
        'path' => "/purchase_units/@reference_id=='default'",
        'value' => $params['purchase_units'][0],
      ],
    ];
    return $this->client->patch(sprintf('/v2/checkout/orders/%s', $remote_id), ['json' => $update_params]);
  }

  /**
   * {@inheritdoc}
   */
  public function authorizeOrder($remote_id) {
    $headers = [
      'Content-Type' => 'application/json',
    ];
    return $this->client->post(sprintf('/v2/checkout/orders/%s/authorize', $remote_id), ['headers' => $headers]);
  }

  /**
   * {@inheritdoc}
   */
  public function captureOrder($remote_id) {
    $headers = [
      'Content-Type' => 'application/json',
    ];
    return $this->client->post(sprintf('/v2/checkout/orders/%s/capture', $remote_id), ['headers' => $headers]);
  }

  /**
   * Prepare the order request parameters.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @return array
   *   An array suitable for use in the create|update order API calls.
   */
  protected function prepareOrderRequest(OrderInterface $order) {
    $items = [];
    $item_total = NULL;
    $tax_total = NULL;
    foreach ($order->getItems() as $order_item) {
      // We need to pass the adjusted unit/total because passing a discount
      // in the breakdown isn't supported yet.
      // See https://github.com/paypal/paypal-checkout-components/issues/1016.
      $item_total = $item_total ? $item_total->add($order_item->getAdjustedTotalPrice(['promotion'])) : $order_item->getAdjustedTotalPrice(['promotion']);
      $item = [
        'name' => mb_substr($order_item->getTitle(), 0, 127),
        'unit_amount' => [
          'currency_code' => $order_item->getUnitPrice()->getCurrencyCode(),
          'value' => $order_item->getAdjustedUnitPrice(['promotion'])->getNumber(),
        ],
        'quantity' => intval($order_item->getQuantity()),
      ];

      $purchased_entity = $order_item->getPurchasedEntity();
      if ($purchased_entity instanceof ProductVariationInterface) {
        $line_item['sku'] = mb_substr($purchased_entity->getSku(), 127);
      }
      $items[] = $item;
    }
    $adjustments = $order->collectAdjustments();

    $breakdown = [
      'item_total' => [
        'currency_code' => $item_total->getCurrencyCode(),
        'value' => Calculator::trim($item_total->getNumber()),
      ],
    ];

    $tax_total = $this->getAdjustmentsTotal($adjustments, ['tax']);
    if (!empty($tax_total)) {
      $breakdown['tax_total'] = [
        'currency_code' => $tax_total->getCurrencyCode(),
        'value' => Calculator::trim($tax_total->getNumber()),
      ];
    }

    $shipping_total = $this->getAdjustmentsTotal($adjustments, ['shipping']);
    if (!empty($shipping_total)) {
      $breakdown['shipping'] = [
        'currency_code' => $shipping_total->getCurrencyCode(),
        'value' => Calculator::trim($shipping_total->getNumber()),
      ];
    }
    $payer = [];

    if (!empty($order->getEmail())) {
      $payer['email_address'] = $order->getEmail();
    }

    $billing_profile = $order->getBillingProfile();
    if (!empty($billing_profile)) {
      /** @var \Drupal\address\AddressInterface $address */
      $address = $billing_profile->address->first();
      if (!empty($address)) {
        $payer['address'] = static::formatAddress($address);
      }
    }
    $time = \Drupal::time()->getRequestTime();
    $params = [
      'intent' => strtoupper($this->config['intent']),
      'purchase_units' => [
        [
          'reference_id' => 'default',
          'custom_id' => $order->id(),
          'invoice_id' => $order->id() . '-' . $time,
          'amount' => [
            'currency_code' => $order->getTotalPrice()->getCurrencyCode(),
            'value' => Calculator::trim($order->getTotalPrice()->getNumber()),
            'breakdown' => $breakdown,
          ],
          'items' => $items,
        ],
      ],
      'application_context' => [
        'brand_name' => mb_substr($order->getStore()->label(), 0, 127),
      ],
    ];
    $shipping_address = $this->collectShippingAddress($order);
    if ($shipping_address) {
      $params['purchase_units'][0]['shipping']['address'] = $shipping_address;
    }
    $shipping_preference = $this->config['shipping_preference'];

    // The shipping module isn't enabled, override the shipping preference
    // configured.
    if (!$this->moduleHandler->moduleExists('commerce_shipping')) {
      $shipping_preference = 'no_shipping';
    }
    else {
      // If no shipping address was already collected, override the shipping
      // preference to "GET_FROM_FILE" so that the shipping address is collected
      // on the PayPal site.
      if ($shipping_preference == 'set_provided_address' && !$shipping_address) {
        $shipping_preference = 'get_from_file';
      }
    }
    $params['application_context']['shipping_preference'] = strtoupper($shipping_preference);

    if ($payer) {
      $params['payer'] = $payer;
    }

    return $params;
  }

  /**
   * Get the total for the given adjustments.
   *
   * @param \Drupal\commerce_order\Adjustment[] $adjustments
   *   The adjustments.
   * @param string[] $adjustment_types
   *   The adjustment types to include in the calculation.
   *   Examples: fee, promotion, tax. Defaults to all adjustment types.
   *
   * @return \Drupal\commerce_price\Price|NULL
   *   The adjustments total, or NULL if no matching adjustments were found.
   */
  protected function getAdjustmentsTotal(array $adjustments, array $adjustment_types = []) {
    $adjustments_total = NULL;
    $matching_adjustments = [];

    foreach ($adjustments as $adjustment) {
      if ($adjustment_types && !in_array($adjustment->getType(), $adjustment_types)) {
        continue;
      }
      if ($adjustment->isIncluded()) {
        continue;
      }
      $matching_adjustments[] = $adjustment;
    }
    if ($matching_adjustments) {
      $matching_adjustments = $this->adjustmentTransformer->processAdjustments($matching_adjustments);
      foreach ($matching_adjustments as $adjustment) {
        $adjustments_total = $adjustments_total ? $adjustments_total->add($adjustment->getAmount()) : $adjustment->getAmount();
      }
    }

    return $adjustments_total;
  }

  /**
   * Collect the shipping address from the first referenced shipment.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array
   *   The formatted shipping address extracted from the first referenced
   *   shipment, an empty array if no shipping profile was found.
   */
  protected function collectShippingAddress(OrderInterface $order) {
    $shipping_address = [];

    if (!$order->hasField('shipments') || $order->get('shipments')->isEmpty()) {
      return $shipping_address;
    }
    /**
     * @var \Drupal\commerce_shipping\Entity\ShipmentInterface $first_shipment
     */
    $first_shipment = $order->get('shipments')->first()->entity;
    $shipping_profile = $first_shipment->getShippingProfile();
    if (empty($shipping_profile) || $shipping_profile->get('address')->isEmpty()) {
      return $shipping_address;
    }
    $shipping_address = static::formatAddress($shipping_profile->get('address')->first());

    return $shipping_address;
  }

  /**
   * {@inheritdoc}
   */
  public static function formatAddress(AddressInterface $address) {
    return [
      'name' => [
        'given_name' => $address->getGivenName(),
        'surname' => $address->getFamilyName(),
      ],
      'address_line_1' => $address->getAddressLine1(),
      'address_line_2' => $address->getAddressLine2(),
      'admin_area_2' => $address->getLocality(),
      'admin_area_1' => $address->getAdministrativeArea(),
      'postal_code' => $address->getPostalCode(),
      'country_code' => $address->getCountryCode(),
    ];
  }

}
