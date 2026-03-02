# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
RHService — Agregações e consultas de leitura para o módulo de Recursos Humanos.

Antigravity Rule #2: sem imports do Flask — puro Python, compatível com Cython.
Este serviço contém apenas operações de leitura (sem mutações), portanto não
chama AuditService.
"""

from __future__ import annotations

import calendar
from datetime import date, timedelta

from sqlalchemy import func

from app.extensions import db
from app.models.funcionario import Funcionario
from app.models.ponto import TimeDay, TimeEntry
from app.services.base import BaseService


class RHService(BaseService):
    """
    Serviço de leitura e agregação para o dashboard de RH.

    Todos os métodos são estáticos — sem estado de instância — compatível com Cython.
    Sem mutações: não chama AuditService.
    """

    # ------------------------------------------------------------------
    # Resumo Mensal
    # ------------------------------------------------------------------

    @staticmethod
    def resumo_mensal(ano: int, mes: int) -> dict:
        """Agrega dados de ponto de todos os funcionários em um mês específico.

        Utiliza SQLAlchemy func.sum() para eficiência — uma query por métrica,
        sem carregar registros individuais na memória.

        Args:
            ano: Ano do período a agregar (ex.: 2026).
            mes: Mês do período a agregar (1–12).

        Returns:
            Dicionário com as métricas agregadas:
            - total_funcionarios (int): funcionários ativos no sistema.
            - total_minutos_trabalhados (int): soma de minutos trabalhados no mês.
            - total_saldo_minutos (int): soma dos saldos calculados no mês.
            - dias_com_revisao (int): dias marcados com needs_review=True.
        """
        inicio = date(ano, mes, 1)
        fim = date(ano + 1, 1, 1) if mes == 12 else date(ano, mes + 1, 1)

        total_funcionarios = (
            db.session.query(func.count(Funcionario.id))
            .filter_by(ativo=True)
            .scalar()
        ) or 0

        agg = (
            db.session.query(
                func.coalesce(func.sum(TimeDay.minutos_trabalhados), 0),
                func.coalesce(func.sum(TimeDay.saldo_calculado_minutos), 0),
                func.coalesce(
                    func.sum(db.case((TimeDay.needs_review == True, 1), else_=0)), 0
                ),
            )
            .filter(
                TimeDay.shift_date >= inicio,
                TimeDay.shift_date < fim,
            )
            .one()
        )

        return {
            "total_funcionarios": total_funcionarios,
            "total_minutos_trabalhados": int(agg[0]),
            "total_saldo_minutos": int(agg[1]),
            "dias_com_revisao": int(agg[2]),
        }

    # ------------------------------------------------------------------
    # Central de Alertas
    # ------------------------------------------------------------------

    @staticmethod
    def alertas_revisao(janela_dias: int = 7) -> list[dict]:
        """Retorna ocorrências com needs_review=True nos últimos N dias.

        Faz JOIN entre TimeDay e Funcionario para retornar o nome do colaborador
        junto com os dados do dia problemático.

        Args:
            janela_dias: Quantidade de dias para trás a considerar (padrão: 7).

        Returns:
            Lista de dicionários ordenados por shift_date DESC, cada um contendo:
            - funcionario_id (int)
            - nome (str): nome do funcionário
            - shift_date (date): data do dia com pendência
            - minutos_trabalhados (int)
        """
        corte = date.today() - timedelta(days=janela_dias)

        rows = (
            db.session.query(
                TimeDay.funcionario_id,
                Funcionario.nome,
                TimeDay.shift_date,
                TimeDay.minutos_trabalhados,
            )
            .join(Funcionario, Funcionario.id == TimeDay.funcionario_id)
            .filter(
                TimeDay.needs_review == True,
                TimeDay.shift_date >= corte,
                Funcionario.ativo == True,
            )
            .order_by(TimeDay.shift_date.desc())
            .all()
        )

        return [
            {
                "funcionario_id": r.funcionario_id,
                "nome": r.nome,
                "shift_date": r.shift_date,
                "minutos_trabalhados": r.minutos_trabalhados,
            }
            for r in rows
        ]

    # ------------------------------------------------------------------
    # Listagem com Filtros
    # ------------------------------------------------------------------

    @staticmethod
    def listar_com_filtros(
        q: str = "",
        area_id: int | None = None,
        status: str = "ativo",
    ) -> list[Funcionario]:
        """Lista funcionários com filtros combinados de busca, área e status.

        Move a lógica de query do blueprint para o serviço (Antigravity Rule #2 —
        sem queries em blueprints).

        Args:
            q: Termo livre para filtrar por nome ou CPF (case-insensitive).
            area_id: ID da área para filtrar. None = todas as áreas.
            status: "ativo" | "inativo" | "todos". Padrão: "ativo".

        Returns:
            Lista de Funcionario ordenada por nome.
        """
        query = db.session.query(Funcionario)

        if status == "ativo":
            query = query.filter(Funcionario.ativo == True)
        elif status == "inativo":
            query = query.filter(Funcionario.ativo == False)
        # "todos" → sem filtro de status

        if area_id:
            query = query.filter(Funcionario.area_id == area_id)

        if q:
            termo = f"%{q}%"
            query = query.filter(
                db.or_(
                    Funcionario.nome.ilike(termo),
                    Funcionario.cpf.ilike(termo),
                )
            )

        return query.order_by(Funcionario.nome).all()

    # ------------------------------------------------------------------
    # Folha de Ponto Individual (Tab 3 do Dashboard RH)
    # ------------------------------------------------------------------

    @staticmethod
    def folha_ponto_mes(funcionario_id: int, ano: int, mes: int) -> dict:
        """Monta a Folha de Ponto CLT de um funcionário para um mês específico.

        Carrega TimeDay e TimeEntry em queries separadas (evitando produto
        cartesiano) e monta a estrutura de 10 colunas usada na tabela CLT
        e no PDF da Folha de Ponto.

        Antigravity Rule #2: sem queries em blueprints — toda lógica aqui.

        Args:
            funcionario_id: PK do funcionário.
            ano: Ano do período (ex.: 2026).
            mes: Mês do período (1–12).

        Returns:
            Dicionário com:
            - funcionario (Funcionario): instância do funcionário.
            - linhas (list[dict]): uma linha por dia com TimeDay registrado,
              contendo as 10 colunas CLT + time_day_id + needs_review.
              Cada linha:
                shift_date, dia_semana, clock_in, lunch_start, lunch_end,
                clock_out, minutos_trabalhados, expected_minutes_snapshot,
                saldo_calculado_minutos, status, needs_review, time_day_id.
              Os campos de batida são instâncias de TimeEntry ou None.
            - total_minutos_trabalhados (int): soma dos minutos trabalhados.
            - total_saldo_minutos (int): soma dos saldos calculados do mês.
            - total_expected_minutos (int): soma dos minutos esperados.
            - dias_com_revisao (int): contagem de dias com needs_review=True.

        Raises:
            ValueError: Se o funcionário não for encontrado.
        """
        from collections import defaultdict

        funcionario = db.session.get(Funcionario, funcionario_id)
        if not funcionario:
            raise ValueError(f"Funcionário {funcionario_id} não encontrado.")

        inicio = date(ano, mes, 1)
        fim = date(ano + 1, 1, 1) if mes == 12 else date(ano, mes + 1, 1)

        # Query 1: dias processados
        days = (
            db.session.query(TimeDay)
            .filter(
                TimeDay.funcionario_id == funcionario_id,
                TimeDay.shift_date >= inicio,
                TimeDay.shift_date < fim,
            )
            .order_by(TimeDay.shift_date)
            .all()
        )

        # Query 2: batidas — separada para evitar produto cartesiano
        entries_raw = (
            db.session.query(TimeEntry)
            .filter(
                TimeEntry.funcionario_id == funcionario_id,
                TimeEntry.shift_date >= inicio,
                TimeEntry.shift_date < fim,
            )
            .order_by(TimeEntry.shift_date, TimeEntry.punch_time)
            .all()
        )

        # Agrupa batidas por dia
        entries_by_day: dict = defaultdict(list)
        for e in entries_raw:
            entries_by_day[e.shift_date].append(e)

        DOW_ABBR = ["Seg", "Ter", "Qua", "Qui", "Sex", "Sáb", "Dom"]

        _STATUS_LABELS = {
            True:  "Revisar",
            False: "OK",
        }

        linhas = []
        for d in days:
            entries = entries_by_day.get(d.shift_date, [])

            # Mapeia tipo → primeiro TimeEntry daquele tipo
            by_type: dict[str, "TimeEntry"] = {}  # type: ignore[name-defined]
            for e in entries:
                key = e.punch_type.value
                if key not in by_type:
                    by_type[key] = e

            linhas.append({
                "shift_date":              d.shift_date,
                "dia_semana":             DOW_ABBR[d.shift_date.weekday()],
                "clock_in":               by_type.get("clock_in"),
                "lunch_start":            by_type.get("lunch_start"),
                "lunch_end":              by_type.get("lunch_end"),
                "clock_out":              by_type.get("clock_out"),
                "minutos_trabalhados":    d.minutos_trabalhados,
                "expected_minutes_snapshot": d.expected_minutes_snapshot,
                "saldo_calculado_minutos": d.saldo_calculado_minutos,
                "status":                 _STATUS_LABELS[d.needs_review],
                "needs_review":           d.needs_review,
                "time_day_id":            d.id,
            })

        total_minutos_trabalhados = sum(d.minutos_trabalhados for d in days)
        total_saldo_minutos = sum(d.saldo_calculado_minutos for d in days)
        total_expected_minutos = sum(d.expected_minutes_snapshot for d in days)
        dias_com_revisao = sum(1 for d in days if d.needs_review)

        return {
            "funcionario":             funcionario,
            "linhas":                  linhas,
            "total_minutos_trabalhados": total_minutos_trabalhados,
            "total_saldo_minutos":     total_saldo_minutos,
            "total_expected_minutos":  total_expected_minutos,
            "dias_com_revisao":        dias_com_revisao,
        }

    # ------------------------------------------------------------------
    # Planilha Mensal de Batidas
    # ------------------------------------------------------------------

    @staticmethod
    def matriz_batidas_mes(ano: int, mes: int) -> dict:
        """Agrega contagem de batidas (TimeEntry) por funcionário × dia do mês.

        Retorna estrutura pronta para renderização da planilha mensal no dashboard RH.
        Usa GROUP BY em TimeEntry (não TimeDay) para obter a contagem bruta de batidas,
        independentemente do processamento do saldo.

        Args:
            ano: Ano do período (ex.: 2026).
            mes: Mês do período (1–12).

        Returns:
            Dicionário com:
            - dias_no_mes (int): total de dias no mês.
            - fins_semana (set[int]): dias 1..N que são sábado ou domingo.
            - linhas (list[dict]): uma entrada por funcionário ativo, contendo:
                - funcionario_id (int)
                - nome (str)
                - batidas (dict[int, int]): {dia: contagem}; ausente = zero batidas.
        """
        inicio = date(ano, mes, 1)
        dias_no_mes = calendar.monthrange(ano, mes)[1]
        fim = date(ano, mes, dias_no_mes) + timedelta(days=1)

        # Identifica fins de semana (5=sáb, 6=dom)
        fins_semana = {
            d for d in range(1, dias_no_mes + 1)
            if date(ano, mes, d).weekday() >= 5
        }

        # Funcionários ativos ordenados por nome
        funcionarios = (
            db.session.query(Funcionario)
            .filter_by(ativo=True)
            .order_by(Funcionario.nome)
            .all()
        )

        # Contagem de batidas agrupada por funcionario_id + shift_date
        agg = (
            db.session.query(
                TimeEntry.funcionario_id,
                TimeEntry.shift_date,
                func.count(TimeEntry.id).label("punch_count"),
            )
            .filter(
                TimeEntry.shift_date >= inicio,
                TimeEntry.shift_date < fim,
            )
            .group_by(TimeEntry.funcionario_id, TimeEntry.shift_date)
            .all()
        )

        # Lookup: funcionario_id → {dia_do_mes: contagem}
        lookup: dict[int, dict[int, int]] = {}
        for row in agg:
            lookup.setdefault(row.funcionario_id, {})[row.shift_date.day] = row.punch_count

        linhas = [
            {
                "funcionario_id": f.id,
                "nome": f.nome,
                "batidas": lookup.get(f.id, {}),
            }
            for f in funcionarios
        ]

        return {
            "dias_no_mes": dias_no_mes,
            "fins_semana": fins_semana,
            "linhas": linhas,
        }
