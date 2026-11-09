<?php

namespace Drupal\commerce_elavon\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsVoidsInterface;

/**
 * On-site Converge gateway.
 *
 * Stored payment methods hold a Converge token, so Commerce Recurring can
 * charge the same card for a later membership renewal or donation.
 */
interface OnsiteInterface extends OnsitePaymentGatewayInterface, SupportsAuthorizationsInterface, SupportsVoidsInterface, SupportsRefundsInterface {}
