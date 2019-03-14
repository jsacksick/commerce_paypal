<?php

namespace Drupal\commerce_paypal\Controller;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_paypal\CheckoutSdkFactoryInterface;
use Drupal\commerce_paypal\Plugin\Commerce\PaymentGateway\CheckoutInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\AccessException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use GuzzleHttp\Exception\ClientException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * PayPal checkout controller.
 */
class CheckoutController extends ControllerBase {

  /**
   * The PayPal Checkout SDK factory.
   *
   * @var \Drupal\commerce_paypal\CheckoutSdkFactoryInterface
   */
  protected $checkoutSdkFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   */
  protected $entityTypeManager;

  /**
   * Constructs a PayPalCheckoutController object.
   *
   * @param \Drupal\commerce_paypal\CheckoutSdkFactoryInterface $checkout_sdk_factory
   *   The PayPal Checkout SDK factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(CheckoutSdkFactoryInterface $checkout_sdk_factory, EntityTypeManagerInterface $entity_type_manager) {
    $this->checkoutSdkFactory = $checkout_sdk_factory;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('commerce_paypal.checkout_sdk_factory'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Create the order in PayPal.
   *
   * @param PaymentGatewayInterface $commerce_payment_gateway
   *   The payment gateway.
   * @param OrderInterface $commerce_order
   *   The order.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A response.
   */
  public function onCreate(PaymentGatewayInterface $commerce_payment_gateway, OrderInterface $commerce_order) {
    $payment_gateway_plugin = $commerce_payment_gateway->getPlugin();
    if (!$payment_gateway_plugin instanceof CheckoutInterface) {
      throw new AccessException('Invalid payment gateway provided.');
    }
    $config = $commerce_payment_gateway->getPluginConfiguration();
    $sdk = $this->checkoutSdkFactory->get($config);
    try {
      $response = $sdk->createOrder($commerce_order);
      $body = Json::decode($response->getBody()->getContents());
      return new JsonResponse(['id' => $body['id']]);
    }
    catch (ClientException $exception) {
      return new Response('', Response::HTTP_BAD_REQUEST);
    }
  }

}
