<?php

namespace Drupal\commerce_elavon\Converge;

/**
 * Removes secrets and card numbers from text before it is logged or shown.
 */
final class Redactor {

  /**
   * Redacts PANs, PINs, and CVVs from a gateway or exception message.
   */
  public static function redact(string $message): string {
    $message = preg_replace('/\b(?:\d[ -]*?){13,19}\b/', '[redacted]', $message) ?? $message;
    $message = preg_replace('/((?:ssl_)?(?:pin|cvv2cvc2|card_number))([^A-Za-z0-9]{1,3})([^<\s&"\']+)/i', '$1$2[redacted]', $message) ?? $message;
    return $message;
  }

}
