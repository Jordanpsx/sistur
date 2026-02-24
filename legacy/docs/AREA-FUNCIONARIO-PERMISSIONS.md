# Sistema de Permissões - Área do Funcionário

**Versão:** 2.2.0  
**Atualizado em:** 2026-02-18

## Visão Geral

O Portal do Colaborador (`/areafuncionario/`) utiliza um sistema de permissões modulares baseado em RBAC (Role-Based Access Control). Cada módulo no dashboard é exibido apenas para funcionários que possuem a permissão correspondente.

---

## Módulos e Permissões

| Módulo | Permissão Requerida | Descrição |
|--------|---------------------|-----------|
| Ponto Eletrônico | *(nenhuma)* | Disponível para todos os funcionários |
| Meu Perfil | *(nenhuma)* | Disponível para todos os funcionários |
| Restaurante | `restaurant.view` | CMV, PDV e gestão do restaurante |
| Aprovações | `approvals.approve` | Aprovar/rejeitar solicitações |
| Gestão de Funcionários (RH) | `employees.view` | Acesso ao módulo de RH completo |
| Finanças | `payments.view_all` | Relatórios financeiros e controle |

---

## Papéis Disponíveis

### Funcionário (Padrão)
- Acesso ao ponto eletrônico
- Visualizar próprio perfil
- Visualizar próprios pagamentos

### Restaurante
- Todas as permissões do Funcionário
- Acesso ao módulo Restaurante (`restaurant.view`, `restaurant.edit`)
- Visualizar e editar CMV (`cmv.view`, `cmv.edit`)
- Movimentações de estoque (`inventory.movements`)
- Registrar vendas (`inventory.record_sale`)

### Gestor de Banco de Horas
- `dashboard.view` — Ver dashboard central
- `timebank.manage` — Gerenciar banco de horas
- `time_tracking.view_all` — Visualizar ponto de todos
- `time_tracking.approve` — Aprovar pontos
- `approvals.view_pending` — Ver solicitações pendentes
- `approvals.approve` — Aprovar solicitações

### Gerente de RH
- `dashboard.view` — Ver dashboard central
- `employees.view/create/edit/delete/export` — Gestão completa de funcionários
- `employees.manage_departments` — Gerenciar departamentos
- `time_tracking.view_all/edit_all/approve/export` — Ponto de todos
- `payments.view_all/create/edit/export` — Pagamentos
- `reports.view_advanced/export` — Relatórios avançados
- `timebank.manage` — Banco de horas
- `permissions.manage` — Gerenciar permissões
- `approvals.view_pending/approve` — Aprovações

### Administrador
- `is_admin = 1` — Acesso total a todos os módulos (sem verificação de permissões individuais)

---

## Lógica de Verificação no Módulo de RH

O `SISTUR_RH_Module::can($module, $action)` verifica na seguinte ordem:

1. **Admin WordPress** (`manage_options`) → acesso total
2. **Super admin do portal** (`is_admin = 1` na tabela `sistur_roles`) → acesso total
3. **Permissão específica** via `SISTUR_Permissions::can()` → verifica `sistur_role_permissions`

```php
// Verificar permissão no módulo de RH
$rh_module = SISTUR_RH_Module::get_instance();
$can_edit = $rh_module->can('employees', 'edit');

// Verificar se é super admin do portal
$permissions = SISTUR_Permissions::get_instance();
$is_portal_admin = $permissions->is_admin($employee_id);
```

---

## Como Atribuir Permissões

### Via Admin WordPress

1. Acesse: **SISTUR > Permissões**
2. Edite o papel desejado ou crie um novo
3. Marque as permissões necessárias
4. Salve as alterações

### Atribuir Papel a Funcionário

1. Acesse: **SISTUR > Funcionários**
2. Edite o funcionário desejado
3. Selecione o papel no campo "Papel do Sistema"
4. Salve as alterações

---

## Estrutura de Arquivos

```
templates/
├── portal-colaborador.php              # Dashboard principal
├── rh-module.php                       # Módulo de RH (SPA com abas)
└── components/
    ├── module-gestao-funcionarios.php  # Embute o módulo de RH
    ├── module-restaurante.php          # CMV + PDV
    ├── module-ponto.php                # Ponto eletrônico
    ├── module-financas.php             # Finanças
    ├── module-aprovacoes.php           # Aprovações
    └── module-perfil.php               # Perfil do usuário

includes/
├── class-sistur-rh-module.php          # Lógica do módulo de RH
├── class-sistur-permissions.php        # Sistema RBAC
└── class-sistur-activator.php          # Criação/migração de roles e permissões
```

---

## Changelog

### v2.3.0 (2026-02-18)
- **Bug fix:** `portal-colaborador.php` — filtro de módulos, `$can_approve`, guards dos tabs Estoque e Restaurante agora verificam `$is_portal_admin` antes de checar permissões individuais
- **Bug fix:** `SISTUR_RH_Module::get_current_user_permissions()` — campo `is_admin` agora retorna `true` para super admins do portal (`is_admin=1` em `sistur_roles`), não apenas para WP admins (`manage_options`)
- **Limpeza:** removido docblock duplicado em `SISTUR_RH_Module::render()`

### v2.2.0 (2026-02-18)
- **Bug fix:** `create_default_roles()` pulava roles existentes (`if (!$exists)`), deixando permissões novas fora do banco em instalações já existentes
- **Novo método:** `SISTUR_Activator::update_rh_role_permissions()` — usa `INSERT IGNORE` para garantir que roles existentes recebam todas as permissões corretas em toda reativação do plugin
- **Gerente de RH:** adicionadas permissões `dashboard.view`, `timebank.manage`, `permissions.manage`, `approvals.view_pending`, `approvals.approve`
- **SISTUR_RH_Module::can():** agora verifica `is_admin` do portal antes de checar permissões específicas, garantindo acesso total a super admins do portal

### v2.1.0 (2026-02-02)
- Adicionadas permissões `restaurant.view`, `restaurant.edit`, `cmv.view`, `cmv.edit`
- Criado papel "Restaurante" com permissões de CMV e estoque
- Implementado módulo Restaurante com tabs CMV/PDV
- Filtro de módulos baseado em permissões no portal
