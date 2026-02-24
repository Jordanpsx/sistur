/**
 * Jest Configuration para SISTUR
 *
 * @package SISTUR
 */

module.exports = {
    // Diretório de testes
    testMatch: ['**/tests/js/**/*.test.js'],

    // Ambiente de execução
    testEnvironment: 'jsdom',

    // Setup files
    setupFilesAfterEnv: ['<rootDir>/tests/js/setup.js'],

    // Coverage
    collectCoverageFrom: [
        'assets/js/**/*.js',
        '!assets/js/**/*.min.js',
        '!node_modules/**'
    ],

    // Coverage reporters
    coverageReporters: ['text', 'lcov', 'html'],

    // Coverage directory
    coverageDirectory: '<rootDir>/coverage',

    // Verbose output
    verbose: true,

    // Transform
    transform: {
        '^.+\\.js$': 'babel-jest'
    },

    // Module name mapper (para importações de estilos/assets)
    moduleNameMapper: {
        '\\.(css|less|scss|sass)$': 'identity-obj-proxy',
        '\\.(jpg|jpeg|png|gif|svg)$': '<rootDir>/tests/js/__mocks__/fileMock.js'
    }
};
