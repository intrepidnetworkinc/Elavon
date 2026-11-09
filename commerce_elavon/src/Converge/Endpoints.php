<?php

namespace Drupal\commerce_elavon\Converge;

/**
 * Converge demo and production endpoints.
 *
 * XML calls are server-to-server. The hosted process URL receives only a
 * session token. The session-token URL must be called from an IP Elavon has
 * allow-listed on the terminal.
 */
final class Endpoints {

  /**
   * Returns the processxml.do URL for a gateway mode.
   */
  public static function xml(string $mode): string {
    if ($mode === 'live') {
      return 'https://api.convergepay.com/VirtualMerchant/processxml.do';
    }
    return 'https://api.demo.convergepay.com/VirtualMerchantDemo/processxml.do';
  }

  /**
   * Returns the hosted process.do URL for a gateway mode.
   */
  public static function hostedProcess(string $mode): string {
    if ($mode === 'live') {
      return 'https://api.convergepay.com/VirtualMerchant/process.do';
    }
    return 'https://api.demo.convergepay.com/VirtualMerchantDemo/process.do';
  }

  /**
   * Returns the hosted-payments session token URL for a gateway mode.
   */
  public static function sessionToken(string $mode): string {
    if ($mode === 'live') {
      return 'https://api.convergepay.com/hosted-payments/transaction_token';
    }
    return 'https://api.demo.convergepay.com/hosted-payments/transaction_token';
  }

}
