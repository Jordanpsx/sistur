# CLAUDE.md — SISTUR Project

## Project Overview

**SISTUR** is a web-based ERP for tourism operations (clubs and tourist attractions).
It manages HR, electronic time tracking (Ponto Eletrônico), inventory, leads, and more.

- **Language context:** Portuguese (pt-BR) — all business logic, variable names, and user-facing text are in Brazilian Portuguese
- **Active development stack:** Python 3 / Flask + SQLAlchemy + MySQL
- **Deployment:** Docker Compose on VPS via GitHub Actions (triggered on `develop` branch push)

> ### ⚠️ Read `docs/antigravity.md` First (if you haven't already)
>
> **`docs/antigravity.md` is a mandatory rules and best practices file.**
> It contains non-negotiable architecture constraints that apply to every feature
> implemented in the SISTUR Python rewrite, including audit logging, layered architecture,
> mobile-first UI, mandatory docstrings, and testing requirements.
>
> This file (`CLAUDE.md`) and `docs/antigravity.md` are **complementary rule files**:
> - `CLAUDE.md` — project-wide conventions, stack, module index, and guidelines
> - `docs/antigravity.md` — non-negotiable architecture constraints
>
> Both must be read before implementing any feature.
> If you have already read `docs/antigravity.md` in this session, you do not need to re-read it —
> but do not skip it on the first interaction of any new session.

## Current Goal: Python Rewrite

**We are rewriting SISTUR in Python/Flask from scratch**, using `legacy/` (the original PHP WordPress plugin) as the functional reference.

- `legacy/` is **read-only reference** — do not modify it, do not add features to it
- Once all legacy features are ported to Python, `legacy/` will be permanently deleted
- All new code goes into the Python project at the repository root
- The Python version is a standalone web application — **not** a WordPress plugin

---

## Repository Structure

```
sistur/
├── legacy/                  # READ-ONLY reference — original PHP/WordPress plugin (v2.13.1)
│   ├── sistur.php           # Plugin entry point
│   ├── includes/            # 29 core PHP classes — use as functional spec
│   ├── templates/           # Original HTML templates
│   ├── mu-plugins/externo/  # QR scanner Python app (ponto.py)
│   ├── sql/                 # DB schemas to replicate in Python
│   └── docs/                # 118 documentation files in pt-BR — important business rules
│
│   ⚠️  Do not modify legacy/. It will be deleted once the Python port is complete.
│
├── app/
│   ├── blueprints/          # HTTP controllers — one per business module
│   ├── core/                # Shared: models.py, audit.py, permissions.py
│   ├── models/              # Domain models (Funcionario, etc.)
│   ├── services/            # Business logic layer — Cython-ready
│   ├── templates/           # Jinja2 templates
│   ├── config.py            # Flask config (Dev / Prod)
│   ├── extensions.py        # SQLAlchemy singleton
│   └── __init__.py          # App factory
├── docs/
│   └── antigravity.md       # Non-negotiable architecture rules
├── requirements.txt         # Python dependencies (Flask, SQLAlchemy, MySQL)
├── .github/workflows/       # CI/CD: deploy.yml triggers on develop branch
├── CLAUDE.md                # This file
└── README.md
```

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | Flask 3.0.0 |
| ORM | SQLAlchemy 2.0.23 |
| Database | MySQL 5.7+ / MariaDB 10.3+ |
| Database driver | mysql-connector-python 8.2.0 |
| Container | Docker + Docker Compose |
| CI/CD | GitHub Actions → SSH deploy |

### PDF Generation

`WeasyPrint>=64.0` is used for Folha de Ponto PDF export.

**Dependency policy:** Do **not** manually pin `pydyf` or other WeasyPrint sub-dependencies.
WeasyPrint declares its own compatible range; pinning `pydyf` separately causes
`ResolutionImpossible` conflicts in the pip resolver.
If WeasyPrint must be pinned to a specific version, verify the matching `pydyf` range in its
own `setup.cfg`/`pyproject.toml` and pin both together — or leave both unpinned within a
major-version range (`WeasyPrint>=64.0,<65`).

### Python Tooling (to be ported)
- `opencv-python` + `pyzbar` — QR code scanner via webcam (reference: `legacy/mu-plugins/externo/ponto.py`)
- `win32print` — Thermal printer integration (Windows)

---

## Key Business Modules

| Module (pt-BR) | Description |
|---|---|
| **RH (Funcionários)** | Employee data, departments — dashboard at `/rh/` with tabs |
| **Ponto Eletrônico** | Electronic punch clock via QR code |
| **Banco de Horas** | Hour banking — most complex module, see `legacy/docs/BANCO_DE_HORAS/` |
| **Estoque / CMV** | Inventory, stock movements, recipes |
| **Leads** | Customer prospect tracking |
| **Aprovações** | Multi-step approval workflow |
| **Permissões** | Role-based access control |
| **Auditoria** | Full audit trail for all actions — viewer at `/admin/audit-logs` |
| **Configurações** | Global settings & feature control — master switches, RBAC UI, branding |

---

## Module: Configurações — Global Settings & Feature Control

**Route:** `GET /admin/configuracoes/` (and sub-routes)
**Blueprint:** `app/blueprints/configuracoes/routes.py` — registered at `/admin/configuracoes`
**Templates:** `app/templates/admin/configuracoes/`

### Access Control

Exclusive to `Role.is_super_admin = True`. HTTP 403 for any other profile.

### Features

| Feature | Route | Description |
|---|---|---|
| Module toggles | `POST /modulos/<chave>/toggle` | AJAX toggle to enable/disable modules |
| Branding | `POST /branding` | Override COMPANY_NAME and COMPANY_LOGO via DB |
| Roles list | `GET /roles` | List all roles with permission counts |
| Create role | `POST /roles/criar` | Create new role (with optional super_admin flag) |
| Edit permissions | `GET+POST /roles/<id>/permissoes` | Bulk toggle permissions per role |
| Deactivate role | `POST /roles/<id>/desativar` | Soft-delete role (blocked for super_admin roles) |

### Model

`SystemSetting` — `app/models/configuracoes.py`, table `sistur_system_settings`
Key-value store: `chave` (unique), `valor` (text), `tipo` (bool/string/int)

### Known Setting Keys

| Key | Type | Default | Description |
|---|---|---|---|
| `modulo.ponto` | bool | True | Enables/disables Ponto Eletrônico module |
| `modulo.estoque` | bool | True | Enables/disables Estoque module |
| `modulo.restaurante` | bool | True | Enables/disables Restaurante module |
| `modulo.financeiro` | bool | True | Enables/disables Financeiro module (placeholder) |
| `branding.empresa_nome` | string | None | Overrides COMPANY_NAME env var |
| `branding.empresa_logo` | string | None | Overrides COMPANY_LOGO env var |

### Master Switch Behavior

- Module OFF → `before_request` hook on that blueprint calls `abort(403)`
- Module OFF → card hidden from dashboard for non-super-admin users
- Super admin always bypasses module checks (lockout prevention)
- Dashboard shows "Desativado" badge on module cards visible to super admin

### Service

`ConfiguracaoService` — `app/services/configuracao_service.py`
Methods: `get(chave, default)`, `set(chave, valor, ator_id)`, `is_module_enabled(modulo)`, `get_all()`

---

## Module: RH — Dashboard de Recursos Humanos

**Route:** `GET /rh/` (dashboard principal com abas)
**Blueprint:** `app/blueprints/rh/routes.py` — registered at `/rh`
**Templates:** `app/templates/rh/index.html` (dashboard), `app/templates/rh/funcionarios/` (CRUD forms)

### Routes

| Route | Description |
|---|---|
| `GET /rh/` | Dashboard com abas: Visão Geral + Colaboradores |
| `GET /rh/funcionarios` | Listagem com filtros (q, area_id, status) — backward compat |
| `GET+POST /rh/funcionarios/novo` | Cadastro de funcionário |
| `GET+POST /rh/funcionarios/<id>/editar` | Edição (auditada) |
| `POST /rh/funcionarios/<id>/desativar` | Soft-delete |
| `POST /rh/funcionarios/<id>/reprocessar_ponto` | Reprocessamento em lote de ponto |

### Dashboard Query Params

| Param | Values | Default | Description |
|---|---|---|---|
| `aba` | `visao-geral` \| `colaboradores` | `visao-geral` | Selects active tab |
| `q` | string | "" | Free text search on nome/CPF (Tab 2) |
| `area_id` | int | None | Filter by Area.id (Tab 2) |
| `status` | `ativo` \| `inativo` \| `todos` | `ativo` | Employee active status (Tab 2) |

### Service: RHService

`app/services/rh_service.py` — read-only aggregations, no mutations, no Flask imports.

| Method | Description |
|---|---|
| `resumo_mensal(ano, mes)` | `func.sum()` on TimeDay: total employees, minutes, balance, days to review |
| `alertas_revisao(janela_dias=7)` | JOIN TimeDay+Funcionario where `needs_review=True` in last N days |
| `listar_com_filtros(q, area_id, status)` | Filtered Funcionario list (enforces Antigravity Rule #2 — no DB queries in blueprints) |

### Alert Center Logic

- Queries `TimeDay.needs_review = True` for past 7 days (configurable)
- Joins to `Funcionario` for name; filters `ativo=True`
- Displayed as cards in Tab 1 with direct link to edit form
- Count appears on the "Para Revisar" summary card

---

## Module: Admin — Audit Log Viewer

**Route:** `GET /admin/audit-logs`  
**Blueprint:** `app/blueprints/admin/routes.py` — registered at `/admin`  
**Template:** `app/templates/admin/audit_logs.html`

### Access Control (3 levels)

| Level | Permission | Access |
|---|---|---|
| Super Admin | `Role.is_super_admin = True` | All logs, all modules |
| Gerente | `audit.view_all` RolePermission | All logs, all modules |
| Supervisor | `audit.view` RolePermission | Logs filtered by own `Funcionario.area_id` |
| No permission | — | HTTP 403 |

> The dropdown filter for "Ator" is only shown to users with `audit.view_all`/super_admin.
> Supervisors only see the "Módulo" dropdown (scoped to their area's logs).

### Query Parameters

| Param | Type | Description |
|---|---|---|
| `user_id` | int | Filter by actor Funcionario ID |
| `module` | str | Filter by system module name |
| `page` | int | Page number (default: 1, 50 per page) |

### Implementation notes
- `AuditLog.user_id` is polymorphic (points to `sistur_funcionarios.id`); names resolved via in-memory dict
- Logs without `area_id` are invisible to supervisors (only gerente/super_admin see them)
- `.paginate(per_page=50, error_out=False)` prevents loading all records at once

---

## Module: Ponto Eletrônico — Strict Business Rules

These rules are non-negotiable. Any implementation must satisfy all six.

| # | Rule | Detail |
|---|------|--------|
| 1 | **Dual input** | Portal touch/click AND QR code camera scan (`ponto.py`) are equally valid; both write to the same `time_entries` table |
| 2 | **GPS geofencing only** | Use Haversine formula over lat/lon from `sistur_authorized_locations`; IP blocking is **forbidden** (browser privacy causes false positives) |
| 3 | **Admin CRUD fully audited** | Every admin insert/update/delete requires a non-empty `admin_change_reason` and must call `AuditService` — no exceptions |
| 4 | **10-minute tolerance** | Applies to delays (negative balance) only: if `abs(saldo) ≤ 10` → forgive to 0; if `abs(saldo) > 10` → penalize only the excess (`saldo + 10`); overtime is never affected |
| 5 | **Even/Odd pairing** | A day with an odd number of punches gets `needs_review=True` and the balance is frozen at 0 until corrected |
| 6 | **Review → Approvals** | Days with `needs_review=True` or unjustified absences must generate a pending item in the Aprovações module |

### Key legacy references for this module
- `legacy/includes/class-sistur-time-tracking.php` — Even/Odd pairing, duplicate-punch prevention (5-second window)
- `legacy/includes/class-sistur-timebank-manager.php` — Tolerance calculation, daily balance, `expected_minutes_snapshot`
- `legacy/mu-plugins/externo/ponto.py` — QR scanner: OpenCV + pyzbar, SHA256 auth, thermal printer receipt

### DB tables (to be created as SQLAlchemy models)
- `sistur_time_entries` — `employee_id`, `punch_time` (datetime), `shift_date` (date), `punch_type` (clock_in/lunch_start/lunch_end/clock_out/extra), `source` (employee/admin/KIOSK/QR), `processing_status` (PENDENTE/PROCESSADO), `admin_change_reason`, `changed_by_user_id`
- `sistur_time_days` — `employee_id`, `shift_date`, `minutos_trabalhados`, `saldo_calculado_minutos`, `saldo_final_minutos`, `needs_review` (bool), `expected_minutes_snapshot`, `schedule_id_snapshot`
- `sistur_authorized_locations` — `latitude`, `longitude`, `radius_meters`, `name`

---

## Database Tables (WordPress prefix `wp_`)

- `wp_sistur_employees`, `wp_sistur_departments`
- `wp_sistur_time_entries`, `wp_sistur_time_days`
- `wp_sistur_timebank`, `wp_sistur_payment_records`
- `wp_sistur_leads`
- `wp_sistur_products`, `wp_sistur_product_categories`, `wp_sistur_inventory_movements`

Schema for hour banking: `legacy/sql/create-timebank-tables.sql`

---

## Authentication & Security

- Employees log in via **CPF** (no password) — Brazilian tax ID
- Session tokens expire after **8 hours** (HTTPOnly, SameSite cookies)
- Python API bridge uses **SHA256 hash + 60-second timestamp** for QR punch validation
- All AJAX endpoints use **WordPress nonces**
- Input is sanitized and output is escaped throughout — never skip this
- Prepared statements only — no raw SQL string interpolation

---

## Development Guidelines

### Services Layer & Future Protection

**All core business logic must reside in `app/services/`.**
Routes must only call these services and handle HTTP responses.
This structure is mandatory to facilitate future code obfuscation/compilation
for commercial distribution.

```
app/services/
├── base.py                  # BaseService: _snapshot(), _require()
├── funcionario_service.py   # FuncionarioService: criar, atualizar, desativar
└── banco_horas_service.py   # BancoDeHorasService: calcular_saldo_dia, formatar_minutos
```

**Rules for every service file:**
1. **No Flask imports** — no `request`, `session`, `current_app`, `g`
2. Receive explicit parameters; never reach into HTTP context
3. Call `AuditService` for every mutation (Antigravity Rule #1)
4. Use `BaseService._snapshot(obj, fields)` to build audit state dicts
5. Accept `actor_id: int | None` (not a User object) for Cython compatibility

**Blueprint → Service call pattern:**
```python
# In a route (blueprint):
from app.services.funcionario_service import FuncionarioService

@bp.route("/funcionarios", methods=["POST"])
@login_required
def criar_funcionario():
    ator_id = session["funcionario_id"]
    try:
        f = FuncionarioService.criar(
            nome=request.form["nome"],
            cpf=request.form["cpf"],
            ator_id=ator_id,
        )
    except ValueError as exc:
        flash(str(exc), "erro")
        return render_template("..."), 400
    return redirect(url_for("..."))
```

See `docs/antigravity.md` for the full layered architecture specification.

---

### Testing Protocol

Every new feature **must** include unit tests before merging to `develop`.

**Run tests:**
```bash
pytest                        # all tests
pytest tests/services/        # service layer only
pytest -v --tb=short          # verbose with short tracebacks
```

**Rules:**
1. Tests run against **SQLite in-memory** — never against dev/prod database
2. Every mutation test must assert that **exactly one `AuditLog` row** was created
3. Hardware integrations (printer, QR scanner) must use mocks from `tests/hardware/mocks.py`
4. The `db` fixture in `conftest.py` is `autouse=True` — no manual teardown needed
5. Use `app.app_context()` inside tests when calling services directly

**Audit assertion pattern:**
```python
def test_criar_dispara_audit(app, db):
    with app.app_context():
        f = FuncionarioService.criar(nome="Teste", cpf="52998224725")
        count = db.session.query(AuditLog).filter_by(
            action=AuditAction.create, entity_id=f.id
        ).count()
    assert count == 1
```

### Documentation Protocol

Every module has a dedicated directory under `docs/`:

```
docs/
├── antigravity.md            # Non-negotiable architecture rules
├── api_catalog.md            # All endpoints, schemas, error codes
├── auth/README.md
├── funcionarios/README.md
├── banco_horas/README.md
├── ponto/README.md
└── estoque/README.md
```

**Rules:**
- Document new endpoints in `docs/api_catalog.md` when adding routes
- Document business rules and edge cases in the module's `README.md`
- Reference the legacy PHP equivalent when porting a module

---

### When implementing a feature
1. Read the corresponding PHP class in `legacy/includes/` to understand the business logic
2. Check `legacy/docs/` for documentation and edge cases — especially for Banco de Horas
3. Replicate the behavior, not the code structure — Flask patterns, not WordPress patterns
4. Verify Brazilian-specific business rules (CLT labor law, CPF format, etc.) against `legacy/docs/`

### Code standards
- **Flask blueprints** for each module (one blueprint per business area)
- **SQLAlchemy models** for all DB access — no raw SQL unless absolutely necessary
- **Naming:** Use Portuguese names for business entities (`funcionario`, `banco_horas`, `ponto`, `estoque`)
- **Security:** Validate all input at the boundary, use parameterized queries, sanitize output
- **Docstrings:** Every function and method must have a Google-style docstring in **Portuguese (pt-BR)**
- **Templates:** Always mobile-first — see UI standards below

### Templates & UI — Mobile-First Standards

The primary users of the Portal do Colaborador are **employees on their phones** (Android, iOS).
Every Jinja2 template must be designed mobile-first before scaling up to desktop.

**Non-negotiable rules for every template:**

| Rule | Requirement |
|------|-------------|
| **Default layout is single-column** | Stack everything vertically on mobile; expand to 2-3 cols at `sm`/`lg` |
| **Touch targets ≥ 44px** | Buttons, links, and interactive elements must be at least 44×44 px |
| **Readable font sizes** | Body text ≥ 14px; labels ≥ 12px; never smaller |
| **No horizontal scroll** | Content must fit viewport at 375px width with no overflow |
| **Hero/banner sections** | Must collapse gracefully — side-by-side panels become stacked on mobile |
| **Tables on mobile** | Replace wide tables with cards or add horizontal scroll (`overflow-x: auto`) |
| **Forms** | Full-width inputs on mobile; never place two inputs side-by-side below `sm` |
| **Navbar/top bar** | Employee name and secondary labels can hide on mobile (`hidden sm:block`) |

**CSS breakpoint convention (Tailwind):**

```
Mobile first (default) → no prefix   → 0px+
Small tablet           → sm:          → 640px+
Tablet / small laptop  → md:          → 768px+
Desktop                → lg:          → 1024px+
```

**Checklist before any template commit:**
- [ ] Viewed at 375px width (iPhone SE) in browser DevTools
- [ ] Viewed at 768px width (iPad portrait)
- [ ] All buttons/links are easily tappable
- [ ] No content is cut off or requires horizontal scroll
- [ ] Forms are usable with an on-screen keyboard (inputs not hidden behind it)

### Docstring Standard (Google-style, pt-BR)

Every function and method — in services, blueprints, models, and utilities — **must** have a docstring.
Use Google-style format. Write in Portuguese. Document intent, not implementation.

**Template obrigatório para serviços:**
```python
def criar(cls, nome: str, cpf: str, ator_id: int | None = None) -> Funcionario:
    """Cria um novo funcionário e registra o evento no log de auditoria.

    Args:
        nome: Nome completo do funcionário.
        cpf: CPF no formato somente dígitos (11 caracteres).
        ator_id: ID do usuário que está realizando a ação. None em operações de sistema.

    Returns:
        Instância de Funcionario persistida no banco de dados.

    Raises:
        ValueError: Se o CPF for inválido ou já estiver cadastrado.
    """
```

**Template para rotas (blueprints):**
```python
@bp.route("/funcionarios", methods=["POST"])
@login_required
def criar_funcionario():
    """Recebe o formulário de cadastro e delega criação ao FuncionarioService.

    Form fields:
        nome (str): Nome completo.
        cpf (str): CPF somente dígitos.

    Returns:
        Redirect para a listagem em caso de sucesso, ou renderiza o
        formulário com mensagem de erro (HTTP 400) em caso de falha.
    """
```

**Regras:**
1. Docstrings em **português (pt-BR)** — idioma do domínio de negócio do projeto
2. A primeira linha é um resumo conciso da responsabilidade (imperativo, sem ponto final)
3. Seções `Args:`, `Returns:` e `Raises:` são **obrigatórias** quando aplicáveis
4. Não documente o óbvio — documente regras de negócio, restrições e comportamentos não triviais
5. Métodos privados (prefixo `_`) também precisam de docstring se a lógica não for autoexplicativa

### Port priority order (reference)
Follow the dependency order from the legacy system:
1. Core auth / session (CPF login)
2. Funcionários (employees)
3. Ponto Eletrônico (time tracking)
4. Banco de Horas (hour banking — most complex)
5. Estoque / CMV (inventory)
6. Leads
7. Aprovações, Auditoria, Permissões

### What NOT to do
- Do not modify anything in `legacy/` — it is read-only reference
- Do not port WordPress-specific patterns (hooks, shortcodes, nonces) — use Flask equivalents
- Do not assume Portuguese business logic — always verify against `legacy/docs/`
- Do not commit directly to `main`; use `develop` for deployed changes
- Do not add abstractions for one-time operations — keep it simple

---

## Deployment

- **CI/CD:** Push to `develop` → GitHub Actions → SSH into VPS → `docker-compose up -d --build`
- Secrets required: `VPS_HOST`, `VPS_USER`, `VPS_SSH_KEY`
- See `.github/workflows/deploy.yml` for full pipeline

### Local Python setup
```bash
pip install -r requirements.txt
```

---

## Database Management & Migrations

**Flask-Migrate** is configured for automatic schema versioning. Use it whenever models change.

### Initial Setup (VPS after first deployment)

```bash
# Create migrations folder (one-time, after flask deploy)
flask db init

# Run pending migrations to initialize production schema
flask db upgrade
```

### Development Workflow

When you **modify or add SQLAlchemy models**:

```bash
# 1. Auto-generate migration from model changes
flask db migrate -m "Descriptive message of what changed"

# 2. Review the generated migration file in migrations/versions/
# 3. Commit to git
git add migrations/ && git commit -m "...migration..."

# 4. On VPS: deployment automatically runs `flask db upgrade`
#    (configured in .github/workflows/deploy.yml)
```

### Common Commands

| Command | Purpose |
|---------|---------|
| `flask db init` | Create migrations folder (one-time) |
| `flask db migrate -m "message"` | Auto-generate migration from model changes |
| `flask db upgrade` | Apply pending migrations to database |
| `flask db downgrade` | Rollback to previous schema version |
| `flask db current` | Show current schema revision |
| `flask db history` | View migration history |

**Important:** Never edit migration files manually — they are version-controlled source of truth.

---

## Commit Guidelines

1. Write commit messages in **English**, clear and descriptive
2. Make atomic commits — one logical change per commit
3. Examples:
   - `Fix duplicate punch prevention in time tracking`
   - `Add inventory movement endpoint to Flask API`
   - `Refactor hour banking calculation for DST edge case`

---

## Legacy Reference Index

Use these when porting a module to understand the original business logic:

| Topic | Legacy reference |
|---|---|
| Hour banking rules | `legacy/TROUBLESHOOTING-BANCO-HORAS.md`, `legacy/docs/REFATORACAO-BANCO-HORAS.md`, `legacy/docs/BANCO_DE_HORAS/` |
| Hour reprocessing | `legacy/REPROCESSAR_HORAS.md` |
| DB schema | `legacy/sql/create-timebank-tables.sql` |
| Approval workflow | `legacy/includes/class-sistur-approvals.php` |
| Time tracking logic | `legacy/includes/class-sistur-time-tracking.php`, `legacy/includes/class-sistur-timebank-manager.php` |
| QR scanner app | `legacy/mu-plugins/externo/ponto.py` |
| Permissions system | `legacy/PERMISSIONS_SYSTEM.md`, `legacy/includes/class-sistur-permissions.php` |
| WiFi time clock | `legacy/WIFI_TIME_CLOCK.md` |
