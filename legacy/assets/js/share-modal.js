/**
 * SISTUR - Share Modal
 *
 * Script para gerenciar modal de compartilhamento
 * Usa código defensivo para evitar erros quando elementos não existem
 *
 * @package SISTUR
 * @version 1.4.1-debug
 */

(function() {
    'use strict';

    // Sistema de Debug
    var DEBUG = true; // Mude para false em produção
    var debugLog = function(message, data) {
        if (DEBUG && console && console.log) {
            console.log('[SISTUR Share Modal v1.4.1]', message, data || '');
        }
    };

    debugLog('Script carregado', {
        timestamp: new Date().toISOString(),
        readyState: document.readyState,
        url: window.location.href
    });

    // Aguarda o DOM estar pronto
    if (document.readyState === 'loading') {
        debugLog('DOM ainda carregando, aguardando DOMContentLoaded...');
        document.addEventListener('DOMContentLoaded', safeInit);
    } else {
        debugLog('DOM já pronto, executando safeInit()...');
        safeInit();
    }

    function safeInit() {
        debugLog('safeInit() chamado');
        try {
            init();
        } catch (error) {
            debugLog('ERRO capturado em safeInit():', {
                message: error.message,
                stack: error.stack,
                line: error.lineNumber || 'unknown'
            });
            console.error('[SISTUR Share Modal] Erro:', error);
        }
    }

    function init() {
        debugLog('init() iniciado');

        // Verifica se existem elementos de share modal na página
        var shareButtons = document.querySelectorAll('[data-share-modal]');
        var shareModal = document.getElementById('share-modal');

        debugLog('Elementos encontrados:', {
            shareButtons: shareButtons ? shareButtons.length : 0,
            shareModal: shareModal ? 'sim' : 'não',
            shareModalType: shareModal ? typeof shareModal : 'null'
        });

        // Valida explicitamente que os elementos existem e são válidos
        if (!shareModal || shareButtons.length === 0) {
            debugLog('Saindo: elementos não encontrados ou inexistentes');
            return; // Sai silenciosamente se elementos não existirem
        }

        debugLog('Adicionando event listeners aos botões...');

        // Só adiciona event listeners se os elementos existirem
        if (shareButtons.length > 0 && shareModal) {
            shareButtons.forEach(function(button, index) {
                debugLog('Processando botão ' + index, {
                    button: button ? 'existe' : 'null',
                    hasAddEventListener: button && typeof button.addEventListener === 'function'
                });

                if (button && typeof button.addEventListener === 'function') {
                    button.addEventListener('click', function(e) {
                        e.preventDefault();
                        debugLog('Botão clicado, abrindo modal');
                        openShareModal(this);
                    });
                    debugLog('Event listener adicionado ao botão ' + index);
                }
            });

            // Fechar modal ao clicar no X ou fora dele
            var closeButton = shareModal.querySelector('[data-close-modal]');
            debugLog('Botão de fechar encontrado:', closeButton ? 'sim' : 'não');

            if (closeButton && typeof closeButton.addEventListener === 'function') {
                closeButton.addEventListener('click', closeShareModal);
                debugLog('Event listener adicionado ao botão de fechar');
            }

            if (typeof shareModal.addEventListener === 'function') {
                shareModal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        debugLog('Clique fora do modal, fechando');
                        closeShareModal();
                    }
                });
                debugLog('Event listener adicionado ao modal (fechar ao clicar fora)');
            }
        }

        debugLog('init() finalizado com sucesso');
    }

    function openShareModal(button) {
        const shareModal = document.getElementById('share-modal');
        if (!shareModal) return;

        // Pega dados do botão (URL, título, etc)
        const shareUrl = button.getAttribute('data-share-url') || window.location.href;
        const shareTitle = button.getAttribute('data-share-title') || document.title;

        // Atualiza o modal com os dados
        const urlInput = shareModal.querySelector('[data-share-url-input]');
        if (urlInput) {
            urlInput.value = shareUrl;
        }

        const titleElement = shareModal.querySelector('[data-share-title-display]');
        if (titleElement) {
            titleElement.textContent = shareTitle;
        }

        // Mostra o modal
        shareModal.style.display = 'flex';
        shareModal.setAttribute('aria-hidden', 'false');
    }

    function closeShareModal() {
        const shareModal = document.getElementById('share-modal');
        if (!shareModal) return;

        shareModal.style.display = 'none';
        shareModal.setAttribute('aria-hidden', 'true');
    }

    // Expõe funções globalmente se necessário
    window.SisturShareModal = {
        open: openShareModal,
        close: closeShareModal
    };
})();
