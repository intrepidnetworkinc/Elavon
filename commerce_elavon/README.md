# Commerce Elavon 10.x-1.0-dev

Private fork of [Commerce Elavon](https://www.drupal.org/project/commerce_elavon) 8.x-2.4 for Intrepid Network Inc.

Do not install `drupal/commerce_elavon` from Drupal.org. That project was marked unsupported by [SA-CONTRIB-2026-084](https://www.drupal.org/sa-contrib-2026-084) (CVE-2026-16641). Drupal did not publish a patch or a technical description. This release replaces 8.x-2.4 and fixes the defects found in that code.

## Requirements

- Drupal `^10.3 || ^11` (tested target: 10.6.21; also compatible with Drupal 11)
- PHP `>=8.1` (dev site: 8.3)
- Drupal Commerce `^3.3.13` (dev site: 3.3.14)
- PHP extensions: `dom`, `libxml`, `simplexml`
- An Elavon Converge terminal

Commerce 3.3.11 and earlier are refused by `composer.json`. Those releases are affected by [SA-CONTRIB-2026-254](https://www.drupal.org/sa-contrib-2026-254).

## Install

Upload this directory to the Drupal root as:

`modules/custom/commerce_elavon`

The folder name must stay `commerce_elavon`. Then:

```
drush en commerce_elavon -y
drush cr
```

Composer, from a git checkout of branch `10.x-1.x`:

```
composer config repositories.commerce_elavon vcs git@github.com:intrepidnetworkinc/commerce_elavon.git
composer require intrepidnetworkinc/commerce_elavon:dev-10.x-1.x
```

Do not also require `drupal/commerce_elavon`.

Add a gateway at Administration > Commerce > Configuration > Payment gateways.

- **Elavon (On-site)** (`elavon_onsite`) collects the card on your site and stores a Converge token.
- **Elavon (Off-site redirect)** (`elavon_offsite_redirect`) sends the shopper to Converge. You can enable either gateway or both.

## Credentials

The PIN field is a password field. A saved PIN is not shown again. Leave it blank to keep the current value.

Prefer test values in the gateway form, and put live values in `settings.php`. API calls use these when they are non-empty. They are not printed on the form.

```php
$settings['commerce_elavon_credentials'] = [
  'merchant_id' => 'LIVE_ACCOUNT_ID',
  'user_id' => 'LIVE_USER_ID',
  'pin' => 'LIVE_PIN',
];
```

Per gateway (the machine name of the payment gateway config):

```php
$settings['commerce_elavon_credentials'] = [
  'elavon_live' => [
    'merchant_id' => 'LIVE_ACCOUNT_ID',
    'user_id' => 'LIVE_USER_ID',
    'pin' => 'LIVE_PIN',
  ],
];
```

**Account ID field.** The current Converge name is `ssl_account_id` (formerly `ssl_merchant_id`). This release still sends `ssl_merchant_id` by default, which is what 8.x-2.4 sent. Switch the gateway radio to `ssl_account_id` if the terminal rejects the old name.

There are no demo credentials in this module or in 8.x-2.4. Use the demo terminal from the Elavon developer portal. Demo endpoints are selected by the gateway mode "Test".

## What was fixed

1. **Off-site return is not proof of payment.** The return URL is checked with a server-side `txnquery`. The amount must match the payment. If Converge echoes an invoice number, it must be this order's numeric ID. A transaction ID already stored on another order is rejected. The browser form posts only `ssl_txn_auth_token`. The PIN, user ID, and account ID are requested on the server from `/hosted-payments/transaction_token` and stripped again if a hook adds them.
2. **A non-empty XML body is not success.** Sale, capture, void, refund, and token calls require `ssl_result` `0`, a success message (`APPROVAL`, `APPROVED`, or `SUCCESS`), and no `errorCode`.
3. **The public routes `commerce_elavon/redirect_post` and `commerce_elavon/redirect_302` are removed.** They were an open redirect.
4. **Off-site refund calls Converge `ccreturn`.** On-site refund, capture (`cccomplete`), and void (`ccdelete`) do the same and update the local payment only after approval.
5. **Zero and negative amounts throw `PaymentGatewayException` and do not call Converge.** The gateway form has a minimum charge. The default is `0.01`. A configured minimum of `0` still refuses a zero amount.
6. **The hook name matches the code.** Implement `hook_commerce_elavon_offsite_payment()`. The old documented name `hook_elavon_commerce_elavon_offsite_payment()` was never called.

XML is parsed with `LIBXML_NONET`. A `DOCTYPE` or `ENTITY` in a response is rejected. Request and response bodies are not logged. Log lines are redacted for card numbers and PIN values.

## AVS and CVV

Both default to **Decline on no-match**.

| Mode | AVS | CVV |
|---|---|---|
| Decline on no-match (default) | Decline code `N` only | Decline code `N` only |
| Decline unless fully matched | Allow only `Y`, `X`, `D`, `M`, `F` | Allow only `M` |
| Record and continue | 8.x-2.4 behavior | 8.x-2.4 behavior |

An empty code is not a decline. Saved-card renewals usually have no CVV, so they are not declined for a missing CVV. Unavailable AVS codes (`U`, `R`, `S`, `G`, and partial matches such as `A` or `Z`) are allowed in the default mode and declined in strict mode.

If Converge already approved and the local policy declines, a sale is returned (`ccreturn`) and an authorization or token request is voided (`ccdelete`). If that reversal fails, the payment is not marked complete and the log tells you to void or refund that transaction ID in Converge.

## Invoice numbers

Unchanged on purpose.

- Off-site sends the numeric order ID (`ssl_invoice_number`). It does not send the customer-facing order number.
- On-site sends the order number when Commerce has assigned one, otherwise the numeric order ID.

## Recurring memberships and donations

Use the **on-site** gateway. `ccgettoken` stores a Converge token on the Commerce payment method. Install [Commerce Recurring](https://www.drupal.org/project/commerce_recurring) and bill that payment method. A charge against a card saved more than one day earlier is sent as `ssl_merchant_initiated_unscheduled=Y`.

The off-site gateway does not store a card. It cannot run a renewal by itself.

The on-site flow posts the card number to your server and then to Converge. The number is not stored in Drupal. That is a higher PCI scope than the off-site redirect. Checkout.js would keep the card number off your server for recurring charges. It is not included in this release. Say if you need that follow-up.

## Off-site setup you need from the office

The off-site gateway needs the **hosted payment** session-token endpoint, not Checkout.js. From the production (or demo) server:

```
curl -fsS https://www.convergepay.com/hosted-payments/myip
```

Ask Elavon to allow-list that IP for hosted payments, and to confirm the terminal can open the hosted payment page. Until that is done, on-site XML calls can still work and off-site token requests will fail.

Use the Test gateway mode and the demo host first. Live mode posts to `api.convergepay.com`.

## Commerce core

This module does not change Commerce core. 3.3.14 already includes the fix for SA-CONTRIB-2026-254. If this site took orders before that update, audit orders for unpaid orders completed outside checkout, orders marked paid with no payment, overridden unit prices, and custom adjustments. Update Commerce API, License, Shipping, and Stripe too if those projects published matching security releases.

## Tests

From this directory, with no Drupal bootstrap:

```
php tests/run-unit.php
```

There is no Converge demo PIN in the repository, so the HTTP calls are not executed here.
