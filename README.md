# Elavon

Private Drupal module for taking Elavon Converge payments in Drupal Commerce 3. Maintained by Intrepid Network Inc. for the Ocean Beach site and for anyone helping test or maintain this fork.

The module code is in [commerce_elavon](commerce_elavon). That directory is what gets installed. This repository is not itself a Drupal module.

Do not install `drupal/commerce_elavon` 8.x-2.4 from Drupal.org. That release is unsupported because of [SA-CONTRIB-2026-084](https://www.drupal.org/sa-contrib-2026-084) (CVE-2026-16641). This fork replaces it. Details of the fixes are in [commerce_elavon/README.md](commerce_elavon/README.md).

## What it does

The module adds two Commerce payment gateways:

- **Elavon (On-site)** collects the card on the Drupal site, sends it to Converge, and stores a Converge token. Use this when a membership or donation must be charged again later.
- **Elavon (Off-site redirect)** sends the shopper to Converge. The card number never reaches Drupal. The return URL is not trusted until the module loads the transaction from Converge and checks the amount, invoice, and transaction type.

You can enable either gateway or both. Refunds can be started from the order in Drupal. Live API calls go to `api.convergepay.com`. Test mode goes to `api.demo.convergepay.com`, which requires a separate demo account from Elavon.

## Requirements

- Drupal 10.3 or later, or Drupal 11
- PHP 8.1 or later, with the `dom`, `libxml`, and `simplexml` extensions
- Drupal Commerce 3.3.13 or later
- An Elavon Converge terminal

The development site this was written for runs Drupal 10.6.21, PHP 8.3, and Commerce 3.3.14.

## Install

Copy [commerce_elavon](commerce_elavon) to `modules/custom/commerce_elavon` on the Drupal site. The folder name must stay `commerce_elavon`. Enable **Commerce Elavon** under the **Commerce (custom)** group at Administration, Extend, then clear caches.

For Composer, keep the module in `packages/commerce_elavon` and point the site `composer.json` at that directory as a path repository. The package name is `intrepidnetworkinc/commerce_elavon` version `10.1.0`. Its `replace` entry stops Composer from installing `drupal/commerce_elavon`.

## Credentials in settings.php

Demo credentials can be entered on the payment gateway form. For a live PIN, add this at the bottom of `sites/default/settings.php` so the PIN is not stored in Drupal's exported configuration. Replace the three placeholder values. Clear the Drupal cache after saving the file.

```php
$settings['commerce_elavon_credentials'] = [
  'merchant_id' => 'LIVE_ACCOUNT_ID',
  'user_id' => 'LIVE_USER_ID',
  'pin' => 'LIVE_PIN',
]; 
```

A non-empty value replaces the Account ID, User ID, or PIN saved on the gateway. Do not commit `settings.php` or a real PIN to this repository.
