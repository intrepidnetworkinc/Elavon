<?php

namespace Drupal\commerce_elavon\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_elavon\AvsResponses;
use Drupal\commerce_elavon\Converge\Amounts;
use Drupal\commerce_elavon\Converge\ApiClient;
use Drupal\commerce_elavon\Converge\AvsCvvPolicy;
use Drupal\commerce_elavon\Converge\Credentials;
use Drupal\commerce_elavon\Converge\Endpoints;
use Drupal\commerce_elavon\Converge\Redactor;
use Drupal\commerce_elavon\Converge\TransactionResult;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_price\Price;
use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Psr\Log\LoggerInterface;

/**
 * Shared Converge configuration and transaction operations.
 *
 * Used by the on-site and off-site gateway plugins, which cannot share a
 * parent class because Commerce gives them different base classes.
 */
trait ConvergeGatewayTrait {

  use MessengerTrait;
  use StringTranslationTrait;

  /**
   * The Converge HTTP client.
   *
   * @var \Drupal\commerce_elavon\Converge\ApiClient
   */
  protected $apiClient;

  /**
   * Site settings, used for credential overrides.
   *
   * @var \Drupal\Core\Site\Settings
   */
  protected $settings;

  /**
   * AVS code labels.
   *
   * @var \Drupal\commerce_elavon\AvsResponses
   */
  protected $avsResponses;

  /**
   * Transliteration service.
   *
   * @var \Drupal\Component\Transliteration\TransliterationInterface
   */
  protected $transliteration;

  /**
   * Logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $convergeLogger;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'merchant_id' => '',
      'user_id' => '',
      'pin' => '',
      'account_id_field' => 'ssl_merchant_id',
      'multicurrency' => FALSE,
      'minimum_amount' => '0.01',
      'avs_policy' => AvsCvvPolicy::NO_MATCH,
      'cvv_policy' => AvsCvvPolicy::NO_MATCH,
    ] + parent::defaultConfiguration();
  }

  /**
   * Builds the shared credential and fraud-policy form.
   */
  protected function buildConvergeConfigurationForm(array $form, FormStateInterface $form_state): array {
    $overridden = $this->overriddenCredentialKeys();

    $form['merchant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Account ID'),
      '#description' => $this->t('Converge account ID. The current API name is ssl_account_id. ssl_merchant_id is the previous name and remains the default wire field.'),
      '#default_value' => $this->configuration['merchant_id'],
      '#required' => empty($overridden['merchant_id']),
    ];
    $form['user_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User ID'),
      '#default_value' => $this->configuration['user_id'],
      '#required' => empty($overridden['user_id']),
    ];
    $form['pin'] = [
      '#type' => 'textfield',
      '#title' => $this->t('PIN'),
      '#description' => empty($overridden['pin'])
        ? $this->t('Converge terminal PIN. The saved PIN is shown so it can be checked.')
        : $this->t('The PIN used for transactions comes from settings.php and is not shown here.'),
      '#default_value' => empty($overridden['pin']) ? $this->configuration['pin'] : '',
      '#required' => $this->configuration['pin'] === '' && empty($overridden['pin']),
    ];
    if ($overridden) {
      $form['credentials_override'] = [
        '#type' => 'item',
        '#title' => $this->t('settings.php override'),
        '#markup' => $this->t('API calls use commerce_elavon_credentials from settings.php for: @keys. Those values are not shown here.', [
          '@keys' => implode(', ', array_keys($overridden)),
        ]),
      ];
    }

    $form['account_id_field'] = [
      '#type' => 'radios',
      '#title' => $this->t('Account ID field sent to Converge'),
      '#options' => [
        'ssl_merchant_id' => $this->t('ssl_merchant_id (previous name, same as Commerce Elavon 8.x-2.4)'),
        'ssl_account_id' => $this->t('ssl_account_id (current Converge name)'),
      ],
      '#default_value' => ($this->configuration['account_id_field'] ?? '') === 'ssl_account_id' ? 'ssl_account_id' : 'ssl_merchant_id',
    ];
    $form['multicurrency'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send the order currency (ssl_transaction_currency)'),
      '#description' => $this->t('Enable only when the terminal is boarded for multi-currency. Leave off for a USD-only terminal.'),
      '#default_value' => !empty($this->configuration['multicurrency']),
    ];
    $form['minimum_amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum charge'),
      '#description' => $this->t('Charges below this amount are refused before Converge is contacted. Zero and negative amounts are always refused.'),
      '#field_suffix' => $this->t('in the payment currency'),
      '#default_value' => $this->configuration['minimum_amount'] ?? '0.01',
      '#min' => 0,
      '#step' => '0.01',
      '#required' => TRUE,
    ];
    $policy_options = [
      AvsCvvPolicy::NO_MATCH => $this->t('Decline on no-match (recommended)'),
      AvsCvvPolicy::STRICT => $this->t('Decline unless fully matched'),
      AvsCvvPolicy::DISABLED => $this->t('Record the code and continue (8.x-2.4 behavior)'),
    ];
    $form['avs_policy'] = [
      '#type' => 'radios',
      '#title' => $this->t('AVS policy'),
      '#description' => $this->t('No-match declines only code N. Strict declines every code except Y, X, D, M, and F. An empty code is not a decline. If an approved sale fails this policy, the module returns it. An approved authorization is voided.'),
      '#options' => $policy_options,
      '#default_value' => AvsCvvPolicy::normalizeMode((string) ($this->configuration['avs_policy'] ?? AvsCvvPolicy::NO_MATCH)),
    ];
    $form['cvv_policy'] = [
      '#type' => 'radios',
      '#title' => $this->t('CVV policy'),
      '#description' => $this->t('No-match declines only code N. Strict declines every code except M. Empty CVV responses are not declined, so a saved card can be charged again without asking for the security code.'),
      '#options' => $policy_options,
      '#default_value' => AvsCvvPolicy::normalizeMode((string) ($this->configuration['cvv_policy'] ?? AvsCvvPolicy::NO_MATCH)),
    ];
    return $form;
  }

  /**
   * Stores the shared configuration. An empty PIN keeps the previous PIN.
   */
  protected function submitConvergeConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValue($form['#parents']);
    $this->configuration['merchant_id'] = trim((string) ($values['merchant_id'] ?? ''));
    $this->configuration['user_id'] = trim((string) ($values['user_id'] ?? ''));
    $submitted_pin = trim((string) ($values['pin'] ?? ''));
    if ($submitted_pin !== '') {
      $this->configuration['pin'] = $submitted_pin;
    }
    $field = $values['account_id_field'] ?? 'ssl_merchant_id';
    $this->configuration['account_id_field'] = $field === 'ssl_account_id' ? 'ssl_account_id' : 'ssl_merchant_id';
    $this->configuration['multicurrency'] = !empty($values['multicurrency']);
    $minimum = trim((string) ($values['minimum_amount'] ?? '0.01'));
    if (!is_numeric($minimum) || (float) $minimum < 0) {
      $form_state->setError($form['minimum_amount'], $this->t('Enter a minimum charge of zero or greater.'));
      return;
    }
    $this->configuration['minimum_amount'] = Amounts::format($minimum);
    $this->configuration['avs_policy'] = AvsCvvPolicy::normalizeMode((string) ($values['avs_policy'] ?? AvsCvvPolicy::NO_MATCH));
    $this->configuration['cvv_policy'] = AvsCvvPolicy::normalizeMode((string) ($values['cvv_policy'] ?? AvsCvvPolicy::NO_MATCH));
  }

  /**
   * Refuses zero, negative, and below-minimum amounts before any API call.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   */
  protected function assertChargeableAmount(Price $amount): void {
    $number = $amount->getNumber();
    if (!is_numeric($number) || Amounts::isZeroOrNegative($number)) {
      throw new PaymentGatewayException('The payment amount must be greater than zero.');
    }
    $minimum = (string) ($this->configuration['minimum_amount'] ?? '0.01');
    if (!is_numeric($minimum) || (float) $minimum < 0) {
      $minimum = '0.01';
    }
    if (Amounts::belowMinimum($number, $minimum)) {
      throw new PaymentGatewayException(sprintf('The payment amount is below the configured minimum of %s.', Amounts::format($minimum)));
    }
  }

  /**
   * Resolved credentials for the next API call.
   *
   * @return array
   *   merchant_id, user_id, pin, account_id_field.
   */
  protected function getConvergeCredentials(): array {
    $settings = $this->settings instanceof Settings ? $this->settings->get('commerce_elavon_credentials', []) : [];
    if (!is_array($settings)) {
      $settings = [];
    }
    return Credentials::resolve($this->configuration, $settings, $this->getConvergeGatewayId());
  }

  /**
   * Credential keys currently supplied by settings.php.
   */
  protected function overriddenCredentialKeys(): array {
    $settings = $this->settings instanceof Settings ? $this->settings->get('commerce_elavon_credentials', []) : [];
    if (!is_array($settings)) {
      return [];
    }
    return Credentials::overriddenKeys($settings, $this->getConvergeGatewayId());
  }

  /**
   * The parent payment gateway ID, when the plugin is attached to one.
   */
  protected function getConvergeGatewayId(): ?string {
    if (!empty($this->parentEntity)) {
      return (string) $this->parentEntity->id();
    }
    return NULL;
  }

  /**
   * Posts a transaction and returns an approved result, or throws.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   */
  protected function postApproved(array $fields): TransactionResult {
    $result = $this->apiClient->postTransaction($this->getMode(), $this->getConvergeCredentials(), $fields);
    if (!$result->isApproved()) {
      $message = $result->message() !== '' ? $result->message() : 'The payment was declined.';
      throw new HardDeclineException(Redactor::redact($message));
    }
    return $result;
  }

  /**
   * Applies the AVS/CVV policy and reverses the gateway transaction on failure.
   *
   * @throws \Drupal\commerce_payment\Exception\HardDeclineException
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   When the gateway approved the payment and the automatic reversal failed.
   */
  protected function applyAvsCvvPolicy(TransactionResult $result, ?Price $amount, bool $captured): void {
    $reason = AvsCvvPolicy::declineReason(
      $result->avs(),
      $result->cvv(),
      (string) ($this->configuration['avs_policy'] ?? AvsCvvPolicy::NO_MATCH),
      (string) ($this->configuration['cvv_policy'] ?? AvsCvvPolicy::NO_MATCH),
    );
    if ($reason === NULL) {
      return;
    }
    if ($result->txnId() !== '') {
      try {
        if ($captured && $amount instanceof Price) {
          $this->reverseTransaction($result->txnId(), $amount, TRUE);
        }
        else {
          $this->reverseTransaction($result->txnId(), NULL, FALSE);
        }
      }
      catch (PaymentGatewayException $e) {
        $this->convergeLogger->critical('Converge approved transaction @txn but the AVS/CVV reversal failed. Void or refund it in Converge. @message', [
          '@txn' => $result->txnId(),
          '@message' => Redactor::redact($e->getMessage()),
        ]);
        throw new PaymentGatewayException(sprintf('The payment was approved by the gateway but failed the local fraud check, and the automatic reversal failed. Transaction %s must be voided or refunded in Converge.', $result->txnId()), 0, $e);
      }
    }
    throw new HardDeclineException($reason);
  }

  /**
   * Voids an authorization or returns a captured amount.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   */
  protected function reverseTransaction(string $txn_id, ?Price $amount, bool $captured): void {
    $fields = [
      'ssl_transaction_type' => $captured ? 'ccreturn' : 'ccdelete',
      'ssl_txn_id' => $txn_id,
    ];
    if ($captured) {
      if (!$amount instanceof Price) {
        throw new PaymentGatewayException('A captured payment cannot be reversed without an amount.');
      }
      $fields['ssl_amount'] = Amounts::format($amount->getNumber());
    }
    $result = $this->apiClient->postTransaction($this->getMode(), $this->getConvergeCredentials(), $fields);
    if (!$result->isApproved()) {
      throw new PaymentGatewayException(Redactor::redact($result->message() !== '' ? $result->message() : 'The reversal was declined.'));
    }
  }

  /**
   * Records AVS on the payment when Converge sent a code.
   */
  protected function recordAvs(PaymentInterface $payment, TransactionResult $result): void {
    $code = $result->avs();
    if ($code === NULL || $code === '') {
      return;
    }
    $payment->setAvsResponseCode($code);
    if ($this->avsResponses instanceof AvsResponses && $this->avsResponses->hasLabel($code)) {
      $payment->setAvsResponseCodeLabel($this->avsResponses->getLabel($code));
    }
  }

  /**
   * Captures an authorization for the given amount.
   */
  protected function captureConvergePayment(PaymentInterface $payment, Price $amount): void {
    $this->assertPaymentState($payment, ['authorization']);
    $this->assertChargeableAmount($amount);
    $result = $this->postApproved([
      'ssl_transaction_type' => 'cccomplete',
      'ssl_amount' => Amounts::format($amount->getNumber()),
      'ssl_txn_id' => (string) $payment->getRemoteId(),
    ]);
    $this->assertResponseAmount($result, $amount);
    $payment->setState('completed');
    $payment->setAmount($amount);
    $payment->save();
  }

  /**
   * Voids an authorization at Converge.
   */
  protected function voidConvergePayment(PaymentInterface $payment): void {
    $this->assertPaymentState($payment, ['authorization']);
    $this->postApproved([
      'ssl_transaction_type' => 'ccdelete',
      'ssl_txn_id' => (string) $payment->getRemoteId(),
    ]);
    $payment->setState('authorization_voided');
    $payment->save();
  }

  /**
   * Refunds a captured payment at Converge, then updates the local payment.
   */
  protected function refundConvergePayment(PaymentInterface $payment, ?Price $amount): void {
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    $amount = $amount ?: $payment->getAmount();
    $this->assertRefundAmount($payment, $amount);
    $this->assertChargeableAmount($amount);
    $this->postApproved([
      'ssl_transaction_type' => 'ccreturn',
      'ssl_amount' => Amounts::format($amount->getNumber()),
      'ssl_txn_id' => (string) $payment->getRemoteId(),
    ]);

    $currency = $amount->getCurrencyCode();
    $old_refunded = $payment->getRefundedAmount() ?: new Price('0', $currency);
    $new_refunded = $old_refunded->add($amount);
    if ($new_refunded->lessThan($payment->getAmount())) {
      $payment->setState('partially_refunded');
    }
    else {
      $payment->setState('refunded');
    }
    $payment->setRefundedAmount($new_refunded);
    $payment->save();
  }

  /**
   * Checks a returned amount, and currency when multi-currency is enabled.
   *
   * A missing amount is ignored because some follow-up calls omit it. A
   * present amount that does not match is a failure.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   */
  protected function assertResponseAmount(TransactionResult $result, Price $expected): void {
    if ($result->amount() !== NULL && !Amounts::equals($expected->getNumber(), $result->amount())) {
      throw new PaymentGatewayException('The gateway amount did not match the payment amount.');
    }
    if (!empty($this->configuration['multicurrency']) && $result->currency() !== NULL) {
      if (strtoupper($result->currency()) !== strtoupper($expected->getCurrencyCode())) {
        throw new PaymentGatewayException('The gateway currency did not match the payment currency.');
      }
    }
  }

  /**
   * Adds the currency field when the terminal is multi-currency.
   */
  protected function addCurrencyField(array $fields, Price $amount): array {
    if (!empty($this->configuration['multicurrency'])) {
      $fields['ssl_transaction_currency'] = $amount->getCurrencyCode();
    }
    return $fields;
  }

  /**
   * The hosted process.do URL for this gateway mode.
   */
  public function getHostedProcessUrl(): string {
    return Endpoints::hostedProcess($this->getMode());
  }
  
    /**
   * Drops the HTTP client before Drupal caches the checkout form.
   *
   * The client contains closures, which PHP cannot serialize. Checkout fails
   * on the order information step if this plugin is stored in the form cache.
   */
  public function __sleep(): array {
    $this->apiClient = NULL;
    $this->convergeLogger = NULL;
    $this->avsResponses = NULL;
    $this->settings = NULL;
    $this->transliteration = NULL;
    if (property_exists($this, 'moduleHandler')) {
      $this->moduleHandler = NULL;
    }
    return parent::__sleep();
  }

  /**
   * Restores the clients removed by __sleep().
   */
  public function __wakeup(): void {
    parent::__wakeup();
    $container = \Drupal::getContainer();
    $this->apiClient = new ApiClient(
      $container->get('http_client'),
      $container->get('logger.factory')->get('commerce_elavon'),
    );
    $this->convergeLogger = $container->get('logger.factory')->get('commerce_elavon');
    $this->settings = $container->get('settings');
    $this->avsResponses = AvsResponses::create($container);
    $this->transliteration = $container->get('transliteration');
    if (property_exists($this, 'moduleHandler')) {
      $this->moduleHandler = $container->get('module_handler');
    }
  }

  /**
   * Transliterates a billing value for Converge, which rejects some Unicode.
   */
  protected function transliterateValue(string $value): string {
    if (!$this->transliteration instanceof TransliterationInterface) {
      return $value;
    }
    return $this->transliteration->transliterate($value);
  }

}
