<?php

/**
 * @file
 * Runs the Converge unit checks without a Drupal bootstrap.
 *
 * Usage: php tests/run-unit.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
  $prefix = 'Drupal\\commerce_elavon\\';
  if (!str_starts_with($class, $prefix)) {
    return;
  }
  $relative = substr($class, strlen($prefix));
  $path = $root . '/src/' . str_replace('\\', '/', $relative) . '.php';
  if (is_file($path)) {
    require_once $path;
  }
});

use Drupal\commerce_elavon\Converge\Amounts;
use Drupal\commerce_elavon\Converge\AvsCvvPolicy;
use Drupal\commerce_elavon\Converge\BrowserPayload;
use Drupal\commerce_elavon\Converge\Credentials;
use Drupal\commerce_elavon\Converge\Redactor;
use Drupal\commerce_elavon\Converge\TransactionResult;
use Drupal\commerce_elavon\Converge\XmlRequest;

$failures = 0;

/**
 * Records a failed assertion.
 */
function check(bool $condition, string $message): void {
  global $failures;
  if ($condition) {
    fwrite(STDOUT, "ok  $message\n");
    return;
  }
  $failures++;
  fwrite(STDERR, "FAIL $message\n");
}

$approved = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<txn>
  <ssl_result>0</ssl_result>
  <ssl_result_message>APPROVAL</ssl_result_message>
  <ssl_txn_id>ABC-123</ssl_txn_id>
  <ssl_amount>25.00</ssl_amount>
  <ssl_transaction_currency>USD</ssl_transaction_currency>
  <ssl_invoice_number>42</ssl_invoice_number>
  <ssl_avs_response>Y</ssl_avs_response>
  <ssl_cvv2_response>M</ssl_cvv2_response>
  <ssl_token>tok_1</ssl_token>
  <ssl_token_response>SUCCESS</ssl_token_response>
  <ssl_transaction_type>SALE</ssl_transaction_type>
  <ssl_card_number>40**********0002</ssl_card_number>
</txn>
XML;

$result = TransactionResult::fromXml($approved);
check($result->isApproved(), 'approval XML is approved');
check($result->txnId() === 'ABC-123', 'transaction id is read');
check($result->amount() === '25.00', 'amount is read');
check($result->invoiceNumber() === '42', 'invoice is read');
check($result->avs() === 'Y' && $result->cvv() === 'M', 'AVS and CVV are read');
check($result->token() === 'tok_1' && $result->tokenResponse() === 'SUCCESS', 'token is read');

$error = '<txn><errorCode>4025</errorCode><errorName>Invalid Credentials</errorName><errorMessage>Invalid credentials</errorMessage></txn>';
$error_result = TransactionResult::fromXml($error);
check(!$error_result->isApproved(), 'errorCode XML is not approved');
check($error_result->errorCode() === '4025', 'error code is read');

$declined = '<txn><ssl_result>1</ssl_result><ssl_result_message>APPROVAL</ssl_result_message></txn>';
check(!TransactionResult::fromXml($declined)->isApproved(), 'APPROVAL with a non-zero result is not approved');

$message_declined = '<txn><ssl_result>0</ssl_result><ssl_result_message>DECLINED</ssl_result_message></txn>';
check(!TransactionResult::fromXml($message_declined)->isApproved(), 'result 0 with DECLINED is not approved');

$empty_message = '<txn><ssl_result>0</ssl_result></txn>';
check(!TransactionResult::fromXml($empty_message)->isApproved(), 'result 0 without a success message is not approved');

$threw = FALSE;
try {
  TransactionResult::fromXml('<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><txn><ssl_result>&xxe;</ssl_result></txn>');
}
catch (UnexpectedValueException $e) {
  $threw = TRUE;
}
check($threw, 'DOCTYPE responses are rejected');

$threw = FALSE;
try {
  TransactionResult::fromXml('not xml');
}
catch (UnexpectedValueException $e) {
  $threw = TRUE;
}
check($threw, 'non-XML responses are rejected');

$threw = FALSE;
try {
  TransactionResult::fromXml('');
}
catch (UnexpectedValueException $e) {
  $threw = TRUE;
}
check($threw, 'empty responses are rejected');

$list = <<<'XML'
<txnlist>
  <txn><ssl_result>0</ssl_result><ssl_result_message>APPROVAL</ssl_result_message><ssl_txn_id>OTHER</ssl_txn_id><ssl_amount>1.00</ssl_amount></txn>
  <txn><ssl_result>0</ssl_result><ssl_result_message>APPROVAL</ssl_result_message><ssl_txn_id>WANTED</ssl_txn_id><ssl_amount>25.00</ssl_amount></txn>
</txnlist>
XML;
$picked = TransactionResult::fromXml($list, 'WANTED');
check($picked->txnId() === 'WANTED' && $picked->amount() === '25.00', 'txnquery list selects the requested transaction');

check(Amounts::equals('10.00', '10.0'), '10.00 equals 10.0');
check(!Amounts::equals('10.00', '10.01'), '10.00 does not equal 10.01');
check(Amounts::isZeroOrNegative('0') && Amounts::isZeroOrNegative('-1.00'), 'zero and negative amounts are refused');
check(!Amounts::isZeroOrNegative('0.01'), 'one cent is chargeable');
check(Amounts::belowMinimum('0.009', '0.01') && !Amounts::belowMinimum('0.01', '0.01'), 'minimum comparison uses cents');

check(AvsCvvPolicy::declineReason('N', 'M', 'no_match', 'no_match') !== NULL, 'AVS N declines in no_match mode');
check(AvsCvvPolicy::declineReason('Y', 'N', 'no_match', 'no_match') !== NULL, 'CVV N declines in no_match mode');
check(AvsCvvPolicy::declineReason('A', 'P', 'no_match', 'no_match') === NULL, 'partial AVS and unprocessed CVV are allowed by default');
check(AvsCvvPolicy::declineReason('U', NULL, 'no_match', 'no_match') === NULL, 'unavailable AVS and empty CVV are allowed by default');
check(AvsCvvPolicy::declineReason('A', 'M', 'strict', 'strict') !== NULL, 'strict AVS declines a partial match');
check(AvsCvvPolicy::declineReason('Y', 'P', 'strict', 'strict') !== NULL, 'strict CVV declines a non-match');
check(AvsCvvPolicy::declineReason('N', 'N', 'disabled', 'disabled') === NULL, 'disabled mode does not decline');
check(AvsCvvPolicy::declineReason('Y', NULL, 'strict', 'strict') === NULL, 'strict CVV ignores an empty code');

$xml = XmlRequest::build([
  'merchant_id' => 'ACCT',
  'user_id' => 'USER',
  'pin' => 'SECRET-PIN',
  'account_id_field' => 'ssl_merchant_id',
], [
  'ssl_transaction_type' => 'ccsale',
  'ssl_amount' => '10.00',
  'ssl_pin' => 'BROWSER-PIN',
  'ssl_card_number' => '4111111111111111',
  'not_ssl' => 'nope',
  'ssl_avs_address' => '1 Main & "Street"',
]);
check(str_contains($xml, '<ssl_pin>SECRET-PIN</ssl_pin>'), 'server PIN is the credential PIN');
check(!str_contains($xml, 'BROWSER-PIN'), 'field list cannot override the PIN');
check(str_contains($xml, '<ssl_merchant_id>ACCT</ssl_merchant_id>'), 'legacy account field is used by default');
check(!str_contains($xml, '<not_ssl>'), 'non-ssl keys are dropped');
check(str_contains($xml, '1 Main & "Street"'), 'address text is escaped');
check(substr_count($xml, '<ssl_pin>') === 1, 'PIN is present once');

$account_xml = XmlRequest::build([
  'merchant_id' => 'ACCT',
  'user_id' => 'USER',
  'pin' => 'SECRET-PIN',
  'account_id_field' => 'ssl_account_id',
], ['ssl_transaction_type' => 'txnquery']);
check(str_contains($account_xml, '<ssl_account_id>ACCT</ssl_account_id>'), 'current account field can be selected');
check(!str_contains($account_xml, 'ssl_merchant_id'), 'legacy field is omitted when the current field is selected');

$browser = BrowserPayload::sanitize([
  'ssl_txn_auth_token' => 'token123',
  'ssl_pin' => 'SECRET-PIN',
  'ssl_user_id' => 'USER',
  'ssl_merchant_id' => 'ACCT',
  'ssl_card_number' => '4111111111111111',
  'ssl_first_name' => 'Ada',
]);
check($browser === ['ssl_txn_auth_token' => 'token123', 'ssl_first_name' => 'Ada'], 'browser payload keeps the token and drops secrets');

$resolved = Credentials::resolve([
  'merchant_id' => 'stored-account',
  'user_id' => 'stored-user',
  'pin' => 'stored-pin',
  'account_id_field' => 'ssl_merchant_id',
], [
  'pin' => 'global-pin',
  'elavon_live' => [
    'merchant_id' => 'live-account',
    'pin' => 'live-pin',
  ],
], 'elavon_live');
check($resolved['merchant_id'] === 'live-account', 'per-gateway account overrides configuration');
check($resolved['user_id'] === 'stored-user', 'missing per-gateway user falls through');
check($resolved['pin'] === 'live-pin', 'per-gateway PIN beats the global PIN');

$global = Credentials::resolve([
  'merchant_id' => 'stored-account',
  'user_id' => 'stored-user',
  'pin' => 'stored-pin',
], [
  'pin' => 'global-pin',
], 'other');
check($global['pin'] === 'global-pin' && $global['merchant_id'] === 'stored-account', 'global PIN override leaves other stored values');
check(isset(Credentials::overriddenKeys(['pin' => 'global-pin'], 'other')['pin']), 'overridden PIN is reported');
check(!isset(Credentials::overriddenKeys(['pin' => ''], NULL)['pin']), 'empty settings do not count as an override');

$redacted = Redactor::redact('ssl_pin=SECRET-PIN card 4111111111111111');
check(!str_contains($redacted, 'SECRET-PIN') && !str_contains($redacted, '4111111111111111'), 'logs redact PIN and PAN');

if ($failures > 0) {
  fwrite(STDERR, "$failures failed\n");
  exit(1);
}
fwrite(STDOUT, "all checks passed\n");
exit(0);
