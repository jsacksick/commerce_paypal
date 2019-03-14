<?php

namespace Drupal\commerce_paypal\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides the Paypal Checkout payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "paypal_checkout",
 *   label = @Translation("PayPal (Checkout)"),
 *   display_label = @Translation("PayPal"),
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "discover", "mastercard", "visa",
 *   },
 * )
 */
class Checkout extends OnsitePaymentGatewayBase implements CheckoutInterface {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'client_id' => '',
      'secret' => '',
      'intent' => 'capture',
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

    return $form;
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
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    // TODO: Implement createPayment() method.
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    // TODO: Implement createPaymentMethod() method.
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    // TODO: Implement deletePaymentMethod() method.
  }

}
