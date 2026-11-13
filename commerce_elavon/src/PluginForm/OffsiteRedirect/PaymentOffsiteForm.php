<?php

namespace Drupal\commerce_elavon\PluginForm\OffsiteRedirect;

use Drupal\commerce_elavon\Plugin\Commerce\PaymentGateway\OffsiteRedirect;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Redirects the shopper to Converge with a session token only.
 */
class PaymentOffsiteForm extends BasePaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    $plugin = $payment->getPaymentGateway()->getPlugin();
    if (!$plugin instanceof OffsiteRedirect) {
      throw new PaymentGatewayException('The Elavon off-site gateway is not available.');
    }

    $order = $payment->getOrder();
    $token = $plugin->createHostedSession($payment, $form['#return_url'], $form['#cancel_url']);
    $invoice = $order ? ($order->getOrderNumber() ?: (string) $order->id()) : '';
    $data = $plugin->alterOffsiteBrowserData([
      'ssl_txn_auth_token' => $token,
      'ssl_invoice_number' => $invoice,
    ], $order);

    return $this->buildRedirectForm($form, $form_state, $plugin->getHostedProcessUrl(), $data, 'post');
  }

}
