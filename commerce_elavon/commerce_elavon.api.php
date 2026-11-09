<?php

/**
 * @file
 * Hooks for the Commerce Elavon module.
 */

use Drupal\commerce_order\Entity\OrderInterface;

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alters the fields posted from the shopper's browser to Converge.
 *
 * Invoked only for the off-site redirect gateway, after a session token has
 * been created on the server. Credential fields, the card number, and the CVV
 * are removed again after this hook runs.
 *
 * @param array $data
 *   Browser fields. ssl_txn_auth_token is required.
 * @param \Drupal\commerce_order\Entity\OrderInterface $order
 *   The order being paid.
 */
function hook_commerce_elavon_offsite_payment(array &$data, OrderInterface $order) {
  $profile = $order->getBillingProfile();
  if ($profile && !$profile->get('address')->isEmpty()) {
    $address = $profile->get('address')->first();
    $data['ssl_first_name'] = $address->getGivenName();
    $data['ssl_last_name'] = $address->getFamilyName();
  }
}

/**
 * @} End of "addtogroup hooks".
 */
