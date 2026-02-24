# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
Funcionario — core business entity for the Portal do Colaborador.
Maps to legacy wp_sistur_employees. Business fields use Portuguese names (CLAUDE.md).
CPF is the single login identifier (no password required in this phase).
"""

from __future__ import annotations

import re
from datetime import datetime, timezone

from app.extensions import db


# ---------------------------------------------------------------------------
# CPF helpers
# ---------------------------------------------------------------------------

def validar_cpf(cpf: str) -> str:
    """
    Validate a Brazilian CPF and return the cleaned 11-digit string.
    Ported from legacy/includes/login-funcionario-new.php :: sistur_validate_cpf().

    Raises ValueError if invalid.
    """
    cleaned = re.sub(r"[^0-9]", "", cpf)

    if len(cleaned) != 11:
        raise ValueError("CPF deve conter 11 dígitos.")

    if len(set(cleaned)) == 1:
        raise ValueError("CPF inválido.")

    for t in range(9, 11):
        d = 0
        for c in range(t):
            d += int(cleaned[c]) * ((t + 1) - c)
        d = ((10 * d) % 11) % 10
        if int(cleaned[t]) != d:
            raise ValueError("CPF inválido.")

    return cleaned


def formatar_cpf(cpf: str) -> str:
    """Format an 11-digit CPF string as '000.000.000-00'."""
    c = re.sub(r"[^0-9]", "", cpf)
    return f"{c[:3]}.{c[3:6]}.{c[6:9]}-{c[9:11]}"


# ---------------------------------------------------------------------------
# Model
# ---------------------------------------------------------------------------

class Funcionario(db.Model):
    __tablename__ = "sistur_funcionarios"

    # Identity & auth
    id = db.Column(db.Integer, primary_key=True)
    nome = db.Column(db.String(255), nullable=False)
    cpf = db.Column(db.String(11), unique=True, nullable=False, index=True)
    email = db.Column(db.String(255), nullable=True)
    telefone = db.Column(db.String(20), nullable=True)
    senha_hash = db.Column(db.String(255), nullable=True)  # nullable: CPF-only login this phase
    token_qr = db.Column(db.String(36), unique=True, nullable=True)

    # Employment
    cargo = db.Column(db.String(255), nullable=True)
    matricula = db.Column(db.String(50), nullable=True)
    data_admissao = db.Column(db.Date, nullable=True)
    ctps = db.Column(db.String(50), nullable=True)
    ctps_uf = db.Column(db.String(2), nullable=True)
    cbo = db.Column(db.String(20), nullable=True)
    foto = db.Column(db.String(500), nullable=True)
    bio = db.Column(db.Text, nullable=True)

    # Work schedule (legacy: time_expected_minutes / lunch_minutes)
    minutos_esperados_dia = db.Column(db.SmallInteger, default=480, nullable=False)
    minutos_almoco = db.Column(db.SmallInteger, default=60, nullable=False)

    # Organisation
    area_id = db.Column(
        db.Integer,
        db.ForeignKey("sistur_areas.id", ondelete="SET NULL"),
        nullable=True,
        index=True,
    )

    # State
    ativo = db.Column(db.Boolean, default=True, nullable=False)
    criado_em = db.Column(
        db.DateTime, default=lambda: datetime.now(timezone.utc), nullable=False
    )
    atualizado_em = db.Column(
        db.DateTime,
        default=lambda: datetime.now(timezone.utc),
        onupdate=lambda: datetime.now(timezone.utc),
        nullable=False,
    )

    area = db.relationship("Area", foreign_keys=[area_id], lazy="select")

    # Business logic

    def saldo_banco_horas(self) -> int:
        """
        Current hour-bank balance in minutes.
        Stub — returns 0 until BancoDeHoras module is ported.
        """
        return 0

    def saldo_banco_horas_formatado(self) -> str:
        total = self.saldo_banco_horas()
        sinal = "-" if total < 0 else ""
        total = abs(total)
        return f"{sinal}{total // 60}h {total % 60:02d}min"

    def cpf_formatado(self) -> str:
        return formatar_cpf(self.cpf)

    def __repr__(self) -> str:
        return f"<Funcionario id={self.id} cpf={self.cpf!r} nome={self.nome!r}>"
