# Sistema de Rastreamento de Alterações Administrativas

## 📋 Visão Geral

Este documento descreve o sistema de auditoria implementado para rastrear todas as alterações feitas por administradores e gerentes no sistema de pontos.

## ✨ Funcionalidades Implementadas

### 1. Campos de Auditoria no Banco de Dados

**Tabela `wp_sistur_time_entries` (Registros de Batidas):**
- `admin_change_reason` (TEXT): Motivo formatado da alteração
- `changed_by_user_id` (BIGINT): ID do usuário que fez a alteração
- `changed_by_role` (VARCHAR): Papel do usuário (administrador/gerente)

**Tabela `wp_sistur_time_days` (Status Diário):**
- `admin_change_reason` (TEXT): Motivo formatado da alteração
- `changed_by_user_id` (BIGINT): ID do usuário que fez a alteração
- `changed_by_role` (VARCHAR): Papel do usuário (administrador/gerente)

### 2. Detecção Automática de Papel

O sistema detecta automaticamente se o usuário é:
- **Administrador**: Usuário com permissão `administrator` do WordPress OU permissão `manage_options`
- **Gerente**: Usuário vinculado a um papel SISTUR que contém "gerente" ou "supervisor" no nome

### 3. Formato da Observação

Todas as alterações são registradas com o seguinte formato:

```
Alterado por [administrador/gerente] devido: [motivo fornecido pelo usuário]
```

**Exemplo:**
```
Alterado por administrador devido: Funcionário esqueceu de registrar ponto
```

### 4. Interface de Usuário

Foi implementada uma modal customizada que:
- Aparece ANTES de qualquer alteração ser salva
- Exige que o usuário forneça um motivo
- Bloqueia a alteração se nenhum motivo for fornecido
- Suporta tecla Enter para confirmar
- Permite cancelamento da operação

### 5. Operações que Exigem Motivo

#### a) Criar Novo Registro de Ponto
- **Endpoint**: `sistur_time_save_entry`
- **Campo obrigatório**: `admin_change_reason`
- **Localização**: `/includes/class-sistur-time-tracking.php:271`

#### b) Atualizar Registro Existente
- **Endpoint**: `sistur_time_update_entry`
- **Campo obrigatório**: `admin_change_reason`
- **Localização**: `/includes/class-sistur-time-tracking.php:352`

#### c) Excluir Registro
- **Endpoint**: `sistur_time_delete_entry`
- **Campo obrigatório**: `admin_change_reason`
- **Localização**: `/includes/class-sistur-time-tracking.php:462`

#### d) Alterar Status do Dia
- **Endpoint**: `sistur_time_day_status`
- **Campo obrigatório**: `admin_change_reason`
- **Localização**: `/includes/class-sistur-time-tracking.php:520`

### 6. Sistema de Auditoria Integrado

Todas as operações também são registradas na tabela `wp_sistur_audit_logs` com:
- Valores antigos (before)
- Valores novos (after)
- Motivo da alteração
- Papel do usuário
- Timestamp
- IP do usuário
- User agent

## 🔧 Como Ativar

1. **Reativar o Plugin** (se já estiver ativo):
   ```bash
   wp plugin deactivate sistur
   wp plugin activate sistur
   ```

   Isso executará automaticamente as migrações do banco de dados.

2. **Ou via WP Admin**:
   - Vá em Plugins
   - Desative SISTUR
   - Ative novamente

As colunas serão adicionadas automaticamente nas tabelas.

## 🧪 Como Testar

### Teste 1: Criar Novo Registro de Ponto

1. Acesse `SISTUR → RH → Registro de Ponto`
2. Selecione um funcionário
3. Escolha tipo, data e hora
4. Clique em "Registrar Ponto"
5. **Deve aparecer a modal** solicitando o motivo
6. Digite um motivo (ex: "Funcionário esqueceu de bater ponto")
7. Clique em "Confirmar"
8. Verifique no banco:

```sql
SELECT admin_change_reason, changed_by_role, changed_by_user_id
FROM wp_sistur_time_entries
ORDER BY id DESC LIMIT 1;
```

**Resultado esperado:**
```
admin_change_reason: "Alterado por administrador devido: Funcionário esqueceu de bater ponto"
changed_by_role: "administrador"
changed_by_user_id: [ID do usuário atual]
```

### Teste 2: Editar Registro Existente

1. Na folha de ponto, clique em um horário
2. Digite um novo horário
3. **Deve aparecer a modal** solicitando o motivo
4. Digite um motivo
5. Confirme
6. Verifique que o registro foi atualizado com o motivo

### Teste 3: Excluir Registro

1. Clique no ícone de lixeira de um registro
2. Confirme a exclusão
3. **Deve aparecer a modal** solicitando o motivo
4. Digite um motivo
5. Verifique o log de auditoria:

```sql
SELECT * FROM wp_sistur_audit_logs
WHERE action = 'delete' AND module = 'time_tracking'
ORDER BY id DESC LIMIT 1;
```

### Teste 4: Alterar Status do Dia

Esta funcionalidade será testada quando houver interface para alterar status diários.

### Teste 5: Cancelamento

1. Tente fazer qualquer alteração
2. Quando a modal aparecer, clique em "Cancelar"
3. **Resultado esperado**: Operação NÃO é executada

### Teste 6: Motivo Vazio

1. Tente fazer qualquer alteração
2. Quando a modal aparecer, deixe o campo vazio
3. Clique em "Confirmar"
4. **Resultado esperado**: Alerta "O motivo é obrigatório!"

## 📊 Consultas Úteis

### Ver todas as alterações administrativas

```sql
SELECT
    e.id,
    e.employee_id,
    e.punch_type,
    e.punch_time,
    e.admin_change_reason,
    e.changed_by_role,
    u.user_login as changed_by_user,
    e.created_at
FROM wp_sistur_time_entries e
LEFT JOIN wp_users u ON e.changed_by_user_id = u.ID
WHERE e.admin_change_reason IS NOT NULL
ORDER BY e.created_at DESC;
```

### Ver log de auditoria completo

```sql
SELECT
    a.*,
    u.user_login
FROM wp_sistur_audit_logs a
LEFT JOIN wp_users u ON a.user_id = u.ID
WHERE a.module = 'time_tracking'
ORDER BY a.created_at DESC
LIMIT 20;
```

### Estatísticas de alterações por usuário

```sql
SELECT
    changed_by_role as papel,
    COUNT(*) as total_alteracoes,
    COUNT(DISTINCT changed_by_user_id) as usuarios_unicos
FROM wp_sistur_time_entries
WHERE admin_change_reason IS NOT NULL
GROUP BY changed_by_role;
```

## 🔒 Segurança

- ✅ Todos os campos são sanitizados com `sanitize_textarea_field()`
- ✅ Nonce verification em todos os endpoints AJAX
- ✅ Verificação de permissões com `current_user_can('manage_options')`
- ✅ Prepared statements para queries SQL
- ✅ XSS protection nas saídas HTML

## 📝 Arquivos Modificados

1. `/includes/class-sistur-activator.php` - Migrações do banco
2. `/includes/class-sistur-time-tracking.php` - Métodos AJAX com validação
3. `/admin/views/time-tracking/admin-page.php` - Interface modal e JavaScript

## 🎯 Benefícios

1. **Rastreabilidade Completa**: Toda alteração tem motivo, autor e timestamp
2. **Conformidade**: Atende requisitos de auditoria e compliance
3. **Transparência**: Funcionários podem ver o motivo das alterações
4. **Prevenção de Fraude**: Dificulta alterações mal-intencionadas
5. **Histórico**: Registro permanente de todas as modificações

## 🚀 Próximos Passos

1. Criar relatório de auditoria na interface admin
2. Notificar funcionário quando seu ponto for alterado
3. Adicionar filtros por período/usuário/tipo de alteração
4. Exportar relatórios de auditoria em PDF/Excel
5. Dashboard com estatísticas de alterações

## ❓ FAQ

**P: O que acontece se o administrador não fornecer motivo?**
R: A alteração é bloqueada e não é salva no banco.

**P: Alterações antigas terão motivo?**
R: Não, apenas alterações feitas após esta implementação.

**P: Funcionários podem ver os motivos?**
R: Atualmente não, mas pode ser implementado facilmente.

**P: O sistema funciona com gerentes?**
R: Sim, detecta automaticamente se o usuário é admin ou gerente.

**P: É possível editar o motivo depois?**
R: Não, o motivo é imutável após ser registrado.

---

**Desenvolvido para aumentar a confiabilidade e transparência do sistema de pontos SISTUR.**
