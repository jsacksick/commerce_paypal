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

  /**
   * Get an existing order from PayPal.
   *
   * @param $remote_id
   *   The PayPal order ID.
   *
   * @return \Psr\Http\Message\ResponseInterface
   */
  public function getOrder($remote_id);

  /**
   * Updates an existing PayPal order.
   *
   * @param $remote_id
   *   The PayPal order ID.
   * @param OrderInterface $order
   *   The order.
   *
   * @return \Psr\Http\Message\ResponseInterface
   */
  public function updateOrder($remote_id, OrderInterface $order);

}
