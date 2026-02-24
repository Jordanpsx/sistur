/**
 * Jest Setup File
 * Configurações globais para testes JavaScript
 *
 * @package SISTUR
 */

// Mock do jQuery se necessário
global.jQuery = require('jquery');
global.$ = global.jQuery;

// Mock do objeto wp (WordPress)
global.wp = {
    i18n: {
        __: (text) => text,
        _x: (text) => text,
        _n: (single, plural, number) => number === 1 ? single : plural
    }
};

// Mock do ajaxurl (WordPress AJAX URL)
global.ajaxurl = '/wp-admin/admin-ajax.php';

// Limpar mocks após cada teste
afterEach(() => {
    jest.clearAllMocks();
});
