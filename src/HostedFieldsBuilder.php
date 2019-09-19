<?php

namespace Drupal\commerce_paypal;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_paypal\Plugin\Commerce\PaymentGateway\CheckoutInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Url;
use GuzzleHttp\Exception\ClientException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Provides a helper for building the PayPal checkout hosted fields form.
 */
class HostedFieldsBuilder implements HostedFieldsBuilderInterface {

  /**
   * The PayPal Checkout SDK factory.
   *
   * @var \Drupal\commerce_paypal\CheckoutSdkFactoryInterface
   */
  protected $checkoutSdkFactory;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a new HostedFieldsBuilder object.
   *
   * @param \Drupal\commerce_paypal\CheckoutSdkFactoryInterface
   *   The PayPal Checkout SDK factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   */
  public function __construct(CheckoutSdkFactoryInterface $checkout_sdk_factory, LoggerInterface $logger) {
    $this->checkoutSdkFactory = $checkout_sdk_factory;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public function build(OrderInterface $order, PaymentGatewayInterface $payment_gateway) {
    $element = [];
    if (!$payment_gateway->getPlugin() instanceof CheckoutInterface) {
      return $element;
    }
    $config = $payment_gateway->getPlugin()->getConfiguration();
    $sdk = $this->checkoutSdkFactory->get($config);
    try {
      $response = $sdk->getClientToken();
      $body = Json::decode($response->getBody()->getContents());
      $client_token = $body['client_token'];
    }
    catch (ClientException $exception) {
      $this->logger->error($exception->getMessage());
      return $element;
    }
    $create_url = Url::fromRoute('commerce_paypal.checkout.create', [
      'commerce_payment_gateway' => $payment_gateway->id(),
      'commerce_order' => $order->id(),
    ]);
    $return_url = Url::fromRoute('commerce_paypal.checkout.approve', [
      'commerce_order' => $order->id(),
    ]);
    $options = [
      'query' => [
        'components' => 'hosted-fields',
        'client-id' => $config['client_id'],
        'intent' => $config['intent'],
        'currency' => $order->getTotalPrice()->getCurrencyCode(),
      ],
    ];
    $element['#attached']['library'][] = 'commerce_paypal/paypal_checkout_hosted_fields';
    $element['#attached']['drupalSettings']['paypalCheckout'] = [
      'src' => Url::fromUri('https://www.paypal.com/sdk/js', $options)->toString(),
      'onCreateUrl' => $create_url->toString(),
      'onApproveUrl' => $return_url->toString(),
      'clientToken' => $client_token,
    ];
    $element += [
      '#theme' => 'commerce_paypal_checkout_card_form',
    ];
    return $element;
  }

}
