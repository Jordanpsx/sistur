# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
RHService — Agregações e consultas de leitura para o módulo de Recursos Humanos.

Antigravity Rule #2: sem imports do Flask — puro Python, compatível com Cython.
Este serviço contém apenas operações de leitura (sem mutações), portanto não
chama AuditService.
"""

from __future__ import annotations

from datetime import date, timedelta

from sqlalchemy import func

from app.extensions import db
from app.models.funcionario import Funcionario
from app.models.ponto import TimeDay
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
