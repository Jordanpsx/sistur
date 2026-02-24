# Sincronização de Funcionários com Usuários WordPress

## Problema Resolvido

Quando um administrador registrava manualmente um usuário no painel WordPress, esse usuário **não era automaticamente criado como funcionário** na tabela `wp_sistur_employees`. Isso causava o seguinte erro ao tentar bater ponto:

```
WordPress database error Cannot add or update a child row:
a foreign key constraint fails (`wp_sistur_time_entries`,
CONSTRAINT `fk_time_entries_employee` FOREIGN KEY (`employee_id`)
REFERENCES `wp_sistur_employees` (`id`) ON DELETE CASCADE)
```

## Solução Implementada

### 1. Coluna `user_id` na Tabela de Funcionários

Foi adicionada uma nova coluna `user_id` na tabela `wp_sistur_employees` que vincula cada funcionário SISTUR a um usuário WordPress:

```sql
ALTER TABLE wp_sistur_employees
ADD COLUMN user_id bigint(20) DEFAULT NULL AFTER id,
ADD UNIQUE KEY user_id (user_id);
```

### 2. Hook Automático de Sincronização

Dois hooks do WordPress foram adicionados para sincronização automática:

- `user_register`: Quando um novo usuário é criado
- `profile_update`: Quando um usuário existente é atualizado

**Como funciona:**

1. Quando um administrador cria/atualiza um usuário WordPress
2. O sistema verifica se o usuário tem uma role adequada (`employee`, `subscriber`, `administrator`)
3. Se não existir um funcionário vinculado, cria automaticamente:
   - Registro na tabela `wp_sistur_employees`
   - Token UUID para o funcionário
   - QR Code para registro de ponto
4. Vincula o funcionário ao usuário WordPress via `user_id`

### 3. Script de Migração Manual

Para sincronizar funcionários já existentes, foi criado um script web:

**Localização:** `/wp-content/plugins/sistur2/sync-employees-wordpress.php`

## Como Usar

### Para Novos Funcionários (Automático)

Basta criar o usuário normalmente no WordPress:

1. Acessar **Usuários > Adicionar Novo** no painel WordPress
2. Preencher os dados do usuário
3. Selecionar uma role apropriada (`Funcionário`, `Assinante`, etc.)
4. Clicar em **Adicionar Novo Usuário**

O sistema **automaticamente**:
- Criará o registro na tabela de funcionários
- Gerará o token UUID
- Criará o QR Code
- Vinculará o usuário WordPress ao funcionário

### Para Funcionários Existentes (Migração)

Se você já tem funcionários ou usuários que precisam ser sincronizados:

1. **Acessar o script de sincronização:**
   - URL: `http://seu-site.com/wp-content/plugins/sistur2/sync-employees-wordpress.php`
   - Ou: Acessar diretamente pelo navegador

2. **O script executará automaticamente:**
   - ✅ Verificação da coluna `user_id`
   - ✅ Adição da coluna se não existir
   - ✅ Sincronização de funcionários com emails correspondentes
   - ✅ Criação de funcionários para usuários WordPress sem registro
   - ✅ Geração de tokens e QR codes faltantes

3. **Resultado:**
   - Tabela com todos os funcionários e status de sincronização
   - Resumo com totais de sincronizações
   - Links para voltar ao painel

## Estrutura Técnica

### Arquivos Modificados

1. **`includes/class-sistur-employees.php`**
   - Adicionado método `sync_wordpress_user_to_employee()`
   - Hooks `user_register` e `profile_update`

2. **`includes/class-sistur-activator.php`**
   - Migração para adicionar coluna `user_id`
   - Método `sync_existing_employees_with_wordpress_users()`

3. **`sync-employees-wordpress.php`** (novo)
   - Interface web para sincronização manual
   - Relatórios detalhados

### Fluxo de Dados

```
Usuário WordPress (wp_users)
         ↓ user_id
         ↓
Funcionário SISTUR (wp_sistur_employees)
         ↓ employee_id
         ↓
Registros de Ponto (wp_sistur_time_entries)
```

## Verificação

Para verificar se a sincronização funcionou:

1. **Via Banco de Dados:**
```sql
SELECT e.id, e.name, e.email, e.user_id, u.user_login
FROM wp_sistur_employees e
LEFT JOIN wp_users u ON e.user_id = u.ID
WHERE e.user_id IS NOT NULL;
```

2. **Via Painel WordPress:**
   - Acessar **SISTUR > Funcionários**
   - Verificar se os funcionários aparecem listados
   - Verificar se possuem QR codes gerados

## Benefícios

✅ **Sincronização Automática:** Não é mais necessário cadastrar funcionários em dois lugares

✅ **Integridade de Dados:** Garante que todos os usuários têm registro de funcionário correspondente

✅ **Prevenção de Erros:** Evita o erro de foreign key ao bater ponto

✅ **QR Codes Automáticos:** Gera automaticamente tokens e QR codes para novos funcionários

✅ **Migração Fácil:** Script web intuitivo para sincronizar dados existentes

## Logs

Todas as operações são registradas no log de erros do WordPress:

```
SISTUR: Funcionário ID 5 criado automaticamente para usuário WordPress ID 2
SISTUR: Token UUID e QR Code gerados automaticamente para funcionário ID 5
```

Para ver os logs:
- Ativar `WP_DEBUG` e `WP_DEBUG_LOG` no `wp-config.php`
- Verificar arquivo `wp-content/debug.log`

## Suporte

Em caso de problemas:

1. Execute o script de sincronização manualmente
2. Verifique os logs do WordPress
3. Verifique se a coluna `user_id` foi adicionada
4. Teste criar um novo usuário WordPress manualmente
