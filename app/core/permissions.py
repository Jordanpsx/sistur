# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
Permission helpers for area-specific supervision.

Supervision is area-scoped:
    A supervisor in 'rh' can access RH audit logs.
    They cannot access 'restaurante' logs unless they have a
    separate UserAreaPermission row with is_supervisor=True for that area.
"""

from __future__ import annotations

import functools

from flask import abort, session

from app.extensions import db
from app.core.models import Area, User, UserAreaPermission


# ---------------------------------------------------------------------------
# Plain helper — use this in service-layer code
# ---------------------------------------------------------------------------

def is_supervisor_of(user_id: int, area_slug: str) -> bool:
    """
    Return True if the user holds a supervisor permission for the given area.

    Args:
        user_id:   ID of the user to check.
        area_slug: Slug of the area (e.g. 'rh', 'restaurante').
    """
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
    """Return all areas where the user has is_supervisor=True."""
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


# ---------------------------------------------------------------------------
# Decorator — use this on Flask route functions
# ---------------------------------------------------------------------------

def require_supervisor(area_slug: str):
    """
    Route decorator that enforces area-specific supervisor access.

    Reads the current user from the Flask session (key: 'user_id').
    Returns 403 if the user is not logged in or is not a supervisor
    in the requested area.

    Usage::

        @bp.route("/rh/audit-logs")
        @require_supervisor("rh")
        def rh_audit_logs():
            ...
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
    """
    Route decorator that allows access if the user is a supervisor
    in *at least one* area (used for generic supervisor dashboards).
    """
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
