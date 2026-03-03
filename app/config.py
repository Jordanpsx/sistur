# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

import os


class Config:
    SECRET_KEY = os.environ.get("SECRET_KEY", "change-me-in-production")
    SQLALCHEMY_TRACK_MODIFICATIONS = False
    SESSION_COOKIE_HTTPONLY = True
    SESSION_COOKIE_SAMESITE = "Lax"
    PERMANENT_SESSION_LIFETIME = 60 * 60 * 8  # 8 hours — matches legacy behavior

    # White-label branding — override via environment variables
    COMPANY_NAME = os.environ.get("COMPANY_NAME", "SISTUR")
    COMPANY_LOGO = os.environ.get("COMPANY_LOGO", "")  # URL; empty = SVG fallback

    # QR code encryption key — falls back to SECRET_KEY if not set.
    # Used by QRService to derive a Fernet key (SHA-256 → base64url).
    QR_SECRET_KEY = os.environ.get("QR_SECRET_KEY") or os.environ.get("SECRET_KEY", "change-me-in-production")

    # Token de autenticação para o serviço QR scanner (kiosk → API interna).
    QR_SERVICE_TOKEN = os.environ.get("QR_SERVICE_TOKEN", "")


class DevelopmentConfig(Config):
    DEBUG = True
    SQLALCHEMY_DATABASE_URI = os.environ.get(
        "DATABASE_URL",
        "mysql+mysqlconnector://root:root@localhost/sistur_dev",
    )


class ProductionConfig(Config):
    DEBUG = False
    SQLALCHEMY_DATABASE_URI = os.environ.get("DATABASE_URL")


class TestingConfig(Config):
    TESTING = True
    DEBUG = True
    # In-memory SQLite — fast, isolated, no external dependencies
    SQLALCHEMY_DATABASE_URI = "sqlite:///:memory:"
    # Disable CSRF-equivalent protections in tests
    WTF_CSRF_ENABLED = False
    # Token fixo para testes do endpoint QR scanner
    QR_SERVICE_TOKEN = "test-qr-service-token"


config = {
    "development": DevelopmentConfig,
    "production": ProductionConfig,
    "testing": TestingConfig,
    "default": DevelopmentConfig,
}
