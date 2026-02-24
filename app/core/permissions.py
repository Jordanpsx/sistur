# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
Permission helpers for area-specific supervision.
Supervision is area-scoped: a supervisor in 'rh' cannot access 'restaurante' logs.
"""

from __future__ import annotations

import functools

from flask import abort, session

from app.extensions import db
from app.core.models import Area, User, UserAreaPermission


def is_supervisor_of(user_id: int, area_slug: str) -> bool:
    return (
        db.session.query(UserAreaPermission)
        .join(Area, UserAreaPermission.area_id == Area.id)
        .filter(
            UserAreaPermission.user_id == user_id,
            UserAreaPermission.is_supervisor == True,
            Area.slug == area_slug,
            Area.is_active == True,
        )
        .first()
        is not None
    )


def get_supervised_areas(user_id: int) -> list[Area]:
    return (
        db.session.query(Area)
        .join(UserAreaPermission, UserAreaPermission.area_id == Area.id)
        .filter(
            UserAreaPermission.user_id == user_id,
            UserAreaPermission.is_supervisor == True,
            Area.is_active == True,
        )
        .all()
    )


def require_supervisor(area_slug: str):
    """
    Route decorator that enforces area-specific supervisor access.
    Reads current user from Flask session key 'user_id'. Returns 403 if not authorized.
    """
    def decorator(fn):
        @functools.wraps(fn)
        def wrapper(*args, **kwargs):
            user_id: int | None = session.get("user_id")
            if not user_id:
                abort(401)
            if not is_supervisor_of(user_id, area_slug):
                abort(403)
            return fn(*args, **kwargs)
        return wrapper
    return decorator


def require_any_supervisor(fn):
    @functools.wraps(fn)
    def wrapper(*args, **kwargs):
        user_id: int | None = session.get("user_id")
        if not user_id:
            abort(401)
        has_any = (
            db.session.query(UserAreaPermission)
            .filter(
                UserAreaPermission.user_id == user_id,
                UserAreaPermission.is_supervisor == True,
            )
            .first()
            is not None
        )
        if not has_any:
            abort(403)
        return fn(*args, **kwargs)
    return wrapper
