# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
Testes unitários e de integração para PontoService.

Separados em duas classes:
    TestApplyTolerance       — função pura (sem DB)
    TestCalculateDailyBalance — função pura (sem DB)
    TestRegistrarBatida      — mutação com DB (usa fixture app/db do conftest)
    TestProcessarDia         — mutação com DB
    TestEditarBatidaAdmin    — mutação com DB (anteriormente stub)
"""

from __future__ import annotations

from datetime import datetime, timedelta, timezone

import pytest

from app.core.models import AuditAction, AuditLog
from app.models.funcionario import Funcionario
from app.models.ponto import ProcessingStatus, TimeDay, TimeEntry
from app.services.ponto_service import PontoService, _apply_tolerance


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _punch(hour: int, minute: int = 0) -> dict:
    """Constrói um dict de batida para o dia fixo 2026-01-15."""
    return {"time": datetime(2026, 1, 15, hour, minute, tzinfo=timezone.utc)}


def _criar_funcionario(db) -> Funcionario:
    """Cria e persiste um funcionário de teste."""
    f = Funcionario(
        nome="Colaborador Teste",
        cpf="52998224725",
        minutos_esperados_dia=480,
        minutos_almoco=60,
        ativo=True,
    )
    db.session.add(f)
    db.session.commit()
    return f


# ---------------------------------------------------------------------------
# _apply_tolerance (puro — sem DB)
# ---------------------------------------------------------------------------

class TestApplyTolerance:
    def test_hora_extra_inalterada(self):
        assert _apply_tolerance(30, 10) == 30

    def test_zero_inalterado(self):
        assert _apply_tolerance(0, 10) == 0

    def test_atraso_pequeno_perdoado(self):
        assert _apply_tolerance(-8, 10) == 0

    def test_limite_da_tolerancia_perdoado(self):
        assert _apply_tolerance(-10, 10) == 0

    def test_atraso_grande_apenas_excesso_penalizado(self):
        assert _apply_tolerance(-25, 10) == -15

    def test_um_acima_da_tolerancia(self):
        assert _apply_tolerance(-11, 10) == -1


# ---------------------------------------------------------------------------
# calculate_daily_balance (puro — sem DB)
# ---------------------------------------------------------------------------

class TestCalculateDailyBalance:

    def test_saldo_par_normal_zero(self):
        """4 batidas CLT (8-12, 13-17): pares somam 8h=480 min, expected=480 → saldo 0.

        O almoço NÃO é subtraído separadamente — o intervalo 12h-13h nunca
        é somado, pois fica entre os pares. Sem desconto duplo.
        """
        punches = [_punch(8, 0), _punch(12, 0), _punch(13, 0), _punch(17, 0)]
        result = PontoService.calculate_daily_balance(punches, expected_minutes=480)
        # Par(0,1)=240 min + Par(2,3)=240 min = 480 min trabalhados
        assert result["minutos_trabalhados"] == 480
        assert result["saldo_calculado_minutos"] == 0
        assert result["needs_review"] is False

    def test_saldo_impar_congela(self):
        """Rule #5: 3 batidas → needs_review=True, saldo congelado."""
        punches = [_punch(8), _punch(12), _punch(13)]
        result = PontoService.calculate_daily_balance(punches, expected_minutes=480)
        assert result["needs_review"] is True
        assert result["saldo_calculado_minutos"] == 0
        assert result["minutos_trabalhados"] == 0

    def test_saldo_um_punch_congela(self):
        """1 batida também é ímpar → needs_review=True."""
        result = PontoService.calculate_daily_balance([_punch(8)], expected_minutes=480)
        assert result["needs_review"] is True

    def test_tolerancia_pequeno_atraso(self):
        """8 min de atraso com 2 batidas → dentro da tolerância → saldo 0."""
        punches = [_punch(8, 8), _punch(17, 0)]
        result = PontoService.calculate_daily_balance(punches, expected_minutes=540)
        assert result["saldo_calculado_minutos"] == 0
        assert result["needs_review"] is False

    def test_tolerancia_grande_atraso(self):
        """25 min de atraso → apenas -15 penalizado."""
        punches = [_punch(8, 25), _punch(17, 0)]
        result = PontoService.calculate_daily_balance(punches, expected_minutes=540)
        assert result["saldo_calculado_minutos"] == -15

    def test_hora_extra_nao_afetada(self):
        """Horas extras não sofrem tolerância."""
        punches = [_punch(8, 0), _punch(18, 0)]
        result = PontoService.calculate_daily_balance(punches, expected_minutes=540)
        assert result["saldo_calculado_minutos"] == 60

    def test_zero_punches_returns_review(self):
        """0 é par — sem batidas o saldo fica negativo."""
        result = PontoService.calculate_daily_balance([], expected_minutes=480)
        assert result["needs_review"] is False
        assert result["minutos_trabalhados"] == 0
        assert result["saldo_calculado_minutos"] == _apply_tolerance(-480, 10)

    def test_caso_real_quatro_batidas_11h25_a_14h02(self):
        """Reproduz o bug reportado: 11:25, 11:34, 12:56, 14:02 → 75 min (não 15).

        Bug anterior: o código subtraía 60 min fixos após somar os pares,
        resultando em 75 - 60 = 15 min. O correto é 75 min, pois o almoço
        (11:34 → 12:56 = 82 min) já fica excluído pela estrutura dos pares.
        """
        punches = [
            {"time": datetime(2026, 2, 25, 11, 25, tzinfo=timezone.utc)},
            {"time": datetime(2026, 2, 25, 11, 34, tzinfo=timezone.utc)},
            {"time": datetime(2026, 2, 25, 12, 56, tzinfo=timezone.utc)},
            {"time": datetime(2026, 2, 25, 14,  2, tzinfo=timezone.utc)},
        ]
        result = PontoService.calculate_daily_balance(punches, expected_minutes=480)
        # Par(0,1): 11:25→11:34 =  9 min
        # Par(2,3): 12:56→14:02 = 66 min  → total = 75 min
        assert result["minutos_trabalhados"] == 75
        assert result["needs_review"] is False

    def test_quatro_batidas_nao_subtrai_almoco_adicional(self):
        """Com 4 batidas, minutos_trabalhados é apenas a soma dos pares (almoço já excluído)."""
        punches = [_punch(8), _punch(12), _punch(13), _punch(17)]
        result = PontoService.calculate_daily_balance(punches, expected_minutes=480)
        # Par(0,1): 08:00→12:00 = 240 min
        # Par(2,3): 13:00→17:00 = 240 min  → total = 480 min (sem subtração extra)
        assert result["minutos_trabalhados"] == 480

    def test_duas_batidas_trabalho_continuo(self):
        """Com 2 batidas (sem almoço registrado), soma o intervalo inteiro."""
        punches = [_punch(8), _punch(17)]
        result = PontoService.calculate_daily_balance(punches, expected_minutes=480)
        assert result["minutos_trabalhados"] == 540


# ---------------------------------------------------------------------------
# registrar_batida (com DB)
# ---------------------------------------------------------------------------

class TestRegistrarBatida:

    def test_registra_batida_e_cria_time_entry(self, app, db):
        """Happy path: registrar_batida cria uma TimeEntry no banco."""
        with app.app_context():
            f = _criar_funcionario(db)
            entry = PontoService.registrar_batida(
                funcionario_id=f.id,
                punch_time=datetime(2026, 1, 15, 8, 0, tzinfo=timezone.utc),
                source="employee",
                ator_id=f.id,
            )
            assert entry.id is not None
            assert entry.funcionario_id == f.id
            assert entry.processing_status == ProcessingStatus.PROCESSADO

    def test_registrar_batida_gera_audit(self, app, db):
        """Cada batida deve gerar exatamente um AuditLog com action=create."""
        with app.app_context():
            f = _criar_funcionario(db)
            entry = PontoService.registrar_batida(
                funcionario_id=f.id,
                punch_time=datetime(2026, 1, 15, 8, 0, tzinfo=timezone.utc),
                ator_id=f.id,
            )
            count = (
                db.session.query(AuditLog)
                .filter_by(action=AuditAction.create, entity_id=entry.id, module="ponto")
                .count()
            )
            assert count == 1

    def test_registrar_batida_cria_time_day(self, app, db):
        """Após registrar batida, um TimeDay deve ser criado ou atualizado."""
        with app.app_context():
            f = _criar_funcionario(db)
            punch_time = datetime(2026, 1, 15, 8, 0, tzinfo=timezone.utc)
            PontoService.registrar_batida(
                funcionario_id=f.id,
                punch_time=punch_time,
                ator_id=f.id,
            )
            day = (
                db.session.query(TimeDay)
                .filter_by(funcionario_id=f.id, shift_date=punch_time.date())
                .first()
            )
            assert day is not None
            assert day.needs_review is True  # 1 batida é ímpar

    def test_duplicata_levanta_value_error(self, app, db):
        """Batida duplicada em menos de 5 segundos deve levantar ValueError."""
        with app.app_context():
            f = _criar_funcionario(db)
            t = datetime(2026, 1, 15, 8, 0, tzinfo=timezone.utc)
            PontoService.registrar_batida(funcionario_id=f.id, punch_time=t, ator_id=f.id)
            t2 = t + timedelta(seconds=2)
            with pytest.raises(ValueError, match="duplicada"):
                PontoService.registrar_batida(funcionario_id=f.id, punch_time=t2, ator_id=f.id)

    def test_funcionario_inexistente_levanta_value_error(self, app, db):
        """Funcionário não encontrado deve levantar ValueError."""
        with app.app_context():
            with pytest.raises(ValueError, match="não encontrado"):
                PontoService.registrar_batida(funcionario_id=9999, ator_id=1)


# ---------------------------------------------------------------------------
# reprocess_interval (com DB)
# ---------------------------------------------------------------------------

class TestReprocessarPonto:

    def test_reprocess_success(self, app, db):
        """Reprocessar atualiza os saldos mantendo os snapshots antigos."""
        with app.app_context():
            f = _criar_funcionario(db)
            data_str = datetime(2026, 1, 15, tzinfo=timezone.utc)
            
            # 2 batidas
            PontoService.registrar_batida(f.id, data_str.replace(hour=8), ator_id=f.id)
            PontoService.registrar_batida(f.id, data_str.replace(hour=17), ator_id=f.id)
            
            # Adulterando o snapshot pra estragar o saldo simulando bug velho
            day = db.session.query(TimeDay).filter_by(funcionario_id=f.id).first()
            day.saldo_calculado_minutos = 15 # Valor arbitrário e errado
            db.session.commit()
            
            res = PontoService.reprocess_interval(
                funcionario_id=f.id,
                start_date=data_str.date(),
                end_date=data_str.date(),
                ator_id=f.id,
                override_snapshots=False
            )
            
            # Verificando se arrumou para +60 (ja que a jornada snapshot tava certa)
            db.session.refresh(day)
            assert res["dias_processados"] == 1
            assert day.saldo_calculado_minutos == 60
            
    def test_reprocess_com_override(self, app, db):
        """Reprocessar ignorando snapshot antigo, adotando jornada atual."""
        with app.app_context():
            f = _criar_funcionario(db)
            data_str = datetime(2026, 1, 15, tzinfo=timezone.utc)
            
            # 2 batidas (9 hrs trabalhadas - 8h as 17h = 540 min)
            PontoService.registrar_batida(f.id, data_str.replace(hour=8), ator_id=f.id)
            PontoService.registrar_batida(f.id, data_str.replace(hour=17), ator_id=f.id)
            
            # Mudando a carga horária atual do funcionário no cadastro para 6h (360m)
            f.minutos_esperados_dia = 360
            db.session.commit()
            
            # Override!
            PontoService.reprocess_interval(
                funcionario_id=f.id,
                start_date=data_str.date(),
                end_date=data_str.date(),
                ator_id=f.id,
                override_snapshots=True,
                tolerance=15
            )
            
            # Antes o saldo era de +60 (480 min esperado). Agora a pessoa deve 360 e trabalhou 540.
            # Saldo esperado: 540 - 360 = +180 min
            day = db.session.query(TimeDay).filter_by(funcionario_id=f.id).first()
            
            assert day.expected_minutes_snapshot == 360
            assert day.tolerance_snapshot == 15
            assert day.saldo_calculado_minutos == 180

    def test_reprocess_gera_log_massivo(self, app, db):
        """A operação de reprocessamento deve gerar 1 único AuditLog com os deltas."""
        with app.app_context():
            f = _criar_funcionario(db)
            start = datetime(2026, 1, 10, tzinfo=timezone.utc).date()
            end = datetime(2026, 1, 15, tzinfo=timezone.utc).date()
            
            
            PontoService.reprocess_interval(f.id, start, end, ator_id=f.id)
            db.session.commit()
            
            logs = db.session.query(AuditLog).filter_by(action=AuditAction.update, module="ponto", entity_id=f.id).all()
            # Procurar o log massivo pela key 'action' que injetamos manualmente no payload
            mass_logs = [l for l in logs if l.previous_state and l.previous_state.get("action") == "mass_reprocess_before"]
            assert len(mass_logs) == 1

# ---------------------------------------------------------------------------
# processar_dia (com DB)
# ---------------------------------------------------------------------------

class TestProcessarDia:

    def test_par_de_batidas_gera_saldo_correto(self, app, db):
        """2 batidas (08:00–17:00) → 540 min, esperado 480 → saldo +60."""
        with app.app_context():
            f = _criar_funcionario(db)
            shift_date = datetime(2026, 1, 15, tzinfo=timezone.utc).date()
            for h in [8, 17]:
                PontoService.registrar_batida(
                    funcionario_id=f.id,
                    punch_time=datetime(2026, 1, 15, h, 0, tzinfo=timezone.utc),
                    ator_id=f.id,
                )
            day = (
                db.session.query(TimeDay)
                .filter_by(funcionario_id=f.id, shift_date=shift_date)
                .first()
            )
            assert day is not None
            assert day.needs_review is False
            assert day.saldo_calculado_minutos == 60   # +1h extra

    def test_batida_impar_seta_needs_review(self, app, db):
        """1 batida (ímpar) → needs_review=True."""
        with app.app_context():
            f = _criar_funcionario(db)
            PontoService.registrar_batida(
                funcionario_id=f.id,
                punch_time=datetime(2026, 1, 15, 8, 0, tzinfo=timezone.utc),
                ator_id=f.id,
            )
            day = db.session.query(TimeDay).filter_by(funcionario_id=f.id).first()
            assert day.needs_review is True


# ---------------------------------------------------------------------------
# editar_batida_admin (com DB)
# ---------------------------------------------------------------------------

class TestEditarBatidaAdmin:

    def test_motivo_vazio_levanta_value_error(self):
        """motivo vazio deve levantar ValueError antes de acessar o banco."""
        with pytest.raises(ValueError, match="motivo"):
            PontoService.editar_batida_admin(
                time_entry_id=1,
                novo_horario=datetime(2026, 1, 15, 9, 0),
                motivo="",
                ator_id=1,
            )

    def test_editar_batida_gera_audit(self, app, db):
        """Editar uma batida deve gerar exatamente um AuditLog com action=update."""
        with app.app_context():
            f = _criar_funcionario(db)
            entry = PontoService.registrar_batida(
                funcionario_id=f.id,
                punch_time=datetime(2026, 1, 15, 8, 0, tzinfo=timezone.utc),
                ator_id=f.id,
            )
            novo_horario = datetime(2026, 1, 15, 8, 30, tzinfo=timezone.utc)
            PontoService.editar_batida_admin(
                time_entry_id=entry.id,
                novo_horario=novo_horario,
                motivo="Correção de horário de entrada",
                ator_id=f.id,
            )
            count = (
                db.session.query(AuditLog)
                .filter_by(action=AuditAction.update, entity_id=entry.id, module="ponto")
                .count()
            )
            assert count == 1

    def test_editar_batida_atualiza_punch_time(self, app, db):
        """O punch_time da batida deve ser atualizado para o novo horário."""
        with app.app_context():
            f = _criar_funcionario(db)
            entry = PontoService.registrar_batida(
                funcionario_id=f.id,
                punch_time=datetime(2026, 1, 15, 8, 0, tzinfo=timezone.utc),
                ator_id=f.id,
            )
            novo_horario = datetime(2026, 1, 15, 9, 0, tzinfo=timezone.utc)
            PontoService.editar_batida_admin(
                time_entry_id=entry.id,
                novo_horario=novo_horario,
                motivo="Teste de edição",
                ator_id=f.id,
            )
            db.session.expire(entry)
            updated = db.session.get(TimeEntry, entry.id)
            # SQLite retorna datetimes sem tzinfo; comparamos apenas o valor ingênuo
            assert updated.punch_time.replace(tzinfo=None) == novo_horario.replace(tzinfo=None)
            assert updated.admin_change_reason == "Teste de edição"

    def test_batida_inexistente_levanta_value_error(self, app, db):
        """Batida não encontrada deve levantar ValueError."""
        with app.app_context():
            with pytest.raises(ValueError, match="não encontrada"):
                PontoService.editar_batida_admin(
                    time_entry_id=9999,
                    novo_horario=datetime(2026, 1, 15, 9, 0),
                    motivo="Motivo qualquer",
                    ator_id=1,
                )
