# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
PontoService — foundational business logic for the Ponto Eletrônico module.

Antigravity Rule #1: every mutation calls AuditService.
Antigravity Rule #2: no Flask imports — pure Python, Cython-compatible.

Business rules enforced here
------------------------------
Rule #4  10-minute tolerance: forgives delays ≤ 10 min; penalises only excess.
Rule #5  Even/Odd pairing: odd punch count → needs_review=True, balance frozen.

Rules #1, #2, #3, #6 are enforced at the route/model layer or are process rules
that do not belong in a pure-calculation service.
"""

from __future__ import annotations

from datetime import datetime
from typing import Any

from app.core.audit import AuditService
from app.services.base import BaseService


# ---------------------------------------------------------------------------
# Module-private helpers
# ---------------------------------------------------------------------------

def _apply_tolerance(saldo_bruto: int, tolerance: int) -> int:
    """
    Apply the 10-minute tolerance rule (Rule #4).

    Tolerance applies to delays (negative balances) only.
    Overtime (positive balance) is returned unchanged.

    Args:
        saldo_bruto: Raw balance in minutes (worked - expected).
        tolerance:   Tolerance threshold in minutes (default 10).

    Returns:
        Adjusted balance in minutes.

    Examples:
        _apply_tolerance(-8, 10)  → 0    (forgiven — within tolerance)
        _apply_tolerance(-25, 10) → -15  (only excess penalised)
        _apply_tolerance(30, 10)  → 30   (overtime unaffected)
    """
    if saldo_bruto >= 0:
        return saldo_bruto
    if abs(saldo_bruto) <= tolerance:
        return 0
    return saldo_bruto + tolerance


# ---------------------------------------------------------------------------
# Service
# ---------------------------------------------------------------------------

class PontoService(BaseService):
    """
    Core calculations for the Ponto Eletrônico module.

    All methods are static — no instance state — so the class compiles
    cleanly via Cython and is importable with no side effects.

    NOTE: SQLAlchemy model TimeEntry does not exist yet.
          editar_batida_admin is a documented stub that raises NotImplementedError
          until the model is created in the next iteration.
    """

    TOLERANCE_MINUTES: int = 10

    # ------------------------------------------------------------------
    # Calculations (pure — no DB access)
    # ------------------------------------------------------------------

    @staticmethod
    def calculate_daily_balance(
        punch_list: list[dict[str, Any]],
        expected_minutes: int,
        almoco_minutes: int = 60,
    ) -> dict[str, Any]:
        """
        Calculate the day's hour-bank balance from an ordered punch list.

        Implements Rule #4 (tolerance) and Rule #5 (even/odd pairing).

        Args:
            punch_list:       List of dicts with at least {'time': datetime}.
                              Must be sorted ascending by 'time'.
            expected_minutes: Minutes expected to be worked that day.
                              Use jornada_semanal value or Funcionario.minutos_esperados_dia.
            almoco_minutes:   Expected lunch break in minutes (default 60).
                              Deducted only when 4+ punches are present
                              (i.e. a full lunch pair was recorded).

        Returns:
            {
                'minutos_trabalhados':    int,   # raw minutes between punch pairs
                'saldo_calculado_minutos': int,  # after Rule #4 tolerance
                'needs_review':           bool,  # True when punch count is odd (Rule #5)
            }

        Examples:
            4 punches, 9h worked, 1h lunch, expected 8h → saldo = 0
            3 punches                                   → needs_review=True, saldo=0
        """
        # Rule #5: odd punch count → freeze calculation
        if len(punch_list) % 2 != 0:
            return {
                "minutos_trabalhados": 0,
                "saldo_calculado_minutos": 0,
                "needs_review": True,
            }

        # Sum adjacent pairs: (entry[0], entry[1]), (entry[2], entry[3]), ...
        worked: int = 0
        for i in range(0, len(punch_list), 2):
            entrada: datetime = punch_list[i]["time"]
            saida: datetime = punch_list[i + 1]["time"]
            worked += int((saida - entrada).total_seconds() / 60)

        # Deduct lunch only when a full lunch pair was recorded (≥ 4 punches)
        if len(punch_list) >= 4:
            worked -= almoco_minutes

        saldo_bruto: int = worked - expected_minutes

        # Rule #4: apply tolerance
        saldo_final: int = _apply_tolerance(saldo_bruto, PontoService.TOLERANCE_MINUTES)

        return {
            "minutos_trabalhados": worked,
            "saldo_calculado_minutos": saldo_final,
            "needs_review": False,
        }

    # ------------------------------------------------------------------
    # Mutations (require AuditService — Rule #3)
    # ------------------------------------------------------------------

    @staticmethod
    def editar_batida_admin(
        time_entry_id: int,
        novo_horario: datetime,
        motivo: str,
        ator_id: int,
    ) -> dict[str, Any]:
        """
        Edit the timestamp of an existing punch (admin-only operation).

        Antigravity Rule #1: records an AuditLog with previous and new state.
        Antigravity Rule #2: no Flask imports.
        Rule #3: non-empty `motivo` is mandatory.

        Args:
            time_entry_id: PK of the time entry to edit.
            novo_horario:  New datetime for punch_time.
            motivo:        Reason for the edit — mandatory, stored as admin_change_reason.
            ator_id:       ID of the admin Funcionario performing the action.

        Returns:
            Dict snapshot of the updated entry (id, punch_time, motivo).

        Raises:
            ValueError:          If motivo is empty.
            NotImplementedError: Until the TimeEntry SQLAlchemy model is created.

        Expected TimeEntry fields (for reference when model is created):
            id                  INT PK
            employee_id         INT FK sistur_funcionarios
            punch_time          DATETIME
            shift_date          DATE
            punch_type          ENUM(clock_in, lunch_start, lunch_end, clock_out, extra)
            source              ENUM(employee, admin, KIOSK, QR)
            processing_status   ENUM(PENDENTE, PROCESSADO)
            admin_change_reason TEXT nullable
            changed_by_user_id  INT nullable FK sistur_funcionarios
        """
        BaseService._require(motivo, "motivo")

        # TODO: replace stub with real implementation once TimeEntry model exists.
        # Template for the real implementation:
        #
        #   from app.models.ponto import TimeEntry
        #
        #   entry = db.session.get(TimeEntry, time_entry_id)
        #   if not entry:
        #       raise ValueError(f"Batida {time_entry_id} não encontrada.")
        #
        #   snapshot_antes = {
        #       "punch_time": entry.punch_time.isoformat(),
        #       "source": entry.source,
        #   }
        #   entry.punch_time = novo_horario
        #   entry.source = "admin"
        #   entry.admin_change_reason = motivo.strip()
        #   entry.changed_by_user_id = ator_id
        #   db.session.flush()
        #
        #   AuditService.log_update(
        #       "time_entries",
        #       entity_id=entry.id,
        #       previous_state=snapshot_antes,
        #       new_state={
        #           "punch_time": entry.punch_time.isoformat(),
        #           "source": entry.source,
        #           "admin_change_reason": entry.admin_change_reason,
        #       },
        #       actor_id=ator_id,
        #   )
        #   db.session.commit()
        #   return {"id": entry.id, "punch_time": entry.punch_time, "motivo": motivo}

        raise NotImplementedError(
            "editar_batida_admin requires the TimeEntry model — create it first."
        )
