# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
ReceitaService — Gestão de fichas técnicas (receitas) do módulo Restaurante.

Regras Antigravity obrigatórias:
    - Nenhum import Flask.
    - Toda mutação chama AuditService (Regra #1).
    - Compatível com compilação Cython.

Conceitos-chave:
    - Uma Receita liga um produto-pai (prato final) a um produto-filho (ingrediente).
    - O fator_rendimento indica a transformação após cocção/processo:
        fator > 1 → produto ganha volume (ex.: arroz seco → cozido, fator=2.5)
        fator < 1 → produto perde volume (ex.: carne crua → cozida, fator=0.8)
    - quantidade_bruta = quantidade_liquida / fator_rendimento
      (quanto é retirado do estoque antes do processo)
"""

from __future__ import annotations

from decimal import Decimal

from app.core.audit import AuditService
from app.extensions import db
from app.models.estoque import Produto, Receita
from app.services.base import BaseService


class ReceitaService(BaseService):
    """Serviço de CRUD e cálculo de custos para fichas técnicas."""

    _CAMPOS_SNAPSHOT = [
        "id", "produto_pai_id", "produto_filho_id", "unidade_id",
        "quantidade_liquida", "fator_rendimento", "quantidade_bruta",
    ]

    @classmethod
    def criar_item(
        cls,
        produto_pai_id: int,
        produto_filho_id: int,
        quantidade_liquida: float,
        *,
        fator_rendimento: float = 1.0,
        unidade_id: int | None = None,
        observacoes: str | None = None,
        ator_id: int | None = None,
    ) -> Receita:
        """Adiciona um ingrediente à ficha técnica de um produto.

        Args:
            produto_pai_id: PK do produto final (prato ou produto fabricado).
            produto_filho_id: PK do ingrediente a ser consumido.
            quantidade_liquida: Quantidade desejada no produto final (pós-cocção).
            fator_rendimento: Razão entre peso final e peso inicial no processo.
                              1.0 = sem perda/ganho; 2.5 = triplica; 0.8 = perde 20%.
            unidade_id: Unidade de medida. None = herda unidade_base do ingrediente.
            observacoes: Notas livres sobre o ingrediente na receita.
            ator_id: ID do funcionário que realiza a ação.

        Returns:
            Instância de Receita persistida.

        Raises:
            ValueError: Se produto pai ou filho não for encontrado.
            ValueError: Se o par (pai, filho) já existir na receita.
            ValueError: Se fator_rendimento for <= 0.
            ValueError: Se quantidade_liquida for <= 0.
        """
        liq = Decimal(str(quantidade_liquida))
        if liq <= 0:
            raise ValueError("A quantidade_liquida deve ser maior que zero.")

        fator = Decimal(str(fator_rendimento))
        if fator <= 0:
            raise ValueError("O fator_rendimento deve ser maior que zero.")

        pai = db.session.get(Produto, produto_pai_id)
        if not pai or not pai.ativo:
            raise ValueError(f"Produto pai com ID {produto_pai_id} não encontrado.")

        filho = db.session.get(Produto, produto_filho_id)
        if not filho or not filho.ativo:
            raise ValueError(f"Ingrediente com ID {produto_filho_id} não encontrado.")

        existente = (
            db.session.query(Receita)
            .filter_by(produto_pai_id=produto_pai_id, produto_filho_id=produto_filho_id)
            .first()
        )
        if existente:
            raise ValueError(
                f"O ingrediente '{filho.nome}' já consta na ficha técnica de '{pai.nome}'."
            )

        bruto = liq / fator

        receita = Receita(
            produto_pai_id=produto_pai_id,
            produto_filho_id=produto_filho_id,
            unidade_id=unidade_id,
            quantidade_liquida=liq,
            fator_rendimento=fator,
            quantidade_bruta=bruto,
            observacoes=observacoes,
        )
        db.session.add(receita)
        db.session.flush()

        AuditService.log_create(
            "restaurante",
            entity_id=receita.id,
            new_state=cls._snapshot(receita, cls._CAMPOS_SNAPSHOT),
            actor_id=ator_id,
            area_slug="restaurante",
        )
        db.session.commit()
        return receita

    @classmethod
    def atualizar_item(
        cls,
        receita_id: int,
        dados: dict,
        ator_id: int | None = None,
    ) -> Receita:
        """Atualiza quantidade e/ou fator de rendimento de um item de receita.

        Recalcula automaticamente quantidade_bruta após qualquer alteração.

        Args:
            receita_id: PK do item de receita a atualizar.
            dados: Campos a alterar. Aceitos: quantidade_liquida, fator_rendimento,
                   unidade_id, observacoes.
            ator_id: ID do funcionário que realiza a ação.

        Returns:
            Instância de Receita atualizada.

        Raises:
            ValueError: Se item não for encontrado.
            ValueError: Se quantidade_liquida ou fator_rendimento forem <= 0.
        """
        receita = db.session.get(Receita, receita_id)
        if not receita:
            raise ValueError(f"Item de receita com ID {receita_id} não encontrado.")

        snapshot_antes = cls._snapshot(receita, cls._CAMPOS_SNAPSHOT)

        if "quantidade_liquida" in dados:
            liq = Decimal(str(dados["quantidade_liquida"]))
            if liq <= 0:
                raise ValueError("A quantidade_liquida deve ser maior que zero.")
            receita.quantidade_liquida = liq

        if "fator_rendimento" in dados:
            fator = Decimal(str(dados["fator_rendimento"]))
            if fator <= 0:
                raise ValueError("O fator_rendimento deve ser maior que zero.")
            receita.fator_rendimento = fator

        if "unidade_id" in dados:
            receita.unidade_id = dados["unidade_id"]

        if "observacoes" in dados:
            receita.observacoes = dados["observacoes"]

        # Recalcular bruto
        receita.quantidade_bruta = receita.quantidade_liquida / receita.fator_rendimento

        AuditService.log_update(
            "restaurante",
            entity_id=receita.id,
            previous_state=snapshot_antes,
            new_state=cls._snapshot(receita, cls._CAMPOS_SNAPSHOT),
            actor_id=ator_id,
            area_slug="restaurante",
        )
        db.session.commit()
        return receita

    @classmethod
    def remover_item(cls, receita_id: int, ator_id: int | None = None) -> None:
        """Remove um item da ficha técnica.

        A remoção é auditada antes da exclusão.

        Args:
            receita_id: PK do item de receita a remover.
            ator_id: ID do funcionário que realiza a ação.

        Raises:
            ValueError: Se item não for encontrado.
        """
        receita = db.session.get(Receita, receita_id)
        if not receita:
            raise ValueError(f"Item de receita com ID {receita_id} não encontrado.")

        snapshot = cls._snapshot(receita, cls._CAMPOS_SNAPSHOT)

        AuditService.log_delete(
            "restaurante",
            entity_id=receita.id,
            previous_state=snapshot,
            actor_id=ator_id,
            area_slug="restaurante",
        )
        db.session.delete(receita)
        db.session.commit()

    @classmethod
    def listar_por_produto(cls, produto_pai_id: int) -> list[Receita]:
        """Retorna todos os ingredientes da ficha técnica de um produto.

        Args:
            produto_pai_id: PK do produto final.

        Returns:
            Lista de Receita ordenada pelo nome do ingrediente.
        """
        return (
            db.session.query(Receita)
            .join(Produto, Receita.produto_filho_id == Produto.id)
            .filter(Receita.produto_pai_id == produto_pai_id)
            .order_by(Produto.nome)
            .all()
        )

    @classmethod
    def calcular_custo_receita(cls, produto_pai_id: int) -> float:
        """Calcula o custo total da ficha técnica com base no custo médio dos ingredientes.

        Fórmula por ingrediente:
            custo_ingrediente = quantidade_bruta × ingrediente.custo_medio

        O custo total é a soma de todos os ingredientes.
        Se o custo_medio de um ingrediente for zero, ele não contribui para o total.

        Args:
            produto_pai_id: PK do produto final.

        Returns:
            Custo total da ficha técnica em float (unidade: moeda base).
        """
        itens = cls.listar_por_produto(produto_pai_id)
        total = Decimal("0")

        for item in itens:
            ingrediente = item.produto_filho
            if ingrediente and ingrediente.custo_medio:
                total += item.quantidade_bruta * ingrediente.custo_medio

        return float(total)
