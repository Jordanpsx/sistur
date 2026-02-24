# SISTUR - Testes Automatizados

Estrutura de testes para o plugin SISTUR, incluindo testes PHP (PHPUnit) e JavaScript (Jest).

## 📁 Estrutura

```
tests/
├── php/                    # Testes PHP (PHPUnit)
│   ├── bootstrap.php       # Bootstrap para PHPUnit
│   └── test-*.php          # Arquivos de teste
├── js/                     # Testes JavaScript (Jest)
│   ├── setup.js            # Setup para Jest
│   └── *.test.js           # Arquivos de teste
└── README.md               # Este arquivo
```

## 🧪 Testes PHP (PHPUnit)

### Pré-requisitos

1. **PHPUnit** (versão 9.0 ou superior)
2. **WordPress Test Suite** instalado

### Instalação do WordPress Test Suite

```bash
# 1. Criar diretório para test suite
mkdir -p /tmp/wordpress-tests-lib

# 2. Baixar test suite
svn co https://develop.svn.wordpress.org/trunk/ /tmp/wordpress-tests-lib

# 3. Ou usar o script install-wp-tests.sh
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest
```

### Configuração

Defina a variável de ambiente `WP_TESTS_DIR`:

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib
```

### Executar Testes PHP

```bash
# Executar todos os testes
vendor/bin/phpunit

# Executar teste específico
vendor/bin/phpunit tests/php/test-permissions.php

# Executar com coverage (requer Xdebug)
vendor/bin/phpunit --coverage-html coverage/
```

### Instalar PHPUnit (via Composer)

```bash
composer require --dev phpunit/phpunit ^9.0
```

## 🎭 Testes JavaScript (Jest)

### Pré-requisitos

1. **Node.js** (versão 14 ou superior)
2. **npm** ou **yarn**

### Instalação

```bash
# Instalar dependências
npm install --save-dev jest @babel/core @babel/preset-env babel-jest jest-environment-jsdom identity-obj-proxy jquery

# Ou com yarn
yarn add --dev jest @babel/core @babel/preset-env babel-jest jest-environment-jsdom identity-obj-proxy jquery
```

### Configuração do Babel

Crie o arquivo `.babelrc` na raiz do projeto:

```json
{
  "presets": [
    ["@babel/preset-env", {
      "targets": {
        "node": "current"
      }
    }]
  ]
}
```

### Executar Testes JavaScript

```bash
# Executar todos os testes
npm test

# Executar em modo watch
npm test -- --watch

# Executar com coverage
npm test -- --coverage

# Executar teste específico
npm test -- toast.test.js
```

### Adicionar Scripts ao package.json

```json
{
  "scripts": {
    "test": "jest",
    "test:watch": "jest --watch",
    "test:coverage": "jest --coverage"
  }
}
```

## 📝 Escrevendo Novos Testes

### Exemplo de Teste PHP

```php
<?php
class Test_Minha_Funcionalidade extends WP_UnitTestCase {

    public function setUp(): void {
        parent::setUp();
        // Setup antes de cada teste
    }

    public function tearDown(): void {
        parent::tearDown();
        // Cleanup após cada teste
    }

    public function test_algo_funciona() {
        $resultado = minha_funcao();
        $this->assertEquals('esperado', $resultado);
    }
}
```

### Exemplo de Teste JavaScript

```javascript
describe('Minha Funcionalidade', () => {
    beforeEach(() => {
        // Setup antes de cada teste
    });

    afterEach(() => {
        // Cleanup após cada teste
    });

    test('deve fazer algo', () => {
        const resultado = minhaFuncao();
        expect(resultado).toBe('esperado');
    });
});
```

## 🎯 Metas de Coverage

- **PHP**: Mínimo 70% de cobertura de código
- **JavaScript**: Mínimo 80% de cobertura de código

## 🔧 Debugging

### PHPUnit

```bash
# Modo verbose
vendor/bin/phpunit --verbose

# Parar no primeiro erro
vendor/bin/phpunit --stop-on-failure

# Debug com var_dump
vendor/bin/phpunit --debug
```

### Jest

```bash
# Modo verbose
npm test -- --verbose

# Debug com Node Inspector
node --inspect-brk node_modules/.bin/jest --runInBand
```

## 📚 Recursos

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [WordPress PHPUnit Testing](https://make.wordpress.org/core/handbook/testing/automated-testing/phpunit/)
- [Jest Documentation](https://jestjs.io/docs/getting-started)
- [Testing Best Practices](https://github.com/goldbergyoni/javascript-testing-best-practices)

## ⚡ CI/CD

Para integrar os testes em um pipeline CI/CD:

```yaml
# Exemplo para GitHub Actions
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v2

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.0'

      - name: Install PHP Dependencies
        run: composer install

      - name: Run PHPUnit
        run: vendor/bin/phpunit

      - name: Setup Node
        uses: actions/setup-node@v2
        with:
          node-version: '16'

      - name: Install JS Dependencies
        run: npm install

      - name: Run Jest
        run: npm test
```

## 🐛 Troubleshooting

### Erro: "Could not find WordPress test suite"

Certifique-se de que o WordPress test suite está instalado e a variável `WP_TESTS_DIR` está definida corretamente.

### Erro: "Cannot find module 'jest'"

Execute `npm install` para instalar as dependências JavaScript.

### Erro: "Class not found" no PHPUnit

Verifique se o bootstrap.php está carregando corretamente todos os arquivos necessários.

---

**Desenvolvido por:** SISTUR Development Team
**Versão:** 1.2.0
**Última atualização:** 2025-01-14
