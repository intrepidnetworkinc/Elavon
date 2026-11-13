<?php

namespace Drupal\commerce_elavon\Controller;

use Drupal\commerce_elavon\Plugin\Commerce\PaymentGateway\OffsiteRedirect;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Receives the single Converge hosted-payment redirect.
 */
class HostedReturnController extends ControllerBase {

  /**
   * Continues checkout for the order named by the Converge invoice number.
   */
  public function returnFromConverge(Request $request): RedirectResponse {
    $invoice = $this->read($request, 'ssl_invoice_number');
    $txn_id = $this->read($request, 'ssl_txn_id');
    if (!preg_match('/^[A-Za-z0-9-]+$/', $txn_id)) {
      $this->getLogger('commerce_elavon')->warning('Hosted return had no usable transaction ID. Query keys: @query. POST keys: @post.', [
        '@query' => implode(', ', array_keys($request->query->all())),
        '@post' => implode(', ', array_keys($request->request->all())),
      ]);
      $this->messenger()->addError($this->t('The payment could not be verified.'));
      return $this->redirectToCart();
    }
    if (!preg_match('/^[0-9]{1,10}$/', $invoice)) {
      $invoice = (string) $this->orderIdFromTransaction($txn_id, $this->read($request, 'ssl_amount'), $this->read($request, 'ssl_email'));
    }
    if (!preg_match('/^[0-9]{1,10}$/', $invoice)) {
      $this->getLogger('commerce_elavon')->warning('Hosted return for transaction @txn did not match a Drupal order.', [
        '@txn' => $txn_id,
      ]);
      $this->messenger()->addError($this->t('The payment could not be verified.'));
      return $this->redirectToCart();
    }

    $order = $this->entityTypeManager()->getStorage('commerce_order')->load($invoice);
    if (!$order instanceof OrderInterface || $order->getState()->getId() !== 'draft') {
      $this->messenger()->addError($this->t('The payment could not be verified.'));
      return $this->redirectToCart();
    }
    $gateway = $order->get('payment_gateway')->entity ?? NULL;
    if (!$gateway instanceof PaymentGatewayInterface || $gateway->getPluginId() !== 'elavon_offsite_redirect') {
      $this->messenger()->addError($this->t('The payment could not be verified.'));
      return $this->redirectToCart();
    }

    $step = (string) $order->get('checkout_step')->value;
    if ($step === '') {
      $step = 'payment';
    }
    $cart_session = \Drupal::service('commerce_cart.cart_session');
    if (!$cart_session->hasCartId($order->id())) {
      $cart_session->addCartId($order->id());
    }
    $url = Url::fromRoute('commerce_payment.checkout.return', [
      'commerce_order' => $order->id(),
      'step' => $step,
    ], [
      'query' => [
        'ssl_txn_id' => $txn_id,
        'ssl_invoice_number' => $invoice,
      ],
    ]);
    return new RedirectResponse($url->toString());
  }

  /**
   * Loads the approved transaction and returns its Drupal order ID.
   */
  private function orderIdFromTransaction(string $txn_id, string $amount, string $email): ?string {
    $gateways = $this->entityTypeManager()->getStorage('commerce_payment_gateway')->loadByProperties([
      'plugin' => 'elavon_offsite_redirect',
    ]);
    foreach ($gateways as $gateway) {
      if (!$gateway instanceof PaymentGatewayInterface) {
        continue;
      }
      $plugin = $gateway->getPlugin();
      if (!$plugin instanceof OffsiteRedirect) {
        continue;
      }
      try {
        return $plugin->findOrderIdForHostedReturn($txn_id, $amount, $email);
      }
      catch (\Exception $e) {
        $this->getLogger('commerce_elavon')->warning('Hosted return could not query transaction @txn: @message', [
          '@txn' => $txn_id,
          '@message' => $e->getMessage(),
        ]);
      }
    }
    return NULL;
  }

  /**
   * Returns the shopper to checkout without canceling or deleting the order.
   */
  public function cancelFromConverge(): RedirectResponse {
    $carts = \Drupal::service('commerce_cart.cart_provider')->getCarts();
    foreach ($carts as $order) {
      if (!$order instanceof OrderInterface || $order->getState()->getId() !== 'draft') {
        continue;
      }
      $gateway = $order->get('payment_gateway')->entity ?? NULL;
      if (!$gateway instanceof PaymentGatewayInterface || $gateway->getPluginId() !== 'elavon_offsite_redirect') {
        continue;
      }
      $this->messenger()->addStatus($this->t('Payment was canceled. You can choose another way to pay.'));
      $step = (string) $order->get('checkout_step')->value;
      if ($step === '') {
        $step = 'order_information';
      }
      return new RedirectResponse(Url::fromRoute('commerce_checkout.form', [
        'commerce_order' => $order->id(),
        'step' => $step,
      ])->toString());
    }
    $this->messenger()->addStatus($this->t('Payment was canceled.'));
    return $this->redirectToCart();
  }

  /**
   * Reads one Converge field from the query string or the POST body.
   */
  private function read(Request $request, string $name): string {
    $value = $request->query->get($name);
    if (!is_scalar($value) || (string) $value === '') {
      $value = $request->request->get($name);
    }
    return is_scalar($value) ? trim((string) $value) : '';
  }

  /**
   * Sends the shopper to the cart when the return cannot be matched.
   */
  private function redirectToCart(): RedirectResponse {
    return new RedirectResponse(Url::fromRoute('commerce_cart.page')->toString());
  }

}