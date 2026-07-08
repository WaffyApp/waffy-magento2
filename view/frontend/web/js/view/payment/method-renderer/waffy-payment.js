define([
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/model/full-screen-loader',
    'mage/url'
], function (Component, fullScreenLoader, url) {
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
