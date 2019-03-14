<?php

namespace Drupal\commerce_paypal;

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
   * The payment gateway configuration.
   *
   * @var array
   */
  protected $config;

  /**
   * Constructs a new CheckoutSdk object.
   *
   * @param \GuzzleHttp\ClientInterface $client
   *   The client.
   * @param array $config
   *   The payment gateway plugin configuration array.
   */
  public function __construct(ClientInterface $client, array $config) {
    $this->client = $client;
    $this->config = $config;
  }

}