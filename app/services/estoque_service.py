# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
Serviços de negócio para o módulo Estoque.

Regras Antigravity obrigatórias:
    - Nenhum import Flask (request, session, current_app, g).
    - Parâmetros sempre explícitos; nunca ler contexto HTTP.
    - Toda mutação chama AuditService (Regra #1).
    - Compatível com compilação Cython.

Classes:
    ProdutoService              — CRUD de produtos
    CategoriaProdutoService     — CRUD de categorias
    LocalArmazenamentoService   — CRUD de locais de armazenamento
    EstoqueService              — Gestão de lotes e consultas de estoque
"""

from __future__ import annotations

from datetime import datetime, timezone
from decimal import Decimal

from app.core.audit import AuditService
from app.core.models import AuditAction
from app.extensions import db
from app.models.estoque import (
    CategoriaProduto,
    LoteEstoque,
    LocalArmazenamento,
    Produto,
    StatusLote,
    TipoLocal,
    TipoProduto,
    TipoTransacao,
    TransacaoEstoque,
)
from app.services.base import BaseService


# ---------------------------------------------------------------------------
# ProdutoService
# ---------------------------------------------------------------------------

class ProdutoService(BaseService):
    """Serviço de CRUD para produtos do estoque."""

    _CAMPOS_SNAPSHOT = [
        "id", "nome", "descricao", "sku", "codigo_barras",
        "categoria_id", "tipo", "unidade_base_id", "custo_medio",
        "preco_venda", "estoque_minimo", "is_perecivel", "ativo",
    ]

    @classmethod
    def criar(
        cls,
        nome: str,
        tipo: str | TipoProduto,
        *,
        descricao: str | None = None,
        sku: str | None = None,
        codigo_barras: str | None = None,
        categoria_id: int | None = None,
        unidade_base_id: int | None = None,
        resource_parent_id: int | None = None,
        custo_medio: float = 0.0,
        preco_venda: float | None = None,
        estoque_minimo: float = 0.0,
        is_perecivel: bool = False,
        ator_id: int | None = None,
    ) -> Produto:
        """Cria um novo produto e registra o evento no log de auditoria.

        Args:
            nome: Nome completo do produto — obrigatório.
            tipo: Classificação do produto (TipoProduto enum ou string).
            descricao: Descrição opcional.
            sku: Código SKU único — None se não aplicável.
            codigo_barras: Código de barras — None se não aplicável.
            categoria_id: FK para CategoriaProduto — None se sem categoria.
            unidade_base_id: FK para Unidade — None se não definida.
            resource_parent_id: FK para produto pai (somente tipo RESOURCE).
            custo_medio: Custo médio inicial (padrão 0).
            preco_venda: Preço de venda sugerido.
            estoque_minimo: Quantidade mínima desejada em estoque.
            is_perecivel: Indica se o produto tem prazo de validade.
            ator_id: ID do funcionário que realiza a ação. None = sistema.

        Returns:
            Instância de Produto persistida no banco de dados.

        Raises:
            ValueError: Se nome estiver vazio.
            ValueError: Se tipo for inválido.
            ValueError: Se SKU já estiver cadastrado.
        """
        cls._require(nome, "nome")

        if isinstance(tipo, str):
            try:
                tipo = TipoProduto(tipo)
            except ValueError:
                raise ValueError(
                    f"Tipo de produto inválido: '{tipo}'. "
                    f"Use: {[t.value for t in TipoProduto]}"
                )

        if sku:
            existente = db.session.query(Produto).filter_by(sku=sku).first()
            if existente:
                raise ValueError(f"SKU '{sku}' já está cadastrado para outro produto.")

        produto = Produto(
            nome=nome.strip(),
            tipo=tipo,
            descricao=descricao,
            sku=sku or None,
            codigo_barras=codigo_barras or None,
            categoria_id=categoria_id,
            unidade_base_id=unidade_base_id,
            resource_parent_id=resource_parent_id,
            custo_medio=Decimal(str(custo_medio)),
            preco_venda=Decimal(str(preco_venda)) if preco_venda is not None else None,
            estoque_minimo=Decimal(str(estoque_minimo)),
            is_perecivel=is_perecivel,
            ativo=True,
        )
        db.session.add(produto)
        db.session.flush()  # Obtém o ID antes do commit

        AuditService.log_create(
            "estoque",
            entity_id=produto.id,
            new_state=cls._snapshot(produto, cls._CAMPOS_SNAPSHOT),
            actor_id=ator_id,
            area_slug="estoque",
        )
        db.session.commit()
        return produto

    @classmethod
    def atualizar(
        cls,
        produto_id: int,
        dados: dict,
        ator_id: int | None = None,
    ) -> Produto:
        """Atualiza campos de um produto existente e registra no log de auditoria.

        Args:
            produto_id: PK do produto a atualizar.
            dados: Dicionário com os campos a alterar e seus novos valores.
                   Campos aceitos: nome, descricao, sku, codigo_barras,
                   categoria_id, unidade_base_id, preco_venda, estoque_minimo,
                   is_perecivel.
            ator_id: ID do funcionário que realiza a ação.

        Returns:
            Instância de Produto atualizada.

        Raises:
            ValueError: Se produto não for encontrado ou estiver inativo.
            ValueError: Se SKU já pertencer a outro produto.
        """
        produto = cls.buscar_por_id(produto_id)
        snapshot_antes = cls._snapshot(produto, cls._CAMPOS_SNAPSHOT)

        campos_permitidos = {
            "nome", "descricao", "sku", "codigo_barras",
            "categoria_id", "unidade_base_id", "resource_parent_id",
            "preco_venda", "estoque_minimo", "is_perecivel",
        }

        for campo, valor in dados.items():
            if campo not in campos_permitidos:
                continue

            if campo == "sku" and valor:
                existente = (
                    db.session.query(Produto)
                    .filter(Produto.sku == valor, Produto.id != produto_id)
                    .first()
                )
                if existente:
                    raise ValueError(f"SKU '{valor}' já está cadastrado para outro produto.")

            if campo in ("preco_venda", "estoque_minimo") and valor is not None:
                valor = Decimal(str(valor))
            elif campo == "nome" and valor:
                valor = valor.strip()
                cls._require(valor, "nome")

            setattr(produto, campo, valor)

        produto.atualizado_em = datetime.now(timezone.utc)

        AuditService.log_update(
            "estoque",
            entity_id=produto.id,
            previous_state=snapshot_antes,
            new_state=cls._snapshot(produto, cls._CAMPOS_SNAPSHOT),
            actor_id=ator_id,
            area_slug="estoque",
        )
        db.session.commit()
        return produto

    @classmethod
    def desativar(cls, produto_id: int, ator_id: int | None = None) -> Produto:
        """Desativa um produto (soft-delete: ativo=False).

        Produto desativado não aparece nas listagens nem pode receber lotes.
        A operação é auditada.

        Args:
            produto_id: PK do produto a desativar.
            ator_id: ID do funcionário que realiza a ação.

        Returns:
            Instância de Produto desativada.

        Raises:
            ValueError: Se produto não for encontrado ou já estiver inativo.
        """
        produto = cls.buscar_por_id(produto_id)
        snapshot_antes = cls._snapshot(produto, cls._CAMPOS_SNAPSHOT)

        produto.ativo = False
        produto.atualizado_em = datetime.now(timezone.utc)

        AuditService.log_delete(
            "estoque",
            entity_id=produto.id,
            previous_state=snapshot_antes,
            actor_id=ator_id,
            area_slug="estoque",
        )
        db.session.commit()
        return produto

    @classmethod
    def buscar_por_id(cls, produto_id: int) -> Produto:
        """Retorna um Produto ativo pelo seu ID.

        Args:
            produto_id: PK do produto.

        Returns:
            Instância de Produto.

        Raises:
            ValueError: Se produto não for encontrado ou estiver inativo.
        """
        produto = db.session.get(Produto, produto_id)
        if not produto or not produto.ativo:
            raise ValueError(f"Produto com ID {produto_id} não encontrado.")
        return produto

    @classmethod
    def listar(
        cls,
        apenas_ativos: bool = True,
        tipo: str | TipoProduto | None = None,
        categoria_id: int | None = None,
        q: str | None = None,
    ) -> list[Produto]:
        """Retorna lista de produtos com filtros opcionais.

        Args:
            apenas_ativos: Se True, retorna apenas produtos com ativo=True.
            tipo: Filtro por TipoProduto (RAW, RESALE, MANUFACTURED, RESOURCE).
            categoria_id: Filtro por categoria.
            q: Busca livre em nome e SKU.

        Returns:
            Lista de Produto ordenada por nome.
        """
        query = db.session.query(Produto)

        if apenas_ativos:
            query = query.filter(Produto.ativo == True)  # noqa: E712

        if tipo:
            if isinstance(tipo, str):
                tipo = TipoProduto(tipo)
            query = query.filter(Produto.tipo == tipo)

        if categoria_id:
            query = query.filter(Produto.categoria_id == categoria_id)

        if q:
            termo = f"%{q}%"
            query = query.filter(
                db.or_(
                    Produto.nome.ilike(termo),
                    Produto.sku.ilike(termo),
                )
            )

        return query.order_by(Produto.nome).all()


# ---------------------------------------------------------------------------
# CategoriaProdutoService
# ---------------------------------------------------------------------------

class CategoriaProdutoService(BaseService):
    """Serviço de CRUD para categorias de produto."""

    _CAMPOS_SNAPSHOT = ["id", "nome", "descricao"]

    @classmethod
    def criar(
        cls,
        nome: str,
        *,
        descricao: str | None = None,
        ator_id: int | None = None,
    ) -> CategoriaProduto:
        """Cria uma nova categoria de produto e registra no log de auditoria.

        Args:
            nome: Nome único da categoria — obrigatório.
            descricao: Descrição opcional.
            ator_id: ID do funcionário que realiza a ação.

        Returns:
            Instância de CategoriaProduto persistida.

        Raises:
            ValueError: Se nome estiver vazio.
            ValueError: Se nome já estiver cadastrado.
        """
        cls._require(nome, "nome")

        existente = db.session.query(CategoriaProduto).filter_by(nome=nome.strip()).first()
        if existente:
            raise ValueError(f"Categoria '{nome}' já está cadastrada.")

        categoria = CategoriaProduto(nome=nome.strip(), descricao=descricao)
        db.session.add(categoria)
        db.session.flush()

        AuditService.log_create(
            "estoque",
            entity_id=categoria.id,
            new_state=cls._snapshot(categoria, cls._CAMPOS_SNAPSHOT),
            actor_id=ator_id,
            area_slug="estoque",
        )
        db.session.commit()
        return categoria

    @classmethod
    def atualizar(
        cls,
        categoria_id: int,
        dados: dict,
        ator_id: int | None = None,
    ) -> CategoriaProduto:
        """Atualiza campos de uma categoria existente.

        Args:
            categoria_id: PK da categoria a atualizar.
            dados: Campos a alterar (nome, descricao).
            ator_id: ID do funcionário que realiza a ação.

        Returns:
            Instância de CategoriaProduto atualizada.

        Raises:
            ValueError: Se categoria não for encontrada.
            ValueError: Se nome já pertencer a outra categoria.
        """
        categoria = db.session.get(CategoriaProduto, categoria_id)
        if not categoria:
            raise ValueError(f"Categoria com ID {categoria_id} não encontrada.")

        snapshot_antes = cls._snapshot(categoria, cls._CAMPOS_SNAPSHOT)

        if "nome" in dados and dados["nome"]:
            novo_nome = dados["nome"].strip()
            existente = (
                db.session.query(CategoriaProduto)
                .filter(
                    CategoriaProduto.nome == novo_nome,
                    CategoriaProduto.id != categoria_id,
                )
                .first()
            )
            if existente:
                raise ValueError(f"Categoria '{novo_nome}' já está cadastrada.")
            categoria.nome = novo_nome

        if "descricao" in dados:
            categoria.descricao = dados["descricao"]

        AuditService.log_update(
            "estoque",
            entity_id=categoria.id,
            previous_state=snapshot_antes,
            new_state=cls._snapshot(categoria, cls._CAMPOS_SNAPSHOT),
            actor_id=ator_id,
            area_slug="estoque",
        )
        db.session.commit()
        return categoria

    @classmethod
    def listar(cls) -> list[CategoriaProduto]:
        """Retorna todas as categorias ordenadas por nome.

        Returns:
            Lista de CategoriaProduto.
        """
        return (
            db.session.query(CategoriaProduto)
            .order_by(CategoriaProduto.nome)
            .all()
        )


# ---------------------------------------------------------------------------
# LocalArmazenamentoService
# ---------------------------------------------------------------------------

class LocalArmazenamentoService(BaseService):
    """Serviço de CRUD para locais de armazenamento."""

    _CAMPOS_SNAPSHOT = ["id", "nome", "codigo", "tipo", "parent_id", "ativo"]

    @classmethod
    def criar(
        cls,
        nome: str,
        tipo: str | TipoLocal,
        *,
        codigo: str | None = None,
        descricao: str | None = None,
        parent_id: int | None = None,
        ator_id: int | None = None,
    ) -> LocalArmazenamento:
        """Cria um novo local de armazenamento e registra no log de auditoria.

        Args:
            nome: Nome do local — obrigatório.
            tipo: Tipo físico (TipoLocal enum ou string).
            codigo: Código alfanumérico curto (ex.: "ALM-A1").
            descricao: Descrição opcional.
            parent_id: FK para o local pai (hierarquia).
            ator_id: ID do funcionário que realiza a ação.

        Returns:
            Instância de LocalArmazenamento persistida.

        Raises:
            ValueError: Se nome estiver vazio.
            ValueError: Se tipo for inválido.
        """
        cls._require(nome, "nome")

        if isinstance(tipo, str):
            try:
                tipo = TipoLocal(tipo)
            except ValueError:
                raise ValueError(
                    f"Tipo de local inválido: '{tipo}'. "
                    f"Use: {[t.value for t in TipoLocal]}"
                )

        local = LocalArmazenamento(
            nome=nome.strip(),
            tipo=tipo,
            codigo=codigo or None,
            descricao=descricao,
            parent_id=parent_id,
            ativo=True,
        )
        db.session.add(local)
        db.session.flush()

        AuditService.log_create(
            "estoque",
            entity_id=local.id,
            new_state=cls._snapshot(local, cls._CAMPOS_SNAPSHOT),
            actor_id=ator_id,
            area_slug="estoque",
        )
        db.session.commit()
        return local

    @classmethod
    def listar(cls, apenas_ativos: bool = True) -> list[LocalArmazenamento]:
        """Retorna lista de locais de armazenamento.

        Args:
            apenas_ativos: Se True, retorna apenas locais com ativo=True.

        Returns:
            Lista de LocalArmazenamento ordenada por nome.
        """
        query = db.session.query(LocalArmazenamento)
        if apenas_ativos:
            query = query.filter(LocalArmazenamento.ativo == True)  # noqa: E712
        return query.order_by(LocalArmazenamento.nome).all()


# ---------------------------------------------------------------------------
# EstoqueService
# ---------------------------------------------------------------------------

class EstoqueService(BaseService):
    """Serviço de gestão de lotes e consultas de estoque."""

    _CAMPOS_LOTE_SNAPSHOT = [
        "id", "produto_id", "local_id", "unidade_id", "codigo_lote",
        "quantidade_inicial", "quantidade", "custo_unitario",
        "data_validade", "status",
    ]

    @classmethod
    def registrar_entrada(
        cls,
        produto_id: int,
        local_id: int,
        quantidade: float,
        unidade_id: int,
        custo_unitario: float,
        ator_id: int | None = None,
        *,
        codigo_lote: str | None = None,
        local_aquisicao: str | None = None,
        data_fabricacao=None,
        data_validade=None,
        fornecedor_cnpj: str | None = None,
        num_nfe: str | None = None,
        chave_nfe: str | None = None,
        observacoes: str | None = None,
    ) -> LoteEstoque:
        """Registra entrada de um lote de mercadoria no estoque.

        Cria um LoteEstoque, uma TransacaoEstoque do tipo IN e recalcula
        o custo médio ponderado do produto.

        Fórmula do custo médio ponderado:
            custo_medio_novo = SOMA(qtd × custo_unitario) / SOMA(qtd)
            para todos os lotes com status=ativo após a entrada.

        Args:
            produto_id: PK do produto.
            local_id: PK do local de armazenamento de destino.
            quantidade: Quantidade do lote — deve ser > 0.
            unidade_id: PK da unidade de medida.
            custo_unitario: Custo unitário deste lote específico.
            ator_id: ID do funcionário que registra a entrada.
            codigo_lote: Código do lote (fornecedor ou gerado automaticamente).
            local_aquisicao: Descrição do local de compra (ex.: "Mercado Brasil").
            data_fabricacao: Data de fabricação (date).
            data_validade: Data de validade (date).
            fornecedor_cnpj: CNPJ do fornecedor para rastreio de NFe.
            num_nfe: Número da NF-e.
            chave_nfe: Chave de 44 dígitos da NF-e.
            observacoes: Observações livres.

        Returns:
            Instância de LoteEstoque criada.

        Raises:
            ValueError: Se produto não for encontrado.
            ValueError: Se local não for encontrado.
            ValueError: Se quantidade for <= 0.
        """
        qtd = Decimal(str(quantidade))
        if qtd <= 0:
            raise ValueError("A quantidade deve ser maior que zero.")

        produto = db.session.get(Produto, produto_id)
        if not produto or not produto.ativo:
            raise ValueError(f"Produto com ID {produto_id} não encontrado.")

        local = db.session.get(LocalArmazenamento, local_id)
        if not local or not local.ativo:
            raise ValueError(f"Local com ID {local_id} não encontrado.")

        custo = Decimal(str(custo_unitario))

        # Gerar código de lote automático se não fornecido
        if not codigo_lote:
            now_str = datetime.now(timezone.utc).strftime("%d%m%y%H%M%S")
            codigo_lote = f"ENT-{now_str}-{produto_id}"

        lote = LoteEstoque(
            produto_id=produto_id,
            local_id=local_id,
            unidade_id=unidade_id,
            codigo_lote=codigo_lote,
            local_aquisicao=local_aquisicao,
            data_fabricacao=data_fabricacao,
            data_validade=data_validade,
            quantidade_inicial=qtd,
            quantidade=qtd,
            custo_unitario=custo,
            fornecedor_cnpj=fornecedor_cnpj,
            num_nfe=num_nfe,
            chave_nfe=chave_nfe,
            observacoes=observacoes,
            status=StatusLote.ativo,
        )
        db.session.add(lote)
        db.session.flush()

        # Transação de entrada (ledger imutável)
        transacao = TransacaoEstoque(
            produto_id=produto_id,
            lote_id=lote.id,
            para_local_id=local_id,
            funcionario_id=ator_id,
            tipo=TipoTransacao.IN,
            quantidade=qtd,
            unidade_id=unidade_id,
            custo_unitario=custo,
            custo_total=qtd * custo,
            motivo="Entrada de estoque",
        )
        db.session.add(transacao)

        # Recalcular custo médio ponderado do produto
        cls._recalcular_custo_medio(produto)

        AuditService.log_create(
            "estoque",
            entity_id=lote.id,
            new_state=cls._snapshot(lote, cls._CAMPOS_LOTE_SNAPSHOT),
            actor_id=ator_id,
            area_slug="estoque",
        )
        db.session.commit()
        return lote

    @classmethod
    def _recalcular_custo_medio(cls, produto: Produto) -> None:
        """Recalcula o custo médio ponderado do produto com base nos lotes ativos.

        Fórmula:
            custo_medio = SOMA(quantidade × custo_unitario) / SOMA(quantidade)

        O resultado é gravado em produto.custo_medio antes do commit.
        Este método não faz commit — o chamador é responsável.

        Args:
            produto: Instância de Produto a ser atualizada.
        """
        lotes_ativos = (
            db.session.query(LoteEstoque)
            .filter(
                LoteEstoque.produto_id == produto.id,
                LoteEstoque.status == StatusLote.ativo,
                LoteEstoque.quantidade > 0,
            )
            .all()
        )

        if not lotes_ativos:
            return

        soma_qtd = sum(l.quantidade for l in lotes_ativos)
        soma_valor = sum(l.quantidade * l.custo_unitario for l in lotes_ativos)

        if soma_qtd > 0:
            produto.custo_medio = soma_valor / soma_qtd

    @classmethod
    def consultar_estoque_por_produto(cls, produto_id: int) -> list[LoteEstoque]:
        """Retorna todos os lotes ativos de um produto, ordenados por data de entrada.

        Obedece ao critério FEFO (First Expired, First Out): lotes com validade
        mais próxima aparecem primeiro; lotes sem validade aparecem por último.

        Args:
            produto_id: PK do produto.

        Returns:
            Lista de LoteEstoque com status=ativo.

        Raises:
            ValueError: Se produto não for encontrado.
        """
        produto = db.session.get(Produto, produto_id)
        if not produto:
            raise ValueError(f"Produto com ID {produto_id} não encontrado.")

        return (
            db.session.query(LoteEstoque)
            .filter(
                LoteEstoque.produto_id == produto_id,
                LoteEstoque.status == StatusLote.ativo,
                LoteEstoque.quantidade > 0,
            )
            .order_by(
                LoteEstoque.data_validade.asc().nullslast(),
                LoteEstoque.data_entrada.asc(),
            )
            .all()
        )

    @classmethod
    def consultar_estoque_por_local(cls, local_id: int) -> list[LoteEstoque]:
        """Retorna todos os lotes ativos em um local específico.

        Args:
            local_id: PK do local de armazenamento.

        Returns:
            Lista de LoteEstoque com status=ativo no local, ordenada por produto.

        Raises:
            ValueError: Se local não for encontrado.
        """
        local = db.session.get(LocalArmazenamento, local_id)
        if not local:
            raise ValueError(f"Local com ID {local_id} não encontrado.")

        return (
            db.session.query(LoteEstoque)
            .join(Produto)
            .filter(
                LoteEstoque.local_id == local_id,
                LoteEstoque.status == StatusLote.ativo,
                LoteEstoque.quantidade > 0,
                Produto.ativo == True,  # noqa: E712
            )
            .order_by(Produto.nome, LoteEstoque.data_entrada)
            .all()
        )
