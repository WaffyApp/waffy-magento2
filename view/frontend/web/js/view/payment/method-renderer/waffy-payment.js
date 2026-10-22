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

    /** Seconds before the buyer may ask for another OTP. */
    var RESEND_COOLDOWN = 60;

    /** Store-level config published by Model\CheckoutConfigProvider. */
    function waffyConfig() {
        return (window.checkoutConfig &&
            window.checkoutConfig.payment &&
            window.checkoutConfig.payment.waffy_payment) || {};
    }

    /** Error carrying the step endpoint's JSON ({ error, message }). */
    function stepError(data) {
        var error = new Error((data && data.error) || 'waffy_error');
        error.data = data || {};
        return error;
    }

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
         * Waffy wordmark, inlined by Model\CheckoutConfigProvider from the SDK's
         * Branding\Logo. Bound with `html:` in the template — static markup we
         * ship, never anything the buyer or a merchant can influence.
         */
        getLogoSvg: function () {
            return waffyConfig().logoSvg || '';
        },

        /**
         * Waffy is a redirect method: the payment URL only exists after the
         * order is placed. We open the modal, place the order, and then run the
         * Waffy steps from the modal (see afterPlaceOrder) — stopping for an OTP
         * when Waffy says the buyer must consent.
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

            this._orderPlaced = false;
            this._openModal();

            // If placing the order fails, afterPlaceOrder() never runs. Watch the
            // place-order guard flip back to true and, if the order wasn't placed,
            // close the modal (Magento shows its own placement error).
            var sub = this.isPlaceOrderActionAllowed.subscribe(function (allowed) {
                if (!allowed) {
                    return;
                }
                sub.dispose();
                if (!self._orderPlaced) {
                    self._closeModal();
                }
            });

            proceed.call(self, data, event); // places the order; afterPlaceOrder() runs the Waffy steps

            return false;
        },

        afterPlaceOrder: function () {
            this._orderPlaced = true;
            this._run();
        },

        // ── The Waffy steps ──────────────────────────────────────────────────

        /**
         * Runs the calls in the order the SDK documents, each as its own request:
         *
         *   prepare ─┬─▶ startPayment ─────────────┐
         *   begin ───┴─(consent resolved)─▶ ticket ─┴─▶ go → "Continue to Waffy"
         *
         * `begin` sends an OTP only when Waffy says the buyer's consent is
         * required; the modal then asks for the code. Start-payment never waits
         * for the buyer.
         */
        _run: function () {
            var self = this,
                prepared,
                consent,
                payment,
                ticket;

            this._showView('progress');
            this._setStep('contract', 'running');
            this._setStep('consent', 'running');
            this._setStep('payment', 'pending');

            // Branch A: contract → milestone → parties (retried once).
            prepared = this._api('prepare').catch(function () {
                return self._api('prepare');
            });
            prepared.then(function () {
                self._setStep('contract', 'done');
            }, function () {
                self._setStep('contract', 'failed');
            });

            // Branch B: Link Customer → [consent.required] Send OTP → Verify OTP.
            consent = this._api('begin').then(function (res) {
                if (!res.consentRequired) {
                    return undefined;
                }
                if (!res.otpSent) {
                    throw res.reason || $t('We could not send you a verification code.');
                }
                return self._askOtp();
            });
            consent.then(function () {
                self._setStep('consent', 'done');
            }, function () {
                self._setStep('consent', 'failed');
            });

            // Start payment waits for the contract only; the ticket also for consent.
            // Start payment runs quietly in the background — the buyer only sees
            // "Getting your payment page" once their part (the code) is done.
            payment = prepared.then(function () {
                return self._api('startpayment');
            });
            ticket = Promise.all([prepared, consent]).then(function () {
                self._setStep('payment', 'running');
                return self._api('ticket');
            });

            Promise.all([payment, ticket])
                .then(function () {
                    return self._api('go');
                })
                .then(function (res) {
                    self._setStep('payment', 'done');
                    self._readyUrl = res.url;
                    self._setButtonReady();
                })
                .catch(function (error) {
                    self._fail(error);
                });
        },

        /** POST one step; resolves with its JSON, rejects with stepError(). */
        _api: function (step, body) {
            var base = waffyConfig().stepUrl || url.build('waffy/checkout/');

            if (base.slice(-1) !== '/') {
                base += '/';
            }

            return new Promise(function (resolve, reject) {
                $.ajax({
                    url: base + step,
                    method: 'POST',
                    data: JSON.stringify(body || {}),
                    contentType: 'application/json',
                    dataType: 'json',
                    cache: false
                }).done(function (data) {
                    resolve(data || {});
                }).fail(function (xhr) {
                    reject(stepError(xhr && xhr.responseJSON));
                });
            });
        },

        /** The OTP view: resolves once Waffy accepts the code. */
        _askOtp: function () {
            var self = this,
                length = parseInt(waffyConfig().otpLength, 10) || 4,
                $el = this._modalEl,
                $code = $el.find('[data-role="otp-code"]'),
                $error = $el.find('[data-role="otp-error"]'),
                $validate = $el.find('[data-role="otp-validate"]'),
                $resend = $el.find('[data-role="otp-resend"]'),
                timer = null;

            function cooldown(seconds) {
                window.clearInterval(timer);
                $resend.prop('disabled', true).text($t('Resend in') + ' ' + seconds);
                timer = window.setInterval(function () {
                    seconds -= 1;
                    if (seconds > 0) {
                        $resend.text($t('Resend in') + ' ' + seconds);
                        return;
                    }
                    window.clearInterval(timer);
                    $resend.prop('disabled', false).text($t('Resend code'));
                }, 1000);
            }

            function showError(message) {
                $error.text(message).prop('hidden', false);
            }

            return new Promise(function (resolve) {
                $code.val('').attr('maxlength', length);
                $error.prop('hidden', true);
                $validate.prop('disabled', false);
                self._showView('otp');
                $code.trigger('focus');
                cooldown(RESEND_COOLDOWN);

                $resend.off('click').on('click', function () {
                    self._api('begin', { resend: true }).then(function (res) {
                        if (res.otpSent) {
                            $error.prop('hidden', true);
                            cooldown(RESEND_COOLDOWN);
                        } else {
                            showError(res.reason || $t('We could not send you a verification code.'));
                        }
                    }, function (error) {
                        showError(self._messageOf(error));
                    });
                });

                $el.find('[data-role="otp-cancel"]').off('click').on('click', function () {
                    window.clearInterval(timer);
                    self._cancel();
                });

                $el.find('[data-role="otp-form"]').off('submit').on('submit', function (event) {
                    var code = String($code.val() || '').replace(/\s+/g, '');

                    event.preventDefault();
                    if (!new RegExp('^\\d{' + length + '}$').test(code)) {
                        showError($t('Please enter the code we sent you.'));
                        return;
                    }

                    $validate.prop('disabled', true);
                    $error.prop('hidden', true);
                    self._api('verify', { otp: code }).then(function () {
                        window.clearInterval(timer);
                        self._showView('progress');
                        resolve();
                    }, function (error) {
                        $validate.prop('disabled', false);
                        showError((error.data && error.data.message) ||
                            $t('That code is not correct. Please check it and try again.'));
                    });
                });
            });
        },

        /** The buyer gave up: cancel the order (and its contract), back to the cart. */
        _cancel: function () {
            var cart = waffyConfig().cartUrl || url.build('checkout/cart');

            fullScreenLoader.startLoader();
            this._api('cancel').then(function () {
                window.location.replace(cart);
            }, function () {
                window.location.replace(cart);
            });
        },

        _messageOf: function (error) {
            if (typeof error === 'string') {
                return error;
            }
            return (error && error.data && error.data.message) ||
                $t('Waffy payment could not be initiated. Please try again or choose a different payment method.');
        },

        /** A step failed: say why, and offer to retry or give up. */
        _fail: function (error) {
            var self = this;

            this._modalEl.find('[data-role="failure-message"]').text(this._messageOf(error));
            this._showView('failure');
            this._modalEl.find('[data-role="retry"]').off('click').on('click', function () {
                self._run();
            });
            this._modalEl.find('[data-role="give-up"]').off('click').on('click', function () {
                self._cancel();
            });
        },

        // ── The modal ────────────────────────────────────────────────────────

        /** Build (once) and open the modal in its loading state. */
        _openModal: function () {
            var self = this;

            if (!this._modalEl) {
                this._modalEl = $(
                    '<div class="waffy-disclaimer">' +
                        '<div data-view="progress">' +
                            '<p class="waffy-disclaimer__body">' +
                                $t('To complete your order you will be taken to Waffy\'s secure escrow payment page.' +
                                   ' Once your payment is confirmed, you will be redirected back here to your order confirmation.') +
                            '</p>' +
                            '<div class="waffy-progress">' +
                                '<div class="waffy-progress__track" role="progressbar"' +
                                    ' aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"' +
                                    ' aria-label="' + $t('Preparing your payment') + '">' +
                                    '<div class="waffy-progress__fill"></div>' +
                                '</div>' +
                                '<ul class="waffy-progress__steps">' +
                                    this._stepRow('contract', $t('Creating your escrow contract')) +
                                    this._stepRow('consent', $t('Confirming your mobile number')) +
                                    this._stepRow('payment', $t('Getting your payment page')) +
                                '</ul>' +
                            '</div>' +
                        '</div>' +
                        '<form class="waffy-otp" data-view="otp" data-role="otp-form" autocomplete="off" hidden>' +
                            '<p class="waffy-disclaimer__body">' +
                                $t('Waffy sent a verification code to your mobile number. Enter it to continue.') +
                            '</p>' +
                            '<label class="waffy-otp__label" for="waffy-otp-code">' + $t('Verification code') + '</label>' +
                            '<input id="waffy-otp-code" class="waffy-otp__code" data-role="otp-code"' +
                                ' inputmode="numeric" autocomplete="one-time-code" dir="ltr" required>' +
                            '<p class="waffy-otp__error" data-role="otp-error" role="alert" hidden></p>' +
                            '<div class="waffy-otp__actions">' +
                                '<button type="submit" class="action primary waffy-btn" data-role="otp-validate">' +
                                    $t('Validate') + '</button>' +
                                '<button type="button" class="action secondary" data-role="otp-resend">' +
                                    $t('Resend code') + '</button>' +
                                '<button type="button" class="action-link waffy-otp__cancel" data-role="otp-cancel">' +
                                    $t('Cancel') + '</button>' +
                            '</div>' +
                        '</form>' +
                        '<div class="waffy-failure" data-view="failure" hidden>' +
                            '<p class="waffy-otp__error" data-role="failure-message" role="alert"></p>' +
                            '<div class="waffy-otp__actions">' +
                                '<button type="button" class="action primary waffy-btn" data-role="retry">' +
                                    $t('Try again') + '</button>' +
                                '<button type="button" class="action-link waffy-otp__cancel" data-role="give-up">' +
                                    $t('Cancel and return to cart') + '</button>' +
                            '</div>' +
                        '</div>' +
                        '<div class="waffy-disclaimer__actions" data-view="progress">' +
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
                    keyEventHandlers: {},
                    buttons: [] // no default footer; our own buttons live in the content
                });

                this._continueBtn = this._modalEl.find('.waffy-disclaimer-continue');
                this._continueBtn.on('click', function () {
                    if (this.disabled || !self._readyUrl) {
                        return;
                    }
                    fullScreenLoader.startLoader();
                    window.location.replace(self._readyUrl);
                });
            }

            // Reset to the loading state on every open.
            this._readyUrl = null;
            this._continueBtn.prop('disabled', true).addClass('-loading');
            this._showView('progress');
            this._modalEl.find('.waffy-progress__step').attr('class', 'waffy-progress__step is-pending');
            this._renderPercent();
            this._modalEl.modal('openModal');
        },

        _stepRow: function (id, label) {
            return '<li class="waffy-progress__step is-pending" data-step="' + id + '">' +
                '<span class="waffy-progress__mark" aria-hidden="true"></span>' +
                '<span class="waffy-progress__label">' + label + '</span>' +
            '</li>';
        },

        /** Show one of the modal's views: progress, otp or failure. */
        _showView: function (view) {
            this._modalEl.find('[data-view]').each(function () {
                $(this).prop('hidden', $(this).attr('data-view') !== view);
            });
        },

        _setStep: function (id, status) {
            this._modalEl.find('[data-step="' + id + '"]').attr('class', 'waffy-progress__step is-' + status);
            this._renderPercent();
        },

        /** The bar fills by the share of steps done. */
        _renderPercent: function () {
            var $steps = this._modalEl.find('.waffy-progress__step'),
                pct = Math.round($steps.filter('.is-done').length / Math.max(1, $steps.length) * 100);

            this._modalEl.find('.waffy-progress__fill').css('width', pct + '%');
            this._modalEl.find('.waffy-progress__track').attr('aria-valuenow', pct);
        },

        /** Link is ready — enable the button so the buyer can proceed. */
        _setButtonReady: function () {
            if (this._continueBtn) {
                this._continueBtn.removeClass('-loading').prop('disabled', false);
            }
        },

        _closeModal: function () {
            if (this._modalEl) {
                this._modalEl.modal('closeModal');
            }
        }
    });
});
