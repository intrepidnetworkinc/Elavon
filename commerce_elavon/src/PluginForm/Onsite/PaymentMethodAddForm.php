<?php

namespace Drupal\commerce_elavon\PluginForm\Onsite;

use Drupal\commerce_payment\PluginForm\PaymentMethodAddForm as BasePaymentMethodAddForm;

/**
 * On-site card form.
 *
 * The parent form collects the card number. It is sent to Converge and is
 * not stored. No test card number is prefilled.
 */
class PaymentMethodAddForm extends BasePaymentMethodAddForm {}
