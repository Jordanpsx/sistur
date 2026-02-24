/**
 * Script de Login do SISTUR
 *
 * @package SISTUR
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        // Verificar se o template já tem seu próprio handler inline
        // Se o formulário tiver data-inline-handler, não adicionar outro handler
        var $form = $('#sistur-login-form');

        if ($form.length && !$form.data('inline-handler')) {
            $form.on('submit', function(e) {
                e.preventDefault();

                var cpf = $('#sistur-cpf').val();
                var password = $('#sistur-password').val();

                $.ajax({
                    url: sisturPublic.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'sistur_funcionario_login',
                        cpf: cpf,
                        password: password,
                        nonce: sisturPublic.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            window.location.href = response.data.redirect;
                        } else {
                            alert(response.data.message);
                        }
                    },
                    error: function() {
                        alert('Erro ao processar login. Tente novamente.');
                    }
                });
            });
        }
    });

})(jQuery);
