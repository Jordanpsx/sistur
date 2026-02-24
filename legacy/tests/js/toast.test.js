/**
 * Testes para SisturToast
 *
 * @package SISTUR
 */

describe('SisturToast', () => {
    let SisturToast;

    beforeEach(() => {
        // Limpar container anterior
        document.body.innerHTML = '';

        // Carregar o módulo SisturToast
        // Nota: Você precisará adaptar o caminho conforme sua estrutura
        const toastCode = `
            var SisturToast = (function() {
                var container = null;

                function init() {
                    if (!container) {
                        container = document.createElement('div');
                        container.className = 'sistur-toast-container';
                        document.body.appendChild(container);
                    }
                }

                function show(message, type, duration) {
                    init();
                    duration = duration || 3000;

                    var toast = document.createElement('div');
                    toast.className = 'sistur-toast sistur-toast-' + type;
                    toast.textContent = message;

                    container.appendChild(toast);

                    setTimeout(function() {
                        toast.classList.add('sistur-toast-show');
                    }, 10);

                    setTimeout(function() {
                        toast.classList.remove('sistur-toast-show');
                        setTimeout(function() {
                            if (toast.parentNode) {
                                toast.parentNode.removeChild(toast);
                            }
                        }, 300);
                    }, duration);

                    return toast;
                }

                return {
                    success: function(message, duration) {
                        return show(message, 'success', duration);
                    },
                    error: function(message, duration) {
                        return show(message, 'error', duration);
                    },
                    warning: function(message, duration) {
                        return show(message, 'warning', duration);
                    },
                    info: function(message, duration) {
                        return show(message, 'info', duration);
                    }
                };
            })();
        `;

        eval(toastCode);
        SisturToast = global.SisturToast;
    });

    test('deve criar container ao exibir toast', () => {
        SisturToast.success('Teste');

        const container = document.querySelector('.sistur-toast-container');
        expect(container).toBeTruthy();
    });

    test('deve criar toast de sucesso', () => {
        SisturToast.success('Mensagem de sucesso');

        const toast = document.querySelector('.sistur-toast-success');
        expect(toast).toBeTruthy();
        expect(toast.textContent).toBe('Mensagem de sucesso');
    });

    test('deve criar toast de erro', () => {
        SisturToast.error('Mensagem de erro');

        const toast = document.querySelector('.sistur-toast-error');
        expect(toast).toBeTruthy();
        expect(toast.textContent).toBe('Mensagem de erro');
    });

    test('deve criar toast de aviso', () => {
        SisturToast.warning('Mensagem de aviso');

        const toast = document.querySelector('.sistur-toast-warning');
        expect(toast).toBeTruthy();
        expect(toast.textContent).toBe('Mensagem de aviso');
    });

    test('deve criar toast de informação', () => {
        SisturToast.info('Mensagem de informação');

        const toast = document.querySelector('.sistur-toast-info');
        expect(toast).toBeTruthy();
        expect(toast.textContent).toBe('Mensagem de informação');
    });

    test('deve permitir múltiplos toasts', () => {
        SisturToast.success('Toast 1');
        SisturToast.error('Toast 2');
        SisturToast.warning('Toast 3');

        const toasts = document.querySelectorAll('.sistur-toast');
        expect(toasts.length).toBe(3);
    });

    test('deve remover toast após duração', (done) => {
        SisturToast.success('Toast temporário', 500);

        // Verificar que existe
        expect(document.querySelector('.sistur-toast')).toBeTruthy();

        // Verificar que foi removido após duração
        setTimeout(() => {
            expect(document.querySelector('.sistur-toast')).toBeFalsy();
            done();
        }, 1000);
    });
});
