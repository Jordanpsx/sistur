# Antigravity Rules — SISTUR Python

> These rules are **non-negotiable constraints** that apply to every feature
> implemented in the SISTUR Python rewrite.  When in doubt, re-read this file.

---

## Rule #1 — Mandatory Audit Logging

For every **mutation** (create, update, delete, state change) implemented in the
Employee Portal, a comprehensive audit log entry is **strictly required**.

Each `AuditLog` row must capture:

| Field | Requirement |
|---|---|
| `action` | What operation was performed (`create`, `update`, `delete`, `login`, …) |
| `previous_state` | JSON snapshot of the data **before** the change |
| `new_state` | JSON snapshot of the data **after** the change |
| `user_id` | ID of the actor who performed the action |
| `user_type` | `employee` (Funcionario) or `admin` (User) |
| `module` | System area the action belongs to (e.g. `'funcionarios'`, `'banco_horas'`) |
| `entity_id` | Primary key of the affected row |
| `ip_address` | Request IP (captured automatically by AuditService) |
| `created_at` | UTC timestamp (ISO 8601) |

**Implementation:** always use `AuditService` from `app/core/audit.py`.
Never write raw `AuditLog` rows from routes or services directly.

```python
# Correct — inside a service method
AuditService.log_update(
    "funcionarios",
    entity_id=funcionario.id,
    previous_state=snapshot_antes,
    new_state=snapshot_depois,
    actor_id=ator_id,
)
```

---

## Rule #2 — Layered Architecture

Code is strictly separated into three layers.
**No layer may import from a layer above it.**

```
┌─────────────────────────────────────────────────────────┐
│  Blueprints / Routes  (app/blueprints/)                 │
│  • HTTP handling only: parse request, call service,     │
│    return response / render template                    │
│  • No business logic. No math. No DB queries.           │
├─────────────────────────────────────────────────────────┤
│  Services  (app/services/)                              │
│  • All business logic and calculations live here        │
│  • Pure Python — no Flask imports (no request/session)  │
│  • Receives explicit parameters; raises Python errors   │
│  • Calls AuditService for every mutation (Rule #1)      │
│  • Designed to be compiled via Cython without API break │
├─────────────────────────────────────────────────────────┤
│  Models  (app/models/, app/core/models.py)              │
│  • SQLAlchemy ORM definitions only                      │
│  • No business logic; only simple computed properties   │
└─────────────────────────────────────────────────────────┘
```

**Why this matters:**
The services layer will be compiled via **Cython** before commercial distribution
to protect proprietary business logic (CLT calculations, Banco de Horas rules, etc.).
A service must be replaceable by its compiled `.so` equivalent without any change
to the blueprint that calls it.

---

## Rule #3 — Deploy via develop Branch

Changes are deployed to the VPS by pushing to `develop`.
The GitHub Actions workflow SSHes into the VPS and runs `docker compose up -d --build`.

**Never push secrets, credentials, or `.env` files to the repository.**

---

## Rule #4 — Portal do Colaborador is the Target

All new features go into the Python **Portal do Colaborador**.
Do not modify the WordPress admin panel unless explicitly instructed.

---

## Rule #5 — Read CLAUDE.md First

Before making any change, read `CLAUDE.md` for project conventions,
naming rules, port priority, and the legacy reference index.
