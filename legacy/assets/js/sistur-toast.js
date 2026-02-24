/**
 * SISTUR Toast Notification System
 * Sistema moderno de notificações substituindo alerts do navegador
 *
 * @package SISTUR
 * @version 1.2.0
 */

(function($) {
    'use strict';

    /**
     * Toast Notification Manager
     */
    window.SisturToast = {

        /**
         * Container para os toasts
         */
        container: null,

        /**
         * Contador de IDs
         */
        idCounter: 0,

        /**
         * Inicializa o sistema de toasts
         */
        init: function() {
            if (this.container) return;

            this.container = $('<div class="sistur-toast-container"></div>');
            $('body').append(this.container);
        },

        /**
         * Mostra um toast
         *
         * @param {Object} options - Opções do toast
         * @param {string} options.type - Tipo: 'success', 'error', 'warning', 'info'
         * @param {string} options.title - Título do toast
         * @param {string} options.message - Mensagem do toast
         * @param {number} options.duration - Duração em ms (0 = não fecha automaticamente)
         * @param {boolean} options.closable - Se pode ser fechado manualmente
         */
        show: function(options) {
            this.init();

            var settings = $.extend({
                type: 'info',
                title: '',
                message: '',
                duration: 5000,
                closable: true
            }, options);

            var id = 'sistur-toast-' + (++this.idCounter);

            var icons = {
                success: '<svg class="sistur-toast-icon" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" style="color: #00a32a;"/></svg>',
                error: '<svg class="sistur-toast-icon" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" style="color: #d63638;"/></svg>',
                warning: '<svg class="sistur-toast-icon" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" style="color: #f0b429;"/></svg>',
                info: '<svg class="sistur-toast-icon" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" style="color: #2271b1;"/></svg>'
            };

            var closeButton = settings.closable
                ? '<button class="sistur-toast-close" type="button" aria-label="Fechar"><svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg></button>'
                : '';

            var titleHtml = settings.title
                ? '<div class="sistur-toast-title">' + this.escapeHtml(settings.title) + '</div>'
                : '';

            var toast = $([
                '<div class="sistur-toast sistur-toast-' + settings.type + '" id="' + id + '" role="alert">',
                    icons[settings.type] || icons.info,
                    '<div class="sistur-toast-content">',
                        titleHtml,
                        '<div class="sistur-toast-message">' + this.escapeHtml(settings.message) + '</div>',
                    '</div>',
                    closeButton,
                '</div>'
            ].join(''));

            this.container.append(toast);

            // Animar entrada
            setTimeout(function() {
                toast.addClass('active');
            }, 10);

            // Fechar ao clicar no X
            if (settings.closable) {
                toast.find('.sistur-toast-close').on('click', function() {
                    SisturToast.hide(id);
                });
            }

            // Auto-fechar
            if (settings.duration > 0) {
                setTimeout(function() {
                    SisturToast.hide(id);
                }, settings.duration);
            }

            return id;
        },

        /**
         * Esconde um toast
         *
         * @param {string} id - ID do toast
         */
        hide: function(id) {
            var toast = $('#' + id);

            toast.css({
                opacity: 0,
                transform: 'translateX(100%)'
            });

            setTimeout(function() {
                toast.remove();
            }, 300);
        },

        /**
         * Atalho para toast de sucesso
         */
        success: function(message, title) {
            return this.show({
                type: 'success',
                title: title || 'Sucesso!',
                message: message
            });
        },

        /**
         * Atalho para toast de erro
         */
        error: function(message, title) {
            return this.show({
                type: 'error',
                title: title || 'Erro!',
                message: message,
                duration: 7000
            });
        },

        /**
         * Atalho para toast de aviso
         */
        warning: function(message, title) {
            return this.show({
                type: 'warning',
                title: title || 'Atenção!',
                message: message,
                duration: 6000
            });
        },

        /**
         * Atalho para toast de informação
         */
        info: function(message, title) {
            return this.show({
                type: 'info',
                title: title || 'Informação',
                message: message
            });
        },

        /**
         * Escapa HTML para prevenir XSS
         */
        escapeHtml: function(text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
        }
    };

    /**
     * Substituir alerts nativos por toasts
     * Para ativar, adicione data-use-toast="true" no elemento pai
     */
    $(document).ready(function() {
        // Interceptar confirm() também
        window.SisturConfirm = function(message, callback) {
            var confirmed = confirm(message);
            if (callback) {
                callback(confirmed);
            }
            return confirmed;
        };
    });

})(jQuery);

/**
 * Exemplos de uso:
 *
 * // Toast simples
 * SisturToast.success('Registro salvo com sucesso!');
 *
 * // Toast com título customizado
 * SisturToast.error('Não foi possível conectar ao servidor.', 'Erro de Conexão');
 *
 * // Toast completo com opções
 * SisturToast.show({
 *     type: 'warning',
 *     title: 'Atenção',
 *     message: 'Você tem alterações não salvas.',
 *     duration: 0,  // Não fecha automaticamente
 *     closable: true
 * });
 *
 * // Fechar toast específico
 * var toastId = SisturToast.info('Processando...');
 * setTimeout(function() {
 *     SisturToast.hide(toastId);
 * }, 2000);
 */
