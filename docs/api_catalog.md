# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

# API Catalog — SISTUR Portal do Colaborador

> **Source of truth** for all HTTP endpoints.
> Update this file whenever a route is added, changed, or removed.

---

## Conventions

| Item | Convention |
|---|---|
| Base URL | `/` (served via Nginx reverse proxy on port 80/443) |
| Content-Type | `application/x-www-form-urlencoded` for form POSTs; `application/json` for API responses |
| Auth | Session cookie (`funcionario_id` in server-side session) |
| Error format | Flash message (HTML pages) or `{"erro": "message"}` (JSON endpoints) |
| Timestamps | ISO 8601, UTC (`2026-02-24T18:30:00Z`) |

---

## Custom Error Codes

| Code | HTTP Status | Meaning |
|---|---|---|
| `CPF_INVALIDO` | 400 | CPF failed check-digit validation |
| `CPF_NAO_ENCONTRADO` | 401 | CPF not in DB or employee inactive |
| `SESSAO_EXPIRADA` | 401 | Session cookie expired or missing |
| `NAO_AUTORIZADO` | 403 | Authenticated but lacks permission for this resource |
| `NAO_ENCONTRADO` | 404 | Requested resource does not exist |
| `CPF_DUPLICADO` | 409 | CPF already registered |

---

## Module: Auth (`/portal`)

### `GET /portal/login`

Renders the login page.

**Auth required:** No
**Redirect:** If already authenticated → `GET /portal/dashboard`

**Response:** `200 text/html` — `auth/login.html`

---

### `POST /portal/login`

Authenticate via CPF.

**Auth required:** No
**Content-Type:** `application/x-www-form-urlencoded`

**Request body:**

| Field | Type | Required | Description |
|---|---|---|---|
| `cpf` | string | Yes | CPF in any format (`000.000.000-00` or `00000000000`) |

**Success response:** `302` → `GET /portal/dashboard`

**Error responses:**

| Scenario | Status | Flash category | Message |
|---|---|---|---|
| CPF format invalid | 400 | `erro` | "CPF inválido." |
| CPF not found / inactive | 401 | `erro` | "CPF não encontrado ou funcionário inativo." |

**Audit:** `AuditLog(action=login, module=auth, new_state={"success": bool})`

---

### `POST /portal/logout`

End the authenticated session.

**Auth required:** No (safe to call even unauthenticated)
**Response:** `302` → `GET /portal/login`

**Audit:** `AuditLog(action=logout, module=auth)` — only if session was active

---

## Module: Portal (`/portal`)

### `GET /portal/dashboard`

Employee dashboard page.

**Auth required:** Yes → redirects to `/portal/login` if unauthenticated
**Response:** `200 text/html` — `portal/dashboard.html`

**Template variables:**

| Variable | Type | Description |
|---|---|---|
| `funcionario` | `Funcionario` | Authenticated employee ORM object |
| `company_name` | string | From `COMPANY_NAME` env var (via context processor) |
| `company_logo` | string | From `COMPANY_LOGO` env var (empty = SVG fallback) |

---

## Module: Funcionários (planned — `/admin/funcionarios`)

> Not yet implemented. Planned for next sprint.

| Method | Path | Description |
|---|---|---|
| `GET` | `/admin/funcionarios` | List all active employees |
| `POST` | `/admin/funcionarios` | Create employee (calls `FuncionarioService.criar`) |
| `GET` | `/admin/funcionarios/<id>` | Employee detail |
| `POST` | `/admin/funcionarios/<id>/editar` | Update employee (calls `FuncionarioService.atualizar`) |
| `POST` | `/admin/funcionarios/<id>/desativar` | Soft-delete (calls `FuncionarioService.desativar`) |

---

## Module: Banco de Horas (planned — `/portal/banco-horas`)

> Not yet implemented. Blocked by Ponto Eletrônico port.

| Method | Path | Description |
|---|---|---|
| `GET` | `/portal/banco-horas` | Employee's current balance and history |

---

## Module: Ponto Eletrônico (`/ponto`)

### `GET /ponto/`

Exibe o histórico mensal de batidas do colaborador autenticado.

**Auth required:** Yes → redirects to `/portal/login`
**Query params:**

| Param | Type | Default | Description |
|---|---|---|---|
| `mes` | int | mês corrente | Mês a exibir (1–12) |
| `ano` | int | ano corrente | Ano a exibir |

**Response:** `200 text/html` — `ponto/index.html`

**Template variables:** `funcionario`, `days` (list[TimeDay]), `entries_by_day` (dict), `mes`, `ano`, `hoje`

---

### `POST /ponto/registrar`

Registra uma nova batida de ponto para o colaborador autenticado.

**Auth required:** Yes
**Content-Type:** `application/x-www-form-urlencoded`

**Request body:**

| Field | Type | Required | Description |
|---|---|---|---|
| `punch_time` | string (ISO 8601) | No | Timestamp local do cliente. Se ausente, usa o servidor. |

**Success response:** `302` → `GET /ponto/`

**Error responses:**

| Scenario | Flash category | Message |
|---|---|---|
| Batida duplicada (< 5s) | `erro` | "Batida duplicada detectada. Aguarde N segundos…" |
| Funcionário inativo | `erro` | "Funcionário X não encontrado ou inativo." |

**Audit:** `AuditLog(action=create, module='ponto', entity_id=TimeEntry.id)`

---

## Module: Admin — Audit Log Viewer (`/admin`)

### `GET /admin/audit-logs`

Visualizador de logs de auditoria com filtros dinâmicos e paginação server-side.

**Auth required:** Yes → `302` to `/portal/login` if unauthenticated  
**Permission required:** `audit.view` OR `audit.view_all` (super_admin bypasses automatically)

**Query params:**

| Param | Type | Default | Description |
|---|---|---|---|
| `user_id` | int | — | Filtra pelo ID do ator (Funcionario) |
| `module` | str | — | Filtra pelo módulo (e.g. `auth`, `ponto`, `funcionarios`) |
| `page` | int | 1 | Número da página (50 registros por página) |

**Access levels:**

| Role | Permissão | Acesso |
|---|---|---|
| `super_admin` | `is_super_admin=True` | Todos os logs |
| Gerente | `audit.view_all` | Todos os logs |
| Supervisor | `audit.view` | Logs filtrados pelo `area_id` do Funcionario |
| Sem permissão | — | HTTP 403 |

**Response:** `200 text/html` — `admin/audit_logs.html`

**Template variables:**

| Variable | Type | Description |
|---|---|---|
| `pagination` | `Pagination` | Objeto Flask-SQLAlchemy com `.items`, `.total`, `.pages` |
| `logs` | `list[AuditLog]` | Registros da página atual |
| `modulos` | `list[str]` | Módulos distintos disponíveis para filtro |
| `funcionarios_map` | `dict[int, str]` | Mapa id → nome dos atores |
| `filtro_user_id` | int? | Filtro ativo de ator |
| `filtro_module` | str? | Filtro ativo de módulo |
| `acesso_total` | bool | True se gerente/super_admin |
| `ator` | `Funcionario` | Funcionário autenticado |

---

## Module: Configurações (`/admin/configuracoes`)

> **Access:** Exclusivo para `Role.is_super_admin = True`. HTTP 403 para qualquer outro perfil.

### `GET /admin/configuracoes/`

Exibe o painel de controle com quatro abas: Módulos Ativos, Regras Globais, Identidade Visual, Dados da Empresa.

**Auth required:** Yes (super_admin)
**Response:** `200 text/html` — `admin/configuracoes/index.html`

**Template variables:**

| Variable | Type | Description |
|---|---|---|
| `modulos_info` | `list[dict]` | Lista de módulos com chave, nome, label, ativo |
| `empresa_nome` | str | Nome principal do branding (do banco ou "") |
| `empresa_logo` | str | URL do logo (do banco ou "") |
| `empresa_razao_social` | str | Razão Social da empresa (do banco ou "") |
| `empresa_cnpj` | str | CNPJ da empresa (do banco ou "") |
| `empresa_endereco` | str | Endereço completo (do banco ou "") |

---

### `POST /admin/configuracoes/modulos/<chave>/toggle`

Inverte o estado ativo/inativo de um módulo via AJAX.

**Auth required:** Yes (super_admin)
**Path param:** `chave` — deve ser `modulo.ponto`, `modulo.estoque`, `modulo.restaurante` ou `modulo.financeiro`
**Response:** `200 application/json` `{"ativo": bool, "chave": str}`
**Error:** `400 application/json` `{"erro": "Chave inválida."}` se a chave não for válida

---

### `POST /admin/configuracoes/branding`

Salva configurações de identidade visual.

**Auth required:** Yes (super_admin)
**Form fields:** `empresa_nome` (str), `empresa_logo` (str URL)
**Response:** Redirect `GET /admin/configuracoes/` com flash de sucesso

---

### `GET /admin/configuracoes/roles`

Lista todos os roles do sistema (ativos e inativos).

**Auth required:** Yes (super_admin)
**Response:** `200 text/html` — `admin/configuracoes/roles.html`

---

### `POST /admin/configuracoes/roles/criar`

Cria um novo role.

**Auth required:** Yes (super_admin)
**Form fields:** `nome` (str, snake_case), `descricao` (str), `is_super_admin` (checkbox)
**Response:** Redirect `GET /admin/configuracoes/roles` com flash de resultado
**Error:** Flash `"erro"` se nome duplicado ou vazio

---

### `GET /admin/configuracoes/roles/<id>/permissoes`

Exibe o editor de permissões de um role específico.

**Auth required:** Yes (super_admin)
**Path param:** `id` (int) — PK do role
**Response:** `200 text/html` — `admin/configuracoes/role_permissoes.html`
**Error:** HTTP 404 se role não encontrado

**Template variables:**

| Variable | Type | Description |
|---|---|---|
| `role` | `Role` | Instância do role a editar |
| `permissoes_sistema` | `dict[str, list[str]]` | Mapa módulo → lista de ações |
| `perms_atuais` | `set[tuple[str,str]]` | Pares (modulo, acao) atualmente concedidos |
| `labels_modulo` | `dict[str,str]` | Nomes legíveis por módulo |
| `labels_acao` | `dict[str,str]` | Nomes legíveis por ação |

---

### `POST /admin/configuracoes/roles/<id>/permissoes`

Salva o conjunto completo de permissões de um role (bulk diff).

**Auth required:** Yes (super_admin)
**Path param:** `id` (int)
**Form fields:** Checkboxes `perm_<modulo>_<acao>` = "on" para cada permissão habilitada
**Response:** Redirect `GET /admin/configuracoes/roles/<id>/permissoes` com flash de sucesso

---

### `POST /admin/configuracoes/roles/<id>/desativar`

Desativa um role (soft-delete).

**Auth required:** Yes (super_admin)
**Path param:** `id` (int)
**Response:** Redirect `GET /admin/configuracoes/roles` com flash de resultado
**Error:** Flash `"erro"` se o role for `is_super_admin=True` (proteção contra lockout)

---

### `POST /admin/configuracoes/dados-empresa`

Salva dados cadastrais da empresa para uso no cabeçalho do PDF da Folha de Ponto.

**Auth required:** Yes (super_admin)
**Form fields:** `empresa_razao_social` (str), `empresa_cnpj` (str), `empresa_endereco` (str)
**Response:** Redirect `GET /admin/configuracoes/` com flash de sucesso
**Storage:** `sistur_system_settings` — chaves `empresa.razao_social`, `empresa.cnpj` e `empresa.endereco`
**Audit:** `AuditLog` via `ConfiguracaoService.set`

---

## Module: RH — Folha de Ponto (`/rh`)

> **Access:** Requer permissão `folha_ponto.*` por ação. Super admins têm acesso total.
> Ver documentação completa em `docs/rh/README.md`.

### `GET /rh/folha-ponto/<funcionario_id>`

Exibe a Folha de Ponto CLT de 10 colunas do funcionário para o mês/ano selecionado.

**Permission:** `folha_ponto.view`
**Query params:** `mes` (int), `ano` (int)
**Response:** `200 text/html` — `rh/folha_ponto.html`

---

### `POST /rh/folha-ponto/<funcionario_id>/batida/adicionar`

Adiciona uma batida administrativa com motivo auditado.

**Permission:** `folha_ponto.edit`
**Form fields:** `novo_horario` (datetime-local), `motivo` (str), `mes`, `ano`
**Response:** Redirect `GET /rh/folha-ponto/<id>` com flash de resultado
**Audit:** `AuditLog` via `PontoService.registrar_batida(source="admin")`

---

### `POST /rh/folha-ponto/<funcionario_id>/batida/<entry_id>/editar`

Edita o horário de uma batida existente.

**Permission:** `folha_ponto.edit`
**Form fields:** `novo_horario`, `motivo`, `mes`, `ano`
**Response:** Redirect com flash
**Audit:** `PontoService.editar_batida_admin` — snapshot before/after

---

### `POST /rh/folha-ponto/<funcionario_id>/batida/<entry_id>/deletar`

Remove uma batida com auditoria completa.

**Permission:** `folha_ponto.edit`
**Form fields:** `motivo` (obrigatório), `mes`, `ano`
**Response:** Redirect com flash
**Audit:** `PontoService.deletar_batida_admin` — snapshot antes da exclusão

---

### `POST /rh/folha-ponto/<funcionario_id>/deducao`

Registra uma dedução de banco de horas.

**Permission:** `folha_ponto.deducao`
**Form fields:** `deduction_type`, `minutos`, `data_registro`, `observacao`, `mes`, `ano`
**Response:** Redirect com flash
**Delegates to:** `PontoService.registrar_abatimento_horas`

---

### `GET /rh/folha-ponto/<funcionario_id>/pdf`

Gera PDF da Folha de Ponto via WeasyPrint.

**Permission:** `folha_ponto.imprimir`
**Query params:** `mes`, `ano`
**Response:** `application/pdf` inline — filename `folha_ponto_{cpf}_{ano}-{MM}.pdf`
**Template:** `rh/folha_ponto_pdf.html` (CSS inline, A4 landscape, sem JS)
