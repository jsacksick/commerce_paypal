<?php

namespace Drupal\commerce_paypal;

use Drupal\Core\Http\ClientFactory;

/**
 * Defines a factory for our custom PayPal checkout SDK.
 */
class CheckoutSdkFactory implements CheckoutSdkFactoryInterface {

  /**
   * The core client factory.
   *
   * @var \Drupal\Core\Http\ClientFactory
   */
  protected $clientFactory;

  /**
   * Array of all instantiated PayPal Checkout SDKs.
   *
   * @var \Drupal\commerce_paypal\CheckoutSdkInterface[]
   */
  protected $instances = [];

  /**
   * Constructs a new CheckoutSdkFactory object.
   *
   * @param \Drupal\Core\Http\ClientFactory $client_factory
   *   The client factory.
   */
  public function __construct(ClientFactory $client_factory) {
    $this->clientFactory = $client_factory;
  }

  /**
   * {@inheritdoc}
   */
  public function get(array $configuration) {
    $client_id = $configuration['client_id'];
    if (!isset($this->instances[$client_id])) {
      $client = $this->getClient($configuration);
      $this->instances[$client_id] = new CheckoutSdk($client, $configuration);
    }

    return $this->instances[$client_id];
  }

  /**
   * Gets a preconfigured HTTP client instance for use by the SDK.
   *
   * @param array $config
   *   The config for the client.
   *
   * @return \GuzzleHttp\Client
   *   The API client.
   */
  protected function getClient(array $config) {
    switch ($config['mode']) {
      case 'live':
        $base_uri = 'https://api.paypal.com';
        break;

      case 'test':
      default:
        $base_uri = 'https://api.sandbox.paypal.com';
        break;
    }
    $options = [
      'base_uri' => $base_uri,
    ];
    return $this->clientFactory->fromOptions($options);
  }

}
