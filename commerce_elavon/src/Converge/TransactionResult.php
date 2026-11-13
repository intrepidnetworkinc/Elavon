<?php

namespace Drupal\commerce_elavon\Converge;

/**
 * A parsed Converge <txn> response.
 *
 * Approval requires ssl_result 0 and ssl_result_message APPROVAL (or the
 * small set of equivalent success words). Any errorCode, non-XML body, or
 * DOCTYPE is rejected. Callers must not treat a non-empty body as success.
 */
final class TransactionResult {

  /**
   * Messages Converge uses for a successful transaction.
   */
  private const SUCCESS_MESSAGES = ['APPROVAL', 'APPROVED', 'SUCCESS'];

  /**
   * Constructs a result from already-extracted fields.
   */
  private function __construct(
    private readonly bool $approved,
    private readonly string $resultCode,
    private readonly string $message,
    private readonly string $txnId,
    private readonly ?string $amount,
    private readonly ?string $currency,
    private readonly ?string $invoiceNumber,
    private readonly ?string $avs,
    private readonly ?string $cvv,
    private readonly ?string $token,
    private readonly ?string $tokenResponse,
    private readonly ?string $transactionType,
    private readonly ?string $errorCode,
    private readonly ?string $email,
  ) {}

  /**
   * Parses a processxml.do body.
   *
   * @throws \UnexpectedValueException
   *   When the body is empty, too large, not XML, or uses a DOCTYPE.
   */
  public static function fromXml(string $body, ?string $expected_txn_id = NULL): self {
    $body = trim($body);
    if ($body === '') {
      throw new \UnexpectedValueException('Converge returned an empty response.');
    }
    if (strlen($body) > 1000000) {
      throw new \UnexpectedValueException('Converge returned a response that is too large.');
    }
    if (preg_match('/<!DOCTYPE|<!ENTITY/i', $body)) {
      throw new \UnexpectedValueException('Converge response contained a forbidden DOCTYPE.');
    }

    $previous = libxml_use_internal_errors(TRUE);
    $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$xml instanceof \SimpleXMLElement) {
      throw new \UnexpectedValueException('Converge response was not valid XML.');
    }

    $txn = self::selectTxn($xml, $expected_txn_id);

    $result_code = self::text($txn, 'ssl_result');
    $message = self::text($txn, 'ssl_result_message');
    $error_code = self::text($txn, 'errorCode');
    $error_message = self::text($txn, 'errorMessage');
    $approved = $error_code === ''
      && ($result_code === '0' || ($result_code === '' && $message !== ''))
      && in_array(strtoupper($message), self::SUCCESS_MESSAGES, TRUE);

    return new self(
      $approved,
      $result_code,
      $message !== '' ? $message : $error_message,
      self::text($txn, 'ssl_txn_id'),
      self::nullable($txn, 'ssl_amount'),
      self::nullable($txn, 'ssl_transaction_currency'),
      self::nullable($txn, 'ssl_invoice_number'),
      self::nullable($txn, 'ssl_avs_response'),
      self::nullable($txn, 'ssl_cvv2_response'),
      self::nullable($txn, 'ssl_token'),
      self::nullable($txn, 'ssl_token_response'),
      self::nullable($txn, 'ssl_transaction_type'),
      $error_code !== '' ? $error_code : NULL,
      self::nullable($txn, 'ssl_email'),
    );
  }

  /**
   * TRUE only for an approved transaction with no errorCode.
   */
  public function isApproved(): bool {
    return $this->approved;
  }

  /**
   * The ssl_result code, or an empty string.
   */
  public function resultCode(): string {
    return $this->resultCode;
  }

  /**
   * A short gateway message safe to log after redaction.
   */
  public function message(): string {
    return $this->message;
  }

  /**
   * The Converge transaction ID, or an empty string.
   */
  public function txnId(): string {
    return $this->txnId;
  }

  /**
   * The ssl_amount value, when present.
   */
  public function amount(): ?string {
    return $this->amount;
  }

  /**
   * The ssl_transaction_currency value, when present.
   */
  public function currency(): ?string {
    return $this->currency;
  }

  /**
   * The ssl_invoice_number value, when present.
   */
  public function invoiceNumber(): ?string {
    return $this->invoiceNumber;
  }

  /**
   * The AVS code, when present.
   */
  public function avs(): ?string {
    return $this->avs;
  }

  /**
   * The CVV code, when present.
   */
  public function cvv(): ?string {
    return $this->cvv;
  }

  /**
   * The stored-card token, when present.
   */
  public function token(): ?string {
    return $this->token;
  }

  /**
   * The ssl_token_response value, when present.
   */
  public function tokenResponse(): ?string {
    return $this->tokenResponse;
  }

  /**
   * The ssl_transaction_type value, when present.
   */
  public function transactionType(): ?string {
    return $this->transactionType;
  }

  /**
   * The ssl_email value, when present.
   */
  public function email(): ?string {
    return $this->email;
  }

  /**
   * The errorCode, when present.
   */
  public function errorCode(): ?string {
    return $this->errorCode;
  }

  /**
   * Picks the txn element, preferring the requested transaction ID.
   */
  private static function selectTxn(\SimpleXMLElement $xml, ?string $expected_txn_id): \SimpleXMLElement {
    $matches = $xml->xpath('//txn');
    if (!is_array($matches) || $matches === []) {
      if (strcasecmp($xml->getName(), 'txn') === 0) {
        return $xml;
      }
      throw new \UnexpectedValueException('Converge response did not contain a txn element.');
    }
    if ($expected_txn_id !== NULL && $expected_txn_id !== '') {
      foreach ($matches as $candidate) {
        if ($candidate instanceof \SimpleXMLElement && self::text($candidate, 'ssl_txn_id') === $expected_txn_id) {
          return $candidate;
        }
      }
    }
    $first = $matches[0];
    if (!$first instanceof \SimpleXMLElement) {
      throw new \UnexpectedValueException('Converge response did not contain a txn element.');
    }
    return $first;
  }

  /**
   * Reads a child element as text.
   */
  private static function text(\SimpleXMLElement $txn, string $name): string {
    if (!isset($txn->{$name})) {
      return '';
    }
    return trim((string) $txn->{$name});
  }

  /**
   * Reads a child element, keeping NULL when it was omitted.
   */
  private static function nullable(\SimpleXMLElement $txn, string $name): ?string {
    if (!isset($txn->{$name})) {
      return NULL;
    }
    $value = trim((string) $txn->{$name});
    return $value === '' ? NULL : $value;
  }

}