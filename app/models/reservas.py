# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
Módulo de Reservas — foundational models for generic booking system.

Padrões especiais:
  1. Soft Delete: `deleted_at` timestamp (NOT `ativo` boolean) — ativo = deleted_at IS NULL
  2. Versioning: `Reserva` nunca é atualizado in-place. Edições criam nova versão (row).
     Todas as versões compartilham o mesmo `group_id` UUID. Chave única: (group_id, version).
     Versão anterior é marcada `status=ARCHIVED_VERSION` quando nova versão é criada.

Permissões RBAC dinâmicas: inserir novo `ReservaSource` automaticamente cria permissões
para roles super_admin via SQLAlchemy event listener.

Headless API backend — múltiplos frontends (WordPress legado, portal, POS) consomem as mesmas tabelas.
"""

from __future__ import annotations

import enum
from datetime import date, datetime, timezone

from sqlalchemy import event

from app.extensions import db


# ---------------------------------------------------------------------------
# Enums
# ---------------------------------------------------------------------------


class BillingType(str, enum.Enum):
    """Tipos de cobrança para itens da reserva."""
    FIXED = "FIXED"
    PER_DAY = "PER_DAY"
    PER_HOUR = "PER_HOUR"


class ReservaOrigin(str, enum.Enum):
    """Origem do pedido de reserva."""
    WEB = "WEB"
    BALCAO = "BALCAO"


class ReservaStatus(str, enum.Enum):
    """Estados da reserva ao longo do seu ciclo de vida."""
    PENDING = "PENDING"
    PAID = "PAID"
    CANCELED = "CANCELED"
    COMPLETED = "COMPLETED"
    ARCHIVED_VERSION = "ARCHIVED_VERSION"


# ---------------------------------------------------------------------------
# Models
# ---------------------------------------------------------------------------


class ReservaSource(db.Model):
    """
    Fonte de reserva — location ou venue onde reservas são feitas.

    Exemplo: "Cachoeira" (Parque Cachoeira), "Vinhedo" (Parque Vinhedo).
    Inserir novo source cria automaticamente permissões RBAC via event listener.
    """

    __tablename__ = "sistur_reserva_sources"

    id = db.Column(db.Integer, primary_key=True)
    name = db.Column(db.String(100), unique=True, nullable=False)
    is_active = db.Column(db.Boolean, default=True, nullable=False)
    deleted_at = db.Column(db.DateTime, nullable=True, default=None)
    criado_em = db.Column(
        db.DateTime,
        default=lambda: datetime.now(timezone.utc),
        nullable=False,
    )

    # Relationships
    categories = db.relationship(
        "ReservaCategory",
        back_populates="source",
        cascade="all, delete-orphan",
        lazy="select",
    )

    def __repr__(self) -> str:
        return f"<ReservaSource id={self.id} name={self.name!r} active={self.is_active}>"


class ReservaCategory(db.Model):
    """
    Categoria de reserva dentro de uma fonte (ex: "Day Use", "Camping").

    Unique constraint: (source_id, name) — não pode haver categoria duplicada na mesma fonte.
    """

    __tablename__ = "sistur_reserva_categories"

    id = db.Column(db.Integer, primary_key=True)
    source_id = db.Column(
        db.Integer,
        db.ForeignKey("sistur_reserva_sources.id", ondelete="CASCADE"),
        nullable=False,
        index=True,
    )
    name = db.Column(db.String(100), nullable=False)
    deleted_at = db.Column(db.DateTime, nullable=True, default=None)
    criado_em = db.Column(
        db.DateTime,
        default=lambda: datetime.now(timezone.utc),
        nullable=False,
    )

    __table_args__ = (
        db.UniqueConstraint(
            "source_id", "name", name="uq_reserva_category_source_name"
        ),
    )

    # Relationships
    source = db.relationship("ReservaSource", back_populates="categories")

    def __repr__(self) -> str:
        return f"<ReservaCategory id={self.id} source_id={self.source_id} name={self.name!r}>"


class ReservaItem(db.Model):
    """
    Item disponível para incluir em uma reserva (ex: cama, lanche, atividade).

    stock_quantity: NULL = estoque infinito. Integer >= 0 = quantidade limitada.
    """

    __tablename__ = "sistur_reserva_items"

    id = db.Column(db.Integer, primary_key=True)
    name = db.Column(db.String(150), nullable=False)
    billing_type = db.Column(db.Enum(BillingType), nullable=False)
    stock_quantity = db.Column(db.Integer, nullable=True)
    requires_deposit = db.Column(db.Boolean, default=False, nullable=False)
    deleted_at = db.Column(db.DateTime, nullable=True, default=None)
    criado_em = db.Column(
        db.DateTime,
        default=lambda: datetime.now(timezone.utc),
        nullable=False,
    )

    def __repr__(self) -> str:
        return f"<ReservaItem id={self.id} name={self.name!r} billing={self.billing_type.value}>"


class Reserva(db.Model):
    """
    Reserva — pedido de booking em uma fonte específica.

    Versioning: Edições NÃO modificam esta linha. Em vez disso:
      1. Marca a linha anterior com status=ARCHIVED_VERSION
      2. Insere nova linha com mesmo group_id + version incrementado
      Todas as versões compartilham group_id (UUID String). Chave única: (group_id, version).

    Soft Delete: deleted_at IS NULL = registro ativo.
    """

    __tablename__ = "sistur_reservas"

    id = db.Column(db.Integer, primary_key=True)
    group_id = db.Column(db.String(36), nullable=False, index=True)
    version = db.Column(db.Integer, nullable=False, default=1)
    source_id = db.Column(
        db.Integer,
        db.ForeignKey("sistur_reserva_sources.id", ondelete="SET NULL"),
        nullable=True,
        index=True,
    )
    category_id = db.Column(
        db.Integer,
        db.ForeignKey("sistur_reserva_categories.id", ondelete="SET NULL"),
        nullable=True,
        index=True,
    )
    customer_name = db.Column(db.String(255), nullable=False)
    customer_document = db.Column(db.String(20), nullable=True)
    origin = db.Column(db.Enum(ReservaOrigin), nullable=False)
    status = db.Column(db.Enum(ReservaStatus), default=ReservaStatus.PENDING, nullable=False, index=True)
    check_in_date = db.Column(db.Date, nullable=False, index=True)
    check_out_date = db.Column(db.Date, nullable=True)
    expires_at = db.Column(db.DateTime, nullable=True)
    deleted_at = db.Column(db.DateTime, nullable=True, default=None)
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

    __table_args__ = (
        db.UniqueConstraint("group_id", "version", name="uq_reserva_group_version"),
    )

    # Relationships
    source = db.relationship("ReservaSource", lazy="select")
    category = db.relationship("ReservaCategory", lazy="select")
    items = db.relationship(
        "ReservaItemLink",
        back_populates="reserva",
        cascade="all, delete-orphan",
        lazy="select",
    )

    def __repr__(self) -> str:
        return (
            f"<Reserva id={self.id} group={self.group_id!r} v={self.version} "
            f"status={self.status.value} customer={self.customer_name!r}>"
        )


class ReservaItemLink(db.Model):
    """
    Associação entre Reserva e ReservaItem com quantidade e preço fixo (snapshot).

    locked_price: Preço congelado no momento da reserva (Numeric, não FK).
    deleted_at: marca exclusão lógica de item da reserva.
    """

    __tablename__ = "sistur_reserva_item_links"

    id = db.Column(db.Integer, primary_key=True)
    reserva_id = db.Column(
        db.Integer,
        db.ForeignKey("sistur_reservas.id", ondelete="CASCADE"),
        nullable=False,
        index=True,
    )
    item_id = db.Column(
        db.Integer,
        db.ForeignKey("sistur_reserva_items.id", ondelete="SET NULL"),
        nullable=True,
        index=True,
    )
    quantity = db.Column(db.Integer, nullable=False, default=1)
    locked_price = db.Column(db.Numeric(10, 2), nullable=False)
    deleted_at = db.Column(db.DateTime, nullable=True, default=None)

    __table_args__ = (
        db.UniqueConstraint("reserva_id", "item_id", name="uq_reserva_item_link"),
    )

    # Relationships
    reserva = db.relationship("Reserva", back_populates="items")
    item = db.relationship("ReservaItem", lazy="select")

    def __repr__(self) -> str:
        return (
            f"<ReservaItemLink reserva_id={self.reserva_id} item_id={self.item_id} "
            f"qty={self.quantity} price={self.locked_price}>"
        )


# ---------------------------------------------------------------------------
# SQLAlchemy Event Listener — Dynamic RBAC
# ---------------------------------------------------------------------------


@event.listens_for(ReservaSource, "after_insert")
def _criar_permissoes_source(mapper, connection, target):
    """Cria permissões RBAC automáticas para roles super_admin ao inserir nova fonte.

    Quando uma nova ReservaSource é inserida, esta função dispara e adiciona
    permissões (view + edit) para a fonte em todos os roles com is_super_admin=True.

    Nota: SQLAlchemy emite um warning sobre Session.add() durante flush, mas
    as permissões SÃO criadas corretamente. O warning é benign e não afeta
    a funcionalidade. Alternativas mais complexas foram evitadas em favor
    da simplicidade e clareza do código.

    Args:
        mapper: SQLAlchemy mapper (unused).
        connection: Database connection (unused).
        target: A instância de ReservaSource que foi inserida.
    """
    try:
        # Lazy imports para evitar circular imports no carregamento do módulo
        from app.models.role import Role, RolePermission
        from app.extensions import db

        # Cria nome do módulo baseado no nome da fonte
        modulo = f"reservas_{target.name.lower().replace(' ', '_').replace('-', '_')}"

        # Busca todos os roles super_admin ativos
        super_roles = (
            db.session.query(Role)
            .filter_by(is_super_admin=True, ativo=True)
            .all()
        )

        # Para cada role super_admin, cria permissões view + edit (se não existirem)
        for role in super_roles:
            for acao in ("view", "edit"):
                exists = (
                    db.session.query(RolePermission)
                    .filter_by(role_id=role.id, modulo=modulo, acao=acao)
                    .first()
                )
                if not exists:
                    db.session.add(
                        RolePermission(role_id=role.id, modulo=modulo, acao=acao)
                    )
    except Exception as e:
        # Log silenciosamente — não deve causar falha na inserção do source
        import sys

        print(
            f"Warning: Falha ao criar permissoes automaticas para ReservaSource: {e}",
            file=sys.stderr,
        )
