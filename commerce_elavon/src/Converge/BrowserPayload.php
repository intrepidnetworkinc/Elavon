<?php

namespace Drupal\commerce_elavon\Converge;

/**
 * Fields that are allowed to be posted from the shopper's browser.
 *
 * Credentials, the PAN, and the CVV are removed even if a hook added them.
 */
final class BrowserPayload {

  /**
   * Keys that must never be placed in the hosted redirect form.
   */
  public const FORBIDDEN = [
    'ssl_pin',
    'ssl_user_id',
    'ssl_merchant_id',
    'ssl_account_id',
    'ssl_vendor_id',
    'ssl_partner_app_id',
    'ssl_card_number',
    'ssl_cvv2cvc2',
    'ssl_exp_date',
    'ssl_track_data',
  ];

  /**
   * Removes secret fields from the browser payload.
   */
  public static function sanitize(array $data): array {
    foreach (self::FORBIDDEN as $key) {
      unset($data[$key]);
    }
    return $data;
  }

}
