# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

from flask import Flask, redirect, url_for
from flask_migrate import Migrate

from app.config import config
from app.extensions import db

migrate = Migrate()


def create_app(config_name: str = "default") -> Flask:
    """
    Flask application factory.

    Args:
        config_name: One of 'development', 'production', or 'default'.
    """
    app = Flask(__name__)
    app.config.from_object(config[config_name])

    # Extensions
    db.init_app(app)
    migrate.init_app(app, db)

    # Branding context processor — makes {{ company_name }} and {{ company_logo }}
    # available in every Jinja2 template without manual passing.
    # Os valores do banco de dados têm prioridade sobre as variáveis de ambiente.
    @app.context_processor
    def inject_branding():
        try:
            from app.services.configuracao_service import ConfiguracaoService
            nome = ConfiguracaoService.get("branding.empresa_nome") or app.config["COMPANY_NAME"]
            logo = ConfiguracaoService.get("branding.empresa_logo") or app.config["COMPANY_LOGO"]
        except Exception:
            # Fallback para env vars se o banco ainda não estiver disponível
            # (ex: durante execução de migrações ou testes sem tabelas criadas)
            nome = app.config["COMPANY_NAME"]
            logo = app.config["COMPANY_LOGO"]
        return {"company_name": nome, "company_logo": logo}

    # Root route — serve the login page at /
    @app.route("/")
    def index():
        return redirect(url_for("portal.login"))

    # Import models so SQLAlchemy registers all tables before create_all()
    with app.app_context():
        from app.core import models  # noqa: F401
        from app.models import funcionario  # noqa: F401
        from app.models import role  # noqa: F401
        from app.models import ponto  # noqa: F401
        from app.models import estoque  # noqa: F401
        from app.models import configuracoes  # noqa: F401
        from app.models import geofence  # noqa: F401
        from app.models import calendario  # noqa: F401

    # Blueprints
    from app.blueprints.portal.routes import bp as portal_bp
    app.register_blueprint(portal_bp, url_prefix="/portal")

    from app.blueprints.rh.routes import bp as rh_bp
    app.register_blueprint(rh_bp, url_prefix="/rh")

    from app.blueprints.ponto.routes import bp as ponto_bp
    app.register_blueprint(ponto_bp, url_prefix="/ponto")

    from app.blueprints.estoque.routes import bp as estoque_bp
    app.register_blueprint(estoque_bp, url_prefix="/estoque")

    from app.blueprints.restaurante.routes import bp as restaurante_bp
    app.register_blueprint(restaurante_bp, url_prefix="/restaurante")

    from app.blueprints.admin.routes import bp as admin_bp
    app.register_blueprint(admin_bp, url_prefix="/admin")

    from app.blueprints.configuracoes.routes import bp as configuracoes_bp
    app.register_blueprint(configuracoes_bp, url_prefix="/admin/configuracoes")

    from app.blueprints.api_internal.routes import bp as api_internal_bp
    app.register_blueprint(api_internal_bp, url_prefix="/api/internal")

    # CLI commands
    from app.cli import register_commands
    register_commands(app)

    return app
