# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
AuditService — mandatory logging for every mutation in the Employee Portal.
Antigravity Rule #1: every mutation records previous_state and new_state.
"""

from __future__ import annotations

from typing import TYPE_CHECKING, Any

from flask import request as flask_request

from app.extensions import db
from app.core.models import Area, AuditAction, AuditLog, UserType

if TYPE_CHECKING:
    from app.core.models import User


class AuditService:

    @staticmethod
    def log(
        action: AuditAction | str,
        module: str,
        *,
        entity_id: int | None = None,
        previous_state: dict[str, Any] | None = None,
        new_state: dict[str, Any] | None = None,
        actor: "User | None" = None,
        area_slug: str | None = None,
    ) -> AuditLog:
        if isinstance(action, str):
            action = AuditAction(action)

        area_id: int | None = None
        if area_slug:
            area = db.session.query(Area).filter_by(slug=area_slug).first()
            if area:
                area_id = area.id

        user_type = UserType.guest
        user_id = None
        if actor is not None:
            user_id = actor.id
            user_type = UserType.employee

        log = AuditLog(
            user_id=user_id,
            user_type=user_type,
            action=action,
            module=module,
            entity_id=entity_id,
            previous_state=previous_state,
            new_state=new_state,
            area_id=area_id,
            ip_address=AuditService._get_ip(),
            user_agent=AuditService._get_user_agent(),
        )
        db.session.add(log)
        db.session.commit()
        return log

    @staticmethod
    def log_create(module, entity_id, new_state, *, actor=None, area_slug=None):
        return AuditService.log(
            AuditAction.create, module,
            entity_id=entity_id, new_state=new_state,
            actor=actor, area_slug=area_slug,
        )

    @staticmethod
    def log_update(module, entity_id, previous_state, new_state, *, actor=None, area_slug=None):
        return AuditService.log(
            AuditAction.update, module,
            entity_id=entity_id, previous_state=previous_state, new_state=new_state,
            actor=actor, area_slug=area_slug,
        )

    @staticmethod
    def log_delete(module, entity_id, previous_state, *, actor=None, area_slug=None):
        return AuditService.log(
            AuditAction.delete, module,
            entity_id=entity_id, previous_state=previous_state,
            actor=actor, area_slug=area_slug,
        )

    @staticmethod
    def log_login(user_id: int | None, *, success: bool = True) -> AuditLog:
        log = AuditLog(
            user_id=user_id,
            user_type=UserType.employee if user_id else UserType.guest,
            action=AuditAction.login,
            module="auth",
            new_state={"success": success},
            ip_address=AuditService._get_ip(),
            user_agent=AuditService._get_user_agent(),
        )
        db.session.add(log)
        db.session.commit()
        return log

    @staticmethod
    def log_logout(user_id: int) -> AuditLog:
        log = AuditLog(
            user_id=user_id,
            user_type=UserType.employee,
            action=AuditAction.logout,
            module="auth",
            ip_address=AuditService._get_ip(),
            user_agent=AuditService._get_user_agent(),
        )
        db.session.add(log)
        db.session.commit()
        return log

    @staticmethod
    def get_logs(
        *,
        module=None, area_slug=None, user_id=None,
        start_date=None, end_date=None,
        limit=50, offset=0,
    ) -> list[AuditLog]:
        query = db.session.query(AuditLog)
        if module:
            query = query.filter(AuditLog.module == module)
        if user_id:
            query = query.filter(AuditLog.user_id == user_id)
        if area_slug:
            query = query.join(Area).filter(Area.slug == area_slug)
        if start_date:
            query = query.filter(AuditLog.created_at >= start_date)
        if end_date:
            query = query.filter(AuditLog.created_at <= end_date)
        return query.order_by(AuditLog.created_at.desc()).limit(limit).offset(offset).all()

    @staticmethod
    def _get_ip() -> str | None:
        try:
            return (
                flask_request.headers.get("X-Forwarded-For", "").split(",")[0].strip()
                or flask_request.remote_addr
            )
        except RuntimeError:
            return None

    @staticmethod
    def _get_user_agent() -> str | None:
        try:
            return flask_request.headers.get("User-Agent", "")[:255]
        except RuntimeError:
            return None
