/**
 * SISTUR - Diagnóstico de Scripts
 *
 * Script de diagnóstico para rastrear problemas de carregamento
 *
 * @package SISTUR
 * @version 1.4.1
 */

(function() {
    'use strict';

    console.log('====================================');
    console.log('SISTUR DIAGNÓSTICO v1.4.1');
    console.log('====================================');
    console.log('Timestamp:', new Date().toISOString());
    console.log('URL:', window.location.href);
    console.log('User Agent:', navigator.userAgent);
    console.log('Document Ready State:', document.readyState);
    console.log('');

    // Lista todos os scripts carregados
    console.log('Scripts carregados na página:');
    var scripts = document.getElementsByTagName('script');
    for (var i = 0; i < scripts.length; i++) {
        var src = scripts[i].src || '(inline)';
        if (src.indexOf('sistur') !== -1 || src.indexOf('share') !== -1) {
            console.log(' - [' + i + ']', src);
            console.log('   Tipo:', scripts[i].type || 'text/javascript');
            console.log('   Async:', scripts[i].async);
            console.log('   Defer:', scripts[i].defer);
        }
    }
    console.log('');

    // Verifica se jQuery está disponível
    console.log('jQuery disponível:', typeof jQuery !== 'undefined' ? 'sim (v' + jQuery.fn.jquery + ')' : 'não');
    console.log('$ disponível:', typeof $ !== 'undefined' ? 'sim' : 'não');
    console.log('');

    // Verifica objetos globais SISTUR
    console.log('Objetos globais SISTUR:');
    console.log(' - sisturAdmin:', typeof sisturAdmin !== 'undefined' ? 'definido' : 'não definido');
    console.log(' - sisturPublic:', typeof sisturPublic !== 'undefined' ? 'definido' : 'não definido');
    console.log(' - SisturShareModal:', typeof window.SisturShareModal !== 'undefined' ? 'definido' : 'não definido');
    console.log('');

    // Verifica elementos na página
    console.log('Elementos na página:');
    console.log(' - [data-share-modal]:', document.querySelectorAll('[data-share-modal]').length);
    console.log(' - #share-modal:', document.getElementById('share-modal') ? 'existe' : 'não existe');
    console.log('');

    // Verifica erros de console
    var originalError = console.error;
    var errorCount = 0;
    console.error = function() {
        errorCount++;
        originalError.apply(console, arguments);
    };

    // Monitora carregamento do DOM
    document.addEventListener('DOMContentLoaded', function() {
        console.log('DOMContentLoaded disparado em:', new Date().toISOString());
        console.log('Erros capturados até agora:', errorCount);
    });

    window.addEventListener('load', function() {
        console.log('Window load completo em:', new Date().toISOString());
        console.log('Total de erros capturados:', errorCount);
        console.log('====================================');
    });

    // Captura erros globais
    window.addEventListener('error', function(e) {
        if (e.filename && e.filename.indexOf('share-modal') !== -1) {
            console.error('ERRO DETECTADO em share-modal.js:', {
                message: e.message,
                filename: e.filename,
                lineno: e.lineno,
                colno: e.colno,
                stack: e.error ? e.error.stack : 'não disponível'
            });
        }
    }, true);

})();
