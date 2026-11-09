<?php

namespace Drupal\commerce_elavon\Converge;

/**
 * Decides whether an approved Converge response should be hard-declined.
 *
 * Modes:
 * - disabled: record the code and do not decline (8.x-2.4 behavior).
 * - no_match: decline a definitive no-match only. Unavailable, unsupported,
 *   and partial matches are allowed. This is the default.
 * - strict: decline unless the code is a full match. Empty CVV is not a
 *   decline, because token and renewal charges do not resend the CVV.
 *
 * AVS full matches: Y, X, D, M, F.
 * AVS definitive no-match: N.
 * CVV full match: M.
 * CVV definitive no-match: N.
 */
final class AvsCvvPolicy {

  public const DISABLED = 'disabled';

  public const NO_MATCH = 'no_match';

  public const STRICT = 'strict';

  /**
   * AVS codes that mean street and postal code both matched.
   */
  private const AVS_FULL_MATCH = ['Y', 'X', 'D', 'M', 'F'];

  /**
   * Returns a decline reason, or NULL when the response may be accepted.
   *
   * @param string|null $avs
   *   The ssl_avs_response code, if Converge sent one.
   * @param string|null $cvv
   *   The ssl_cvv2_response code, if Converge sent one.
   * @param string $avs_mode
   *   One of disabled, no_match, strict.
   * @param string $cvv_mode
   *   One of disabled, no_match, strict.
   */
  public static function declineReason(?string $avs, ?string $cvv, string $avs_mode, string $cvv_mode): ?string {
    $avs_reason = self::avsReason($avs, self::normalizeMode($avs_mode));
    if ($avs_reason !== NULL) {
      return $avs_reason;
    }
    return self::cvvReason($cvv, self::normalizeMode($cvv_mode));
  }

  /**
   * Restricts a configured mode to a known value.
   */
  public static function normalizeMode(string $mode): string {
    if ($mode === self::DISABLED || $mode === self::STRICT) {
      return $mode;
    }
    return self::NO_MATCH;
  }

  /**
   * Returns an AVS decline reason, or NULL.
   */
  private static function avsReason(?string $avs, string $mode): ?string {
    if ($mode === self::DISABLED) {
      return NULL;
    }
    $code = strtoupper(trim((string) $avs));
    if ($code === '') {
      return NULL;
    }
    if ($mode === self::STRICT && !in_array($code, self::AVS_FULL_MATCH, TRUE)) {
      return sprintf('AVS response %s is not a full match.', $code);
    }
    if ($mode === self::NO_MATCH && $code === 'N') {
      return 'AVS response N: street address and ZIP do not match.';
    }
    return NULL;
  }

  /**
   * Returns a CVV decline reason, or NULL.
   */
  private static function cvvReason(?string $cvv, string $mode): ?string {
    if ($mode === self::DISABLED) {
      return NULL;
    }
    $code = strtoupper(trim((string) $cvv));
    // Token reuse and merchant-initiated renewals do not include a CVV.
    if ($code === '') {
      return NULL;
    }
    if ($mode === self::STRICT && $code !== 'M') {
      return sprintf('CVV response %s is not a match.', $code);
    }
    if ($mode === self::NO_MATCH && $code === 'N') {
      return 'CVV response N: the security code does not match.';
    }
    return NULL;
  }

}
