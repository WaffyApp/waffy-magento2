/**
 * Setup wizard, step 2: read the chosen env file in the browser, put its text
 * in the form, and show the server's preview of it — which environment it is
 * for, its main values, and anything wrong with it — before the merchant saves.
 */
define(['jquery', 'mage/translate'], function ($, $t) {
    'use strict';

    return function (config) {
        var $file = $('#waffy-env-file'),
            $text = $('#waffy-env-json'),
            $preview = $('#waffy-env-preview');

        function line(html) {
            return $('<div>').html(html).appendTo($preview);
        }

        // Upload the env file, or enter the values — one at a time.
        function showMode(mode) {
            $('[data-mode]').each(function () {
                this.hidden = $(this).attr('data-mode') !== mode;
            });
            $('[data-mode-tab]').each(function () {
                $(this).attr('aria-selected', $(this).attr('data-mode-tab') === mode ? 'true' : 'false');
            });
        }
        $('[data-mode-tab]').on('click', function () {
            showMode($(this).attr('data-mode-tab'));
        });
        showMode('file');

        $file.on('change', function () {
            var file = this.files && this.files[0],
                reader;

            $preview.empty();
            if (!file) {
                return;
            }

            reader = new FileReader();
            reader.onload = function () {
                $text.val(String(reader.result || ''));
                $.ajax({
                    url: config.previewUrl,
                    method: 'POST',
                    dataType: 'json',
                    data: { form_key: window.FORM_KEY, environment: config.environment, envJson: $text.val() }
                }).done(function (preview) {
                    var label;

                    $preview.empty();
                    if (preview.detected) {
                        label = preview.detected === 'sandbox' ? $t('Sandbox') : $t('Live');
                        line($t('This file is for') + ' <span class="waffy-badge ' + preview.detected + '"></span>')
                            .find('span').text(label);
                    }
                    if (preview.values) {
                        line($t('Client ID') + ': <code></code>').find('code').text(preview.values.clientId);
                        line($t('Admin username') + ': <code></code>').find('code').text(preview.values.adminUsername);
                        line($t('Organization account') + ': <code></code>').find('code').text(preview.values.orgAccountId);
                    }
                    $.each(preview.errors || [], function (i, message) {
                        line('<span class="waffy-setup__error"></span>').find('span').text(message);
                    });
                    if (preview.switchTo && config.switchUrls[preview.switchTo]) {
                        line('<a></a>').find('a')
                            .attr('href', config.switchUrls[preview.switchTo])
                            .text(preview.switchTo === 'sandbox' ? $t('Switch to Sandbox') : $t('Switch to Live'));
                    }
                });
            };
            reader.readAsText(file);
        });
    };
});
