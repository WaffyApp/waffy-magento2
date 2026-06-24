define([
    'Magento_Checkout/js/view/payment/default',
    'mage/url'
], function (Component, url) {
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
         * Called by the checkout JS after the order is successfully placed.
         * Redirects to our Start controller, which calls the Waffy SDK
         * and forwards the buyer to the Waffy-hosted payment page.
         */
        afterPlaceOrder: function () {
            window.location.replace(url.build('waffy/checkout/start'));
        }
    });
});
