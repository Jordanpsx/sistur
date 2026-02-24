/**
 * Script do Painel do Funcionário
 *
 * @package SISTUR
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        // Tabs
        $('.sistur-tab').on('click', function() {
            var target = $(this).data('tab');

            $('.sistur-tab').removeClass('active');
            $(this).addClass('active');

            $('.sistur-tab-content').removeClass('active');
            $('#' + target).addClass('active');
        });
    });

})(jQuery);
