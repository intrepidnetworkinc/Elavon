<?php

namespace Drupal\commerce_elavon\Converge;

/**
 * Compares and formats Converge decimal amounts without binary floats.
 */
final class Amounts {

  /**
   * Formats a decimal string as Converge expects (two fraction digits).
   */
  public static function format(string $number): string {
    if (!is_numeric($number)) {
      throw new \InvalidArgumentException('Amount is not numeric.');
    }
    if (function_exists('bcadd')) {
      return bcadd($number, '0', 2);
    }
    return number_format((float) $number, 2, '.', '');
  }

  /**
   * TRUE when two decimal amounts are equal at two fraction digits.
   */
  public static function equals(string $expected, string $actual): bool {
    if (!is_numeric($expected) || !is_numeric($actual)) {
      return FALSE;
    }
    if (function_exists('bccomp')) {
      return bccomp(self::format($expected), self::format($actual), 2) === 0;
    }
    return self::format($expected) === self::format($actual);
  }

  /**
   * TRUE when the amount is less than or equal to zero.
   */
  public static function isZeroOrNegative(string $number): bool {
    if (!is_numeric($number)) {
      return TRUE;
    }
    if (function_exists('bccomp')) {
      return bccomp(self::format($number), '0.00', 2) <= 0;
    }
    return (float) self::format($number) <= 0;
  }

  /**
   * TRUE when $amount is strictly below $minimum.
   */
  public static function belowMinimum(string $amount, string $minimum): bool {
    if (!is_numeric($amount) || !is_numeric($minimum)) {
      return TRUE;
    }
    if (function_exists('bccomp')) {
      return bccomp(self::format($amount), self::format($minimum), 2) < 0;
    }
    return (float) self::format($amount) < (float) self::format($minimum);
  }

}
