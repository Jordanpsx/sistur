# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
Testes unitários para AvisoService e AusenciaService.

Cenários cobertos:
    1. criar() gera exatamente 1 AuditLog.
    2. contar_nao_lidos() retorna 0 após marcar_todos_lidos().
    3. verificar_atrasos() cria Aviso(ATRASO) para funcionário sem batida.
    4. verificar_atrasos() ignora funcionário com AusenciaJustificada aprovada.
    5. verificar_atrasos() ignora funcionário que já registrou ponto.
    6. processar_dia() reverte auto_debit_aplicado quando batida real chega.
    7. AusenciaService.aprovar() reverte débito provisório no banco de horas.
"""

from __future__ import annotations

from datetime import date, datetime, time, timedelta, timezone
from unittest.mock import patch

import pytest

from app.core.models import AuditAction, AuditLog
from app.models.avisos import Aviso, AusenciaJustificada, TipoAviso, TipoAusencia
from app.models.funcionario import Funcionario
from app.models.ponto import TimeDay, TimeEntry
from app.services.aviso_service import AvisoService
from app.services.ausencia_service import AusenciaService


# ---------------------------------------------------------------------------
# Constantes
# ---------------------------------------------------------------------------

_CPF = "52998224725"
_DATA_TESTE = date(2026, 3, 6)   # quinta-feira


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _criar_funcionario(db, *, cpf: str = _CPF, ativo: bool = True) -> Funcionario:
    """Cria e persiste um Funcionario mínimo de teste."""
    f = Funcionario(
        nome="Teste Colaborador",
        cpf=cpf,
        ativo=ativo,
        minutos_esperados_dia=480,
        minutos_almoco=60,
    )
    db.session.add(f)
    db.session.commit()
    return f


def _criar_funcionario_com_horario(db, hora_entrada: time = time(8, 0)) -> Funcionario:
    """Cria Funcionario com horario_entrada_padrao configurado."""
    f = Funcionario(
        nome="Colaborador Pontual",
        cpf="11144477735",
        ativo=True,
        minutos_esperados_dia=480,
        minutos_almoco=60,
        horario_entrada_padrao=hora_entrada,
    )
    db.session.add(f)
    db.session.commit()
    return f


def _count_audit(db, action: AuditAction, module: str | None = None) -> int:
    """Conta linhas de AuditLog filtrando por ação e módulo opcional."""
    q = db.session.query(AuditLog).filter_by(action=action)
    if module:
        q = q.filter_by(module=module)
    return q.count()


# ---------------------------------------------------------------------------
# Teste 1 — criar() dispara exatamente 1 AuditLog
# ---------------------------------------------------------------------------


class TestCriarAviso:

    def test_criar_aviso_dispara_audit(self, app, db):
        """criar() deve persistir o aviso e registrar 1 AuditLog(create, 'avisos')."""
        with app.app_context():
            func = _criar_funcionario(db)
            aviso = AvisoService.criar(
                destinatario_id=func.id,
                titulo="Aviso de teste",
                mensagem="Mensagem de teste",
                tipo="SISTEMA",
                ator_id=None,
            )

            assert aviso.id is not None
            assert aviso.titulo == "Aviso de teste"
            assert aviso.tipo == TipoAviso.SISTEMA
            assert aviso.is_lido is False

            count = _count_audit(db, AuditAction.create, module="avisos")
            assert count == 1

    def test_criar_aviso_destinatario_inativo_levanta_erro(self, app, db):
        """criar() deve lançar ValueError se o destinatário estiver inativo."""
        with app.app_context():
            func = _criar_funcionario(db, ativo=False)
            with pytest.raises(ValueError, match="inativo"):
                AvisoService.criar(
                    destinatario_id=func.id,
                    titulo="Ignorado",
                    mensagem="",
                    tipo="SISTEMA",
                )


# ---------------------------------------------------------------------------
# Teste 2 — contar_nao_lidos() retorna 0 após marcar_todos_lidos()
# ---------------------------------------------------------------------------


class TestMarcarTodosLidos:

    def test_marcar_todos_lidos_zera_contador(self, app, db):
        """Após marcar_todos_lidos(), contar_nao_lidos() deve retornar 0."""
        with app.app_context():
            func = _criar_funcionario(db)

            # Criar 3 avisos não lidos diretamente no banco (sem passar por criar())
            for i in range(3):
                a = Aviso(
                    destinatario_id=func.id,
                    titulo=f"Aviso {i}",
                    tipo=TipoAviso.SISTEMA,
                    is_lido=False,
                )
                db.session.add(a)
            db.session.commit()

            assert AvisoService.contar_nao_lidos(func.id) == 3

            marcados = AvisoService.marcar_todos_lidos(func.id, ator_id=func.id)
            assert marcados == 3
            assert AvisoService.contar_nao_lidos(func.id) == 0


# ---------------------------------------------------------------------------
# Teste 3 — verificar_atrasos() cria Aviso(ATRASO) para funcionário sem batida
# ---------------------------------------------------------------------------


class TestVerificarAtrasos:

    def test_cria_aviso_atraso_sem_batida(self, app, db):
        """verificar_atrasos() deve criar Aviso(ATRASO) e TimeDay com auto_debit_aplicado=True
        para funcionário com horario_entrada_padrao e sem batida no dia."""
        with app.app_context():
            func = _criar_funcionario_com_horario(db, hora_entrada=time(8, 0))

            # Simular horário local às 08:30 (20 min de tolerância já passou)
            hora_simulada = datetime(_DATA_TESTE.year, _DATA_TESTE.month, _DATA_TESTE.day,
                                     8, 30, tzinfo=timezone.utc)

            with patch("app.services.aviso_service.datetime") as mock_dt:
                mock_dt.now.return_value = hora_simulada
                mock_dt.combine = datetime.combine
                mock_dt.side_effect = lambda *a, **kw: datetime(*a, **kw)

                # ConfiguracaoService é importado localmente — patchar no módulo de origem
                with patch(
                    "app.services.configuracao_service.ConfiguracaoService.get",
                    return_value="UTC",
                ):
                    resultado = AvisoService.verificar_atrasos()

            assert resultado["processados"] == 1
            assert resultado["erros"] == []

            avisos = db.session.query(Aviso).filter_by(
                destinatario_id=func.id, tipo=TipoAviso.ATRASO
            ).all()
            assert len(avisos) >= 1

            time_day = db.session.query(TimeDay).filter_by(
                funcionario_id=func.id, shift_date=_DATA_TESTE
            ).first()
            assert time_day is not None
            assert time_day.auto_debit_aplicado is True
            assert time_day.saldo_final_minutos < 0

    # -----------------------------------------------------------------------
    # Teste 4 — ignora AusenciaJustificada aprovada
    # -----------------------------------------------------------------------

    def test_ignora_ausencia_justificada_aprovada(self, app, db):
        """verificar_atrasos() não deve criar Aviso se o funcionário tem ausência aprovada."""
        with app.app_context():
            func = _criar_funcionario_com_horario(db, hora_entrada=time(8, 0))

            # Criar ausência aprovada para hoje
            ausencia = AusenciaJustificada(
                funcionario_id=func.id,
                data=_DATA_TESTE,
                tipo=TipoAusencia.FOLGA,
                aprovado=True,
            )
            db.session.add(ausencia)
            db.session.commit()

            hora_simulada = datetime(_DATA_TESTE.year, _DATA_TESTE.month, _DATA_TESTE.day,
                                     8, 30, tzinfo=timezone.utc)

            with patch("app.services.aviso_service.datetime") as mock_dt:
                mock_dt.now.return_value = hora_simulada
                mock_dt.combine = datetime.combine
                mock_dt.side_effect = lambda *a, **kw: datetime(*a, **kw)

                with patch(
                    "app.services.configuracao_service.ConfiguracaoService.get",
                    return_value="UTC",
                ):
                    resultado = AvisoService.verificar_atrasos()

            assert resultado["processados"] == 0

            avisos = db.session.query(Aviso).filter_by(
                destinatario_id=func.id, tipo=TipoAviso.ATRASO
            ).count()
            assert avisos == 0

    # -----------------------------------------------------------------------
    # Teste 5 — ignora funcionário que já bateu ponto
    # -----------------------------------------------------------------------

    def test_ignora_quem_ja_bateu_ponto(self, app, db):
        """verificar_atrasos() não deve criar Aviso se o funcionário já registrou batida."""
        with app.app_context():
            func = _criar_funcionario_com_horario(db, hora_entrada=time(8, 0))

            # Inserir batida real para hoje
            entry = TimeEntry(
                funcionario_id=func.id,
                shift_date=_DATA_TESTE,
                punch_time=datetime(_DATA_TESTE.year, _DATA_TESTE.month, _DATA_TESTE.day,
                                    8, 5, tzinfo=timezone.utc),
                source="employee",
            )
            db.session.add(entry)
            db.session.commit()

            hora_simulada = datetime(_DATA_TESTE.year, _DATA_TESTE.month, _DATA_TESTE.day,
                                     8, 30, tzinfo=timezone.utc)

            with patch("app.services.aviso_service.datetime") as mock_dt:
                mock_dt.now.return_value = hora_simulada
                mock_dt.combine = datetime.combine
                mock_dt.side_effect = lambda *a, **kw: datetime(*a, **kw)

                with patch(
                    "app.services.configuracao_service.ConfiguracaoService.get",
                    return_value="UTC",
                ):
                    resultado = AvisoService.verificar_atrasos()

            assert resultado["processados"] == 0

            avisos = db.session.query(Aviso).filter_by(
                destinatario_id=func.id, tipo=TipoAviso.ATRASO
            ).count()
            assert avisos == 0


# ---------------------------------------------------------------------------
# Teste 6 — processar_dia() reverte auto_debit_aplicado com batida real
# ---------------------------------------------------------------------------


class TestProcessarDiaRevertAutoDebit:

    def test_processar_dia_reverte_auto_debit(self, app, db):
        """processar_dia() deve zerar auto_debit_aplicado e corrigir banco_horas_acumulado
        quando batidas reais são processadas para um dia com débito provisório ativo."""
        from app.services.ponto_service import PontoService

        with app.app_context():
            func = _criar_funcionario(db)

            # Simular estado após verificar_atrasos(): TimeDay com débito provisório
            time_day = TimeDay(
                funcionario_id=func.id,
                shift_date=_DATA_TESTE,
                saldo_final_minutos=-480,
                saldo_calculado_minutos=-480,
                minutos_trabalhados=0,
                expected_minutes_snapshot=480,
                tolerance_snapshot=10,
                auto_debit_aplicado=True,
                needs_review=False,
            )
            db.session.add(time_day)
            func.banco_horas_acumulado = -480
            db.session.commit()

            # Inserir 2 batidas reais (par válido: entrada às 8h, saída às 9h)
            t_base = datetime(_DATA_TESTE.year, _DATA_TESTE.month, _DATA_TESTE.day,
                              tzinfo=timezone.utc)
            for hora in (8, 9):
                entry = TimeEntry(
                    funcionario_id=func.id,
                    shift_date=_DATA_TESTE,
                    punch_time=t_base.replace(hour=hora),
                    source="employee",
                )
                db.session.add(entry)
            db.session.commit()

            # Processar o dia — deve reverter auto_debit_aplicado via delta
            PontoService.processar_dia(func.id, _DATA_TESTE)

            db.session.refresh(time_day)
            db.session.refresh(func)

            assert time_day.auto_debit_aplicado is False
            # Saldo não é mais o débito provisório de -480
            assert time_day.saldo_final_minutos != -480
            # banco_horas_acumulado foi corrigido (não deve mais ser -480)
            assert func.banco_horas_acumulado != -480


# ---------------------------------------------------------------------------
# Teste 7 — AusenciaService.aprovar() reverte débito provisório
# ---------------------------------------------------------------------------


class TestAprovarAusenciaRevertaDebito:

    def test_aprovar_ausencia_reverte_debito_provisorio(self, app, db):
        """AusenciaService.aprovar() deve zerar saldo_final e corrigir banco_horas_acumulado
        quando existe TimeDay com auto_debit_aplicado=True na data da ausência."""
        with app.app_context():
            func = _criar_funcionario(db)

            # Estado após verificar_atrasos()
            time_day = TimeDay(
                funcionario_id=func.id,
                shift_date=_DATA_TESTE,
                saldo_final_minutos=-480,
                saldo_calculado_minutos=-480,
                minutos_trabalhados=0,
                expected_minutes_snapshot=480,
                tolerance_snapshot=10,
                auto_debit_aplicado=True,
            )
            db.session.add(time_day)
            func.banco_horas_acumulado = -480
            db.session.commit()

            # Criar ausência pendente
            ausencia = AusenciaJustificada(
                funcionario_id=func.id,
                data=_DATA_TESTE,
                tipo=TipoAusencia.ATESTADO,
                aprovado=False,
                criado_por_id=func.id,
            )
            db.session.add(ausencia)
            db.session.commit()

            # Aprovar — deve reverter o débito
            AusenciaService.aprovar(ausencia.id, ator_id=func.id)

            db.session.refresh(time_day)
            db.session.refresh(func)

            assert time_day.auto_debit_aplicado is False
            assert time_day.saldo_final_minutos == 0
            assert func.banco_horas_acumulado == 0
