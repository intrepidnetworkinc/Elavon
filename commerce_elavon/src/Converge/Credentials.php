<?php

namespace Drupal\commerce_elavon\Converge;

/**
 * Resolves Converge credentials from gateway configuration and settings.php.
 *
 * settings.php may define either a global override or a per-gateway override.
 * Per-gateway values win, then global values, then the stored configuration.
 * Empty strings do not override. The PIN is never returned by the admin form.
 *
 * @code
 * $settings['commerce_elavon_credentials'] = [
 *   'merchant_id' => 'GLOBAL_ACCOUNT',
 *   'user_id' => 'GLOBAL_USER',
 *   'pin' => 'GLOBAL_PIN',
 *   'my_gateway_id' => [
 *     'merchant_id' => 'ACCOUNT',
 *     'user_id' => 'USER',
 *     'pin' => 'PIN',
 *   ],
 * ];
 * @endcode
 */
final class Credentials {

  /**
   * Credential keys that can be overridden.
   */
  public const KEYS = ['merchant_id', 'user_id', 'pin'];

  /**
   * Merges configuration with optional settings.php overrides.
   *
   * @param array $configuration
   *   Gateway plugin configuration.
   * @param array $settings
   *   The commerce_elavon_credentials settings array.
   * @param string|null $gateway_id
   *   The payment gateway config entity ID, when known.
   *
   * @return array
   *   Keys merchant_id, user_id, pin, and account_id_field.
   */
  public static function resolve(array $configuration, array $settings, ?string $gateway_id): array {
    $resolved = [];
    foreach (self::KEYS as $key) {
      $resolved[$key] = self::pick($key, $configuration, $settings, $gateway_id);
    }
    $field = $configuration['account_id_field'] ?? 'ssl_merchant_id';
    $resolved['account_id_field'] = $field === 'ssl_account_id' ? 'ssl_account_id' : 'ssl_merchant_id';
    return $resolved;
  }

  /**
   * Returns which credential keys are supplied by settings.php.
   *
   * @param array $settings
   *   The commerce_elavon_credentials settings array.
   * @param string|null $gateway_id
   *   The payment gateway config entity ID, when known.
   *
   * @return array
   *   Keys that have a non-empty settings override.
   */
  public static function overriddenKeys(array $settings, ?string $gateway_id): array {
    $overridden = [];
    foreach (self::KEYS as $key) {
      if (self::fromSettings($key, $settings, $gateway_id) !== NULL) {
        $overridden[$key] = TRUE;
      }
    }
    return $overridden;
  }

  /**
   * Picks the effective value for one credential.
   */
  private static function pick(string $key, array $configuration, array $settings, ?string $gateway_id): string {
    $from_settings = self::fromSettings($key, $settings, $gateway_id);
    if ($from_settings !== NULL) {
      return $from_settings;
    }
    return isset($configuration[$key]) ? trim((string) $configuration[$key]) : '';
  }

  /**
   * Reads one non-empty settings override, or NULL when absent.
   */
  private static function fromSettings(string $key, array $settings, ?string $gateway_id): ?string {
    if ($gateway_id && isset($settings[$gateway_id]) && is_array($settings[$gateway_id])) {
      $value = $settings[$gateway_id][$key] ?? NULL;
      if (is_string($value) && trim($value) !== '') {
        return trim($value);
      }
    }
    $value = $settings[$key] ?? NULL;
    if (is_string($value) && trim($value) !== '') {
      return trim($value);
    }
    return NULL;
  }

}
