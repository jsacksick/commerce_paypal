<?php

namespace Drupal\commerce_paypal;

use Drupal\commerce_order\Entity\OrderInterface;

interface CheckoutSdkInterface {

  /**
   * Gets an access token.
   *
   * @return \Psr\Http\Message\ResponseInterface
   */
  public function getAccessToken();

  /**
   * Creates an order in PayPal.
   *
   * @param OrderInterface $order
   *   The order.
   *
   * @return \Psr\Http\Message\ResponseInterface
   */
  public function createOrder(OrderInterface $order);

}
