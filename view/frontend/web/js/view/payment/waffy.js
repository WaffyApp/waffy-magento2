define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'waffy_payment',
        component: 'Waffy_Payment/js/view/payment/method-renderer/waffy-payment'
    });

    return Component.extend({});
});
