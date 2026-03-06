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

## Module: Avisos — Proactive Absence Monitoring (`/avisos`)

> **Access:** Todas as rotas requerem autenticação (`@login_required`).
> Supervisores recebem notificações via RBAC: permissão `avisos.receber_alertas`.
> Ver documentação completa em `docs/avisos/README.md`.

### `GET /avisos/`

Lista notificações do usuário autenticado com paginação.

**Auth required:** Yes
**Query params:** `page` (int, default: 1)
**Response:** `200 text/html` — `avisos/index.html`

**Template variables:**

| Variable | Type | Description |
|---|---|---|
| `pagination` | `Pagination` | Flask-SQLAlchemy pagination object |
| `avisos` | `list[Aviso]` | Avisos para a página atual |
| `avisos_nao_lidos` | int | Contagem total de não lidos (injected by context processor) |

**Features:**
- Cards with color-coded left border by tipo (amber=ATRASO, red=FALTA, blue=SISTEMA)
- Unread indicator dot
- "Mark as read" + "Delete" buttons per card
- "Mark all as read" button (visible if unread > 0)
- Empty state: "Nenhum aviso por enquanto."
- Pagination (prev/next)
- Mobile-first responsive design

---

### `POST /avisos/<id>/marcar-lido`

Marca uma notificação como lida.

**Auth required:** Yes
**Path param:** `id` (int) — PK do Aviso
**Security:** IDOR guard — verifica `aviso.destinatario_id == current_user`
**Form/AJAX:** Both supported (form fallback)
**Response (Form):** Redirect `GET /avisos/` com flash de sucesso
**Response (AJAX):** `200 application/json` `{"status": "success", "marcados": 1}`
**Error:** HTTP 403 se IDOR falhar; HTTP 404 se aviso não encontrado

---

### `POST /avisos/marcar-todos-lidos`

Marca todas as notificações não lidas como lidas.

**Auth required:** Yes
**Form fields:** None required
**Response:** Redirect `GET /avisos/` com flash `"Todas as notificações marcadas como lidas."`
**Audit:** `AuditLog` via `AvisoService.marcar_todos_lidos` (logs count)

---

### `POST /avisos/<id>/deletar`

Remove uma notificação.

**Auth required:** Yes
**Path param:** `id` (int) — PK do Aviso
**Security:** IDOR guard — verifica `aviso.destinatario_id == current_user`
**Response:** Redirect `GET /avisos/` com flash de sucesso
**Audit:** `AuditLog` via `AvisoService.deletar`
**Error:** HTTP 403 se IDOR falhar; HTTP 404 se aviso não encontrado

---

## Background Scheduler Jobs

> Jobs executados via **Flask-APScheduler**. Configuração em `app/config.py`.

### Job: `verificar_atrasos` (Tardy Detection)

**Trigger:** Interval — every 5 minutes
**Handler:** `AvisoService.verificar_atrasos()`

**Logic:**
1. Reads timezone from `ConfiguracaoService.get("scheduler.timezone", "America/Sao_Paulo")`
2. Converts UTC now to local time
3. For each active employee with `horario_entrada_padrao` set:
   - Skip if today is holiday (via `GlobalEvent.afeta_folha`)
   - Skip if day inactive in `jornada_semanal`
   - Skip if within 20-min tolerance window (entrada_padrao + 20 min)
   - Skip if approved `AusenciaJustificada` exists for today
   - Skip if `TimeEntry` exists (already punched)
   - Skip if `TimeDay.auto_debit_aplicado=True` (guard against duplicates)
4. Apply provisional debit:
   - Create/update `TimeDay` with `saldo_final_minutos = -expected`
   - Set `auto_debit_aplicado = True`
   - Adjust `banco_horas_acumulado` via delta
   - Log audit event
5. Create notifications:
   - `Aviso(tipo=ATRASO)` to employee
   - `Aviso(tipo=ATRASO)` to each supervisor in employee's area (RBAC: `avisos.receber_alertas` or super_admin)

**Error handling:** try/except per employee — isolated failures

**Returns:** `{"processados": int, "erros": list[str]}`

---

### Job: `finalizar_ausencias` (Absence Confirmation)

**Trigger:** Cron — daily at 23:00 (11 PM)
**Handler:** `AvisoService.finalizar_ausencias()`

**Logic:**
1. Find all `TimeDay` with `auto_debit_aplicado=True` for today
2. For each:
   - Check if `TimeEntry` exists (real punches)
   - If no punches: confirm as permanent absence
     - Create `Aviso(tipo=FALTA)` to employee
     - Create `Aviso(tipo=FALTA)` to each supervisor in area
     - Log audit events

**Returns:** `{"confirmadas": int, "erros": list[str]}`

---

## Context Processor: `inject_avisos()`

> Injected into every template request via Flask `@app.context_processor`.

**Variable:** `avisos_nao_lidos` (int)

**Implementation:**
```python
@app.context_processor
def inject_avisos():
    try:
        from flask import session
        fid = session.get("funcionario_id")
        if fid:
            from app.services.aviso_service import AvisoService
            return {"avisos_nao_lidos": AvisoService.contar_nao_lidos(fid)}
    except Exception:
        pass
    return {"avisos_nao_lidos": 0}
```

**Usage:** Templates can reference `{{ avisos_nao_lidos }}` without explicit passing.

**Topbar bell:** `_avisos_bell.html` include displays red badge with count (99+ max).

---

## Models & Database

### `Aviso` Table

```sql
CREATE TABLE sistur_avisos (
    id INTEGER PRIMARY KEY,
    destinatario_id INTEGER NOT NULL,
    remetente_id INTEGER,
    titulo VARCHAR(200) NOT NULL,
    mensagem TEXT,
    tipo ENUM('ATRASO', 'FALTA', 'SISTEMA') NOT NULL DEFAULT 'SISTEMA',
    is_lido BOOLEAN NOT NULL DEFAULT FALSE,
    criado_em DATETIME NOT NULL,
    FOREIGN KEY (destinatario_id) REFERENCES sistur_funcionarios(id) ON DELETE CASCADE,
    FOREIGN KEY (remetente_id) REFERENCES sistur_funcionarios(id) ON DELETE SET NULL,
    INDEX (destinatario_id),
    INDEX (is_lido),
    INDEX (criado_em)
);
```

### `AusenciaJustificada` Table

```sql
CREATE TABLE sistur_ausencias_justificadas (
    id INTEGER PRIMARY KEY,
    funcionario_id INTEGER NOT NULL,
    data DATE NOT NULL,
    tipo ENUM('FOLGA', 'ATESTADO', 'FERIAS') NOT NULL,
    aprovado BOOLEAN NOT NULL DEFAULT FALSE,
    criado_por_id INTEGER,
    observacao TEXT,
    criado_em DATETIME NOT NULL,
    FOREIGN KEY (funcionario_id) REFERENCES sistur_funcionarios(id) ON DELETE CASCADE,
    FOREIGN KEY (criado_por_id) REFERENCES sistur_funcionarios(id) ON DELETE SET NULL,
    UNIQUE KEY uq_ausencia_funcionario_data (funcionario_id, data),
    INDEX (funcionario_id),
    INDEX (data)
);
```

### Model Extensions

**`Funcionario` column:**
```sql
ALTER TABLE sistur_funcionarios ADD COLUMN horario_entrada_padrao TIME;
```

**`TimeDay` column:**
```sql
ALTER TABLE sistur_time_days ADD COLUMN auto_debit_aplicado BOOLEAN NOT NULL DEFAULT FALSE;
```

---

## RBAC Permissions

### New Permission Group: `avisos`

| Permission | Scope | Meaning |
|---|---|---|
| `avisos.view` | Employee | Access `/avisos/` page (implicit for all active employees) |
| `avisos.receber_alertas` | Supervisor | Receive notifications for tardiness/absences in own area |

**Assignment:** Via `/admin/configuracoes/roles/<id>/permissoes` interface

**Query Logic:** `_buscar_supervisores(area_id)` filters employees with this permission OR `role.is_super_admin=True`

---

## Integration Points

### With `PontoService`

**`processar_dia()` method:**
- When `len(entries) > 0` and `auto_debit_aplicado=True`:
  - Reset `auto_debit_aplicado = False`
  - Existing delta mechanism (`banco_horas_acumulado += delta`) handles reversal
  - No extra arithmetic needed

**`_get_expected_minutes(funcionario, shift_date)` function:**
- Called by `verificar_atrasos()` to calculate expected minutes
- Respects `jornada_semanal` overrides + `minutos_esperados_dia` fallback

### With `CalendarService` (GlobalEvent)

**Holiday Detection:**
```python
GlobalEvent.afeta_folha == True AND (
    GlobalEvent.data_evento == today OR (
        GlobalEvent.recorrente_anual == True AND
        EXTRACT(MONTH FROM data_evento) == today.month AND
        EXTRACT(DAY FROM data_evento) == today.day
    )
)
```

---

## Migration

**File:** `migrations/versions/a1b2c3d4e5f6_add_avisos_ausencias_horario_autodebit.py`

**Changes:**
1. Create `sistur_avisos` table (+ 3 indexes)
2. Create `sistur_ausencias_justificadas` table (+ 2 indexes, unique constraint)
3. Add `horario_entrada_padrao` column to `sistur_funcionarios`
4. Add `auto_debit_aplicado` column to `sistur_time_days` (default=FALSE)

**VPS Deployment:**
```bash
docker exec -it sistur-flask-app flask db upgrade
```

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
