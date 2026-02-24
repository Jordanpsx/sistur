# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
Flask CLI commands for one-time setup and maintenance tasks.

Usage
-----
    flask setup          # interactive — prompts for nome and CPF
    flask setup --nome "Jordan Machado" --cpf "529.982.247-25"
"""

import click

from app.extensions import db
from app.models.funcionario import Funcionario, validar_cpf


def register_commands(app) -> None:
    @app.cli.command("setup")
    @click.option(
        "--nome",
        prompt="Nome completo",
        help="Nome do primeiro funcionário.",
    )
    @click.option(
        "--cpf",
        prompt="CPF (somente números ou formatado)",
        help="CPF do primeiro funcionário.",
    )
    @click.option(
        "--cargo",
        default="Administrador",
        show_default=True,
        help="Cargo do primeiro funcionário.",
    )
    def setup_command(nome: str, cpf: str, cargo: str) -> None:
        """Cria as tabelas e o primeiro funcionário se o sistema estiver vazio."""
        # 1. Create / verify all tables
        db.create_all()
        click.echo("[ok] Tabelas criadas/verificadas.")

        # 2. Guard — abort if employees already exist
        if db.session.query(Funcionario).count() > 0:
            click.echo(
                "[aviso] O sistema ja possui funcionarios cadastrados. "
                "Nenhuma alteracao foi feita."
            )
            return

        # 3. Validate CPF before inserting
        try:
            cpf_limpo = validar_cpf(cpf)
        except ValueError as exc:
            click.echo(f"[erro] CPF invalido: {exc}", err=True)
            raise SystemExit(1)

        # 4. Create the first employee
        funcionario = Funcionario(
            nome=nome.strip(),
            cpf=cpf_limpo,
            cargo=cargo,
            ativo=True,
        )
        db.session.add(funcionario)
        db.session.commit()

        click.echo(f"[ok] Funcionario criado: {funcionario.nome} — CPF: {funcionario.cpf_formatado()}")
        click.echo(f"     Acesse /portal/login e informe o CPF: {funcionario.cpf_formatado()}")
