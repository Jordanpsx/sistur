# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
ReservaService — Business logic for the Reservas (Bookings) module.

This service handles all mutations and queries on the Reserva data model,
including create, list, detail, availability checks, and source/category
management.

No Flask imports — pure Python, Cython-ready for future compilation.
"""

from __future__ import annotations

import uuid
from datetime import date, datetime, timedelta, timezone
from decimal import Decimal

from sqlalchemy import and_, func, or_
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import contains_eager

from app.core.audit import AuditService
from app.extensions import db
from app.models.reservas import (
    Reserva,
    ReservaCategory,
    ReservaItem,
    ReservaItemLink,
    ReservaOrigin,
    ReservaSource,
    ReservaStatus,
)
from app.services.base import BaseService


class ReservaService(BaseService):
    """Serviço de gerenciamento de reservas (bookings)."""

    _CAMPOS_SNAPSHOT = [
        "id",
        "group_id",
        "version",
        "source_id",
        "category_id",
        "customer_name",
        "customer_document",
        "origin",
        "status",
        "check_in_date",
        "check_out_date",
        "expires_at",
    ]

    @classmethod
    def criar(
        cls,
        source_name: str,
        category_name: str,
        customer_name: str,
        customer_document: str | None,
        origin: str | ReservaOrigin,
        check_in_date: date,
        check_out_date: date | None,
        items_payload: list[dict],
        *,
        ator_id: int | None = None,
    ) -> Reserva:
        """Cria uma nova reserva com itens associados.

        Implementa a lógica de booking com:
        - Lookup ou criação dinâmica de fonte e categoria
        - Validação de disponibilidade de estoque
        - Soft lock por 15 minutos (expires_at)
        - Versioning (group_id + version=1)
        - Logging de auditoria

        Args:
            source_name: Nome da fonte/venue (cria se não existir)
            category_name: Nome da categoria dentro da fonte
            customer_name: Nome completo do cliente
            customer_document: CPF ou CNPJ (opcional)
            origin: ReservaOrigin.WEB ou ReservaOrigin.BALCAO
            check_in_date: Data de check-in
            check_out_date: Data de check-out (opcional)
            items_payload: Lista de dicts com "item_id", "quantity", "price_override"
            ator_id: ID do usuário fazendo a ação (para auditoria)

        Returns:
            Instância de Reserva persistida no banco de dados.

        Raises:
            ValueError: Se validação falhar (campos obrigatórios, datas, itens)
        """
        # 1. Validações básicas
        cls._require(source_name, "source_name")
        cls._require(category_name, "category_name")
        cls._require(customer_name, "customer_name")

        # 2. Validar origin
        if isinstance(origin, str):
            try:
                origin_enum = ReservaOrigin[origin.upper()]
            except KeyError:
                raise ValueError(f"origin deve ser 'WEB' ou 'BALCAO', recebido: {origin}")
        else:
            origin_enum = origin

        # 3. Validar datas
        if check_out_date and check_out_date < check_in_date:
            raise ValueError(
                "check_out_date deve ser igual ou posterior a check_in_date."
            )

        # 4. Validar itens_payload não está vazio
        if not items_payload:
            raise ValueError("A reserva deve conter ao menos um item.")

        # 5. Source lookup-or-create
        source = db.session.query(ReservaSource).filter(
            ReservaSource.name == source_name,
            ReservaSource.deleted_at.is_(None),
        ).first()

        if not source:
            try:
                source = ReservaSource(name=source_name)
                db.session.add(source)
                db.session.flush()  # triggers RBAC event listener
            except IntegrityError:
                db.session.rollback()
                source = db.session.query(ReservaSource).filter_by(
                    name=source_name
                ).first()
                if not source:
                    raise ValueError(f"Falha ao criar/recuperar fonte '{source_name}'.")

        # 6. Category lookup-or-create within source
        category = db.session.query(ReservaCategory).filter(
            ReservaCategory.source_id == source.id,
            ReservaCategory.name == category_name,
            ReservaCategory.deleted_at.is_(None),
        ).first()

        if not category:
            try:
                category = ReservaCategory(source_id=source.id, name=category_name)
                db.session.add(category)
                db.session.flush()
            except IntegrityError:
                db.session.rollback()
                category = db.session.query(ReservaCategory).filter(
                    ReservaCategory.source_id == source.id,
                    ReservaCategory.name == category_name,
                ).first()
                if not category:
                    raise ValueError(
                        f"Falha ao criar/recuperar categoria '{category_name}'."
                    )

        # 7. Process items and validate stock
        validated_items = []
        seen_item_ids = set()

        for entry in items_payload:
            item_id = entry.get("item_id")
            quantity = entry.get("quantity", 1)
            price_override = entry.get("price_override")

            # Check for duplicates
            if item_id in seen_item_ids:
                raise ValueError(
                    "Itens duplicados no payload: use um único entry por item_id."
                )
            seen_item_ids.add(item_id)

            # Fetch item
            item = db.session.query(ReservaItem).filter(
                ReservaItem.id == item_id,
                ReservaItem.deleted_at.is_(None),
            ).first()

            if not item:
                raise ValueError(f"Item com ID {item_id} não encontrado.")

            # price_override is required
            if not price_override:
                raise ValueError(
                    f"Item {item_id}: campo 'price_override' é obrigatório."
                )

            # Validate stock if not unlimited
            if item.stock_quantity is not None:
                cls._verificar_estoque(
                    item, check_in_date, check_out_date, quantity
                )

            validated_items.append({
                "item": item,
                "quantity": quantity,
                "price_override": Decimal(str(price_override)),
            })

        # 8. Create Reserva
        group_id = str(uuid.uuid4())
        expires_at = datetime.now(timezone.utc) + timedelta(minutes=15)

        reserva = Reserva(
            group_id=group_id,
            version=1,
            source_id=source.id,
            category_id=category.id,
            customer_name=customer_name,
            customer_document=customer_document,
            origin=origin_enum,
            status=ReservaStatus.PENDING,
            check_in_date=check_in_date,
            check_out_date=check_out_date,
            expires_at=expires_at,
        )
        db.session.add(reserva)
        db.session.flush()  # get PK

        # 9. Create ReservaItemLink rows
        for entry in validated_items:
            link = ReservaItemLink(
                reserva_id=reserva.id,
                item_id=entry["item"].id,
                quantity=entry["quantity"],
                locked_price=entry["price_override"],
            )
            db.session.add(link)

        db.session.flush()

        # 10. Log to audit trail
        AuditService.log_create(
            "reservas",
            entity_id=reserva.id,
            new_state=cls._snapshot(reserva, cls._CAMPOS_SNAPSHOT),
            actor_id=ator_id,
        )

        db.session.commit()
        return reserva

    @classmethod
    def listar(
        cls,
        *,
        source_id: int | None = None,
        status: str | None = None,
        q: str | None = None,
        page: int = 1,
        per_page: int = 20,
    ) -> tuple[list[Reserva], int]:
        """Lista reservas ativas com filtros e paginação.

        Args:
            source_id: Filtro por ID da fonte (opcional)
            status: Filtro por status (PENDING, PAID, CANCELED, COMPLETED)
            q: Busca livre por nome ou CPF do cliente
            page: Número da página (começa em 1)
            per_page: Quantidade de itens por página

        Returns:
            Tupla (lista de Reserva, total de registros)

        Raises:
            ValueError: Se status inválido
        """
        # Base filter: deleted_at IS NULL AND status != ARCHIVED_VERSION
        query = db.session.query(Reserva).filter(
            Reserva.deleted_at.is_(None),
            Reserva.status != ReservaStatus.ARCHIVED_VERSION,
        )

        # Apply optional filters
        if source_id:
            query = query.filter(Reserva.source_id == source_id)

        if status:
            # Validate status is a valid enum value
            try:
                status_enum = ReservaStatus[status.upper()]
                query = query.filter(Reserva.status == status_enum)
            except KeyError:
                raise ValueError(f"Status inválido: {status}")

        if q:
            q_filter = f"%{q}%"
            query = query.filter(
                or_(
                    Reserva.customer_name.ilike(q_filter),
                    Reserva.customer_document.ilike(q_filter),
                )
            )

        # Count total
        total = query.count()

        # Apply ordering and pagination
        reservas = (
            query.order_by(Reserva.criado_em.desc())
            .limit(per_page)
            .offset((page - 1) * per_page)
            .all()
        )

        return reservas, total

    @classmethod
    def detalhe(cls, reserva_id: int) -> Reserva:
        """Retorna os detalhes completos de uma reserva específica.

        Args:
            reserva_id: ID da reserva a recuperar

        Returns:
            Instância de Reserva com itens carregados

        Raises:
            ValueError: Se reserva não encontrada ou foi deletada
        """
        reserva = db.session.get(Reserva, reserva_id)

        if not reserva or reserva.deleted_at is not None:
            raise ValueError("Reserva não encontrada.")

        return reserva

    @classmethod
    def listar_fontes(cls) -> list[ReservaSource]:
        """Lista todas as fontes (venues) ativas com suas categorias.

        Retorna apenas fontes com `deleted_at IS NULL` e `is_active=True`,
        com categorias ativas aninhadas.

        Returns:
            Lista de ReservaSource com categorias não deletadas carregadas
        """
        sources = (
            db.session.query(ReservaSource)
            .outerjoin(ReservaSource.categories)
            .options(contains_eager(ReservaSource.categories))
            .filter(
                ReservaSource.deleted_at.is_(None),
                ReservaSource.is_active == True,
                or_(
                    ReservaCategory.deleted_at.is_(None),
                    ReservaCategory.id.is_(None),
                ),
            )
            .order_by(ReservaSource.name)
            .all()
        )

        return sources

    @classmethod
    def verificar_disponibilidade(
        cls,
        category_id: int,
        check_in: date,
        check_out: date,
    ) -> list[dict]:
        """Calcula a disponibilidade de itens em uma categoria para um período.

        Implementa o motor de disponibilidade: para cada item global,
        calcula estoque consumido por reservas ativas que sobrepõem
        o período solicitado.

        Regras:
        - Respeita `stock_quantity = NULL` como estoque infinito
        - Ignora reservas CANCELED e ARCHIVED_VERSION
        - PENDING incluso apenas se expires_at > agora
        - PAID sempre incluído

        Args:
            category_id: ID da categoria a verificar
            check_in: Data de início (YYYY-MM-DD)
            check_out: Data de término (YYYY-MM-DD)

        Returns:
            Lista de dicts com {"item_id", "name", "billing_type", "requires_deposit",
                                "stock_quantity", "consumed", "available"}
        """
        # Validate dates
        if check_out < check_in:
            raise ValueError("check_out deve ser >= check_in.")

        # Find overlapping reservas
        effective_checkout = func.coalesce(Reserva.check_out_date, Reserva.check_in_date)
        req_out_effective = check_out

        overlapping_ids = (
            db.session.query(Reserva.id)
            .filter(
                Reserva.category_id == category_id,
                Reserva.deleted_at.is_(None),
                Reserva.status != ReservaStatus.ARCHIVED_VERSION,
                Reserva.status != ReservaStatus.CANCELED,
                or_(
                    and_(
                        Reserva.status == ReservaStatus.PENDING,
                        Reserva.expires_at > datetime.now(timezone.utc),
                    ),
                    Reserva.status == ReservaStatus.PAID,
                ),
                Reserva.check_in_date <= req_out_effective,
                effective_checkout >= check_in,
            )
            .all()
        )

        overlapping_ids_list = [row.id for row in overlapping_ids]

        # Aggregate consumed quantities per item
        consumed_map = {}
        if overlapping_ids_list:
            rows = (
                db.session.query(
                    ReservaItemLink.item_id,
                    func.sum(ReservaItemLink.quantity).label("total_consumed"),
                )
                .filter(
                    ReservaItemLink.reserva_id.in_(overlapping_ids_list),
                    ReservaItemLink.deleted_at.is_(None),
                )
                .group_by(ReservaItemLink.item_id)
                .all()
            )
            consumed_map = {row.item_id: row.total_consumed for row in rows}

        # Fetch all active items
        items = db.session.query(ReservaItem).filter(
            ReservaItem.deleted_at.is_(None)
        ).all()

        # Build result
        result = []
        for item in items:
            consumed = consumed_map.get(item.id, 0)
            available = (
                None
                if item.stock_quantity is None
                else max(0, item.stock_quantity - consumed)
            )

            result.append({
                "item_id": item.id,
                "name": item.name,
                "billing_type": item.billing_type.value,
                "requires_deposit": item.requires_deposit,
                "stock_quantity": item.stock_quantity,
                "consumed": consumed,
                "available": available,
            })

        return result

    @classmethod
    def _verificar_estoque(
        cls,
        item: ReservaItem,
        check_in: date,
        check_out: date | None,
        requested_qty: int,
    ) -> None:
        """Verifica se há estoque suficiente de um item para um período.

        Utiliza a mesma lógica de sobreposição de datas que
        `verificar_disponibilidade`.

        Args:
            item: ReservaItem a verificar
            check_in: Data de início
            check_out: Data de término (ou None para 1 dia)
            requested_qty: Quantidade solicitada

        Raises:
            ValueError: Se estoque insuficiente
        """
        if item.stock_quantity is None:
            # Infinite stock
            return

        # Find overlapping reservas for this specific item
        effective_checkout = func.coalesce(Reserva.check_out_date, Reserva.check_in_date)
        req_out_effective = check_out or check_in

        overlapping_ids = (
            db.session.query(Reserva.id)
            .filter(
                Reserva.deleted_at.is_(None),
                Reserva.status != ReservaStatus.ARCHIVED_VERSION,
                Reserva.status != ReservaStatus.CANCELED,
                or_(
                    and_(
                        Reserva.status == ReservaStatus.PENDING,
                        Reserva.expires_at > datetime.now(timezone.utc),
                    ),
                    Reserva.status == ReservaStatus.PAID,
                ),
                Reserva.check_in_date <= req_out_effective,
                effective_checkout >= check_in,
            )
            .all()
        )

        overlapping_ids_list = [row.id for row in overlapping_ids]

        # Aggregate consumed for this item
        consumed = 0
        if overlapping_ids_list:
            row = (
                db.session.query(
                    func.sum(ReservaItemLink.quantity).label("total"),
                )
                .filter(
                    ReservaItemLink.reserva_id.in_(overlapping_ids_list),
                    ReservaItemLink.item_id == item.id,
                    ReservaItemLink.deleted_at.is_(None),
                )
                .first()
            )
            consumed = row.total or 0

        # Check availability
        if consumed + requested_qty > item.stock_quantity:
            raise ValueError(
                f"Estoque insuficiente para o item '{item.name}'. "
                f"Disponível: {item.stock_quantity - consumed}, solicitado: {requested_qty}."
            )
