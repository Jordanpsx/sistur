# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
Modelos SQLAlchemy para os módulos Estoque e Restaurante.

Referência legada:
    legacy/includes/class-sistur-inventory.php
    legacy/includes/class-sistur-recipe-manager.php
    legacy/includes/class-sistur-stock-api.php
    legacy/sql/create-timebank-tables.sql

A fonte de verdade do estoque são os *lotes* (LoteEstoque), não um campo
`estoque_atual` no produto.  Cada entrada de mercadoria gera um LoteEstoque
distinto com custo, validade e local próprios.
"""

from __future__ import annotations

import enum
from datetime import datetime, timezone

from app.extensions import db


# ---------------------------------------------------------------------------
# Enums
# ---------------------------------------------------------------------------

class TipoProduto(str, enum.Enum):
    """Classificação do produto conforme seu papel na cadeia produtiva."""
    RAW = "RAW"                    # Ingrediente / matéria-prima
    RESALE = "RESALE"              # Comprado para revenda direta
    MANUFACTURED = "MANUFACTURED"  # Produzido internamente (receita)
    RESOURCE = "RESOURCE"          # Agrupador conceitual (ex.: "Arroz")


class TipoTransacao(str, enum.Enum):
    """Tipo de movimentação no ledger de transações."""
    IN = "IN"              # Entrada de estoque
    OUT = "OUT"            # Saída genérica
    SALE = "SALE"          # Venda
    LOSS = "LOSS"          # Perda / descarte
    ADJUST = "ADJUST"      # Ajuste de inventário
    TRANSFER = "TRANSFER"  # Transferência entre locais


class StatusLote(str, enum.Enum):
    """Estado atual de um lote de estoque."""
    ativo = "ativo"
    esgotado = "esgotado"
    vencido = "vencido"
    bloqueado = "bloqueado"


class TipoLocal(str, enum.Enum):
    """Categoria física do local de armazenamento."""
    almoxarifado = "almoxarifado"
    freezer = "freezer"
    geladeira = "geladeira"
    prateleira = "prateleira"
    outro = "outro"


class TipoUnidade(str, enum.Enum):
    """Dimensionalidade da unidade de medida."""
    dimensional = "dimensional"  # kg, L, g, mL
    unitaria = "unitaria"        # un, cx, pc, dz


class MotivoPerdaEstoque(str, enum.Enum):
    """Motivo registrado ao dar baixa por perda."""
    vencido = "vencido"
    erro_producao = "erro_producao"
    avariado = "avariado"
    divergencia_inventario = "divergencia_inventario"
    outro = "outro"


# ---------------------------------------------------------------------------
# CategoriaProduto
# ---------------------------------------------------------------------------

class CategoriaProduto(db.Model):
    """
    Categoria de agrupamento de produtos no estoque.

    Equivale às taxonomias de produto do sistema legado.
    """

    __tablename__ = "sistur_categorias_produto"

    id = db.Column(db.Integer, primary_key=True)
    nome = db.Column(db.String(255), unique=True, nullable=False)
    descricao = db.Column(db.Text, nullable=True)
    criado_em = db.Column(
        db.DateTime,
        default=lambda: datetime.now(timezone.utc),
        nullable=False,
    )
    atualizado_em = db.Column(
        db.DateTime,
        default=lambda: datetime.now(timezone.utc),
        onupdate=lambda: datetime.now(timezone.utc),
        nullable=False,
    )

    # Relationships
    produtos = db.relationship("Produto", back_populates="categoria", lazy="dynamic")

    def __repr__(self) -> str:
        return f"<CategoriaProduto id={self.id} nome={self.nome!r}>"


# ---------------------------------------------------------------------------
# Unidade
# ---------------------------------------------------------------------------

class Unidade(db.Model):
    """
    Unidade de medida utilizada no estoque e nas fichas técnicas.

    Unidades do sistema (is_sistema=True) não podem ser excluídas.
    Exemplos: kg, g, L, mL, un, cx.
    """

    __tablename__ = "sistur_unidades"

    id = db.Column(db.Integer, primary_key=True)
    nome = db.Column(db.String(100), nullable=False)
    simbolo = db.Column(db.String(10), unique=True, nullable=False)
    tipo = db.Column(db.Enum(TipoUnidade), nullable=False)
    is_sistema = db.Column(db.Boolean, default=False, nullable=False)
    criado_em = db.Column(
        db.DateTime,
        default=lambda: datetime.now(timezone.utc),
        nullable=False,
    )

    def __repr__(self) -> str:
        return f"<Unidade simbolo={self.simbolo!r}>"


# ---------------------------------------------------------------------------
# ConversaoUnidade
# ---------------------------------------------------------------------------

class ConversaoUnidade(db.Model):
    """
    Fator de conversão entre duas unidades de medida.

    Se produto_id for NULL, a conversão é global (aplica-se a todos os produtos).
    Se produto_id estiver preenchido, a conversão é específica para aquele produto.

    Exemplo global:  garrafa → litro, fator=0.75
    Exemplo produto: "Arroz Tio João" caixa → grama, fator=5000
    """

    __tablename__ = "sistur_conversoes_unidade"
    __table_args__ = (
        db.UniqueConstraint(
            "produto_id", "de_unidade_id", "para_unidade_id",
            name="uq_conversao_produto_unidades",
        ),
    )

    id = db.Column(db.Integer, primary_key=True)
    produto_id = db.Column(
        db.Integer,
        db.ForeignKey("sistur_produtos.id", ondelete="CASCADE"),
        nullable=True,
        index=True,
    )
    de_unidade_id = db.Column(
        db.Integer,
        db.ForeignKey("sistur_unidades.id", ondelete="RESTRICT"),
        nullable=False,
    )
    para_unidade_id = db.Column(
        db.Integer,
        db.ForeignKey("sistur_unidades.id", ondelete="RESTRICT"),
        nullable=False,
    )
    fator = db.Column(db.Numeric(10, 4), nullable=False)
    criado_em = db.Column(
        db.DateTime,
        default=lambda: datetime.now(timezone.utc),
        nullable=False,
    )

    # Relationships
    produto = db.relationship("Produto", foreign_keys=[produto_id])
    de_unidade = db.relationship("Unidade", foreign_keys=[de_unidade_id])
    para_unidade = db.relationship("Unidade", foreign_keys=[para_unidade_id])

    def __repr__(self) -> str:
        return (
            f"<ConversaoUnidade produto={self.produto_id}"
            f" de={self.de_unidade_id} para={self.para_unidade_id} fator={self.fator}>"
        )


# ---------------------------------------------------------------------------
# LocalArmazenamento
# ---------------------------------------------------------------------------

class LocalArmazenamento(db.Model):
    """
    Local físico de armazenamento, com suporte a hierarquia pai/filho.

    Exemplos de hierarquia:
        Almoxarifado (pai)
        └── Setor A1 (filho, parent_id=Almoxarifado.id)

    Equivale à tabela wp_sistur_storage_locations do legado.
    """

    __tablename__ = "sistur_locais_armazenamento"

    id = db.Column(db.Integer, primary_key=True)
    nome = db.Column(db.String(255), nullable=False)
    codigo = db.Column(db.String(50), nullable=True)
    descricao = db.Column(db.Text, nullable=True)
    tipo = db.Column(db.Enum(TipoLocal), nullable=False, default=TipoLocal.almoxarifado)
    parent_id = db.Column(
        db.Integer,
        db.ForeignKey("sistur_locais_armazenamento.id", ondelete="SET NULL"),
        nullable=True,
        index=True,
    )
    ativo = db.Column(db.Boolean, default=True, nullable=False)
    criado_em = db.Column(
        db.DateTime,
        default=lambda: datetime.now(timezone.utc),
        nullable=False,
    )
    atualizado_em = db.Column(
        db.DateTime,
        default=lambda: datetime.now(timezone.utc),
        onupdate=lambda: datetime.now(timezone.utc),
        nullable=False,
    )

    # Self-referential
    filhos = db.relationship(
        "LocalArmazenamento",
        backref=db.backref("pai", remote_side=[id]),
        lazy="select",
    )
    lotes = db.relationship("LoteEstoque", back_populates="local", lazy="dynamic")

    def __repr__(self) -> str:
        return f"<LocalArmazenamento id={self.id} nome={self.nome!r}>"


# ---------------------------------------------------------------------------
# Produto
# ---------------------------------------------------------------------------

class Produto(db.Model):
    """
    Produto ou ingrediente cadastrado no sistema.

    Tipos:
        RAW         — ingrediente, não vendável diretamente
        RESALE      — comprado para revenda
        MANUFACTURED— produzido internamente via ficha técnica
        RESOURCE    — agrupador conceitual (ex.: "Arroz") sem estoque próprio

    O custo_medio é recalculado a cada entrada de lote:
        custo_medio = SOMA(quantidade × custo_unitario) / SOMA(quantidade)
    para todos os lotes ativos do produto.

    Equivale à tabela wp_sistur_products do legado.
    """

    __tablename__ = "sistur_produtos"

    id = db.Column(db.Integer, primary_key=True)
    nome = db.Column(db.String(255), nullable=False, index=True)
    descricao = db.Column(db.Text, nullable=True)
    sku = db.Column(db.String(100), unique=True, nullable=True)
    codigo_barras = db.Column(db.String(50), nullable=True)

    categoria_id = db.Column(
        db.Integer,
        db.ForeignKey("sistur_categorias_produto.id", ondelete="SET NULL"),
        nullable=True,
        index=True,
    )

    tipo = db.Column(
        db.Enum(TipoProduto),
        nullable=False,
        default=TipoProduto.RESALE,
    )

    # Unidade base do produto (ex.: grama, litro, unidade)
    unidade_base_id = db.Column(
        db.Integer,
        db.ForeignKey("sistur_unidades.id", ondelete="RESTRICT"),
        nullable=True,
    )

    # Hierarquia RESOURCE: produto filho aponta para o agrupador pai
    resource_parent_id = db.Column(
        db.Integer,
        db.ForeignKey("sistur_produtos.id", ondelete="SET NULL"),
        nullable=True,
    )

    # Embalagem: quantidade e unidade do conteúdo da embalagem
    conteudo_quantidade = db.Column(db.Numeric(10, 3), nullable=True)
    conteudo_unidade_id = db.Column(
        db.Integer,
        db.ForeignKey("sistur_unidades.id", ondelete="SET NULL"),
        nullable=True,
    )

    # Financeiro
    custo_medio = db.Column(db.Numeric(10, 4), default=0, nullable=False)
    preco_venda = db.Column(db.Numeric(10, 4), nullable=True)

    # Controle
    estoque_minimo = db.Column(db.Numeric(10, 3), default=0, nullable=False)
    is_perecivel = db.Column(db.Boolean, default=False, nullable=False)
    ativo = db.Column(db.Boolean, default=True, nullable=False)

    criado_em = db.Column(
        db.DateTime,
        default=lambda: datetime.now(timezone.utc),
        nullable=False,
    )
    atualizado_em = db.Column(
        db.DateTime,
        default=lambda: datetime.now(timezone.utc),
        onupdate=lambda: datetime.now(timezone.utc),
        nullable=False,
    )

    # Relationships
    categoria = db.relationship("CategoriaProduto", back_populates="produtos")
    unidade_base = db.relationship("Unidade", foreign_keys=[unidade_base_id])
    conteudo_unidade = db.relationship("Unidade", foreign_keys=[conteudo_unidade_id])
    resource_pai = db.relationship(
        "Produto",
        remote_side=[id],
        foreign_keys=[resource_parent_id],
        backref=db.backref("resource_filhos", lazy="dynamic"),
    )
    lotes = db.relationship("LoteEstoque", back_populates="produto", lazy="dynamic")
    transacoes = db.relationship("TransacaoEstoque", back_populates="produto", lazy="dynamic")
    receitas_como_pai = db.relationship(
        "Receita",
        foreign_keys="Receita.produto_pai_id",
        back_populates="produto_pai",
        lazy="dynamic",
        cascade="all, delete-orphan",
    )
    receitas_como_ingrediente = db.relationship(
        "Receita",
        foreign_keys="Receita.produto_filho_id",
        back_populates="produto_filho",
        lazy="dynamic",
    )

    def __repr__(self) -> str:
        return f"<Produto id={self.id} nome={self.nome!r} tipo={self.tipo}>"


# ---------------------------------------------------------------------------
# LoteEstoque  (fonte de verdade do estoque)
# ---------------------------------------------------------------------------

class LoteEstoque(db.Model):
    """
    Lote de estoque: unidade mínima de rastreabilidade.

    Cada entrada de mercadoria cria um lote distinto.  O estoque disponível de
    um produto é a soma das quantidades de todos os seus lotes com status=ativo.

    Campos de rastreabilidade:
        codigo_lote    — gerado automaticamente (ENT-DDMMAAHHMMSS-id) ou do fornecedor
        custo_unitario — preço de custo específico deste lote (para custo médio ponderado)
        data_validade  — FEFO tracking (First Expired, First Out)
        fornecedor_cnpj, num_nfe — rastreio de nota fiscal

    Equivale à tabela wp_sistur_inventory_batches do legado.
    """

    __tablename__ = "sistur_lotes_estoque"

    id = db.Column(db.Integer, primary_key=True)
    produto_id = db.Column(
        db.Integer,
        db.ForeignKey("sistur_produtos.id", ondelete="RESTRICT"),
        nullable=False,
        index=True,
    )
    local_id = db.Column(
        db.Integer,
        db.ForeignKey("sistur_locais_armazenamento.id", ondelete="RESTRICT"),
        nullable=False,
        index=True,
    )
    unidade_id = db.Column(
        db.Integer,
        db.ForeignKey("sistur_unidades.id", ondelete="RESTRICT"),
        nullable=False,
    )

    codigo_lote = db.Column(db.String(100), nullable=True)
    local_aquisicao = db.Column(db.String(255), nullable=True)  # Ex.: "Mercado Brasil"

    # Datas
    data_entrada = db.Column(
        db.DateTime,
        default=lambda: datetime.now(timezone.utc),
        nullable=False,
    )
    data_fabricacao = db.Column(db.Date, nullable=True)
    data_validade = db.Column(db.Date, nullable=True)

    # Quantidades (em unidade base do produto)
    quantidade_inicial = db.Column(db.Numeric(10, 3), nullable=False)
    quantidade = db.Column(db.Numeric(10, 3), nullable=False)  # saldo atual

    # Financeiro
    custo_unitario = db.Column(db.Numeric(10, 4), nullable=False, default=0)

    # Rastreio NFe (v2.15)
    fornecedor_cnpj = db.Column(db.String(18), nullable=True)
    num_nfe = db.Column(db.String(50), nullable=True)
    chave_nfe = db.Column(db.String(44), nullable=True)

    observacoes = db.Column(db.Text, nullable=True)
    status = db.Column(
        db.Enum(StatusLote),
        nullable=False,
        default=StatusLote.ativo,
    )

    criado_em = db.Column(
        db.DateTime,
        default=lambda: datetime.now(timezone.utc),
        nullable=False,
    )
    atualizado_em = db.Column(
        db.DateTime,
        default=lambda: datetime.now(timezone.utc),
        onupdate=lambda: datetime.now(timezone.utc),
        nullable=False,
    )

    # Relationships
    produto = db.relationship("Produto", back_populates="lotes")
    local = db.relationship("LocalArmazenamento", back_populates="lotes")
    unidade = db.relationship("Unidade")

    def __repr__(self) -> str:
        return (
            f"<LoteEstoque id={self.id} produto={self.produto_id}"
            f" qtd={self.quantidade} status={self.status}>"
        )


# ---------------------------------------------------------------------------
# TransacaoEstoque  (ledger imutável append-only)
# ---------------------------------------------------------------------------

class TransacaoEstoque(db.Model):
    """
    Ledger imutável de toda movimentação de estoque.

    Regra: nenhuma linha deve ser editada ou deletada após criação.
    Usada para rastreio completo, recálculo de CMV e auditoria fiscal.

    Equivale à tabela wp_sistur_inventory_transactions do legado.
    """

    __tablename__ = "sistur_transacoes_estoque"

    id = db.Column(
        db.BigInteger().with_variant(db.Integer, "sqlite"),
        primary_key=True,
    )
    produto_id = db.Column(
        db.Integer,
        db.ForeignKey("sistur_produtos.id", ondelete="RESTRICT"),
        nullable=False,
        index=True,
    )
    lote_id = db.Column(
        db.Integer,
        db.ForeignKey("sistur_lotes_estoque.id", ondelete="RESTRICT"),
        nullable=True,
        index=True,
    )

    # Locais envolvidos (transferências têm ambos; entradas só têm para_local_id)
    de_local_id = db.Column(
        db.Integer,
        db.ForeignKey("sistur_locais_armazenamento.id", ondelete="SET NULL"),
        nullable=True,
    )
    para_local_id = db.Column(
        db.Integer,
        db.ForeignKey("sistur_locais_armazenamento.id", ondelete="SET NULL"),
        nullable=True,
    )

    # Ator
    funcionario_id = db.Column(db.Integer, nullable=True, index=True)

    tipo = db.Column(db.Enum(TipoTransacao), nullable=False)
    quantidade = db.Column(db.Numeric(10, 3), nullable=False)
    unidade_id = db.Column(
        db.Integer,
        db.ForeignKey("sistur_unidades.id", ondelete="RESTRICT"),
        nullable=True,
    )
    custo_unitario = db.Column(db.Numeric(10, 4), nullable=True)
    custo_total = db.Column(db.Numeric(10, 4), nullable=True)

    motivo = db.Column(db.Text, nullable=True)

    # Referência polimórfica (ex.: 'aprovacao', '42')
    referencia_tipo = db.Column(db.String(50), nullable=True)
    referencia_id = db.Column(db.Integer, nullable=True)

    ip_address = db.Column(db.String(45), nullable=True)
    criado_em = db.Column(
        db.DateTime,
        default=lambda: datetime.now(timezone.utc),
        nullable=False,
        index=True,
    )

    # Relationships
    produto = db.relationship("Produto", back_populates="transacoes")
    lote = db.relationship("LoteEstoque")
    de_local = db.relationship("LocalArmazenamento", foreign_keys=[de_local_id])
    para_local = db.relationship("LocalArmazenamento", foreign_keys=[para_local_id])
    unidade = db.relationship("Unidade")

    def __repr__(self) -> str:
        return (
            f"<TransacaoEstoque id={self.id} produto={self.produto_id}"
            f" tipo={self.tipo} qtd={self.quantidade}>"
        )


# ---------------------------------------------------------------------------
# Receita  (Ficha Técnica)
# ---------------------------------------------------------------------------

class Receita(db.Model):
    """
    Item de ficha técnica: relaciona um produto pai (prato/produto final)
    a um ingrediente filho com quantidade e fator de rendimento.

    Fórmulas:
        quantidade_bruta = quantidade_liquida / fator_rendimento
        custo_ingrediente = quantidade_bruta × ingrediente.custo_medio

    Exemplos de fator_rendimento:
        Arroz:   2.5 → 100g seco produz 250g cozido
        Carne:   0.8 → 100g cru produz 80g cozido (perde umidade)
        Verdura: 0.9 → 100g cru produz 90g (perde aparas)

    Equivale à tabela wp_sistur_recipes do legado.
    """

    __tablename__ = "sistur_receitas"
    __table_args__ = (
        db.UniqueConstraint(
            "produto_pai_id", "produto_filho_id",
            name="uq_receita_pai_filho",
        ),
    )

    id = db.Column(db.Integer, primary_key=True)
    produto_pai_id = db.Column(
        db.Integer,
        db.ForeignKey("sistur_produtos.id", ondelete="CASCADE"),
        nullable=False,
        index=True,
    )
    produto_filho_id = db.Column(
        db.Integer,
        db.ForeignKey("sistur_produtos.id", ondelete="RESTRICT"),
        nullable=False,
        index=True,
    )
    unidade_id = db.Column(
        db.Integer,
        db.ForeignKey("sistur_unidades.id", ondelete="RESTRICT"),
        nullable=True,
    )

    # Quantidade desejada no prato final (após cocção)
    quantidade_liquida = db.Column(db.Numeric(10, 3), nullable=False)

    # Quanto é retirado do estoque (antes do processo)
    # quantidade_bruta = quantidade_liquida / fator_rendimento
    fator_rendimento = db.Column(db.Numeric(10, 4), nullable=False, default=1.0)
    quantidade_bruta = db.Column(db.Numeric(10, 3), nullable=False)

    observacoes = db.Column(db.Text, nullable=True)
    criado_em = db.Column(
        db.DateTime,
        default=lambda: datetime.now(timezone.utc),
        nullable=False,
    )
    atualizado_em = db.Column(
        db.DateTime,
        default=lambda: datetime.now(timezone.utc),
        onupdate=lambda: datetime.now(timezone.utc),
        nullable=False,
    )

    # Relationships
    produto_pai = db.relationship(
        "Produto",
        foreign_keys=[produto_pai_id],
        back_populates="receitas_como_pai",
    )
    produto_filho = db.relationship(
        "Produto",
        foreign_keys=[produto_filho_id],
        back_populates="receitas_como_ingrediente",
    )
    unidade = db.relationship("Unidade")

    def __repr__(self) -> str:
        return (
            f"<Receita pai={self.produto_pai_id} filho={self.produto_filho_id}"
            f" liq={self.quantidade_liquida} bruto={self.quantidade_bruta}>"
        )


# ---------------------------------------------------------------------------
# PerdaEstoque
# ---------------------------------------------------------------------------

class PerdaEstoque(db.Model):
    """
    Registro de perda de estoque, separado do ledger de transações para
    análise de impacto no DRE (Demonstrativo de Resultado do Exercício).

    Cada perda deve ter um TransacaoEstoque correspondente de tipo=LOSS.
    O custo_no_momento captura o valor do bem no momento da perda para
    cálculos históricos de CMV mesmo que o custo médio mude depois.

    Equivale à tabela wp_sistur_inventory_losses do legado.
    """

    __tablename__ = "sistur_perdas_estoque"

    id = db.Column(db.Integer, primary_key=True)
    produto_id = db.Column(
        db.Integer,
        db.ForeignKey("sistur_produtos.id", ondelete="RESTRICT"),
        nullable=False,
        index=True,
    )
    lote_id = db.Column(
        db.Integer,
        db.ForeignKey("sistur_lotes_estoque.id", ondelete="SET NULL"),
        nullable=True,
    )
    transacao_id = db.Column(
        db.BigInteger().with_variant(db.Integer, "sqlite"),
        db.ForeignKey("sistur_transacoes_estoque.id", ondelete="SET NULL"),
        nullable=True,
    )
    local_id = db.Column(
        db.Integer,
        db.ForeignKey("sistur_locais_armazenamento.id", ondelete="SET NULL"),
        nullable=True,
    )

    quantidade = db.Column(db.Numeric(10, 3), nullable=False)
    motivo = db.Column(db.Enum(MotivoPerdaEstoque), nullable=False)
    detalhes_motivo = db.Column(db.Text, nullable=True)

    # Financeiro (snapshot no momento da perda)
    custo_no_momento = db.Column(db.Numeric(10, 4), nullable=True)
    valor_total_perda = db.Column(db.Numeric(10, 4), nullable=True)

    funcionario_id = db.Column(db.Integer, nullable=True)
    ip_address = db.Column(db.String(45), nullable=True)
    criado_em = db.Column(
        db.DateTime,
        default=lambda: datetime.now(timezone.utc),
        nullable=False,
    )

    # Relationships
    produto = db.relationship("Produto")
    lote = db.relationship("LoteEstoque")
    transacao = db.relationship("TransacaoEstoque")
    local = db.relationship("LocalArmazenamento")

    def __repr__(self) -> str:
        return (
            f"<PerdaEstoque id={self.id} produto={self.produto_id}"
            f" qtd={self.quantidade} motivo={self.motivo}>"
        )
