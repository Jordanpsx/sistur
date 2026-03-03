# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
Blueprint de API interna — endpoints machine-to-machine.

Autenticação via Bearer token (QR_SERVICE_TOKEN), não via sessão/cookie.
Destinado a serviços internos da rede Docker (ex: qr-reader).
"""

from __future__ import annotations

import hmac

from flask import Blueprint, abort, current_app, jsonify, request

bp = Blueprint("api_internal", __name__)


def _verificar_token_servico() -> None:
    """Valida o token Bearer do cabeçalho Authorization contra QR_SERVICE_TOKEN.

    Raises:
        abort(401): Se o token estiver ausente, mal formatado ou inválido.
    """
    auth_header = request.headers.get("Authorization", "")
    if not auth_header.startswith("Bearer "):
        abort(401)
    token = auth_header[7:]
    expected = current_app.config.get("QR_SERVICE_TOKEN", "")
    if not expected or not hmac.compare_digest(token, expected):
        abort(401)


@bp.before_request
def autenticar():
    """Intercepta todas as requisições do blueprint para validar o token de serviço."""
    _verificar_token_servico()


@bp.route("/ponto/qr-scan", methods=["POST"])
def qr_scan():
    """Recebe leitura de QR code do scanner e registra batida de ponto.

    JSON body:
        qr_payload (str): Token Fernet criptografado lido pelo scanner.

    Returns:
        JSON com resultado da operação.
        201: Batida registrada com sucesso.
        400: Payload ausente ou formato inválido.
        401: Token de serviço inválido (tratado pelo before_request).
        422: QR code inválido, funcionário não encontrado ou token revogado.
    """
    from app.models.funcionario import Funcionario
    from app.services.ponto_service import GeofenceViolationError, PontoService
    from app.services.qr_service import QRService

    data = request.get_json(silent=True)
    if not data or "qr_payload" not in data:
        return jsonify({"erro": "Campo 'qr_payload' é obrigatório."}), 400

    qr_payload = data["qr_payload"]
    qr_secret_key = current_app.config["QR_SECRET_KEY"]

    # 1. Descriptografar o payload Fernet
    try:
        payload = QRService.descriptografar_payload(qr_payload, qr_secret_key)
    except ValueError:
        return jsonify({"erro": "QR code inválido ou adulterado."}), 422

    funcionario_id = payload.get("id")
    token_payload = payload.get("token")

    if not funcionario_id or not token_payload:
        return jsonify({"erro": "Payload do QR code incompleto."}), 422

    # 2. Validar funcionário e anti-revogação
    from app.extensions import db

    funcionario = db.session.get(Funcionario, funcionario_id)
    if not funcionario or not funcionario.ativo:
        return jsonify({"erro": "Funcionário não encontrado ou inativo."}), 422

    if not hmac.compare_digest(funcionario.token_qr or "", token_payload):
        return jsonify({"erro": "QR code revogado. Solicite um novo ao gestor."}), 422

    # 3. Registrar batida (sem GPS — kiosk é em local fixo autorizado)
    try:
        entrada = PontoService.registrar_batida(
            funcionario_id=funcionario_id,
            source="QR",
            ator_id=funcionario_id,
        )
    except ValueError as exc:
        return jsonify({"erro": str(exc)}), 422
    except GeofenceViolationError as exc:
        return jsonify({"erro": str(exc)}), 422

    # 4. Resposta com dados para exibição/impressão no totem
    hora_local = entrada.punch_time.strftime("%H:%M:%S")
    data_completa = entrada.punch_time.strftime("%d/%m/%Y %H:%M:%S")

    return jsonify({
        "sucesso": True,
        "nome": funcionario.nome,
        "hora": hora_local,
        "tipo_batida": entrada.punch_type.value if entrada.punch_type else "extra",
        "impressao": {
            "nome": funcionario.nome,
            "hora": hora_local,
            "tipo": entrada.punch_type.value if entrada.punch_type else "extra",
            "data_completa": data_completa,
        },
    }), 201
