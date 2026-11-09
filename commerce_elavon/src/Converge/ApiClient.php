<?php

namespace Drupal\commerce_elavon\Converge;

use Drupal\commerce_payment\Exception\PaymentGatewayException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Server-side Converge HTTP client.
 *
 * Redirects are not followed. Request bodies are never logged.
 */
final class ApiClient {

  /**
   * Constructs a client.
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Posts an XML transaction and returns the parsed result.
   *
   * A non-approved result is returned, not thrown, so callers can decide
   * whether to void, refund, or record a decline. Transport and parse
   * failures throw.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   On HTTP or parse failure.
   */
  public function postTransaction(string $mode, array $credentials, array $fields): TransactionResult {
    $type = isset($fields['ssl_transaction_type']) ? (string) $fields['ssl_transaction_type'] : 'unknown';
    try {
      $xml = XmlRequest::build($credentials, $fields);
    }
    catch (\InvalidArgumentException $e) {
      throw new PaymentGatewayException($e->getMessage(), 0, $e);
    }

    try {
      $response = $this->httpClient->request('POST', Endpoints::xml($mode), [
        'form_params' => ['xmldata' => $xml],
        'timeout' => 90,
        'connect_timeout' => 15,
        'http_errors' => TRUE,
        'allow_redirects' => FALSE,
        'headers' => [
          'Accept' => 'text/xml, application/xml',
        ],
      ]);
      $body = (string) $response->getBody();
    }
    catch (GuzzleException $e) {
      $this->logger->error('Converge @type request failed: @message', [
        '@type' => $type,
        '@message' => Redactor::redact($e->getMessage()),
      ]);
      throw new PaymentGatewayException('The payment gateway could not be reached.', 0, $e);
    }

    try {
      $expected_txn_id = isset($fields['ssl_txn_id']) ? (string) $fields['ssl_txn_id'] : NULL;
      $result = TransactionResult::fromXml($body, $expected_txn_id);
    }
    catch (\UnexpectedValueException $e) {
      $this->logger->error('Converge @type response could not be read: @message', [
        '@type' => $type,
        '@message' => Redactor::redact($e->getMessage()),
      ]);
      throw new PaymentGatewayException('The payment gateway returned an unreadable response.', 0, $e);
    }

    $this->logger->info('Converge @type response result @code (@message), transaction @txn.', [
      '@type' => $type,
      '@code' => $result->resultCode() !== '' ? $result->resultCode() : ($result->errorCode() ?? 'none'),
      '@message' => Redactor::redact($result->message()),
      '@txn' => $result->txnId() !== '' ? $result->txnId() : 'none',
    ]);
    return $result;
  }

  /**
   * Requests a hosted-payment session token.
   *
   * The token is the only value that may be placed in the browser. A failure
   * throws; the credentialed request is not retried from the client.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   */
  public function requestSessionToken(string $mode, array $credentials, array $fields): string {
    $merchant_id = trim((string) ($credentials['merchant_id'] ?? ''));
    $user_id = trim((string) ($credentials['user_id'] ?? ''));
    $pin = trim((string) ($credentials['pin'] ?? ''));
    if ($merchant_id === '' || $user_id === '' || $pin === '') {
      throw new PaymentGatewayException('Elavon credentials are incomplete.');
    }
    $id_field = ($credentials['account_id_field'] ?? '') === 'ssl_account_id' ? 'ssl_account_id' : 'ssl_merchant_id';
    $payload = [
      $id_field => $merchant_id,
      'ssl_user_id' => $user_id,
      'ssl_pin' => $pin,
    ];
    foreach ($fields as $key => $value) {
      if (!is_string($key) || !preg_match('/^ssl_[a-z0-9_]+$/', $key)) {
        continue;
      }
      if (in_array($key, ['ssl_pin', 'ssl_user_id', 'ssl_merchant_id', 'ssl_account_id'], TRUE)) {
        continue;
      }
      if ($value === NULL || is_array($value) || is_object($value)) {
        continue;
      }
      $payload[$key] = (string) $value;
    }

    try {
      $response = $this->httpClient->request('POST', Endpoints::sessionToken($mode), [
        'form_params' => $payload,
        'timeout' => 90,
        'connect_timeout' => 15,
        'http_errors' => TRUE,
        'allow_redirects' => FALSE,
        'headers' => [
          'Accept' => 'text/plain',
        ],
      ]);
      $body = trim((string) $response->getBody());
    }
    catch (GuzzleException $e) {
      $this->logger->error('Converge session token request failed: @message', [
        '@message' => Redactor::redact($e->getMessage()),
      ]);
      throw new PaymentGatewayException('The payment gateway could not start the hosted payment.', 0, $e);
    }

    if (!$this->isSessionToken($body)) {
      $this->logger->error('Converge session token was refused: @message', [
        '@message' => Redactor::redact(substr($body, 0, 300)),
      ]);
      throw new PaymentGatewayException('The payment gateway refused to start the hosted payment. Confirm the terminal allows hosted payments and that this server IP is allow-listed.');
    }
    return $body;
  }

  /**
   * TRUE when the body looks like a single-use session token.
   *
   * Converge documents the token as alphanumeric. Base64 characters are also
   * accepted. XML, HTML, and error text are not.
   */
  private function isSessionToken(string $body): bool {
    if ($body === '' || strlen($body) < 8 || strlen($body) > 256) {
      return FALSE;
    }
    if (preg_match('/[<>\s]/', $body)) {
      return FALSE;
    }
    if (preg_match('/error/i', $body)) {
      return FALSE;
    }
    return (bool) preg_match('/^[A-Za-z0-9_+=\/.-]+$/', $body);
  }

}
