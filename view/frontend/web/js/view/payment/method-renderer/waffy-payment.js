define([
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/model/full-screen-loader',
    'Magento_Checkout/js/model/payment/additional-validators',
    'Magento_Ui/js/modal/confirm',
    'mage/translate',
    'mage/url'
], function (Component, fullScreenLoader, additionalValidators, confirm, $t, url) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Waffy_Payment/payment/waffy-payment',
            redirectAfterPlaceOrder: false
        },

        getCode: function () {
            return 'waffy_payment';
        },

        getData: function () {
            return { method: this.item.method };
        },

        /**
         * Waffy is a redirect method: the real payment URL only exists after the
         * order is placed server-side. Before placing it we show a disclaimer so
         * the buyer knows they are about to leave the store for the Waffy hosted
         * payment page and will be brought back afterwards. Only when they accept
         * do we run the real place-order flow (parent placeOrder), which ends in
         * afterPlaceOrder() redirecting to the Waffy checkout Start controller.
         * Nothing is committed if they cancel.
         */
        placeOrder: function (data, event) {
            var self = this,
                proceed = this._super;

            if (event) {
                event.preventDefault();
            }

            // Mirror the parent's guards so we never pop the disclaimer only for
            // place-order to then silently fail (e.g. terms not accepted).
            if (!this.validate() ||
                !additionalValidators.validate() ||
                this.isPlaceOrderActionAllowed() !== true
            ) {
                return false;
            }

            confirm({
                title: $t('You are being redirected to Waffy'),
                modalClass: 'waffy-disclaimer-modal',
                content: '<p>' + $t(
                    'To complete your order you will be taken to Waffy\'s secure escrow payment page.' +
                    ' Once your payment is confirmed, you will be redirected back here to your order confirmation.'
                ) + '</p>',
                buttons: [
                    {
                        text: $t('Cancel'),
                        class: 'action-secondary action-dismiss',
                        click: function (e) {
                            this.closeModal(e);
                        }
                    },
                    {
                        text: $t('Continue to Waffy'),
                        class: 'action-primary action-accept',
                        click: function (e) {
                            this.closeModal(e, true);
                        }
                    }
                ],
                actions: {
                    confirm: function () {
                        proceed.call(self, data, event);
                    }
                }
            });

            return false;
        },

        /**
         * Called by the checkout JS after the order is successfully placed.
         * place-order.js already stopped the full-screen loader in its own
         * .always() handler by this point, so we restart it here to keep the
         * page blocked until the browser actually navigates to the Waffy
         * payment page — otherwise checkout briefly becomes interactive again.
         */
        afterPlaceOrder: function () {
            fullScreenLoader.startLoader();
            window.location.replace(url.build('waffy/checkout/start'));
        }
    });
});
