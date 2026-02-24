/**
 * Script de Gerenciamento de Funcionários
 *
 * @package SISTUR
 */

(function($) {
    'use strict';

    // Já implementado inline nas views por simplicidade
    // Este arquivo serve como fallback ou para funções adicionais

    $(document).ready(function() {
        // Formatação de CPF
        $('input[name="cpf"]').on('input', function() {
            var value = $(this).val().replace(/\D/g, '');
            if (value.length > 11) {
                value = value.substr(0, 11);
            }

            if (value.length >= 9) {
                value = value.replace(/(\d{3})(\d{3})(\d{3})(\d{0,2})/, '$1.$2.$3-$4');
            } else if (value.length >= 6) {
                value = value.replace(/(\d{3})(\d{3})(\d{0,3})/, '$1.$2.$3');
            } else if (value.length >= 3) {
                value = value.replace(/(\d{3})(\d{0,3})/, '$1.$2');
            }

            $(this).val(value);
        });

        // Formatação de telefone
        $('input[name="phone"]').on('input', function() {
            var value = $(this).val().replace(/\D/g, '');
            if (value.length > 11) {
                value = value.substr(0, 11);
            }

            if (value.length >= 7) {
                if (value.length === 11) {
                    value = value.replace(/(\d{2})(\d{5})(\d{4})/, '($1) $2-$3');
                } else {
                    value = value.replace(/(\d{2})(\d{4})(\d{0,4})/, '($1) $2-$3');
                }
            } else if (value.length >= 2) {
                value = value.replace(/(\d{2})(\d{0,5})/, '($1) $2');
            }

            $(this).val(value);
        });

        // Preview de imagem
        $('input[type="file"][name="photo"]').on('change', function(e) {
            var file = e.target.files[0];
            if (file && file.type.match('image.*')) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    $('#photo-preview').attr('src', e.target.result).show();
                };
                reader.readAsDataURL(file);
            }
        });
    });

})(jQuery);
