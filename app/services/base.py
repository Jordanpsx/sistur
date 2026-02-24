# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
BaseService — abstract foundation for all SISTUR service classes.

Design constraints (Antigravity Rule #2 + Cython compatibility):
- NO Flask imports (no request, session, current_app, g).
- Services receive explicit parameters; they never reach into HTTP context.
- This file must compile via Cython without modification.
"""

from __future__ import annotations

from typing import Any


class BaseService:
    """
    Shared utilities for all service classes.

    Subclass this for every business domain:
        class FuncionarioService(BaseService): ...
        class BancoDeHorasService(BaseService): ...
    """

    # ------------------------------------------------------------------
    # Audit snapshot helpers
    # ------------------------------------------------------------------

    @staticmethod
    def _snapshot(obj: Any, fields: list[str]) -> dict[str, Any]:
        """
        Build a JSON-serialisable dict from a SQLAlchemy model instance.

        Args:
            obj:    Model instance to snapshot.
            fields: List of attribute names to include.

        Returns:
            Dict suitable for AuditLog.previous_state / new_state.

        Example:
            snap = BaseService._snapshot(func, ["id", "nome", "cpf", "ativo"])
        """
        result: dict[str, Any] = {}
        for field in fields:
            value = getattr(obj, field, None)
            # Coerce non-serialisable types
            if hasattr(value, "isoformat"):        # date / datetime
                value = value.isoformat()
            elif hasattr(value, "value"):          # Enum
                value = value.value
            result[field] = value
        return result

    # ------------------------------------------------------------------
    # Validation helpers (pure Python — no Flask)
    # ------------------------------------------------------------------

    @staticmethod
    def _require(value: Any, field_name: str) -> Any:
        """Raise ValueError if value is None or empty string."""
        if value is None or value == "":
            raise ValueError(f"Campo obrigatório ausente: '{field_name}'.")
        return value
