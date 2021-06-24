# Magento plugin for Paylike [![Build Status](https://travis-ci.org/paylike/plugin-magento-1.9.svg?branch=master)](https://travis-ci.org/paylike/plugin-magento-1.9)

**Magento 1.9.x is no longer receiving updates so for improved security and maintenance we encourage you to use 2.x. You can find the 2.x extension here: https://github.com/paylike/plugin-magento-2**

This plugin is *not* developed or maintained by Paylike but kindly made
available by the community.

Released under the MIT license: https://opensource.org/licenses/MIT

You can also find information about the plugin here: https://paylike.io/plugins/magento-1.9

## Supported Magento versions

[![Last succesfull test](https://log.derikon.ro/api/v1/log/read?tag=magento19&view=svg&label=Magento&key=ecommerce&background=d75f07)](https://log.derikon.ro/api/v1/log/read?tag=magento19&view=html)

* The plugin has been tested with most versions of Magento at every iteration. We recommend using the latest version of Magento, but if that is not possible for some reason, test the plugin with your Magento version and it would probably function properly.

## Third party modules compatibility
1. FME_QuickCheckout

## Installation

Once you have installed Magento, follow these simple steps:
1. Signup at [paylike.io](https://paylike.io) (it’s free)
1. Create a live account
1. Create an app key for your Magento website
1. Log in as administrator and upload the tgz file using the magento connect manager
    * If you have installed the plugin before, you may encounter issues as it has been renamed. If this happens please contact [support](https://paylike.io/contact) and we will assist on fixing the issues.
1. After the plugin is installed go to the configuration screen : System-> Configuration (top menu)  -> Sales -> Payment Methods (sidebar menu) -> Paylike payment
1. In this settings screen you need to  add the Public and App key that you can find in your Paylike account.


## Updating settings

Under the extension settings, you can:
 * Update the payment method text in the payment gateways list
 * Update the payment method description in the payment gateways list
 * Update the credit card logos that you want to show (you can change which one you accept under the paylike account).
 * Update the title that shows up in the payment popup
 * Update the popup description, choose whether you want to show the popup  (the cart conztents will show up instead)
 * Add test/live keys
 * Set payment mode (test/live)
 * Change the capture type (Instant/Delayed)

## Payment management

Since version 1.0.6 we now support the magento standard UI for orders. In short we support the Void, Invoice, and Credit memo available for any sale order processed via our payment gateway module. See more info for each case bellow.

### Capture

* In *instant* mode, the authorization takes place via the popup, while the capturing is done on the server right on the order checkout page, so you don't need to capture after.
* In *delayed* mode you can capture payments by creating an invoice from the order in question from magento. Leave the capture online on to capture the payment automatically.

### Refund

* Orders can only be refunded if they have been captured. If that is the case, you can create a credit memo from the invoice of the order. *All transactions take place in the amount converted to the currency the user selected. Because of that partial refund is not yet possible on magento*

### Void

* In delayed mode, you can click void to void the payment if when this hasn't been captured, on the order interface screen.


## Changelog

#### 1.1.2:
* Added compatibility with the Amasty Onestep Checkout plugin
