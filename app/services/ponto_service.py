# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
PontoService — lógica de negócio do módulo Ponto Eletrônico.

Antigravity Rule #1: toda mutação chama AuditService.
Antigravity Rule #2: sem imports do Flask — pure Python, Cython-compatível.

Regras de negócio implementadas
---------------------------------
Rule #4  Tolerância de 10 min: perdoa atrasos ≤ 10 min; penaliza apenas o excesso.
Rule #5  Par/Ímpar: número ímpar de batidas → needs_review=True, saldo congelado.
Rule #3  Admin CRUD: edições administrativas exigem motivo não vazio e AuditLog.
"""

from __future__ import annotations

from datetime import datetime, timedelta, timezone
from typing import Any

from app.core.audit import AuditService
from app.extensions import db
from app.services.base import BaseService


# ---------------------------------------------------------------------------
# Module-private helpers
# ---------------------------------------------------------------------------

def _apply_tolerance(saldo_bruto: int, tolerance: int) -> int:
    """
    Aplica a regra de tolerância de 10 minutos (Rule #4).

    A tolerância se aplica somente a atrasos (saldo negativo).
    Horas extras (saldo positivo) são retornadas sem alteração.

    Args:
        saldo_bruto: Saldo bruto em minutos (trabalhado − esperado).
        tolerance:   Limite de tolerância em minutos (padrão 10).

    Returns:
        Saldo ajustado em minutos.

    Examples:
        _apply_tolerance(-8, 10)  → 0    (dentro da tolerância — perdoado)
        _apply_tolerance(-25, 10) → -15  (apenas o excesso é penalizado)
        _apply_tolerance(30, 10)  → 30   (hora extra não é afetada)
    """
    if saldo_bruto >= 0:
        return saldo_bruto
    if abs(saldo_bruto) <= tolerance:
        return 0
    return saldo_bruto + tolerance


def _get_expected_minutes(funcionario) -> int:
    """
    Retorna os minutos esperados para hoje conforme a jornada do funcionário.

    Prioridade: jornada_semanal[dia_semana] > minutos_esperados_dia (fallback global).

    Args:
        funcionario: Instância de Funcionario com os campos de jornada.

    Returns:
        Minutos esperados para o dia atual.
    """
    hoje = datetime.now(timezone.utc).date()
    # Mapeia weekday() (0=Monday) para as chaves do JSON de jornada
    dias = ("segunda", "terca", "quarta", "quinta", "sexta", "sabado", "domingo")
    dia_key = dias[hoje.weekday()]

    if funcionario.jornada_semanal:
        config_dia = funcionario.jornada_semanal.get(dia_key, {})
        if config_dia.get("ativo", False):
            return int(config_dia.get("minutos", funcionario.minutos_esperados_dia))

    return funcionario.minutos_esperados_dia


# ---------------------------------------------------------------------------
# Service
# ---------------------------------------------------------------------------

class PontoService(BaseService):
    """
    Cálculos e mutações do módulo Ponto Eletrônico.

    Todos os métodos são estáticos — sem estado de instância — garantindo
    compatibilidade com compilação via Cython e importabilidade sem efeitos colaterais.
    """

    TOLERANCE_MINUTES: int = 10
    DUPLICATE_WINDOW_SECONDS: int = 5

    # ------------------------------------------------------------------
    # Cálculos puros (sem acesso ao banco)
    # ------------------------------------------------------------------

    @staticmethod
    def calculate_daily_balance(
        punch_list: list[dict[str, Any]],
        expected_minutes: int,
        almoco_minutes: int = 60,
    ) -> dict[str, Any]:
        """
        Calcula o saldo diário do banco de horas a partir de uma lista ordenada de batidas.

        Implementa Rule #4 (tolerância) e Rule #5 (par/ímpar).

        Args:
            punch_list:       Lista de dicts com ao menos {'time': datetime}.
                              Deve estar ordenada em ordem crescente por 'time'.
            expected_minutes: Minutos esperados de trabalho no dia.
                              Use o valor de jornada_semanal ou Funcionario.minutos_esperados_dia.
            almoco_minutes:   Minutos de almoço esperados (padrão 60).
                              Só é descontado quando há 4 ou mais batidas.

        Returns:
            Dicionário com as chaves:
                'minutos_trabalhados':    int  — minutos brutos entre os pares
                'saldo_calculado_minutos': int — após tolerância da Rule #4
                'needs_review':           bool — True quando número de batidas é ímpar (Rule #5)
        """
        # Rule #5: número ímpar de batidas → congela o cálculo
        if len(punch_list) % 2 != 0:
            return {
                "minutos_trabalhados": 0,
                "saldo_calculado_minutos": 0,
                "needs_review": True,
            }

        # Soma pares adjacentes: (0,1), (2,3), ...
        worked: int = 0
        for i in range(0, len(punch_list), 2):
            entrada: datetime = punch_list[i]["time"]
            saida:   datetime = punch_list[i + 1]["time"]
            worked += int((saida - entrada).total_seconds() / 60)

        # Desconta almoço somente quando par de almoço registrado (≥ 4 batidas)
        if len(punch_list) >= 4:
            worked -= almoco_minutes

        saldo_bruto: int = worked - expected_minutes

        # Rule #4: aplica tolerância
        saldo_final: int = _apply_tolerance(saldo_bruto, PontoService.TOLERANCE_MINUTES)

        return {
            "minutos_trabalhados": worked,
            "saldo_calculado_minutos": saldo_final,
            "needs_review": False,
        }

    # ------------------------------------------------------------------
    # Mutações (requerem AuditService — Rule #1 e Rule #3)
    # ------------------------------------------------------------------

    @staticmethod
    def registrar_batida(
        funcionario_id: int,
        punch_time: datetime | None = None,
        source: str = "employee",
        ator_id: int | None = None,
    ) -> "TimeEntry":  # type: ignore[name-defined]  # noqa: F821
        """
        Registra uma nova batida de ponto para o colaborador.

        Valida duplicata nos últimos DUPLICATE_WINDOW_SECONDS segundos.
        Após persistir a TimeEntry, recalcula o TimeDay do dia via processar_dia().
        Registra AuditLog com action=create, module='ponto'.

        Args:
            funcionario_id: PK do colaborador.
            punch_time:     Timestamp da batida em UTC. Se None, usa o momento atual.
            source:         Origem do registro ('employee', 'admin', 'KIOSK', 'QR').
            ator_id:        ID do funcionário autor da ação.

        Returns:
            Instância persistida de TimeEntry.

        Raises:
            ValueError: Se o colaborador não for encontrado ou estiver inativo.
            ValueError: Se já existir uma batida nos últimos 5 segundos (anti-duplicata).
        """
        from app.models.funcionario import Funcionario
        from app.models.ponto import ProcessingStatus, PunchSource, PunchType, TimeEntry

        funcionario = db.session.get(Funcionario, funcionario_id)
        if not funcionario or not funcionario.ativo:
            raise ValueError(f"Funcionário {funcionario_id} não encontrado ou inativo.")

        if punch_time is None:
            punch_time = datetime.now(timezone.utc)

        # Garante que o datetime seja timezone-aware
        if punch_time.tzinfo is None:
            punch_time = punch_time.replace(tzinfo=timezone.utc)

        shift_date = punch_time.date()

        # Anti-duplicata: rejeita batidas em janela de 5 segundos
        janela_inicio = punch_time - timedelta(seconds=PontoService.DUPLICATE_WINDOW_SECONDS)
        duplicata = (
            db.session.query(TimeEntry)
            .filter(
                TimeEntry.funcionario_id == funcionario_id,
                TimeEntry.punch_time >= janela_inicio,
                TimeEntry.punch_time <= punch_time,
            )
            .first()
        )
        if duplicata:
            raise ValueError(
                f"Batida duplicada detectada. Aguarde {PontoService.DUPLICATE_WINDOW_SECONDS} "
                "segundos antes de registrar novamente."
            )

        # Determina o tipo da batida conforme as batidas já existentes no dia
        batidas_hoje = (
            db.session.query(TimeEntry)
            .filter(
                TimeEntry.funcionario_id == funcionario_id,
                TimeEntry.shift_date == shift_date,
            )
            .count()
        )
        punch_type = PontoService._inferir_tipo_batida(batidas_hoje)

        entry = TimeEntry(
            funcionario_id=funcionario_id,
            punch_time=punch_time,
            shift_date=shift_date,
            punch_type=punch_type,
            source=PunchSource(source),
            processing_status=ProcessingStatus.PENDENTE,
        )
        db.session.add(entry)
        db.session.flush()  # obtém o PK antes do AuditLog

        # Antigravity Rule #1
        AuditService.log_create(
            "ponto",
            entity_id=entry.id,
            new_state={
                "funcionario_id": funcionario_id,
                "punch_time": punch_time.isoformat(),
                "punch_type": punch_type.value,
                "source": source,
            },
            actor_id=ator_id,
        )
        db.session.commit()

        # Recalcula o dia
        PontoService.processar_dia(funcionario_id, shift_date)

        return entry

    @staticmethod
    def _inferir_tipo_batida(batidas_existentes: int) -> "PunchType":  # type: ignore[name-defined]  # noqa: F821
        """
        Infere o tipo de batida baseado na contagem de batidas já registradas no dia.

        Sequência padrão CLT (4 batidas):
            0 → clock_in
            1 → lunch_start
            2 → lunch_end
            3 → clock_out
            4+ → extra

        Args:
            batidas_existentes: Número de batidas já registradas no dia antes desta.

        Returns:
            PunchType correspondente à próxima batida esperada.
        """
        from app.models.ponto import PunchType

        sequencia = [
            PunchType.clock_in,
            PunchType.lunch_start,
            PunchType.lunch_end,
            PunchType.clock_out,
        ]
        if batidas_existentes < len(sequencia):
            return sequencia[batidas_existentes]
        return PunchType.extra

    @staticmethod
    def processar_dia(funcionario_id: int, shift_date) -> "TimeDay":  # type: ignore[name-defined]  # noqa: F821
        """
        Recalcula e persiste o agregado diário (TimeDay) para o colaborador no dia informado.

        Busca todas as TimeEntry do dia, chama calculate_daily_balance() e faz upsert
        em sistur_time_days. Marca todas as TimeEntry do dia como PROCESSADO.

        Args:
            funcionario_id: PK do colaborador.
            shift_date:     Data do turno a processar (date).

        Returns:
            Instância criada ou atualizada de TimeDay.

        Raises:
            ValueError: Se o colaborador não for encontrado.
        """
        from app.models.funcionario import Funcionario
        from app.models.ponto import ProcessingStatus, TimeDay, TimeEntry

        funcionario = db.session.get(Funcionario, funcionario_id)
        if not funcionario:
            raise ValueError(f"Funcionário {funcionario_id} não encontrado.")

        # Busca batidas do dia ordenadas
        entries = (
            db.session.query(TimeEntry)
            .filter(
                TimeEntry.funcionario_id == funcionario_id,
                TimeEntry.shift_date == shift_date,
            )
            .order_by(TimeEntry.punch_time)
            .all()
        )

        punch_list = [{"time": e.punch_time} for e in entries]

        # Determina a jornada esperada para o dia
        expected = _get_expected_minutes(funcionario)

        result = PontoService.calculate_daily_balance(
            punch_list,
            expected_minutes=expected,
            almoco_minutes=funcionario.minutos_almoco,
        )

        # Upsert em TimeDay
        time_day = (
            db.session.query(TimeDay)
            .filter_by(funcionario_id=funcionario_id, shift_date=shift_date)
            .first()
        )
        if time_day is None:
            time_day = TimeDay(
                funcionario_id=funcionario_id,
                shift_date=shift_date,
                expected_minutes_snapshot=expected,
            )
            db.session.add(time_day)

        time_day.minutos_trabalhados    = result["minutos_trabalhados"]
        time_day.saldo_calculado_minutos = result["saldo_calculado_minutos"]
        time_day.saldo_final_minutos    = result["saldo_calculado_minutos"]  # sem deduções manuais ainda
        time_day.needs_review           = result["needs_review"]

        # Marca todas as batidas do dia como processadas
        for entry in entries:
            entry.processing_status = ProcessingStatus.PROCESSADO

        db.session.commit()
        return time_day

    @staticmethod
    def editar_batida_admin(
        time_entry_id: int,
        novo_horario: datetime,
        motivo: str,
        ator_id: int,
    ) -> dict[str, Any]:
        """
        Edita o timestamp de uma batida existente (operação exclusiva de admin).

        Antigravity Rule #1: registra AuditLog com snapshots antes e depois.
        Antigravity Rule #2: sem imports do Flask.
        Rule #3: motivo não vazio é obrigatório.

        Após editar, recalcula o TimeDay do dia afetado via processar_dia().

        Args:
            time_entry_id: PK da batida a editar.
            novo_horario:  Novo datetime para punch_time (UTC).
            motivo:        Justificativa da edição — obrigatória e não vazia.
            ator_id:       ID do admin que realiza a ação.

        Returns:
            Dict com id, punch_time e motivo da batida editada.

        Raises:
            ValueError: Se motivo for vazio.
            ValueError: Se a batida não for encontrada.
        """
        from app.models.ponto import PunchSource, TimeEntry

        BaseService._require(motivo, "motivo")

        entry = db.session.get(TimeEntry, time_entry_id)
        if not entry:
            raise ValueError(f"Batida {time_entry_id} não encontrada.")

        snapshot_antes = {
            "punch_time": entry.punch_time.isoformat(),
            "source": entry.source.value,
            "admin_change_reason": entry.admin_change_reason,
        }

        entry.punch_time           = novo_horario
        entry.source               = PunchSource.admin
        entry.admin_change_reason  = motivo.strip()
        entry.changed_by_user_id   = ator_id
        db.session.flush()

        # Antigravity Rule #1
        AuditService.log_update(
            "ponto",
            entity_id=entry.id,
            previous_state=snapshot_antes,
            new_state={
                "punch_time": entry.punch_time.isoformat(),
                "source": entry.source.value,
                "admin_change_reason": entry.admin_change_reason,
            },
            actor_id=ator_id,
        )
        db.session.commit()

        # Recalcula o dia afetado
        PontoService.processar_dia(entry.funcionario_id, entry.shift_date)

        return {
            "id": entry.id,
            "punch_time": entry.punch_time,
            "motivo": motivo,
        }
