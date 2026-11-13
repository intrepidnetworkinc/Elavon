<?php

namespace Drupal\commerce_elavon\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_elavon\AvsResponses;
use Drupal\commerce_elavon\Converge\Amounts;
use Drupal\commerce_elavon\Converge\ApiClient;
use Drupal\commerce_elavon\Converge\BrowserPayload;
use Drupal\commerce_elavon\Converge\Redactor;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Attribute\CommercePaymentGateway;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_elavon\PluginForm\OffsiteRedirect\PaymentOffsiteForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Off-site Converge redirect.
 *
 * The shopper's browser posts only a session token. The return URL is not
 * trusted: the transaction is loaded from Converge with txnquery and checked
 * against this order before a payment is completed.
 */
#[CommercePaymentGateway(
  id: 'elavon_offsite_redirect',
  label: new TranslatableMarkup('Elavon (Off-site redirect)'),
  display_label: new TranslatableMarkup('Elavon Offsite'),
  forms: [
    'offsite-payment' => PaymentOffsiteForm::class,
  ],
  payment_method_types: ['credit_card'],
  credit_card_types: [
    'amex',
    'dinersclub',
    'discover',
    'jcb',
    'maestro',
    'mastercard',
    'visa',
  ],
)]
class OffsiteRedirect extends OffsitePaymentGatewayBase implements SupportsRefundsInterface {

  use ConvergeGatewayTrait;

  /**
   * The module handler, used for the off-site data hook.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->apiClient = new ApiClient(
      $container->get('http_client'),
      $container->get('logger.factory')->get('commerce_elavon'),
    );
    $instance->convergeLogger = $container->get('logger.factory')->get('commerce_elavon');
    $instance->settings = $container->get('settings');
    $instance->avsResponses = AvsResponses::create($container);
    $instance->transliteration = $container->get('transliteration');
    $instance->moduleHandler = $container->get('module_handler');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form = $this->buildConvergeConfigurationForm($form, $form_state);
    $form['hosted_notice'] = [
      '#type' => 'item',
      '#title' => $this->t('Hosted payments'),
      '#markup' => $this->t('This gateway requests a session token from Converge before redirecting the shopper. The PIN is not sent to the browser. The web server IP must be allow-listed on the terminal for hosted payments, and the terminal must allow the hosted payment page.'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $existing_pin = (string) ($this->configuration['pin'] ?? '');
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $this->configuration['pin'] = $existing_pin;
      $this->submitConvergeConfigurationForm($form, $form_state);
    }
  }

  /**
   * Requests the session token placed in the redirect form.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   */
  public function createHostedSession(PaymentInterface $payment, string $return_url, string $cancel_url): string {
    $amount = $payment->getAmount();
    $this->assertChargeableAmount($amount);
    $order = $payment->getOrder();
    $fields = [
      'ssl_transaction_type' => 'ccsale',
      'ssl_amount' => Amounts::format($amount->getNumber()),
      'ssl_show_form' => 'true',
      'ssl_card_present' => 'N',
      'ssl_receipt_link_method' => 'REDP',
      'ssl_receipt_link_url' => $return_url,
      'ssl_receipt_decl_get_url' => $cancel_url,
      'ssl_invoice_number' => $order ? (string) $order->id() : '',
    ];
    $profile = $order ? $order->getBillingProfile() : NULL;
    if ($profile && !$profile->get('address')->isEmpty()) {
      $address = $profile->get('address')->first();
      $countries = ['US' => 'USA', 'CA' => 'CAN'];
      $country = strtoupper((string) $address->getCountryCode());
      $values = [
        'ssl_first_name' => $this->transliterateValue((string) $address->getGivenName()),
        'ssl_last_name' => $this->transliterateValue((string) $address->getFamilyName()),
        'ssl_company' => $this->transliterateValue((string) $address->getOrganization()),
        'ssl_avs_address' => $this->transliterateValue((string) $address->getAddressLine1()),
        'ssl_address2' => $this->transliterateValue((string) $address->getAddressLine2()),
        'ssl_city' => $this->transliterateValue((string) $address->getLocality()),
        'ssl_state' => (string) $address->getAdministrativeArea(),
        'ssl_avs_zip' => preg_replace('/[^[:alnum:]]/', '', (string) $address->getPostalCode()),
        'ssl_country' => $countries[$country] ?? $country,
      ];
      foreach ($values as $key => $value) {
        $value = trim((string) $value);
        if ($value !== '') {
          $fields[$key] = substr($value, 0, $key === 'ssl_avs_zip' ? 9 : 30);
        }
      }
    }
    if ($order && $order->getEmail()) {
      $fields['ssl_email'] = substr($order->getEmail(), 0, 100);
    }
    $fields = $this->addCurrencyField($fields, $amount);
    $request = \Drupal::request();
    $ip = (string) $request->headers->get('CF-Connecting-IP');
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
      $ip = (string) $request->getClientIp();
    }
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
      $fields['ssl_cardholder_ip'] = $ip;
    }
    return $this->apiClient->requestSessionToken($this->getMode(), $this->getConvergeCredentials(), $fields);
  }

  /**
   * Lets other modules add browser fields, then strips secrets.
   *
   * The hook is hook_commerce_elavon_offsite_payment().
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When the session token is missing after the alter.
   */
  public function alterOffsiteBrowserData(array $data, OrderInterface $order): array {
    if ($this->moduleHandler) {
      $this->moduleHandler->invokeAllWith('commerce_elavon_offsite_payment', function (callable $hook, string $module) use (&$data, $order) {
        $hook($data, $order);
      });
    }
    $data = BrowserPayload::sanitize($data);
    if (empty($data['ssl_txn_auth_token']) || !is_string($data['ssl_txn_auth_token'])) {
      throw new PaymentGatewayException('The hosted payment token was removed before redirect.');
    }
    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    $txn_id = (string) ($request->query->get('ssl_txn_id') ?: $request->request->get('ssl_txn_id'));
    if ($txn_id === '' || !preg_match('/^[A-Za-z0-9-]+$/', $txn_id)) {
      throw new PaymentGatewayException('The payment could not be verified.');
    }

    $storage = $this->entityTypeManager->getStorage('commerce_payment');
    $existing_remote = $storage->loadByProperties(['remote_id' => $txn_id]);
    foreach ($existing_remote as $existing) {
      if ((string) $existing->getOrderId() !== (string) $order->id()) {
        $this->convergeLogger->error('Rejected Converge transaction @txn for order @order because it is already stored on another order.', [
          '@txn' => $txn_id,
          '@order' => $order->id(),
        ]);
        throw new PaymentGatewayException('The payment could not be verified.');
      }
      if ($existing->getState()->getId() !== 'new') {
        return;
      }
    }

    $payment = NULL;
    $gateway_id = $this->parentEntity->id();
    foreach ($storage->loadMultipleByOrder($order) as $candidate) {
      if ($candidate->getPaymentGatewayId() !== $gateway_id || $candidate->getState()->getId() !== 'new') {
        continue;
      }
      if (!$payment || (int) $candidate->id() > (int) $payment->id()) {
        $payment = $candidate;
      }
    }
    $expected = $payment ? $payment->getAmount() : ($order->getBalance() ?: $order->getTotalPrice());
    if (!$expected) {
      throw new PaymentGatewayException('The order has no amount to verify.');
    }
    $this->assertChargeableAmount($expected);

    $now = $this->time->getRequestTime();
    try {
      $result = $this->postApproved([
        'ssl_transaction_type' => 'txnquery',
        'ssl_txn_id' => $txn_id,
        'ssl_search_start_date' => date('m/d/Y', $now - (3 * 86400)),
        'ssl_search_end_date' => date('m/d/Y', $now + 86400),
      ]);
    }
    catch (PaymentGatewayException $e) {
      $this->convergeLogger->error('Could not verify Converge transaction @txn for order @order: @message', [
        '@txn' => $txn_id,
        '@order' => $order->id(),
        '@message' => Redactor::redact($e->getMessage()),
      ]);
      throw new PaymentGatewayException('The payment could not be verified.', 0, $e);
    }
    if ($result->txnId() !== '' && $result->txnId() !== $txn_id) {
      throw new PaymentGatewayException('The payment could not be verified.');
    }
    try {
      $this->assertResponseAmount($result, $expected);
    }
    catch (PaymentGatewayException $e) {
      $this->convergeLogger->error('Converge amount or currency for transaction @txn did not match order @order.', [
        '@txn' => $txn_id,
        '@order' => $order->id(),
      ]);
      throw new PaymentGatewayException('The payment could not be verified.', 0, $e);
    }
    $invoice = (string) $order->id();
    if ($result->invoiceNumber() !== NULL && $result->invoiceNumber() !== $invoice) {
      $this->convergeLogger->error('Converge invoice @invoice did not match order @order for transaction @txn.', [
        '@invoice' => $result->invoiceNumber(),
        '@order' => $invoice,
        '@txn' => $txn_id,
      ]);
      throw new PaymentGatewayException('The payment could not be verified.');
    }
    if (!$this->isVerifiedSale($result->transactionType())) {
      $this->convergeLogger->error('Converge transaction @txn for order @order had type @type.', [
        '@txn' => $txn_id,
        '@order' => $order->id(),
        '@type' => (string) $result->transactionType(),
      ]);
      throw new PaymentGatewayException('The payment could not be verified.');
    }
    $this->applyAvsCvvPolicy($result, $expected, TRUE);

    if (!$payment) {
      $payment = $storage->create([
        'state' => 'new',
        'amount' => $expected,
        'payment_gateway' => $gateway_id,
        'order_id' => $order->id(),
      ]);
    }
    $this->recordAvs($payment, $result);
    $payment->setState('completed');
    $payment->setRemoteId($txn_id);
    $payment->setRemoteState($result->resultCode());
    $payment->setAmount($expected);
    $payment->save();
  }

  /**
   * Finds the Drupal order for a hosted sale when the redirect omits it.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When the transaction cannot be loaded or was not approved.
   */
  public function findOrderIdForHostedReturn(string $txn_id, ?string $fallback_amount = NULL, ?string $fallback_email = NULL): ?string {
    $now = $this->time->getRequestTime();
    $result = $this->postApproved([
      'ssl_transaction_type' => 'txnquery',
      'ssl_txn_id' => $txn_id,
      'ssl_search_start_date' => date('m/d/Y', $now - (3 * 86400)),
      'ssl_search_end_date' => date('m/d/Y', $now + 86400),
    ]);
    $invoice = (string) $result->invoiceNumber();
    if (preg_match('/^[0-9]{1,10}$/', $invoice)) {
      return $invoice;
    }

    $amount = $result->amount() ?: $fallback_amount;
    $email = strtolower(trim((string) ($result->email() ?: $fallback_email)));
    if ($amount === NULL || $amount === '') {
      $this->convergeLogger->warning('Hosted transaction @txn had no amount to match.', [
        '@txn' => $txn_id,
      ]);
      return NULL;
    }
    $gateway_id = $this->parentEntity->id();
    $storage = $this->entityTypeManager->getStorage('commerce_order');
    $ids = $storage->getQuery()
      ->condition('state', 'draft')
      ->accessCheck(FALSE)
      ->sort('changed', 'DESC')
      ->range(0, 20)
      ->execute();
    $matches = [];
    foreach ($storage->loadMultiple($ids) as $order) {
      $total = $order->getTotalPrice();
      if (!$total || !Amounts::equals($total->getNumber(), $amount)) {
        continue;
      }
    if ($email !== '' && strtolower((string) $order->getEmail()) !== $email) {
        continue;
      }
      $gateway = $order->get('payment_gateway')->target_id ?? '';
      if ($gateway !== '' && $gateway !== $gateway_id) {
        continue;
      }
      $matches[] = $order;
    }
    if ($matches === []) {
      $this->convergeLogger->warning('Hosted transaction @txn matched no draft order for @amount @email.', [
        '@txn' => $txn_id,
        '@amount' => $amount,
        '@email' => $email !== '' ? $email : 'none',
      ]);
      return NULL;
    }
    usort($matches, static function ($a, $b) {
      return $b->getChangedTime() <=> $a->getChangedTime();
    });
    $chosen = (string) $matches[0]->id();
    $this->convergeLogger->info('Hosted transaction @txn matched draft order @order (@count candidates).', [
      '@txn' => $txn_id,
      '@order' => $chosen,
      '@count' => count($matches),
    ]);
    return $chosen;
  }
  
  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, ?Price $amount = NULL) {
    $this->refundConvergePayment($payment, $amount);
  }

  /**
   * TRUE when a txnquery result may complete an off-site sale.
   */
  private function isVerifiedSale(?string $type): bool {
    $type = strtoupper(trim((string) $type));
    if ($type === '' || $type === 'TXNQUERY' || $type === 'QUERY' || str_contains($type, 'SALE')) {
      return TRUE;
    }
    foreach (['VOID', 'DELETE', 'RETURN', 'REFUND', 'AUTH', 'TOKEN', 'COMPLETE'] as $blocked) {
      if (str_contains($type, $blocked)) {
        return FALSE;
      }
    }
    return TRUE;
  }

}
