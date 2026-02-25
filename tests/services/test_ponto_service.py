# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
Unit tests for PontoService.

Tests are pure Python — no DB, no Flask app context — because
calculate_daily_balance is a pure calculation with no side effects.

editar_batida_admin tests are skipped (marked) until the TimeEntry
SQLAlchemy model is created.
"""

from __future__ import annotations

from datetime import datetime, timezone

import pytest

from app.services.ponto_service import PontoService, _apply_tolerance


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _punch(hour: int, minute: int = 0) -> dict:
    """Build a punch dict for a fixed day (2026-01-15)."""
    return {"time": datetime(2026, 1, 15, hour, minute, tzinfo=timezone.utc)}


# ---------------------------------------------------------------------------
# _apply_tolerance (unit)
# ---------------------------------------------------------------------------

class TestApplyTolerance:
    def test_overtime_unchanged(self):
        assert _apply_tolerance(30, 10) == 30

    def test_exact_zero_unchanged(self):
        assert _apply_tolerance(0, 10) == 0

    def test_small_delay_forgiven(self):
        # -8 min delay, tolerance 10 → forgiven to 0
        assert _apply_tolerance(-8, 10) == 0

    def test_tolerance_boundary_forgiven(self):
        # exactly -10 min → forgiven
        assert _apply_tolerance(-10, 10) == 0

    def test_large_delay_only_excess_penalised(self):
        # -25 min delay, tolerance 10 → only -15 charged
        assert _apply_tolerance(-25, 10) == -15

    def test_one_over_tolerance(self):
        # -11 min → -1 charged
        assert _apply_tolerance(-11, 10) == -1


# ---------------------------------------------------------------------------
# calculate_daily_balance
# ---------------------------------------------------------------------------

class TestCalculateDailyBalance:

    def test_saldo_par_normal_zero(self):
        """
        4 punches: 08:00 in, 12:00 lunch out, 13:00 lunch in, 17:00 out.
        Worked = 4h + 4h = 8h (480 min), minus 60 min lunch = 420 min.
        Expected = 420 → saldo = 0.
        """
        punches = [
            _punch(8, 0),
            _punch(12, 0),
            _punch(13, 0),
            _punch(17, 0),
        ]
        result = PontoService.calculate_daily_balance(punches, expected_minutes=420, almoco_minutes=60)
        assert result["minutos_trabalhados"] == 420
        assert result["saldo_calculado_minutos"] == 0
        assert result["needs_review"] is False

    def test_saldo_impar_congela(self):
        """Rule #5: 3 punches → needs_review=True, balance frozen."""
        punches = [_punch(8), _punch(12), _punch(13)]
        result = PontoService.calculate_daily_balance(punches, expected_minutes=480)
        assert result["needs_review"] is True
        assert result["saldo_calculado_minutos"] == 0
        assert result["minutos_trabalhados"] == 0

    def test_saldo_um_punch_congela(self):
        """1 punch is also odd → needs_review=True."""
        result = PontoService.calculate_daily_balance([_punch(8)], expected_minutes=480)
        assert result["needs_review"] is True

    def test_tolerancia_pequeno_atraso(self):
        """
        2 punches: 08:08 in, 17:00 out.
        Worked = 532 min (no lunch deduction — only 2 punches).
        Expected = 540 → saldo_bruto = -8 → within tolerance → 0.
        """
        punches = [_punch(8, 8), _punch(17, 0)]
        result = PontoService.calculate_daily_balance(punches, expected_minutes=540)
        assert result["saldo_calculado_minutos"] == 0
        assert result["needs_review"] is False

    def test_tolerancia_grande_atraso(self):
        """
        2 punches: 08:25 in, 17:00 out.
        Worked = 515 min, expected 540 → saldo_bruto = -25 → penalise -15.
        """
        punches = [_punch(8, 25), _punch(17, 0)]
        result = PontoService.calculate_daily_balance(punches, expected_minutes=540)
        assert result["saldo_calculado_minutos"] == -15

    def test_hora_extra_nao_afetada(self):
        """
        2 punches: 08:00 in, 18:00 out.
        Worked = 600 min, expected 540 → saldo = +60 (no tolerance applied).
        """
        punches = [_punch(8, 0), _punch(18, 0)]
        result = PontoService.calculate_daily_balance(punches, expected_minutes=540)
        assert result["saldo_calculado_minutos"] == 60

    def test_zero_punches_returns_review(self):
        """0 is even — balance is 0 but no punches means nothing worked."""
        result = PontoService.calculate_daily_balance([], expected_minutes=480)
        assert result["needs_review"] is False
        assert result["minutos_trabalhados"] == 0
        assert result["saldo_calculado_minutos"] == _apply_tolerance(-480, 10)

    def test_quatro_batidas_desconta_almoco(self):
        """
        With 4 punches, lunch deduction must be applied.
        08:00–12:00 + 13:00–17:00 = 480 min − 60 almoco = 420 min worked.
        Expected 420 → saldo = 0.
        """
        punches = [_punch(8), _punch(12), _punch(13), _punch(17)]
        result = PontoService.calculate_daily_balance(punches, expected_minutes=420, almoco_minutes=60)
        assert result["minutos_trabalhados"] == 420

    def test_duas_batidas_nao_desconta_almoco(self):
        """
        With only 2 punches, lunch deduction must NOT be applied.
        08:00–17:00 = 540 min worked (no deduction).
        """
        punches = [_punch(8), _punch(17)]
        result = PontoService.calculate_daily_balance(punches, expected_minutes=480)
        assert result["minutos_trabalhados"] == 540


# ---------------------------------------------------------------------------
# editar_batida_admin (stub)
# ---------------------------------------------------------------------------

class TestEditarBatidaAdmin:

    @pytest.mark.skip(reason="TimeEntry model not yet created")
    def test_editar_batida_gera_audit(self, app, db):
        """
        When TimeEntry model is created, this test must assert:
        - Entry's punch_time is updated
        - Exactly one AuditLog row is created for the edit
        - AuditLog contains previous and new punch_time
        """
        pass

    def test_motivo_vazio_levanta_value_error(self):
        """editar_batida_admin must reject empty motivo before hitting the DB."""
        with pytest.raises(ValueError, match="motivo"):
            PontoService.editar_batida_admin(
                time_entry_id=1,
                novo_horario=datetime(2026, 1, 15, 9, 0),
                motivo="",
                ator_id=1,
            )

    def test_sem_model_levanta_not_implemented(self):
        """Until TimeEntry model exists, must raise NotImplementedError."""
        with pytest.raises(NotImplementedError):
            PontoService.editar_batida_admin(
                time_entry_id=1,
                novo_horario=datetime(2026, 1, 15, 9, 0),
                motivo="Correção de horário",
                ator_id=1,
            )
