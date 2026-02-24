# 🔧 Instruções para Ativar Debug no WordPress

## Passo 1: Editar wp-config.php

Localize esta linha (por volta da linha 93):

```php
define( 'WP_DEBUG', false );
```

**Comente ou remova ela**, deixando assim:

```php
// define( 'WP_DEBUG', false );  // Comentado
```

As definições no final do arquivo já estão corretas:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', true);
```

## Passo 2: Salvar e Recarregar

1. Salve o arquivo `wp-config.php`
2. Vá para o WordPress admin
3. **Desative e reative o plugin SISTUR** (importante!)
4. Vá em **SISTUR → 🔧 Diagnóstico**

## Passo 3: Testar Criação de Rede Wi-Fi

1. Abra o **Console do Navegador** (pressione F12)
2. Vá para a aba **Console**
3. Tente **criar uma rede Wi-Fi**
4. Observe as mensagens no console

## O Que Você Deve Ver no Console:

### ✅ Mensagens Esperadas (tudo funcionando):

```
[SISTUR Share Modal v1.4.1] Script carregado {timestamp: "2025-11-17...", readyState: "complete", url: "..."}
[SISTUR Share Modal v1.4.1] DOM já pronto, executando safeInit()...
[SISTUR Share Modal v1.4.1] safeInit() chamado
[SISTUR Share Modal v1.4.1] init() iniciado
[SISTUR Share Modal v1.4.1] Elementos encontrados: {shareButtons: 0, shareModal: "não", shareModalType: "null"}
[SISTUR Share Modal v1.4.1] Saindo: elementos não encontrados ou inexistentes
```

Isso é **NORMAL** e significa que o script está funcionando corretamente!

### ❌ Mensagens de Erro (problema encontrado):

Se você ver algo como:

```
Uncaught TypeError: Cannot read properties of null (reading 'addEventListener')
    at share-modal.js:1:135
```

Isso significa que o **cache ainda não foi limpo** e o navegador está usando o arquivo antigo.

## Passo 4: Limpar TODOS os Caches

### A) No WordPress:
1. Vá em **SISTUR → 🔧 Diagnóstico**
2. Clique no botão **"🗑️ Limpar Cache de Scripts"**

### B) No Navegador:
**Método 1 (Recomendado):**
- Windows/Linux: Pressione **Ctrl + Shift + Delete**
- Mac: Pressione **Cmd + Shift + Delete**
- Marque "Imagens e arquivos em cache"
- Clique em "Limpar dados"

**Método 2 (Hard Refresh):**
- Windows/Linux: **Ctrl + F5** ou **Ctrl + Shift + R**
- Mac: **Cmd + Shift + R**

### C) Plugin de Cache (se houver):
Se você usa algum plugin de cache (WP Super Cache, W3 Total Cache, etc.):
1. Vá no painel do plugin
2. Clique em "Limpar Cache" / "Clear Cache"

## Passo 5: Enviar Informações

Após seguir todos os passos, me envie:

1. **Todas as mensagens** do console que começam com `[SISTUR Share Modal v1.4.1]`
2. **Qualquer erro em vermelho** que aparecer
3. **Screenshot da página "🔧 Diagnóstico"** (opcional, mas ajuda muito)

## Troubleshooting

### "Ainda vejo o erro na linha 1"
- Isso significa que o cache não foi limpo
- Tente fechar completamente o navegador e abrir novamente
- Ou teste em modo anônimo/privado do navegador

### "Não vejo nenhuma mensagem [SISTUR Share Modal]"
- Verifique se está na aba Console do DevTools (F12)
- Verifique se desativou/reativou o plugin SISTUR
- Verifique se WP_DEBUG está true no wp-config.php

### "O plugin não aparece no menu"
- Desative e reative o plugin SISTUR
- Verifique se tem permissão de administrador
- Verifique o arquivo debug.log em wp-content/debug.log

---

**Dica:** Se nenhuma dessas soluções funcionar, teste em um **navegador diferente** (ex: se usa Chrome, teste no Firefox) para descartar problemas de cache persistente.
