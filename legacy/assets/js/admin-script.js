/**
 * Script Admin Principal do SISTUR
 *
 * @package SISTUR
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        // Funções auxiliares
        window.sisturShowMessage = function(message, type) {
            var messageClass = type === 'success' ? 'notice-success' : 'notice-error';
            var $notice = $('<div class="notice ' + messageClass + ' is-dismissible"><p>' + message + '</p></div>');

            $('.wrap h1').after($notice);

            setTimeout(function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 3000);
        };

        // Confirmação de exclusão
        $(document).on('click', '[data-confirm]', function(e) {
            var message = $(this).data('confirm') || 'Tem certeza que deseja continuar?';
            if (!confirm(message)) {
                e.preventDefault();
                return false;
            }
        });

        // Loading state
        $(document).on('click', '.sistur-loading-btn', function() {
            var $btn = $(this);
            $btn.prop('disabled', true);
            $btn.data('original-text', $btn.text());
            $btn.html('<span class="sistur-spinner"></span> Aguarde...');
        });

        // Auto-hide notices
        setTimeout(function() {
            $('.notice.is-dismissible').fadeOut();
        }, 5000);
    });

})(jQuery);
