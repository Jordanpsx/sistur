# CLAUDE.md — SISTUR Project

## Project Overview

**SISTUR** is a web-based ERP for tourism operations (clubs and tourist attractions).
It manages HR, electronic time tracking (Ponto Eletrônico), inventory, leads, and more.

- **Language context:** Portuguese (pt-BR) — all business logic, variable names, and user-facing text are in Brazilian Portuguese
- **Active development stack:** Python 3 / Flask + SQLAlchemy + MySQL
- **Deployment:** Docker Compose on VPS via GitHub Actions (triggered on `develop` branch push)

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

### Python Tooling (to be ported)
- `opencv-python` + `pyzbar` — QR code scanner via webcam (reference: `legacy/mu-plugins/externo/ponto.py`)
- `win32print` — Thermal printer integration (Windows)

---

## Key Business Modules

| Module (pt-BR) | Description |
|---|---|
| **Funcionários** | Employee data, departments, salaries |
| **Ponto Eletrônico** | Electronic punch clock via QR code |
| **Banco de Horas** | Hour banking — most complex module, see `legacy/docs/BANCO_DE_HORAS/` |
| **Estoque / CMV** | Inventory, stock movements, recipes |
| **Leads** | Customer prospect tracking |
| **Aprovações** | Multi-step approval workflow |
| **Permissões** | Role-based access control |
| **Auditoria** | Full audit trail for all actions |

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
