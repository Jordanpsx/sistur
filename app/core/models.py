# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

import enum
from datetime import datetime, timezone

from app.extensions import db


class UserType(str, enum.Enum):
    employee = "employee"
    admin = "admin"
    guest = "guest"


class AuditAction(str, enum.Enum):
    create = "create"
    update = "update"
    delete = "delete"
    view = "view"
    export = "export"
    login = "login"
    logout = "logout"
    permission_change = "permission_change"


class Area(db.Model):
    """
    Organizational area / department.
    Slugs match legacy wp_sistur_departments.name for data migration.
    """
    __tablename__ = "sistur_areas"

    id = db.Column(db.Integer, primary_key=True)
    name = db.Column(db.String(255), nullable=False)
    slug = db.Column(db.String(100), unique=True, nullable=False)
    description = db.Column(db.Text, nullable=True)
    is_active = db.Column(db.Boolean, default=True, nullable=False)
    created_at = db.Column(
        db.DateTime, default=lambda: datetime.now(timezone.utc), nullable=False
    )

    user_permissions = db.relationship(
        "UserAreaPermission", back_populates="area", lazy="dynamic"
    )
    users = db.relationship("User", back_populates="department", lazy="dynamic")
    audit_logs = db.relationship("AuditLog", back_populates="area", lazy="dynamic")

    def __repr__(self) -> str:
        return f"<Area slug={self.slug!r}>"


class User(db.Model):
    """Minimal auth entity — superseded by Funcionario for business logic."""
    __tablename__ = "sistur_users"

    id = db.Column(db.Integer, primary_key=True)
    name = db.Column(db.String(255), nullable=False)
    cpf = db.Column(db.String(11), unique=True, nullable=False)
    email = db.Column(db.String(255), nullable=True)
    phone = db.Column(db.String(20), nullable=True)
    password_hash = db.Column(db.String(255), nullable=True)
    department_id = db.Column(db.Integer, db.ForeignKey("sistur_areas.id"), nullable=True)
    is_active = db.Column(db.Boolean, default=True, nullable=False)
    created_at = db.Column(
        db.DateTime, default=lambda: datetime.now(timezone.utc), nullable=False
    )
    updated_at = db.Column(
        db.DateTime,
        default=lambda: datetime.now(timezone.utc),
        onupdate=lambda: datetime.now(timezone.utc),
        nullable=False,
    )

    department = db.relationship("Area", back_populates="users", foreign_keys=[department_id])
    area_permissions = db.relationship(
        "UserAreaPermission", back_populates="user", lazy="dynamic", cascade="all, delete-orphan"
    )
    audit_logs = db.relationship("AuditLog", back_populates="actor", lazy="dynamic")

    def is_supervisor_of(self, area_slug: str) -> bool:
        return (
            db.session.query(UserAreaPermission)
            .join(Area)
            .filter(
                UserAreaPermission.user_id == self.id,
                UserAreaPermission.is_supervisor == True,
                Area.slug == area_slug,
                Area.is_active == True,
            )
            .first()
            is not None
        )

    def __repr__(self) -> str:
        return f"<User id={self.id} cpf={self.cpf!r}>"


class UserAreaPermission(db.Model):
    """
    Links a User to an Area with an optional supervisor flag.
    Supervision is area-scoped — a supervisor in 'rh' cannot see 'restaurante' logs.
    """
    __tablename__ = "sistur_user_area_permissions"
    __table_args__ = (
        db.UniqueConstraint("user_id", "area_id", name="uq_user_area"),
    )

    id = db.Column(db.Integer, primary_key=True)
    user_id = db.Column(
        db.Integer, db.ForeignKey("sistur_users.id", ondelete="CASCADE"), nullable=False
    )
    area_id = db.Column(
        db.Integer, db.ForeignKey("sistur_areas.id", ondelete="CASCADE"), nullable=False
    )
    is_supervisor = db.Column(db.Boolean, default=False, nullable=False)
    granted_at = db.Column(
        db.DateTime, default=lambda: datetime.now(timezone.utc), nullable=False
    )

    user = db.relationship("User", back_populates="area_permissions")
    area = db.relationship("Area", back_populates="user_permissions")

    def __repr__(self) -> str:
        return (
            f"<UserAreaPermission user={self.user_id} area={self.area_id}"
            f" supervisor={self.is_supervisor}>"
        )


class AuditLog(db.Model):
    """
    Immutable record of every mutation in the Employee Portal.
    Antigravity Rule #1: every mutation records previous_state and new_state.
    """
    __tablename__ = "sistur_audit_logs"

    id = db.Column(db.BigInteger, primary_key=True)
    user_id = db.Column(
        db.Integer, db.ForeignKey("sistur_users.id", ondelete="SET NULL"), nullable=True
    )
    user_type = db.Column(db.Enum(UserType), default=UserType.employee, nullable=False)
    action = db.Column(db.Enum(AuditAction), nullable=False)
    module = db.Column(db.String(50), nullable=False, index=True)
    entity_id = db.Column(db.Integer, nullable=True, index=True)
    previous_state = db.Column(db.JSON, nullable=True)
    new_state = db.Column(db.JSON, nullable=True)
    area_id = db.Column(
        db.Integer, db.ForeignKey("sistur_areas.id", ondelete="SET NULL"), nullable=True
    )
    ip_address = db.Column(db.String(45), nullable=True)
    user_agent = db.Column(db.String(255), nullable=True)
    created_at = db.Column(
        db.DateTime,
        default=lambda: datetime.now(timezone.utc),
        nullable=False,
        index=True,
    )

    actor = db.relationship("User", back_populates="audit_logs")
    area = db.relationship("Area", back_populates="audit_logs")

    def __repr__(self) -> str:
        return f"<AuditLog action={self.action} module={self.module!r} entity={self.entity_id}>"
