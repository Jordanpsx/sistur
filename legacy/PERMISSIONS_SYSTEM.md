# Sistema de Permissões Modulares - SISTUR

**Versão:** 1.2.0
**Implementado em:** 2025-01-14

## 📋 Índice

1. [Visão Geral](#visão-geral)
2. [Arquitetura](#arquitetura)
3. [Estrutura do Banco de Dados](#estrutura-do-banco-de-dados)
4. [Papéis Pré-Configurados](#papéis-pré-configurados)
5. [Permissões Disponíveis](#permissões-disponíveis)
6. [API de Uso](#api-de-uso)
7. [Exemplos de Implementação](#exemplos-de-implementação)
8. [Próximos Passos](#próximos-passos)

---

## 🎯 Visão Geral

O sistema de permissões modulares permite controle granular sobre as ações que cada funcionário pode realizar no SISTUR. O sistema utiliza o padrão **RBAC (Role-Based Access Control)** onde:

- **Funcionários** são atribuídos a **Papéis/Funções** (Roles)
- **Papéis** possuem um conjunto de **Permissões**
- **Permissões** definem o que pode ser feito em cada **Módulo**

### Benefícios

✅ **Segurança**: Controle fino sobre acesso a funcionalidades
✅ **Flexibilidade**: Papéis customizáveis conforme necessidade
✅ **Escalabilidade**: Fácil adicionar novos módulos e permissões
✅ **Performance**: Sistema de cache para evitar queries repetidas
✅ **Auditoria**: Rastreamento de quem pode fazer o quê

---

## 🏗️ Arquitetura

### Estrutura em 3 Camadas

```
FUNCIONÁRIO
    ↓ (possui um)
PAPEL/FUNÇÃO (Role)
    ↓ (possui várias)
PERMISSÕES
    ↓ (para acessar)
MÓDULOS DO SISTEMA
```

### Classes Principais

#### `SISTUR_Permissions`
Localização: `includes/class-sistur-permissions.php`

Classe Singleton responsável por todo o gerenciamento de permissões:

- Verificação de permissões (`can()`, `can_any()`, `can_all()`)
- CRUD de papéis
- Atribuição de permissões a papéis
- Cache de consultas

---

## 🗄️ Estrutura do Banco de Dados

### Tabela: `wp_sistur_roles`
Armazena os papéis/funções disponíveis no sistema.

```sql
CREATE TABLE wp_sistur_roles (
    id mediumint(9) NOT NULL AUTO_INCREMENT,
    name varchar(100) NOT NULL UNIQUE,
    description text DEFAULT NULL,
    is_admin tinyint(1) DEFAULT 0,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
);
```

**Campos:**
- `is_admin`: Se 1, possui todas as permissões automaticamente

### Tabela: `wp_sistur_permissions`
Armazena todas as permissões possíveis no sistema.

```sql
CREATE TABLE wp_sistur_permissions (
    id mediumint(9) NOT NULL AUTO_INCREMENT,
    module varchar(50) NOT NULL,
    action varchar(50) NOT NULL,
    label varchar(100) NOT NULL,
    description text DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY unique_permission (module, action)
);
```

**Campos:**
- `module`: Nome do módulo (ex: 'employees', 'time_tracking')
- `action`: Ação permitida (ex: 'view', 'create', 'edit', 'delete')
- `label`: Nome amigável para exibição
- `description`: Descrição detalhada da permissão

### Tabela: `wp_sistur_role_permissions`
Relaciona papéis com permissões (tabela de junção).

```sql
CREATE TABLE wp_sistur_role_permissions (
    id mediumint(9) NOT NULL AUTO_INCREMENT,
    role_id mediumint(9) NOT NULL,
    permission_id mediumint(9) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY unique_role_permission (role_id, permission_id)
);
```

### Atualização na Tabela: `wp_sistur_employees`
Adicionada coluna para vincular funcionário ao papel.

```sql
ALTER TABLE wp_sistur_employees
ADD COLUMN role_id mediumint(9) DEFAULT NULL AFTER password;
```

---

## 👥 Papéis Pré-Configurados

O sistema vem com 7 papéis pré-configurados:

### 1. 🔴 Administrador
**Descrição:** Acesso completo ao sistema
**Tipo:** Admin (is_admin = 1)
**Permissões:** TODAS (automaticamente)

### 2. 🟠 Gerente de RH
**Descrição:** Gerencia funcionários, departamentos, pagamentos e ponto
**Permissões:**
- Funcionários: view, create, edit, delete, export, manage_departments
- Ponto: view_all, edit_all, approve, export
- Pagamentos: view_all, create, edit, export
- Relatórios: view_advanced, export

### 3. 🟡 Supervisor
**Descrição:** Supervisiona equipe e aprova pontos
**Permissões:**
- Funcionários: view
- Ponto: view_all, approve, export
- Leads: view, assign
- Relatórios: view_basic, export

### 4. 🟢 Gerente de Vendas
**Descrição:** Gerencia leads e equipe de vendas
**Permissões:**
- Funcionários: view
- Ponto: view_all
- Leads: view, create, edit, delete, assign, export
- Relatórios: view_basic, export

### 5. 🔵 Vendedor
**Descrição:** Gerencia leads e realiza vendas
**Permissões:**
- Ponto: view_own, edit_own
- Pagamentos: view_own
- Leads: view, create, edit
- Relatórios: view_basic

### 6. 🟣 Estoquista
**Descrição:** Gerencia inventário e estoque
**Permissões:**
- Ponto: view_own, edit_own
- Pagamentos: view_own
- Inventário: view, create, edit, movements, export
- Relatórios: view_basic

### 7. ⚪ Funcionário
**Descrição:** Acesso básico - apenas informações próprias
**Permissões:**
- Ponto: view_own, edit_own
- Pagamentos: view_own

---

## 🔐 Permissões Disponíveis

### Módulo: `employees` (Funcionários)
- `view` - Ver lista e detalhes de funcionários
- `create` - Criar novos funcionários
- `edit` - Editar informações de funcionários
- `delete` - Excluir funcionários
- `export` - Exportar dados de funcionários
- `manage_departments` - Gerenciar departamentos

### Módulo: `time_tracking` (Ponto Eletrônico)
- `view_own` - Ver próprio histórico de ponto
- `view_all` - Ver histórico de todos os funcionários
- `edit_own` - Editar próprios registros
- `edit_all` - Editar registros de qualquer funcionário
- `approve` - Aprovar/validar registros
- `export` - Exportar relatórios de ponto

### Módulo: `payments` (Pagamentos)
- `view_own` - Ver próprios pagamentos
- `view_all` - Ver pagamentos de todos
- `create` - Registrar novos pagamentos
- `edit` - Editar registros de pagamentos
- `delete` - Excluir registros
- `export` - Exportar dados

### Módulo: `leads` (Leads)
- `view` - Visualizar leads
- `create` - Criar novos leads
- `edit` - Editar leads
- `delete` - Excluir leads
- `assign` - Atribuir leads a funcionários
- `export` - Exportar dados

### Módulo: `inventory` (Inventário)
- `view` - Ver produtos e estoque
- `create` - Criar produtos
- `edit` - Editar produtos
- `delete` - Excluir produtos
- `movements` - Registrar movimentações
- `export` - Exportar dados

### Módulo: `reports` (Relatórios)
- `view_basic` - Acessar relatórios básicos
- `view_advanced` - Acessar relatórios avançados
- `export` - Exportar relatórios

### Módulo: `settings` (Configurações)
- `view` - Visualizar configurações
- `edit` - Modificar configurações

### Módulo: `permissions` (Permissões)
- `manage` - Gerenciar papéis e permissões

---

## 💻 API de Uso

### Inicializar a Classe

```php
$permissions = SISTUR_Permissions::get_instance();
```

### Verificar Uma Permissão

```php
$employee_id = 123;
$can_edit = $permissions->can($employee_id, 'employees', 'edit');

if ($can_edit) {
    // Permitir edição
} else {
    // Negar acesso
}
```

### Verificar Múltiplas Permissões (OR Logic)

```php
// Verifica se tem QUALQUER uma das permissões
$can_access = $permissions->can_any($employee_id, [
    'employees.view',
    'employees.edit',
    'employees.delete'
]);
```

### Verificar Múltiplas Permissões (AND Logic)

```php
// Verifica se tem TODAS as permissões
$has_all = $permissions->can_all($employee_id, [
    'employees.view',
    'employees.edit'
]);
```

### Obter Todas as Permissões de um Funcionário

```php
$perms = $permissions->get_employee_permissions($employee_id);
// Retorna array com todas as permissões
```

### Verificar se é Admin

```php
$is_admin = $permissions->is_admin($employee_id);
```

### Atribuir Papel a Funcionário

```php
$permissions->assign_role_to_employee($employee_id, $role_id);
```

### Criar Novo Papel

```php
$role_id = $permissions->create_role(
    'Gerente de Loja',
    'Gerencia operações da loja',
    false // não é admin
);
```

### Atribuir Permissões a um Papel

```php
$permission_ids = [1, 2, 3, 5, 8]; // IDs das permissões
$permissions->assign_permissions_to_role($role_id, $permission_ids);
```

---

## 🛠️ Exemplos de Implementação

### Exemplo 1: Proteger AJAX Handler

```php
public function ajax_save_employee() {
    // Verificar nonce
    check_ajax_referer('sistur_employees_nonce', 'nonce');

    // Obter funcionário logado
    $session = SISTUR_Session::get_instance();
    $current = $session->get_employee_data();

    // Verificar permissão
    $permissions = SISTUR_Permissions::get_instance();
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $action = $id > 0 ? 'edit' : 'create';

    if (!$permissions->can($current['id'], 'employees', $action)) {
        wp_send_json_error([
            'message' => 'Você não tem permissão para ' . $action . ' funcionários.'
        ]);
    }

    // Continuar com a lógica...
}
```

### Exemplo 2: Ocultar Botões na Interface

```php
<?php
$permissions = SISTUR_Permissions::get_instance();
$current = sistur_get_current_employee();
$can_create = $permissions->can($current['id'], 'employees', 'create');
$can_edit = $permissions->can($current['id'], 'employees', 'edit');
$can_delete = $permissions->can($current['id'], 'employees', 'delete');
?>

<?php if ($can_create): ?>
    <button class="sistur-btn sistur-btn-primary" id="add-employee">
        Adicionar Funcionário
    </button>
<?php endif; ?>

<table>
    <?php foreach ($employees as $emp): ?>
        <tr>
            <td><?php echo $emp['name']; ?></td>
            <td>
                <?php if ($can_edit): ?>
                    <button class="edit-btn" data-id="<?php echo $emp['id']; ?>">
                        Editar
                    </button>
                <?php endif; ?>

                <?php if ($can_delete): ?>
                    <button class="delete-btn" data-id="<?php echo $emp['id']; ?>">
                        Excluir
                    </button>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
</table>
```

### Exemplo 3: Middleware de Acesso

```php
function sistur_require_permission($module, $action) {
    $session = SISTUR_Session::get_instance();

    if (!$session->is_employee_logged_in()) {
        wp_redirect(home_url('/login-funcionario/'));
        exit;
    }

    $current = $session->get_employee_data();
    $permissions = SISTUR_Permissions::get_instance();

    if (!$permissions->can($current['id'], $module, $action)) {
        wp_die('Você não tem permissão para acessar esta página.');
    }
}

// Usar no início de uma página
sistur_require_permission('employees', 'view');
```

---

## 🚀 Próximos Passos

### Fase 2 - Interface Administrativa (Pendente)

Criar interface visual no admin WordPress para:

1. **Gerenciar Papéis**
   - Listar papéis existentes
   - Criar/Editar/Excluir papéis
   - Ver funcionários por papel

2. **Gerenciar Permissões**
   - Interface com checkboxes por módulo
   - Atribuir permissões a papéis
   - Visualização em grid

3. **Auditoria**
   - Log de mudanças de permissões
   - Histórico de acessos negados
   - Relatório de permissões por funcionário

### Fase 3 - Integrações Completas

1. Aplicar verificações em todos os AJAX handlers existentes
2. Proteger todas as views administrativas
3. Implementar permissões no painel do funcionário
4. Adicionar testes automatizados

### Fase 4 - Recursos Avançados

1. Permissões temporárias (com data de expiração)
2. Delegação de permissões
3. Aprovação em dois níveis
4. API REST com autenticação por token

---

## 📚 Referências

- **Arquivo Principal:** `includes/class-sistur-permissions.php`
- **Banco de Dados:** `includes/class-sistur-activator.php` (linhas 203-273)
- **Integração:** `sistur.php` (linha 105)
- **Formulário:** `admin/views/employees/employees.php` (linhas 125-139)

---

## 📝 Notas de Versão

### v1.2.0 (2025-01-14)
- ✅ Estrutura de banco de dados criada
- ✅ Classe `SISTUR_Permissions` implementada
- ✅ 39 permissões padrão criadas
- ✅ 7 papéis pré-configurados
- ✅ Integração com formulário de funcionários
- ✅ Sistema de cache implementado
- ⏳ Interface administrativa (pendente)
- ⏳ Integrações completas nos módulos (pendente)

---

**Desenvolvido por:** SISTUR Development Team
**Licença:** GPL-2.0+
**Suporte:** Entre em contato através do repositório do projeto
