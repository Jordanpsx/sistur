# 🔧 Resolução do Erro RCG - Guia Completo

## 📋 Problema

Você está vendo este erro:
```
RCG Fatal Error: Uncaught TypeError: call_user_func_array():
Argument #1 ($callback) must be a valid callback,
function "rcg_ajax_check_database" not found
```

## 🎯 Causa Raiz

Este erro ocorre porque:

1. **Um plugin chamado "RCG"** (provavelmente um plugin de cache, debug ou otimização) foi instalado anteriormente
2. **O plugin foi removido/desativado** mas deixou "hooks órfãos" registrados
3. **Esses hooks são disparados** quando você faz qualquer ação AJAX no WordPress
4. **O WordPress tenta chamar uma função** que não existe mais
5. **Resultado:** Erro fatal que bloqueia **TODAS** as requisições AJAX, incluindo o cadastro de funcionários

## 🚨 Por Que Isso Afeta o Cadastro de Funcionários?

O cadastro de funcionários usa **AJAX** para enviar dados ao servidor. Quando você clica em "Salvar":

```
1. Navegador → Envia dados via AJAX → admin-ajax.php
2. WordPress → Processa hooks registrados
3. WordPress → Encontra hook órfão "rcg_ajax_check_database"
4. WordPress → Tenta chamar função que não existe
5. ❌ ERRO FATAL → Nada funciona
```

**O erro RCG acontece ANTES do código SISTUR ser executado!**

## ✅ Soluções (em ordem de eficácia)

### 🥇 Solução 1: Plugin Must-Use (Recomendado)

**O que é:** Um plugin que carrega ANTES de todos os outros e remove o hook órfão.

**Como implementar:**

1. **Baixe o arquivo** `mu-plugins/sistur-fix-rcg-error.php` do repositório
2. **Faça upload via FTP/cPanel** para:
   ```
   /wp-content/mu-plugins/sistur-fix-rcg-error.php
   ```
3. **Se a pasta mu-plugins não existe**, crie ela:
   ```
   /wp-content/mu-plugins/
   ```
4. **Pronto!** O plugin carrega automaticamente (não aparece na lista de plugins)

**Verificar se funcionou:**
- O erro RCG deve parar imediatamente
- Cadastro de funcionários deve funcionar
- Veja nos logs: `SISTUR FIX: Hook órfão removido`

**Remover depois:**
- Quando o erro parar completamente, delete o arquivo `sistur-fix-rcg-error.php`

---

### 🥈 Solução 2: Via Diagnóstico SISTUR

**Como usar:**

1. Acesse **SISTUR → 🔧 Diagnóstico**
2. Encontre a seção **"🚨 Ação Crítica Necessária"**
3. Clique em **"🔧 LIMPAR HOOKS ÓRFÃOS"**
4. Confirme a ação
5. **IMPORTANTE:** Recarregue a página algumas vezes

**Limitação:**
- Pode não funcionar se o hook for registrado em tempo real pelo tema
- Pode precisar clicar várias vezes

---

### 🥉 Solução 3: SQL Manual

**Via phpMyAdmin:**

```sql
-- Ver todas as opções RCG
SELECT * FROM wp_options
WHERE option_name LIKE '%rcg%';

-- Deletar todas as opções RCG (FAÇA BACKUP ANTES!)
DELETE FROM wp_options
WHERE option_name LIKE '%rcg%';

-- Limpar transients órfãos
DELETE FROM wp_options
WHERE option_name LIKE '_transient_%'
  AND option_name NOT LIKE '%sistur%';
```

---

### 🔍 Solução 4: Encontrar a Fonte

**Onde o hook pode estar sendo registrado:**

1. **Plugins órfãos:**
   ```bash
   cd wp-content/plugins/
   grep -r "rcg_ajax_check_database" .
   ```

2. **Tema:**
   ```bash
   cd wp-content/themes/[seu-tema]/
   grep -r "rcg_" .
   ```

3. **Must-Use Plugins:**
   ```bash
   cd wp-content/mu-plugins/
   ls -la
   # Veja se há algum arquivo suspeito
   ```

4. **Procurar por "RCG" em geral:**
   ```bash
   cd wp-content/
   find . -name "*rcg*" -o -name "*RCG*"
   ```

---

## 🔬 Debug Avançado

### Ativar Log de Erros

Adicione ao `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Logs ficam em: `wp-content/debug.log`

### Ver Hooks Registrados

Adicione temporariamente ao `functions.php` do tema:

```php
add_action('admin_init', function() {
    global $wp_filter;

    // Procurar hooks RCG
    foreach ($wp_filter as $hook_name => $hook) {
        if (strpos($hook_name, 'rcg_') !== false) {
            error_log("Hook RCG encontrado: {$hook_name}");
            error_log("Callbacks: " . print_r($hook->callbacks, true));
        }
    }
});
```

---

## 📊 Diagnóstico Via WordPress

### Testar AJAX Manualmente

Crie um arquivo `test-ajax.php` na raiz do WordPress:

```php
<?php
require_once('wp-load.php');

// Simular requisição AJAX
$_REQUEST['action'] = 'save_employee';

// Tentar processar
do_action('wp_ajax_save_employee');

echo "AJAX funcionou!\n";
```

Execute: `php test-ajax.php`

Se mostrar o erro RCG, você confirmou o problema.

---

## 🎯 Solução Definitiva (Prevenção)

### Sempre que remover um plugin:

1. **Não apenas desative**, sempre **DELETE**
2. **Verifique** se deixou arquivos para trás:
   ```bash
   ls -la wp-content/plugins/
   ```
3. **Limpe as opções** do banco:
   ```sql
   DELETE FROM wp_options
   WHERE option_name LIKE '%nome_do_plugin%';
   ```
4. **Use** um plugin de limpeza como:
   - Advanced Database Cleaner
   - WP-Optimize
   - WP Reset

---

## ✅ Como Saber Se Foi Resolvido?

### Teste 1: Erro RCG Sumiu
```
Antes: RCG Fatal Error: rcg_ajax_check_database...
Depois: Nenhum erro RCG nos logs
```

### Teste 2: AJAX Funciona
1. Abra **Console do Navegador** (F12)
2. Vá em **SISTUR → Funcionários**
3. Clique em **"Adicionar Funcionário"**
4. Preencha o formulário e salve
5. Veja no Console se há erros

### Teste 3: Cadastro Funciona
```
✅ Modal abre
✅ Preenche campos
✅ Clica em "Salvar"
✅ Vê mensagem "Funcionário criado com sucesso!"
✅ Página recarrega
✅ Funcionário aparece na lista
```

---

## 📝 Checklist de Resolução

- [ ] Erro RCG identificado nos logs
- [ ] Plugin Must-Use instalado em `mu-plugins/`
- [ ] Erro RCG parou de aparecer
- [ ] AJAX funciona (teste com console F12)
- [ ] Cadastro de funcionário funciona
- [ ] Diagnóstico SISTUR mostra tudo OK
- [ ] Plugin Must-Use removido (se não precisar mais)

---

## 🆘 Se Nada Funcionar

### Opção Nuclear (FAÇA BACKUP PRIMEIRO!)

1. **Backup completo** do site e banco de dados
2. **Desative todos os plugins** exceto SISTUR
3. **Mude para tema padrão** (Twenty Twenty-Four)
4. **Teste se funciona**
5. **Reative plugins um por um** até achar o culpado
6. **Volte ao tema original**

---

## 📞 Suporte

Se mesmo assim não resolver:

1. **Logs relevantes:**
   - `wp-content/debug.log`
   - Logs do servidor (error_log)

2. **Informações do sistema:**
   - SISTUR → Diagnóstico (screenshot)
   - Lista de plugins ativos
   - Tema utilizado

3. **Criar issue** no GitHub com estas informações

---

## 💡 Resumo Rápido

```
PROBLEMA: Hook órfão bloqueando AJAX
CAUSA: Plugin RCG removido incorretamente
SOLUÇÃO RÁPIDA: Instalar plugin Must-Use
SOLUÇÃO PERMANENTE: Limpar banco de dados
RESULTADO: Cadastro funciona normalmente
```

---

**Atualizado:** 2025-11-24
**Versão:** 1.0
**Status:** Testado e Funcionando ✅
