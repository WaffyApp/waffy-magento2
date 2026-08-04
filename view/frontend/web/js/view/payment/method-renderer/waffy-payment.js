define([
    'jquery',
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/model/full-screen-loader',
    'Magento_Checkout/js/model/payment/additional-validators',
    'Magento_Ui/js/modal/modal',
    'mage/translate',
    'mage/url'
], function ($, Component, fullScreenLoader, additionalValidators, modal, $t, url) {
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
         * order is placed server-side. We open a disclaimer modal, place the
         * order, and prepare the Waffy link in the background — the modal's
         * "Continue to Waffy" button shows a loading indicator and is disabled
         * until the link is ready, then becomes clickable so the buyer can
         * proceed once they've read the notice.
         */
        placeOrder: function (data, event) {
            var self = this,
                proceed = this._super;

            if (event) {
                event.preventDefault();
            }

            // Mirror the parent's guards so we never open the modal only for
            // place-order to then silently fail (e.g. terms not accepted).
            if (!this.validate() ||
                !additionalValidators.validate() ||
                this.isPlaceOrderActionAllowed() !== true
            ) {
                return false;
            }

            this._openDisclaimer();
            proceed.call(self, data, event); // places the order; afterPlaceOrder() fetches the link

            return false;
        },

        /**
         * Called by the checkout JS once the order is successfully placed. Rather
         * than redirecting immediately we ask the Start controller for the Waffy
         * URL (JSON), then flip the modal button from loading to ready.
         */
        afterPlaceOrder: function () {
            var self = this;

            $.ajax({
                url: url.build('waffy/checkout/start'),
                data: { format: 'json' },
                dataType: 'json',
                method: 'GET',
                cache: false
            }).done(function (res) {
                if (res && res.url) {
                    self._readyUrl = res.url;
                    self._setButtonReady();
                } else {
                    self._setButtonError(res && res.message);
                }
            }).fail(function () {
                self._setButtonError();
            });
        },

        /** Build (once) and open the disclaimer modal with its button loading. */
        _openDisclaimer: function () {
            var self = this;

            if (!this._modalEl) {
                this._modalEl = $(
                    '<div class="waffy-disclaimer">' +
                        '<p class="waffy-disclaimer__body" data-role="body">' +
                            $t('To complete your order you will be taken to Waffy\'s secure escrow payment page.' +
                               ' Once your payment is confirmed, you will be redirected back here to your order confirmation.') +
                        '</p>' +
                        '<div class="waffy-disclaimer__actions">' +
                            '<button type="button" class="action primary waffy-btn waffy-disclaimer-continue" disabled>' +
                                '<span data-role="label">' + $t('Continue to Waffy') + '</span>' +
                            '</button>' +
                        '</div>' +
                    '</div>'
                );

                this._modalEl.modal({
                    title: $t('You are being redirected to Waffy'),
                    modalClass: 'waffy-disclaimer-modal',
                    clickableOverlay: false,
                    keyEventHandlers: {}
                });

                this._continueBtn = this._modalEl.find('.waffy-disclaimer-continue');
                this._continueBtn.on('click', function () {
                    if (this.disabled) {
                        return;
                    }
                    if (self._readyUrl) {
                        fullScreenLoader.startLoader();
                        window.location.replace(self._readyUrl);
                    } else if (self._errorPath) {
                        window.location.href = url.build(self._errorPath);
                    }
                });
            }

            // Reset to the loading state on every open.
            this._readyUrl = null;
            this._errorPath = null;
            this._continueBtn
                .prop('disabled', true)
                .addClass('-loading')
                .find('[data-role="label"]').text($t('Continue to Waffy'));
            this._modalEl.modal('openModal');
        },

        /** Link is ready — enable the button so the buyer can proceed. */
        _setButtonReady: function () {
            if (!this._continueBtn) {
                return;
            }
            this._continueBtn
                .removeClass('-loading')
                .prop('disabled', false)
                .find('[data-role="label"]').text($t('Continue to Waffy'));
        },

        /**
         * Preparing the link failed. Show the reason and turn the button into a
         * "Return to cart" action (the order stays as pending_payment for retry).
         */
        _setButtonError: function (message) {
            if (!this._modalEl) {
                return;
            }
            this._modalEl.find('[data-role="body"]').text(
                message || $t('Waffy payment could not be initiated. Please try again or choose a different payment method.')
            );
            this._errorPath = 'checkout/cart';
            this._continueBtn
                .removeClass('-loading')
                .prop('disabled', false)
                .find('[data-role="label"]').text($t('Return to cart'));
        }
    });
});
