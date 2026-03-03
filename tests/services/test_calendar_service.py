# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
Testes do CalendarService — consultas de feriados, alta temporada e CRUD com auditoria.
"""

from __future__ import annotations

from datetime import date

import pytest

from app.core.models import AuditAction, AuditLog
from app.models.calendario import GlobalEvent, TipoEvento
from app.services.calendar_service import CalendarService


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _criar_evento(db, **kwargs) -> GlobalEvent:
    """Cria e persiste um GlobalEvent com valores padrão."""
    defaults = {
        "titulo": "Natal",
        "data_evento": date(2026, 12, 25),
        "tipo": TipoEvento.NATIONAL_HOLIDAY,
        "afeta_precificacao": False,
        "afeta_folha": True,
        "recorrente_anual": True,
    }
    defaults.update(kwargs)
    evento = GlobalEvent(**defaults)
    db.session.add(evento)
    db.session.commit()
    return evento


# ---------------------------------------------------------------------------
# eh_feriado
# ---------------------------------------------------------------------------

class TestEhFeriado:

    def test_data_exata_retorna_true(self, app, db):
        """Feriado com data exata deve ser encontrado."""
        with app.app_context():
            _criar_evento(db, data_evento=date(2026, 9, 7), recorrente_anual=False,
                          titulo="Independência do Brasil")
            assert CalendarService.eh_feriado(date(2026, 9, 7)) is True

    def test_data_sem_evento_retorna_false(self, app, db):
        """Data sem evento cadastrado retorna False."""
        with app.app_context():
            assert CalendarService.eh_feriado(date(2026, 3, 15)) is False

    def test_recorrente_anual_match_outro_ano(self, app, db):
        """Evento recorrente cadastrado em 2026 deve casar com 2027 pelo mês+dia."""
        with app.app_context():
            _criar_evento(db, data_evento=date(2026, 12, 25), recorrente_anual=True)
            assert CalendarService.eh_feriado(date(2027, 12, 25)) is True
            assert CalendarService.eh_feriado(date(2028, 12, 25)) is True

    def test_ignora_maintenance(self, app, db):
        """Eventos tipo MAINTENANCE não são considerados feriados."""
        with app.app_context():
            _criar_evento(db, tipo=TipoEvento.MAINTENANCE, titulo="Manutenção",
                          data_evento=date(2026, 6, 15), recorrente_anual=False)
            assert CalendarService.eh_feriado(date(2026, 6, 15)) is False

    def test_ignora_high_season(self, app, db):
        """Eventos tipo HIGH_SEASON não são considerados feriados."""
        with app.app_context():
            _criar_evento(db, tipo=TipoEvento.HIGH_SEASON, titulo="Verão",
                          data_evento=date(2026, 1, 15), recorrente_anual=False)
            assert CalendarService.eh_feriado(date(2026, 1, 15)) is False

    def test_feriado_local_retorna_true(self, app, db):
        """Feriados locais também devem ser reconhecidos."""
        with app.app_context():
            _criar_evento(db, tipo=TipoEvento.LOCAL_HOLIDAY, titulo="Aniversário da Cidade",
                          data_evento=date(2026, 4, 10), recorrente_anual=False)
            assert CalendarService.eh_feriado(date(2026, 4, 10)) is True


# ---------------------------------------------------------------------------
# obter_eventos_mes
# ---------------------------------------------------------------------------

class TestObterEventosMes:

    def test_retorna_eventos_do_mes(self, app, db):
        """Deve retornar todos os eventos do mês informado."""
        with app.app_context():
            _criar_evento(db, data_evento=date(2026, 12, 25), titulo="Natal")
            _criar_evento(db, data_evento=date(2026, 12, 31), titulo="Réveillon",
                          recorrente_anual=False)
            _criar_evento(db, data_evento=date(2026, 1, 1), titulo="Ano Novo",
                          recorrente_anual=False)

            eventos = CalendarService.obter_eventos_mes(2026, 12)
            titulos = [e.titulo for e in eventos]
            assert "Natal" in titulos
            assert "Réveillon" in titulos
            assert "Ano Novo" not in titulos

    def test_inclui_recorrentes_de_outros_anos(self, app, db):
        """Eventos recorrentes de outros anos devem aparecer no mês correspondente."""
        with app.app_context():
            _criar_evento(db, data_evento=date(2020, 12, 25), titulo="Natal",
                          recorrente_anual=True)

            eventos = CalendarService.obter_eventos_mes(2030, 12)
            assert len(eventos) == 1
            assert eventos[0].titulo == "Natal"


# ---------------------------------------------------------------------------
# eh_alta_temporada
# ---------------------------------------------------------------------------

class TestEhAltaTemporada:

    def test_alta_temporada_no_intervalo(self, app, db):
        """HIGH_SEASON dentro do intervalo retorna True."""
        with app.app_context():
            _criar_evento(db, tipo=TipoEvento.HIGH_SEASON, titulo="Verão",
                          data_evento=date(2026, 1, 15), recorrente_anual=False)
            assert CalendarService.eh_alta_temporada(date(2026, 1, 1), date(2026, 1, 31)) is True

    def test_fora_do_intervalo(self, app, db):
        """HIGH_SEASON fora do intervalo retorna False."""
        with app.app_context():
            _criar_evento(db, tipo=TipoEvento.HIGH_SEASON, titulo="Verão",
                          data_evento=date(2026, 1, 15), recorrente_anual=False)
            assert CalendarService.eh_alta_temporada(date(2026, 6, 1), date(2026, 6, 30)) is False


# ---------------------------------------------------------------------------
# CRUD — criar
# ---------------------------------------------------------------------------

class TestCriar:

    def test_criar_persiste_evento(self, app, db):
        """Criação persiste o evento no banco."""
        with app.app_context():
            evento = CalendarService.criar(
                titulo="Carnaval",
                data_evento=date(2026, 2, 17),
                tipo=TipoEvento.NATIONAL_HOLIDAY,
                afeta_folha=True,
                ator_id=None,
            )
            assert evento.id is not None
            assert evento.titulo == "Carnaval"

    def test_criar_gera_audit_log(self, app, db):
        """Criação deve gerar exatamente um AuditLog com action=create."""
        with app.app_context():
            evento = CalendarService.criar(
                titulo="Tiradentes",
                data_evento=date(2026, 4, 21),
                tipo=TipoEvento.NATIONAL_HOLIDAY,
                ator_id=None,
            )
            count = (
                db.session.query(AuditLog)
                .filter_by(action=AuditAction.create, module="calendario", entity_id=evento.id)
                .count()
            )
            assert count == 1

    def test_criar_titulo_vazio_levanta_erro(self, app, db):
        """Título vazio deve levantar ValueError."""
        with app.app_context():
            with pytest.raises(ValueError, match="titulo"):
                CalendarService.criar(
                    titulo="",
                    data_evento=date(2026, 1, 1),
                    tipo=TipoEvento.NATIONAL_HOLIDAY,
                )


# ---------------------------------------------------------------------------
# CRUD — atualizar
# ---------------------------------------------------------------------------

class TestAtualizar:

    def test_atualizar_gera_audit_log(self, app, db):
        """Atualização deve gerar AuditLog com previous_state e new_state."""
        with app.app_context():
            evento = CalendarService.criar(
                titulo="Natal",
                data_evento=date(2026, 12, 25),
                tipo=TipoEvento.NATIONAL_HOLIDAY,
                ator_id=None,
            )
            CalendarService.atualizar(
                event_id=evento.id,
                titulo="Natal - Atualizado",
                ator_id=None,
            )
            log = (
                db.session.query(AuditLog)
                .filter_by(action=AuditAction.update, module="calendario", entity_id=evento.id)
                .first()
            )
            assert log is not None
            assert log.previous_state["titulo"] == "Natal"
            assert log.new_state["titulo"] == "Natal - Atualizado"

    def test_atualizar_evento_inexistente_levanta_erro(self, app, db):
        """Atualizar ID inexistente deve levantar ValueError."""
        with app.app_context():
            with pytest.raises(ValueError, match="não encontrado"):
                CalendarService.atualizar(event_id=9999, titulo="X")


# ---------------------------------------------------------------------------
# CRUD — deletar
# ---------------------------------------------------------------------------

class TestDeletar:

    def test_deletar_gera_audit_log(self, app, db):
        """Deleção deve gerar AuditLog com action=delete e previous_state."""
        with app.app_context():
            evento = CalendarService.criar(
                titulo="Evento Temporário",
                data_evento=date(2026, 8, 1),
                tipo=TipoEvento.MAINTENANCE,
                ator_id=None,
            )
            eid = evento.id
            CalendarService.deletar(event_id=eid, ator_id=None)

            assert db.session.get(GlobalEvent, eid) is None

            log = (
                db.session.query(AuditLog)
                .filter_by(action=AuditAction.delete, module="calendario", entity_id=eid)
                .first()
            )
            assert log is not None
            assert log.previous_state["titulo"] == "Evento Temporário"

    def test_deletar_evento_inexistente_levanta_erro(self, app, db):
        """Deletar ID inexistente deve levantar ValueError."""
        with app.app_context():
            with pytest.raises(ValueError, match="não encontrado"):
                CalendarService.deletar(event_id=9999)
