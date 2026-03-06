# Módulo: Reservas — Generic Booking System API

> **Source of truth** for the Reservas (Bookings) API contract.
> Update this file whenever a reservas endpoint is added, changed, or removed.
> See Rule #12 in `CLAUDE.md` and `docs/antigravity.md` for the enforcement policy.

---

## Overview

Reservas is a **headless API backend** designed to support multiple frontends (legacy WordPress, in-house portal, physical POS counter). The system manages generic bookings across different venues ("sources"), categories, and items with support for:

- **Soft deletion:** Records are never physically deleted; `deleted_at IS NULL` marks active records.
- **Versioning:** Edits do not update existing rows; instead, a new row is created with the same `group_id` and incremented `version`, and the old row is marked `status=ARCHIVED_VERSION`.
- **Dynamic RBAC:** New venues automatically create permission entries for all super-admin roles.

---

## Data Models

### ReservaSource

Represents a venue or location where bookings are made (e.g., "Cachoeira", "Vinhedo").

**JSON schema (read):**

```json
{
  "id": 1,
  "name": "Cachoeira",
  "is_active": true,
  "deleted_at": null,
  "criado_em": "2026-02-24T18:30:00Z",
  "categories": [
    {
      "id": 1,
      "name": "Day Use",
      "deleted_at": null,
      "criado_em": "2026-02-24T18:30:00Z"
    }
  ]
}
```

### ReservaCategory

A category of bookings within a source (e.g., "Day Use", "Camping").

**JSON schema (read):**

```json
{
  "id": 1,
  "source_id": 1,
  "name": "Day Use",
  "deleted_at": null,
  "criado_em": "2026-02-24T18:30:00Z"
}
```

### ReservaItem

An item available for booking (e.g., "Cama", "Lanche", "Atividade").

**JSON schema (read):**

```json
{
  "id": 1,
  "name": "Cama individual",
  "billing_type": "PER_DAY",
  "stock_quantity": 10,
  "requires_deposit": false,
  "deleted_at": null,
  "criado_em": "2026-02-24T18:30:00Z"
}
```

**Billing types:**
- `FIXED` — fixed price per booking
- `PER_DAY` — charged per day in the reservation window
- `PER_HOUR` — charged by the hour

**Stock:** `null` = unlimited inventory; integer ≥ 0 = limited quantity

### Reserva

The booking itself. Uses **versioning**: edits create new rows with same `group_id` and incremented `version`.

**JSON schema (read):**

```json
{
  "id": 1,
  "group_id": "550e8400-e29b-41d4-a716-446655440000",
  "version": 1,
  "source_id": 1,
  "category_id": 1,
  "customer_name": "João Silva",
  "customer_document": "12345678901",
  "origin": "WEB",
  "status": "PENDING",
  "check_in_date": "2026-03-15",
  "check_out_date": "2026-03-17",
  "expires_at": "2026-03-15T09:15:00Z",
  "deleted_at": null,
  "criado_em": "2026-02-24T18:30:00Z",
  "atualizado_em": "2026-02-24T18:30:00Z",
  "items": [
    {
      "id": 1,
      "reserva_id": 1,
      "item_id": 1,
      "quantity": 2,
      "locked_price": "150.00",
      "deleted_at": null
    }
  ]
}
```

**Origins:**
- `WEB` — booked via web portal
- `BALCAO` — booked at the counter

**Statuses:**
- `PENDING` — awaiting payment or confirmation
- `PAID` — payment confirmed
- `CANCELED` — booking cancelled
- `COMPLETED` — check-out completed
- `ARCHIVED_VERSION` — previous version of a booking (marked when new version created)

### ReservaItemLink

Association between a booking and an item, with a quantity and price snapshot.

**JSON schema (read):**

```json
{
  "id": 1,
  "reserva_id": 1,
  "item_id": 1,
  "quantity": 2,
  "locked_price": "150.00",
  "deleted_at": null
}
```

**Note:** `locked_price` is a snapshot of the price at booking time (Numeric, not a FK). This ensures historical accuracy.

---

## Endpoints

### Module: Reservas (`/reservas`)

#### `GET /reservas/`

List all active bookings.

**Auth required:** Yes → redirects to `/portal/login`
**Permission required:** `reservas` → `view`
**Query parameters:**

| Param | Type | Default | Description |
|---|---|---|---|
| `q` | string | "" | Free-text search on customer name or CPF |
| `source_id` | int | null | Filter by venue ID |
| `status` | enum | "" | Filter by booking status (PENDING, PAID, CANCELED, COMPLETED) |
| `page` | int | 1 | Pagination (50 per page) |

**Response:** `200 application/json`

```json
{
  "reservas": [
    {
      "id": 1,
      "group_id": "550e8400-e29b-41d4-a716-446655440000",
      "version": 1,
      "source": {"id": 1, "name": "Cachoeira"},
      "category": {"id": 1, "name": "Day Use"},
      "customer_name": "João Silva",
      "customer_document": "12345678901",
      "origin": "WEB",
      "status": "PENDING",
      "check_in_date": "2026-03-15",
      "check_out_date": "2026-03-17",
      "criado_em": "2026-02-24T18:30:00Z"
    }
  ],
  "total": 42,
  "page": 1,
  "per_page": 50
}
```

**Error responses:**

| Scenario | Status | Body |
|---|---|---|
| Unauthenticated | 401 | Redirect to `/portal/login` |
| Missing permission | 403 | `{"erro": "NAO_AUTORIZADO"}` |

**Audit:** No (read-only)

---

#### `GET /reservas/sources`

List all active venues (ReservaSource).

**Auth required:** Yes → redirects to `/portal/login`
**Permission required:** `reservas` → `view`

**Response:** `200 application/json`

```json
{
  "sources": [
    {
      "id": 1,
      "name": "Cachoeira",
      "is_active": true,
      "criado_em": "2026-02-24T18:30:00Z",
      "categories": [
        {
          "id": 1,
          "name": "Day Use",
          "criado_em": "2026-02-24T18:30:00Z"
        }
      ]
    }
  ]
}
```

**Audit:** No (read-only)

---

#### `GET /reservas/<int:reserva_id>`

Retrieve a single booking by ID.

**Auth required:** Yes → redirects to `/portal/login`
**Permission required:** `reservas` → `view`
**Path parameters:**

| Param | Type | Description |
|---|---|---|
| `reserva_id` | int | Primary key of the booking |

**Response:** `200 application/json`

```json
{
  "reserva": {
    "id": 1,
    "group_id": "550e8400-e29b-41d4-a716-446655440000",
    "version": 1,
    "source": {"id": 1, "name": "Cachoeira"},
    "category": {"id": 1, "name": "Day Use"},
    "customer_name": "João Silva",
    "customer_document": "12345678901",
    "origin": "WEB",
    "status": "PENDING",
    "check_in_date": "2026-03-15",
    "check_out_date": "2026-03-17",
    "expires_at": "2026-03-15T09:15:00Z",
    "items": [
      {
        "item": {"id": 1, "name": "Cama individual"},
        "quantity": 2,
        "locked_price": "150.00"
      }
    ],
    "criado_em": "2026-02-24T18:30:00Z",
    "atualizado_em": "2026-02-24T18:30:00Z"
  }
}
```

**Error responses:**

| Scenario | Status | Body |
|---|---|---|
| Reservation not found | 404 | `{"erro": "NAO_ENCONTRADO"}` |
| Unauthenticated | 401 | Redirect to `/portal/login` |
| Missing permission | 403 | `{"erro": "NAO_AUTORIZADO"}` |

**Audit:** No (read-only)

---

## Future Endpoints (Planning)

The following endpoints are planned but not yet implemented:

### `POST /reservas/`

Create a new booking.

**Expected request body:**

```json
{
  "source_id": 1,
  "category_id": 1,
  "customer_name": "João Silva",
  "customer_document": "12345678901",
  "origin": "WEB",
  "check_in_date": "2026-03-15",
  "check_out_date": "2026-03-17",
  "items": [
    {
      "item_id": 1,
      "quantity": 2
    }
  ]
}
```

**Expected behavior:**
- Generate a new `group_id` (UUID v4)
- Create `Reserva` row with `version=1`, `status=PENDING`
- Create `ReservaItemLink` rows for each item
- Log creation in `AuditLog`
- Return `201 Created` with the new booking

---

### `POST /reservas/<int:reserva_id>/editar`

Edit an existing booking (creates a new version).

**Expected request body:**

```json
{
  "customer_name": "João Silva Atualizado",
  "check_out_date": "2026-03-18",
  "items": [
    {
      "item_id": 1,
      "quantity": 3
    }
  ]
}
```

**Expected behavior:**
- Mark old row as `status=ARCHIVED_VERSION`
- Create new row with same `group_id`, incremented `version`, and updated fields
- Create new `ReservaItemLink` rows for the new version
- Log the edit in `AuditLog` with `previous_state` and `new_state`
- Return `200 OK` with the new booking

---

### `POST /reservas/<int:reserva_id>/cancelar`

Cancel a booking.

**Expected behavior:**
- Mark booking as `status=CANCELED`
- Log the cancellation in `AuditLog`
- Return `200 OK`

---

## Versioning & Editing Behavior

When a booking is edited:

1. **Old row:** Marked with `status=ARCHIVED_VERSION` and remains in the database
2. **New row:** Inserted with the same `group_id`, version incremented, and updated field values
3. **Both rows:** Share the same `group_id` (UUID). The unique constraint `(group_id, version)` ensures no duplicate versions
4. **Audit trail:** All versions are preserved in the database; `AuditLog` captures the change

**Query active version:**

```sql
SELECT * FROM sistur_reservas
WHERE group_id = '550e8400-e29b-41d4-a716-446655440000'
  AND status != 'ARCHIVED_VERSION'
  AND deleted_at IS NULL
ORDER BY version DESC
LIMIT 1;
```

---

## Soft Delete Pattern

All Reservas models use `deleted_at: DateTime` for logical deletion (instead of `ativo: Boolean`).

**Rules:**
- `deleted_at IS NULL` → record is **active**
- `deleted_at IS NOT NULL` → record is **soft-deleted**
- Queries must always filter `deleted_at IS NULL` by default
- Physical deletion is **never** performed (preserves audit trail)

**Query active records:**

```sql
SELECT * FROM sistur_reservas WHERE deleted_at IS NULL;
```

---

## Dynamic RBAC Hook

When a new `ReservaSource` is inserted, a SQLAlchemy event listener automatically creates permission entries for all `super_admin` roles.

**Permissions created:**
- `modulo = "reservas_{source_name_lower}"`
- `acao = "view"`
- `acao = "edit"`

**Example:** Inserting `ReservaSource(name="Cachoeira")` creates:
- `RolePermission(role_id=1, modulo="reservas_cachoeira", acao="view")`
- `RolePermission(role_id=1, modulo="reservas_cachoeira", acao="edit")`

---

## Error Format

All error responses follow the standard format:

```json
{
  "erro": "ERROR_CODE",
  "mensagem": "Human-readable error message"
}
```

**Common error codes:**

| Code | Meaning |
|---|---|
| `NAO_AUTORIZADO` | Authenticated but lacks permission |
| `NAO_ENCONTRADO` | Resource does not exist |
| `VALIDACAO_FALHOU` | Invalid input (e.g., missing required field) |
| `RECURSO_DUPLICADO` | Resource already exists (e.g., duplicate venue name) |
| `ESTOQUE_INSUFICIENTE` | Item quantity exceeds available stock |

---

## Timestamps

All timestamps are **ISO 8601, UTC**:
- Format: `"2026-02-24T18:30:00Z"`
- Always UTC (`Z` suffix or `+00:00`)
- Used in: `criado_em`, `atualizado_em`, `deleted_at`, `expires_at`

---

## Auth & Permissions

All endpoints (except `/portal/login` and `/portal/logout`) require:

1. **Session cookie:** `funcionario_id` in the server-side session
2. **RBAC permission:** The route decorator `@require_permission(modulo, acao)` enforces access control
3. **Super-admin bypass:** Users with `Role.is_super_admin=True` bypass all permission checks

**Permission format:**
- `modulo` = "reservas"
- `acao` = "view" | "create" | "edit" | "delete"

---

## Service Layer

Business logic for all operations lives in `app/services/reserva_service.py` (to be implemented).

**Mandatory operations:**

1. **Every mutation calls `AuditService`** (Antigravity Rule #1)
   - `AuditService.log_create()` — when creating
   - `AuditService.log_update()` — when editing (with `previous_state` and `new_state`)
   - `AuditService.log_delete()` — when soft-deleting

2. **All service methods are `@staticmethod`** with `ator_id: int | None` parameter
   - No Flask imports
   - Pure Python, Cython-ready

**Example signature (to be implemented):**

```python
class ReservaService:
    @staticmethod
    def criar(
        source_id: int,
        category_id: int,
        customer_name: str,
        customer_document: str | None,
        origin: ReservaOrigin,
        check_in_date: date,
        check_out_date: date | None,
        items: list[dict],
        ator_id: int | None = None,
    ) -> Reserva:
        """Cria uma nova reserva com itens associados.

        Args:
            source_id: ID da fonte (venue).
            category_id: ID da categoria.
            customer_name: Nome do cliente.
            customer_document: CPF ou CNPJ (opcional).
            origin: Origem da reserva (WEB ou BALCAO).
            check_in_date: Data de check-in.
            check_out_date: Data de check-out (opcional).
            items: Lista de {"item_id": int, "quantity": int}.
            ator_id: ID do usuário que está criando a reserva.

        Returns:
            Instância de Reserva persistida no banco de dados.

        Raises:
            ValueError: Se source_id, category_id ou item_id não existir.
            ValueError: Se estoque for insuficiente.
        """
```

---

## References

- **Legacy reference:** Not applicable (Reservas is new module)
- **Models:** `app/models/reservas.py`
- **Blueprint:** `app/blueprints/reservas/routes.py`
- **Service:** `app/services/reserva_service.py` (to be implemented)
