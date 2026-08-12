define([
    'jquery',
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/model/full-screen-loader',
    'Magento_Checkout/js/model/payment/additional-validators',
    'Magento_Ui/js/modal/modal',
    'Magento_Ui/js/model/messageList',
    'mage/translate',
    'mage/url'
], function ($, Component, fullScreenLoader, additionalValidators, modal, globalMessageList, $t, url) {
    'use strict';

    /**
     * Poll cadence for the live progress display. Brisk at first — the early
     * steps are the fast ones and the buyer is watching — then easing off, so a
     * slow Waffy response doesn't turn into hundreds of requests.
     */
    var POLL_MIN_MS = 400,
        POLL_MAX_MS = 2000,
        POLL_BACKOFF = 1.25,
        PROGRESS_COOKIE = 'waffy_progress_key';

    /** Store-level config published by Model\CheckoutConfigProvider. */
    function waffyConfig() {
        return (window.checkoutConfig &&
            window.checkoutConfig.payment &&
            window.checkoutConfig.payment.waffy_payment) || {};
    }

    /**
     * Mint the key that ties this browser's polling to this checkout run.
     *
     * A cookie rather than a request parameter: it rides along on both the
     * place-order POST and the progress GET without either having to thread it
     * through Magento's checkout payload. Must satisfy the server's
     * [A-Za-z0-9]{8,64} check.
     */
    function newProgressKey() {
        var alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789',
            out = '',
            bytes,
            i;

        if (window.crypto && window.crypto.getRandomValues) {
            bytes = new Uint8Array(24);
            window.crypto.getRandomValues(bytes);
            for (i = 0; i < bytes.length; i++) {
                out += alphabet[bytes[i] % alphabet.length];
            }
        } else {
            // This key only scopes a progress lookup, never anything
            // security-bearing, so Math.random is an acceptable fallback.
            while (out.length < 24) {
                out += alphabet[Math.floor(Math.random() * alphabet.length)];
            }
        }

        document.cookie = PROGRESS_COOKIE + '=' + out + '; path=/; SameSite=Lax' +
            (window.location.protocol === 'https:' ? '; Secure' : '');

        return out;
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

            this._orderPlaced = false;

            // Mint the progress key BEFORE the order is placed: the Start
            // controller reads it from the cookie to publish its progress under.
            newProgressKey();

            this._openDisclaimer();

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

            this._orderPlaced = true;

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
                    self._closeWithError(res && res.message);
                }
            }).fail(function () {
                self._closeWithError();
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
                        '<div class="waffy-progress">' +
                            '<div class="waffy-progress__track" role="progressbar"' +
                                ' aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"' +
                                ' aria-label="' + $t('Preparing your payment') + '">' +
                                '<div class="waffy-progress__fill"></div>' +
                            '</div>' +
                            '<span class="waffy-progress__percent">0%</span>' +
                            (waffyConfig().isSandbox
                                ? '<p class="waffy-progress__note">' +
                                      $t('Sandbox mode — showing each Waffy API call.') +
                                  '</p><ul class="waffy-progress__steps"></ul>'
                                : '') +
                        '</div>' +
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
                    keyEventHandlers: {},
                    buttons: [] // no default "OK" footer button; our own button lives in the content
                });

                this._continueBtn = this._modalEl.find('.waffy-disclaimer-continue');
                this._continueBtn.on('click', function () {
                    if (this.disabled) {
                        return;
                    }
                    if (self._readyUrl) {
                        fullScreenLoader.startLoader();
                        window.location.replace(self._readyUrl);
                    }
                });
            }

            // Reset to the loading state on every open.
            this._readyUrl = null;
            this._continueBtn
                .prop('disabled', true)
                .addClass('-loading');
            this._resetProgress();
            this._modalEl.modal('openModal');
            this._startPolling();
        },

        /** Link is ready — enable the button so the buyer can proceed. */
        _setButtonReady: function () {
            if (!this._continueBtn) {
                return;
            }

            // The link exists, so every call has landed: fill the bar and settle
            // any row still spinning, in case the last poll hasn't come back.
            this._stopPolling();
            this._renderProgress({ percent: 100, finalize: true });

            this._continueBtn
                .removeClass('-loading')
                .prop('disabled', false);
        },

        /** Close the disclaimer modal if it is open. */
        _closeModal: function () {
            this._stopPolling();
            if (this._modalEl) {
                this._modalEl.modal('closeModal');
            }
        },

        // ── Live progress ────────────────────────────────────────────────────

        /** Clear the bar and the step rows so a retry doesn't inherit the old run. */
        _resetProgress: function () {
            this._stepRows = {};
            this._modalEl.find('.waffy-progress__steps').empty();
            this._renderProgress({ percent: 0 });
        },

        /**
         * Poll Controller\Checkout\Progress until the run settles.
         *
         * The endpoint is deliberately session-free, which is what lets these
         * polls return while the Start controller is still working — Magento
         * holds the session lock for a whole request, so anything session-aware
         * would queue up behind checkout and all arrive at the end.
         */
        _startPolling: function () {
            var self = this,
                interval = POLL_MIN_MS,
                lastPercent = -1;

            this._stopPolling();
            this._polling = true;

            function schedule() {
                if (!self._polling) {
                    return;
                }
                self._pollTimer = window.setTimeout(poll, interval);
                interval = Math.min(POLL_MAX_MS, interval * POLL_BACKOFF);
            }

            function poll() {
                if (!self._polling) {
                    return;
                }

                $.ajax({
                    url: waffyConfig().progressUrl || url.build('waffy/checkout/progress'),
                    dataType: 'json',
                    method: 'GET',
                    cache: false
                }).done(function (data) {
                    if (!self._polling || !data) {
                        schedule();
                        return;
                    }

                    // Something moved — poll briskly again, so a long call
                    // followed by three quick ones still feels live.
                    if (data.percent !== lastPercent) {
                        lastPercent = data.percent;
                        interval = POLL_MIN_MS;
                    }

                    self._renderProgress(data);

                    if (data.state === 'complete' || data.state === 'failed') {
                        self._polling = false;
                        return;
                    }

                    schedule();
                }).fail(function () {
                    // A dropped poll must never disturb a checkout that is
                    // otherwise going fine — try again.
                    schedule();
                });
            }

            poll();
        },

        _stopPolling: function () {
            this._polling = false;
            if (this._pollTimer) {
                window.clearTimeout(this._pollTimer);
                this._pollTimer = null;
            }
        },

        /** Paint one progress payload from the endpoint into the modal. */
        _renderProgress: function (data) {
            var self = this,
                pct = Math.max(0, Math.min(100, data.percent || 0)),
                $list = this._modalEl.find('.waffy-progress__steps');

            this._modalEl.find('.waffy-progress__fill').css('width', pct + '%');
            this._modalEl.find('.waffy-progress__percent').text(pct + '%');
            this._modalEl.find('.waffy-progress__track').attr('aria-valuenow', pct);

            if (!$list.length) {
                return; // production: the bar only
            }

            if (data.finalize) {
                $list.find('.waffy-progress__step').each(function () {
                    var $row = $(this);
                    if ($row.is('.is-running, .is-pending')) {
                        $row.attr('class', 'waffy-progress__step is-done')
                            .find('.waffy-progress__timing').text('');
                    }
                });
            }

            if (!data.steps) {
                return;
            }

            this._stepRows = this._stepRows || {};

            $.each(data.steps, function (i, step) {
                var row = self._stepRows[step.id],
                    timing = '';

                if (!row) {
                    row = $(
                        '<li class="waffy-progress__step">' +
                            '<span class="waffy-progress__mark" aria-hidden="true"></span>' +
                            '<span class="waffy-progress__label"></span>' +
                            '<span class="waffy-progress__timing"></span>' +
                        '</li>'
                    );
                    row.find('.waffy-progress__label').text(step.label);
                    $list.append(row);
                    self._stepRows[step.id] = row;
                }

                if (step.status === 'cached') {
                    // The point of the whole exercise: this call did not happen,
                    // because the token was already cached.
                    timing = $t('cached');
                } else if (step.status === 'done' && typeof step.ms === 'number') {
                    timing = Math.round(step.ms) + ' ms';
                } else if (step.status === 'failed') {
                    timing = $t('failed');
                }

                row.attr('class', 'waffy-progress__step is-' + step.status)
                    .find('.waffy-progress__timing').text(timing);
            });
        },

        /**
         * Preparing the link failed — close the modal and surface the reason in
         * the checkout message area so the buyer can retry or pick another
         * method. The order remains as pending_payment.
         */
        _closeWithError: function (message) {
            this._closeModal();
            globalMessageList.addErrorMessage({
                message: message ||
                    $t('Waffy payment could not be initiated. Please try again or choose a different payment method.')
            });
        }
    });
});
