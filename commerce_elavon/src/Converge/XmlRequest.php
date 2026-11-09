<?php

namespace Drupal\commerce_elavon\Converge;

/**
 * Builds a Converge xmldata document.
 *
 * Only ssl_* keys are emitted. Values are written as text nodes so they
 * cannot break out of the element.
 */
final class XmlRequest {

  /**
   * Builds the <txn> document posted as xmldata.
   *
   * @param array $credentials
   *   Resolved credentials from Credentials::resolve().
   * @param array $fields
   *   Transaction fields keyed by ssl_* name. Credential keys are ignored
   *   here and taken only from $credentials.
   *
   * @return string
   *   The XML document, without a trailing newline.
   */
  public static function build(array $credentials, array $fields): string {
    $merchant_id = trim((string) ($credentials['merchant_id'] ?? ''));
    $user_id = trim((string) ($credentials['user_id'] ?? ''));
    $pin = trim((string) ($credentials['pin'] ?? ''));
    if ($merchant_id === '' || $user_id === '' || $pin === '') {
      throw new \InvalidArgumentException('Elavon credentials are incomplete.');
    }

    $id_field = ($credentials['account_id_field'] ?? '') === 'ssl_account_id' ? 'ssl_account_id' : 'ssl_merchant_id';
    $ordered = [
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
      $ordered[$key] = (string) $value;
    }

    $dom = new \DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = FALSE;
    $txn = $dom->createElement('txn');
    $dom->appendChild($txn);
    foreach ($ordered as $key => $value) {
      $element = $dom->createElement($key);
      $element->appendChild($dom->createTextNode($value));
      $txn->appendChild($element);
    }
    $xml = $dom->saveXML($txn);
    if ($xml === FALSE) {
      throw new \RuntimeException('Unable to build the Converge request.');
    }
    return $xml;
  }

}
