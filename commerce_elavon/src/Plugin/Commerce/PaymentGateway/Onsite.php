<?php

namespace Drupal\commerce_elavon\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_elavon\AvsResponses;
use Drupal\commerce_elavon\Converge\Amounts;
use Drupal\commerce_elavon\Converge\ApiClient;
use Drupal\commerce_elavon\Converge\Redactor;
use Drupal\commerce_payment\CreditCard;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\commerce_price\Price;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_payment\Attribute\CommercePaymentGateway;
use Drupal\commerce_elavon\PluginForm\Onsite\PaymentMethodAddForm;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * On-site Converge gateway.
 *
 * The card number reaches this server once, is sent to Converge, and is not
 * stored. The payment method keeps a Converge token. That token is what
 * Commerce Recurring charges for a later membership renewal or donation.
 */
#[CommercePaymentGateway(
  id: 'elavon_onsite',
  label: new TranslatableMarkup('Elavon (On-site)'),
  display_label: new TranslatableMarkup('Elavon'),
  forms: [
    'add-payment-method' => PaymentMethodAddForm::class,
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
    'unionpay',
  ],
)]
class Onsite extends OnsitePaymentGatewayBase implements OnsiteInterface {

  use ConvergeGatewayTrait;

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
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    return $this->buildConvergeConfigurationForm($form, $form_state);
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
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
  $this->assertPaymentState($payment, ['new']);
  $payment_method = $payment->getPaymentMethod();
  $this->assertPaymentMethod($payment_method);
  $amount = $payment->getAmount();
  $this->assertChargeableAmount($amount);

  $token = (string) $payment_method->getRemoteId();
  $order = $payment->getOrder();
  $prepaid = $order ? $order->getData('commerce_elavon_sale') : NULL;
  if (is_array($prepaid)
    && isset($prepaid['txn_id'], $prepaid['amount'], $prepaid['currency'])
    && $prepaid['currency'] === $amount->getCurrencyCode()
    && Amounts::equals((string) $prepaid['amount'], $amount->getNumber())) {
    $payment->setState($capture ? 'completed' : 'authorization');
    $payment->setRemoteId((string) $prepaid['txn_id']);
    $payment->setRemoteState('0');
    $payment->save();
    $order->unsetData('commerce_elavon_sale');
    $order->save();
    return;
  }
  if ($token === '' || str_starts_with($token, 'txn:')) {
    throw new PaymentGatewayException('The saved card has no Converge token.');
  }

  $fields = [
    'ssl_transaction_type' => $capture ? 'ccsale' : 'ccauthonly',
    'ssl_amount' => Amounts::format($amount->getNumber()),
    'ssl_token' => $token,
  ];
  $order = $payment->getOrder();
  if ($order) {
    $fields['ssl_invoice_number'] = $order->getOrderNumber() ?: (string) $order->id();
  }
  $created = (int) $payment_method->getCreatedTime();
  if ($created > 0 && ($this->time->getRequestTime() - $created) > 86400) {
    // Annual renewals are merchant-initiated card-on-file charges.
    $fields['ssl_merchant_initiated_unscheduled'] = 'Y';
  }
  $fields = $this->addCurrencyField($fields, $amount);

  $result = $this->postApproved($fields);
  if ($result->txnId() === '') {
    throw new PaymentGatewayException('The gateway approved the payment but did not return a transaction ID.');
  }
  $this->assertResponseAmount($result, $amount);
  $this->applyAvsCvvPolicy($result, $amount, (bool) $capture);
  $this->recordAvs($payment, $result);
  $payment->setState($capture ? 'completed' : 'authorization');
  $payment->setRemoteId($result->txnId());
  $payment->setRemoteState($result->resultCode());
  $payment->save();
}

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, ?Price $amount = NULL) {
    $this->captureConvergePayment($payment, $amount ?: $payment->getAmount());
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    $this->voidConvergePayment($payment);
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, ?Price $amount = NULL) {
    $this->refundConvergePayment($payment, $amount);
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
  foreach (['type', 'number', 'expiration'] as $required_key) {
    if (empty($payment_details[$required_key])) {
      throw new \InvalidArgumentException(sprintf('$payment_details must contain the %s key.', $required_key));
    }
  }
  $profile = $payment_method->getBillingProfile();
  if (!$profile || $profile->get('address')->isEmpty()) {
    throw new PaymentGatewayException('A billing address is required.');
  }
  $address = $profile->get('address')->first();

  $month = str_pad((string) $payment_details['expiration']['month'], 2, '0', STR_PAD_LEFT);
  $year = (string) $payment_details['expiration']['year'];
  $exp = $month . substr($year, -2);

  $avs_address = trim($this->transliterateValue((string) $address->getAddressLine1() . ' ' . (string) $address->getAddressLine2()));
  $first_name = $this->transliterateValue((string) $address->getGivenName());
  $last_name = $this->transliterateValue((string) $address->getFamilyName());
  $avs_zip = preg_replace('/[^[:alnum:]]/', '', $this->transliterateValue((string) $address->getPostalCode()));

  $order = \Drupal::routeMatch()->getParameter('commerce_order');
  if ($this->getMode() === 'test' && $order instanceof OrderInterface) {
    $amount = $order->getBalance();
    if ($amount === NULL || !$amount->isPositive()) {
      $amount = $order->getTotalPrice();
    }
    $this->assertChargeableAmount($amount);

    $fields = [
      'ssl_transaction_type' => 'ccsale',
      'ssl_amount' => Amounts::format($amount->getNumber()),
      'ssl_card_number' => $payment_details['number'],
      'ssl_exp_date' => $exp,
      'ssl_add_token' => 'Y',
      'ssl_invoice_number' => (string) $order->id(),
      'ssl_avs_zip' => substr((string) $avs_zip, 0, 9),
      'ssl_avs_address' => substr($avs_address, 0, 30),
      'ssl_first_name' => substr($first_name, 0, 20),
      'ssl_last_name' => substr($last_name, 0, 30),
    ];
    if (!empty($payment_details['security_code'])) {
      $fields['ssl_cvv2cvc2_indicator'] = '1';
      $fields['ssl_cvv2cvc2'] = $payment_details['security_code'];
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

    $result = $this->postApproved($fields);
    if ($result->txnId() === '') {
      throw new PaymentGatewayException('The gateway approved the payment but did not return a transaction ID.');
    }
    $this->assertResponseAmount($result, $amount);
    $this->applyAvsCvvPolicy($result, $amount, TRUE);

    $token = $result->token();
    $order->setData('commerce_elavon_sale', [
      'txn_id' => $result->txnId(),
      'amount' => $amount->getNumber(),
      'currency' => $amount->getCurrencyCode(),
      'token' => $token ?? '',
    ]);
    $order->save();

    $payment_method->card_type = $payment_details['type'];
    $payment_method->card_number = substr($payment_details['number'], -4);
    $payment_method->card_exp_month = $payment_details['expiration']['month'];
    $payment_method->card_exp_year = $payment_details['expiration']['year'];
    $payment_method->setRemoteId($token !== NULL ? $token : 'txn:' . $result->txnId());
    $payment_method->setExpiresTime(CreditCard::calculateExpirationTimestamp($payment_details['expiration']['month'], $payment_details['expiration']['year']));
    $payment_method->save();
    return;
  }

  $fields = [
    'ssl_transaction_type' => 'ccgettoken',
    'ssl_show_form' => 'false',
    'ssl_card_number' => $payment_details['number'],
    'ssl_exp_date' => $exp,
    'ssl_verify' => 'Y',
    'ssl_avs_zip' => substr((string) $avs_zip, 0, 9),
    'ssl_avs_address' => substr($avs_address, 0, 30),
    'ssl_add_token' => 'Y',
    'ssl_first_name' => substr($first_name, 0, 20),
    'ssl_last_name' => substr($last_name, 0, 30),
  ];
  if (!empty($payment_details['security_code'])) {
    $fields['ssl_cvv2cvc2_indicator'] = '1';
    $fields['ssl_cvv2cvc2'] = $payment_details['security_code'];
  }
  else {
    $fields['ssl_cvv2cvc2_indicator'] = '0';
  }

  $result = $this->apiClient->postTransaction($this->getMode(), $this->getConvergeCredentials(), $fields);
  $token = $result->token();
  if ($token === NULL || strtoupper((string) $result->tokenResponse()) !== 'SUCCESS') {
    $message = $result->message() !== '' ? $result->message() : 'The card could not be saved at the payment gateway.';
    throw new PaymentGatewayException(Redactor::redact($message));
  }

  $payment_method->card_type = $payment_details['type'];
  $payment_method->card_number = substr($payment_details['number'], -4);
  $payment_method->card_exp_month = $payment_details['expiration']['month'];
  $payment_method->card_exp_year = $payment_details['expiration']['year'];
  $payment_method->setRemoteId($token);
  $payment_method->setExpiresTime(CreditCard::calculateExpirationTimestamp($payment_details['expiration']['month'], $payment_details['expiration']['year']));
  $payment_method->save();
}

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    // The token is removed locally. Converge has no call this module depends
    // on to delete a token, and a failed remote delete must not strand the
    // saved card in Drupal.
    $payment_method->delete();
  }

}
