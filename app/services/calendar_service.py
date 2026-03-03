# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
CalendarService — fonte única de verdade para feriados e eventos globais.

Nenhum módulo deve hardcodar feriados ou datas especiais. Todos devem
consultar este serviço para verificar se uma data é feriado, alta temporada,
ou manutenção programada.

Antigravity Rules:
    - Sem importações Flask — puro Python, compatível com Cython.
    - Toda mutação chama AuditService (Rule #1).
    - Recebe actor_id: int | None, nunca um objeto User/Funcionario.
"""

from __future__ import annotations

from datetime import date

from sqlalchemy import extract, or_

from app.core.audit import AuditService
from app.extensions import db
from app.models.calendario import GlobalEvent, TipoEvento
from app.services.base import BaseService

_HOLIDAY_TYPES = (TipoEvento.NATIONAL_HOLIDAY, TipoEvento.LOCAL_HOLIDAY)

_AUDIT_MODULE = "calendario"
_AUDIT_FIELDS = [
    "id",
    "titulo",
    "data_evento",
    "tipo",
    "afeta_precificacao",
    "afeta_folha",
    "recorrente_anual",
]


class CalendarService(BaseService):
    """Consultas e CRUD de eventos globais do calendário.

    Todos os métodos são estáticos — sem estado de instância.
    """

    # ------------------------------------------------------------------
    # Consultas (read-only)
    # ------------------------------------------------------------------

    @staticmethod
    def eh_feriado(data: date) -> bool:
        """Verifica se a data é feriado (nacional ou local).

        Considera tanto eventos com data exata quanto eventos recorrentes
        anuais (match por mês+dia, ignorando o ano).

        Args:
            data: Data a verificar.

        Returns:
            True se a data corresponde a um feriado cadastrado.
        """
        existe = (
            db.session.query(GlobalEvent.id)
            .filter(
                GlobalEvent.tipo.in_(_HOLIDAY_TYPES),
                or_(
                    # Match exato (feriados não recorrentes ou do mesmo ano)
                    GlobalEvent.data_evento == data,
                    # Match recorrente anual (mês + dia)
                    db.and_(
                        GlobalEvent.recorrente_anual.is_(True),
                        extract("month", GlobalEvent.data_evento) == data.month,
                        extract("day", GlobalEvent.data_evento) == data.day,
                    ),
                ),
            )
            .first()
        )
        return existe is not None

    @staticmethod
    def obter_eventos_mes(ano: int, mes: int) -> list[GlobalEvent]:
        """Retorna todos os eventos de um determinado mês.

        Inclui eventos com data exata no mês/ano informado e eventos
        recorrentes anuais que caem no mesmo mês.

        Args:
            ano: Ano de referência.
            mes: Mês de referência (1–12).

        Returns:
            Lista de GlobalEvent ordenada por data_evento.
        """
        return (
            db.session.query(GlobalEvent)
            .filter(
                or_(
                    # Eventos com data exata neste mês/ano
                    db.and_(
                        extract("year", GlobalEvent.data_evento) == ano,
                        extract("month", GlobalEvent.data_evento) == mes,
                    ),
                    # Eventos recorrentes que caem neste mês
                    db.and_(
                        GlobalEvent.recorrente_anual.is_(True),
                        extract("month", GlobalEvent.data_evento) == mes,
                    ),
                ),
            )
            .order_by(GlobalEvent.data_evento)
            .all()
        )

    @staticmethod
    def eh_alta_temporada(data_inicio: date, data_fim: date) -> bool:
        """Verifica se há pelo menos um evento de alta temporada no intervalo.

        Args:
            data_inicio: Data inicial do intervalo (inclusive).
            data_fim: Data final do intervalo (inclusive).

        Returns:
            True se algum evento HIGH_SEASON cai dentro do intervalo.
        """
        existe = (
            db.session.query(GlobalEvent.id)
            .filter(
                GlobalEvent.tipo == TipoEvento.HIGH_SEASON,
                or_(
                    # Match exato no intervalo
                    db.and_(
                        GlobalEvent.data_evento >= data_inicio,
                        GlobalEvent.data_evento <= data_fim,
                    ),
                    # Match recorrente — compara mês+dia no intervalo
                    # (simplificação: funciona quando o intervalo não cruza virada de ano)
                    db.and_(
                        GlobalEvent.recorrente_anual.is_(True),
                        extract("month", GlobalEvent.data_evento) >= data_inicio.month,
                        extract("month", GlobalEvent.data_evento) <= data_fim.month,
                    ),
                ),
            )
            .first()
        )
        return existe is not None

    # ------------------------------------------------------------------
    # Mutações (CRUD com auditoria)
    # ------------------------------------------------------------------

    @staticmethod
    def criar(
        titulo: str,
        data_evento: date,
        tipo: TipoEvento,
        afeta_precificacao: bool = False,
        afeta_folha: bool = False,
        recorrente_anual: bool = False,
        ator_id: int | None = None,
    ) -> GlobalEvent:
        """Cria um novo evento global e registra no log de auditoria.

        Args:
            titulo: Nome descritivo do evento.
            data_evento: Data do evento.
            tipo: Classificação do evento (TipoEvento).
            afeta_precificacao: Se True, módulos de precificação consideram o evento.
            afeta_folha: Se True, módulos de folha/ponto consideram o evento.
            recorrente_anual: Se True, o evento se repete todo ano (mês+dia).
            ator_id: ID do funcionário que realiza a ação.

        Returns:
            Instância de GlobalEvent persistida.

        Raises:
            ValueError: Se o título estiver vazio.
        """
        BaseService._require(titulo, "titulo")

        evento = GlobalEvent(
            titulo=titulo.strip(),
            data_evento=data_evento,
            tipo=tipo,
            afeta_precificacao=afeta_precificacao,
            afeta_folha=afeta_folha,
            recorrente_anual=recorrente_anual,
        )
        db.session.add(evento)
        db.session.flush()

        AuditService.log_create(
            module=_AUDIT_MODULE,
            entity_id=evento.id,
            new_state=BaseService._snapshot(evento, _AUDIT_FIELDS),
            actor_id=ator_id,
        )

        db.session.commit()
        return evento

    @staticmethod
    def atualizar(
        event_id: int,
        titulo: str | None = None,
        data_evento: date | None = None,
        tipo: TipoEvento | None = None,
        afeta_precificacao: bool | None = None,
        afeta_folha: bool | None = None,
        recorrente_anual: bool | None = None,
        ator_id: int | None = None,
    ) -> GlobalEvent:
        """Atualiza um evento existente e registra no log de auditoria.

        Apenas os campos fornecidos (não None) são atualizados.

        Args:
            event_id: PK do evento a atualizar.
            titulo: Novo título (opcional).
            data_evento: Nova data (opcional).
            tipo: Novo tipo (opcional).
            afeta_precificacao: Novo valor (opcional).
            afeta_folha: Novo valor (opcional).
            recorrente_anual: Novo valor (opcional).
            ator_id: ID do funcionário que realiza a ação.

        Returns:
            Instância de GlobalEvent atualizada.

        Raises:
            ValueError: Se o evento não for encontrado ou título for vazio.
        """
        evento = db.session.get(GlobalEvent, event_id)
        if not evento:
            raise ValueError(f"Evento {event_id} não encontrado.")

        snapshot_antes = BaseService._snapshot(evento, _AUDIT_FIELDS)

        if titulo is not None:
            BaseService._require(titulo, "titulo")
            evento.titulo = titulo.strip()
        if data_evento is not None:
            evento.data_evento = data_evento
        if tipo is not None:
            evento.tipo = tipo
        if afeta_precificacao is not None:
            evento.afeta_precificacao = afeta_precificacao
        if afeta_folha is not None:
            evento.afeta_folha = afeta_folha
        if recorrente_anual is not None:
            evento.recorrente_anual = recorrente_anual

        db.session.flush()

        AuditService.log_update(
            module=_AUDIT_MODULE,
            entity_id=evento.id,
            previous_state=snapshot_antes,
            new_state=BaseService._snapshot(evento, _AUDIT_FIELDS),
            actor_id=ator_id,
        )

        db.session.commit()
        return evento

    @staticmethod
    def deletar(event_id: int, ator_id: int | None = None) -> None:
        """Remove um evento global e registra no log de auditoria.

        Args:
            event_id: PK do evento a remover.
            ator_id: ID do funcionário que realiza a ação.

        Raises:
            ValueError: Se o evento não for encontrado.
        """
        evento = db.session.get(GlobalEvent, event_id)
        if not evento:
            raise ValueError(f"Evento {event_id} não encontrado.")

        snapshot = BaseService._snapshot(evento, _AUDIT_FIELDS)

        db.session.delete(evento)
        db.session.commit()

        AuditService.log_delete(
            module=_AUDIT_MODULE,
            entity_id=event_id,
            previous_state=snapshot,
            actor_id=ator_id,
        )
