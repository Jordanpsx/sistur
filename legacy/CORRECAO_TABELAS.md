# Correção de Erros no Dashboard do SISTUR

## Problemas Identificados

O dashboard do SISTUR apresentava os seguintes erros:

### 1. ✅ Erro: "Unknown column 'entry_time' in 'where clause'" - **CORRIGIDO**

**Causa**: Query incorreta no arquivo `admin/views/dashboard.php:48` usando nomes de colunas errados.

**Correção Aplicada**:
- `entry_time` → `punch_time`
- `entry_type` → `punch_type`
- `'clock_in'` → `'in'`

**Status**: ✅ Corrigido automaticamente no código

---

### 2. ⚠️ Erro: "Table 'wp_sistur_products' doesn't exist" - **REQUER AÇÃO**

**Causa**: Tabelas `wp_sistur_products` e `wp_sistur_settings` não foram criadas corretamente ou possuem índices duplicados.

**Correção Disponível**: Script de migração criado: `sistur-fix-tables.php`

---

## Como Executar a Correção

### Opção 1: Via Browser (Recomendado)

1. Acesse a URL do script via browser:
   ```
   https://seu-site.com/wp-content/plugins/sistur/sistur-fix-tables.php
   ```

2. O script irá:
   - ✓ Verificar se as tabelas existem
   - ✓ Corrigir índices duplicados
   - ✓ Criar tabelas ausentes
   - ✓ Inserir configurações padrão

3. Após a execução bem-sucedida, **DELETE o arquivo por segurança**:
   ```bash
   rm wp-content/plugins/sistur/sistur-fix-tables.php
   ```

### Opção 2: Via WP-CLI

```bash
wp eval-file wp-content/plugins/sistur/sistur-fix-tables.php
```

### Opção 3: Via SSH/Terminal

```bash
cd /caminho/para/wordpress
php wp-content/plugins/sistur/sistur-fix-tables.php
```

---

## O Que o Script Faz?

### Para `wp_sistur_products`:

1. Verifica se a tabela existe
2. Se existir com problemas:
   - Remove índices duplicados `sku`
   - Recria o índice corretamente
3. Se não existir:
   - Cria a tabela completa com estrutura correta

### Para `wp_sistur_settings`:

1. Verifica se a tabela existe
2. Se existir com problemas:
   - Remove índices duplicados `setting_key`
   - Recria o índice corretamente
3. Se não existir:
   - Cria a tabela completa
   - Insere 6 configurações padrão:
     - `tolerance_minutes_per_punch` (5 minutos)
     - `tolerance_type` (PER_PUNCH)
     - `cron_secret_key` (chave aleatória)
     - `auto_processing_enabled` (1)
     - `processing_batch_size` (50)
     - `processing_time` (01:00)

---

## Resultado Esperado

Após executar o script, você verá:

```
=== RESUMO ===

✓ SUCESSOS:
  - Tabela wp_sistur_products corrigida com sucesso!
  - Tabela wp_sistur_settings criada com sucesso!

✓✓✓ CORREÇÃO CONCLUÍDA COM SUCESSO! ✓✓✓

Você pode agora:
1. Acessar o Dashboard do SISTUR sem erros
2. Deletar este arquivo (sistur-fix-tables.php) por segurança
```

---

## Verificação Pós-Correção

Após executar o script, acesse o Dashboard do SISTUR:

```
WordPress Admin → SISTUR → Dashboard
```

**Você NÃO deve ver mais os seguintes erros**:
- ❌ "Unknown column 'entry_time' in 'where clause'"
- ❌ "Table 'wp_sistur_products' doesn't exist"

**O dashboard deve exibir**:
- ✅ Contagem de funcionários ativos
- ✅ Registros de ponto hoje
- ✅ Total de leads
- ✅ Produtos ativos no estoque
- ✅ Gráficos funcionando corretamente

---

## Problemas?

Se após executar o script ainda houver erros:

1. **Verifique as permissões do usuário do WordPress**:
   - O usuário logado deve ter capacidade `manage_options`

2. **Verifique os logs do MySQL**:
   ```bash
   tail -f /var/log/mysql/error.log
   ```

3. **Execute manualmente no phpMyAdmin**:
   - Acesse phpMyAdmin
   - Selecione o banco de dados do WordPress
   - Execute as queries SQL que estão no script

4. **Entre em contato com o suporte** informando:
   - Mensagens de erro específicas
   - Versão do WordPress
   - Versão do MySQL/MariaDB
   - Logs completos da execução do script

---

## Arquivos Alterados

- `admin/views/dashboard.php` - Correção da query de registros de ponto
- `sistur-fix-tables.php` - Script de correção (REMOVER após uso)
- `CORRECAO_TABELAS.md` - Esta documentação

---

**Última atualização**: 2025-11-17
**Versão do SISTUR**: 1.4.1
**Commit**: 4a2f9a4
