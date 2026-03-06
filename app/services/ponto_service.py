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

import math
from datetime import datetime, timedelta, timezone
from typing import Any

from app.core.audit import AuditService
from app.extensions import db
from app.services.base import BaseService


# ---------------------------------------------------------------------------
# Exceções customizadas
# ---------------------------------------------------------------------------

class GeofenceViolationError(ValueError):
    """Lançada quando as coordenadas do colaborador estão fora de todas as zonas autorizadas."""


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


def _get_expected_minutes(funcionario, date_val=None) -> int:
    """
    Retorna os minutos esperados para a data fornecida (ou hoje, se não informada) 
    conforme a jornada do funcionário.

    Prioridade: jornada_semanal[dia_semana] > minutos_esperados_dia (fallback global).

    Args:
        funcionario: Instância de Funcionario com os campos de jornada.
        date_val: Objeto date opcional para calcular pelo dia do retroativo em vez de hoje.

    Returns:
        Minutos esperados para o dia.
    """
    if date_val is None:
        date_val = datetime.now(timezone.utc).date()
    # Mapeia weekday() (0=Monday) para as chaves do JSON de jornada
    dias = ("segunda", "terca", "quarta", "quinta", "sexta", "sabado", "domingo")
    dia_key = dias[date_val.weekday()]

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

    # -----------------------------------------------------------------------
    # Geofencing — Haversine (Rule #2)
    # -----------------------------------------------------------------------

    @staticmethod
    def _haversine_metros(lat1: float, lng1: float, lat2: float, lng2: float) -> float:
        """Calcula a distância em metros entre dois pontos usando a fórmula de Haversine.

        Args:
            lat1: Latitude do ponto 1 em graus decimais.
            lng1: Longitude do ponto 1 em graus decimais.
            lat2: Latitude do ponto 2 em graus decimais.
            lng2: Longitude do ponto 2 em graus decimais.

        Returns:
            Distância em metros entre os dois pontos.
        """
        R = 6_371_000  # raio médio da Terra em metros
        phi1, phi2 = math.radians(lat1), math.radians(lat2)
        dphi    = math.radians(lat2 - lat1)
        dlambda = math.radians(lng2 - lng1)
        a = math.sin(dphi / 2) ** 2 + math.cos(phi1) * math.cos(phi2) * math.sin(dlambda / 2) ** 2
        return R * 2 * math.atan2(math.sqrt(a), math.sqrt(1 - a))

    @staticmethod
    def _esta_dentro_do_geofence(lat: float, lng: float) -> bool:
        """Verifica se as coordenadas estão dentro de pelo menos uma zona de geofence ativa.

        Comportamento fail-open: se nenhuma zona ativa estiver cadastrada, retorna True
        para não bloquear registros em instalações sem geofencing configurado.

        Args:
            lat: Latitude atual do colaborador em graus decimais.
            lng: Longitude atual do colaborador em graus decimais.

        Returns:
            True se dentro de alguma zona ativa ou se não há zonas cadastradas; False caso contrário.
        """
        from app.models.geofence import GeofenceLocation
        zonas = db.session.query(GeofenceLocation).filter_by(is_active=True).all()
        if not zonas:
            return True  # sem zonas configuradas → permite (fail-open)
        return any(
            PontoService._haversine_metros(lat, lng, z.latitude, z.longitude) <= z.radius_meters
            for z in zonas
        )

    @staticmethod
    def reprocess_interval(
        funcionario_id: int,
        start_date: date,
        end_date: date,
        ator_id: int,
        override_snapshots: bool = False,
        tolerance: int = 10,
    ) -> dict:
        """
        Reprocessa o saldo diário de ponto para um determinado funcionário em 
        um intervalo de datas fechado [start_date, end_date].

        Regra de Snapshot:
            Se `override_snapshots` = False (padrão), o sistema irá recalcular o
            saldo de cada dia usando o `expected_minutes_snapshot` e 
            `tolerance_snapshot` salvos na própria tabela `TimeDay` (preservando
            o contrato da época).
            Se `override_snapshots` = True, o sistema usará o `minutos_esperados_dia`
            atual do cadastro do funcionário e a `tolerance` informada no parâmetro,
            atualizando os snapshots com esses novos valores.

        Regra de Auditoria (Rule #1):
            Gera apenas 1 log coletivo 'mass_reprocess'.

        Args:
            funcionario_id: ID do funcionário.
            start_date:     Data de início (inclusiva).
            end_date:       Data de fim (inclusiva).
            ator_id:        ID do admin executando a ação.
            override_snapshots: Sobrescrever a jornada/tolerância antiga 
                               pelas opções atuais?
            tolerance:      Tolerância em minutos (usada se override_snapshots=True).

        Returns:
            Um dicionário com o número de 'dias_processados' e os detalhes
            'logs_detalhados'.
        """
        from app.models.funcionario import Funcionario
        from app.models.ponto import ProcessingStatus, TimeDay, TimeEntry

        funcionario = db.session.get(Funcionario, funcionario_id)
        if not funcionario:
            raise ValueError("Funcionário não encontrado.")

        if end_date < start_date:
            raise ValueError("Data de fim não pode ser menor que data inicial.")

        # Buscar dias já existentes no banco que caiam nesse intervalo
        query_days = db.session.query(TimeDay).filter(
            TimeDay.funcionario_id == funcionario_id,
            TimeDay.shift_date >= start_date,
            TimeDay.shift_date <= end_date,
        ).order_by(TimeDay.shift_date)
        
        dias_afetados = query_days.all()
        
        resultado = {
            "dias_processados": len(dias_afetados),
            "logs_detalhados": [],
        }

        if not dias_afetados:
            return resultado

        mudancas_audit = {}

        for dia in dias_afetados:
            # Fetch all entries for the current day to ensure we have them
            entries_for_day = (
                db.session.query(TimeEntry)
                .filter(
                    TimeEntry.funcionario_id == funcionario_id,
                    TimeEntry.shift_date == dia.shift_date,
                )
                .order_by(TimeEntry.punch_time)
                .all()
            )
            batidas = [
                {"time": e.punch_time} # "type" is not used in calculate_daily_balance
                for e in entries_for_day
                if e.processing_status == ProcessingStatus.PROCESSADO
            ]

            # Snapshot tracking
            expected_minutes = dia.expected_minutes_snapshot
            tol = dia.tolerance_snapshot

            if override_snapshots:
                expected_minutes = _get_expected_minutes(funcionario, dia.shift_date)
                tol = tolerance
                # Atualiza o snapshot no DB com os novos valores
                dia.expected_minutes_snapshot = expected_minutes
                dia.tolerance_snapshot = tol

            old_saldo_bruto = dia.minutos_trabalhados
            old_saldo_liquido = dia.saldo_calculado_minutos
            old_needs_review = dia.needs_review
            old_saldo_final = dia.saldo_final_minutos

            calc = PontoService.calculate_daily_balance(
                batidas,
                expected_minutes=expected_minutes,
                tolerance=tol
            )

            # Atualizar obj do ORM (apenas recalculados do math)
            dia.minutos_trabalhados = calc["minutos_trabalhados"]
            dia.saldo_calculado_minutos = calc["saldo_calculado_minutos"]
            dia.needs_review = calc["needs_review"]

            # Saldo final (se houve edicao manual de adm sobrepõe, senao é o default)
            # Para evitar bagunçar quem ja fez o banco, mantemos o saldo final == calculado,
            # exceto se houver feature futura de ajuste manual de 'saldo_final_minutos'.
            dia.saldo_final_minutos = calc["saldo_calculado_minutos"]

            mudancas_audit[dia.shift_date.isoformat()] = {
                "old": {
                    "trabalhados": old_saldo_bruto,
                    "calculado": old_saldo_liquido,
                    "needs_review": old_needs_review,
                    "expected_minutes": dia.expected_minutes_snapshot if not override_snapshots else None
                },
                "new": {
                    "trabalhados": dia.minutos_trabalhados,
                    "calculado": dia.saldo_calculado_minutos,
                    "needs_review": dia.needs_review,
                    "expected_minutes": dia.expected_minutes_snapshot
                }
            }
            
            resultado["logs_detalhados"].append({
                "data": dia.shift_date.isoformat(),
                "novo_saldo": dia.saldo_calculado_minutos
            })

        db.session.commit()

        # Audit rule #1 - Lote Massivo
        AuditService.log_update(
            "ponto",
            funcionario_id,
            previous_state={"action": "mass_reprocess_before", "interval": [start_date.isoformat(), end_date.isoformat()]},
            new_state={
                "action": "mass_reprocess_after", 
                "override_snapshots": override_snapshots,
                "changes": mudancas_audit
            },
            actor_id=ator_id,
        )

        db.session.commit()

        # Recalcula do zero absoluto ao final para assegurar total integridade
        PontoService.recalcular_banco_global(funcionario_id, ator_id)
        
        return resultado

    # ------------------------------------------------------------------
    # Cálculos puros (sem acesso ao banco)
    # ------------------------------------------------------------------

    @staticmethod
    def calculate_daily_balance(
        punch_list: list[dict[str, Any]],
        expected_minutes: int,
        almoco_minutes: int = 60,
        tolerance: int = 10,
    ) -> dict[str, Any]:
        """
        Calcula o saldo diário do banco de horas a partir de uma lista ordenada de batidas.

        Implementa Rule #4 (tolerância) e Rule #5 (par/ímpar).

        As batidas são processadas em pares adjacentes (0,1), (2,3), etc.
        Com 4 batidas no padrão CLT (entrada, saída-almoço, retorno-almoço, saída),
        o intervalo de almoço já fica implicitamente excluído — ele corresponde ao
        tempo *entre* o par (0,1) e o par (2,3), que nunca é somado. Por isso,
        **não há subtração adicional de almoço**.

        Exemplo correto com batidas 11:25, 11:34, 12:56, 14:02:
            Par (0,1): 11:25 → 11:34 = 9 min
            Par (2,3): 12:56 → 14:02 = 66 min
            Total trabalhado = 75 min (almoço de 82 min já excluído pelos pares)

        Args:
            punch_list:       Lista de dicts com ao menos {'time': datetime}.
                              Deve estar ordenada em ordem crescente por 'time'.
            expected_minutes: Minutos esperados de trabalho no dia.
                              Use o valor de jornada_semanal ou Funcionario.minutos_esperados_dia.
            almoco_minutes:   Parâmetro mantido por compatibilidade de assinatura;
                              não é utilizado no cálculo (o almoço é excluído implicitamente
                              pela estrutura de pares).
            tolerance:        Limite de tolerância em minutos (padrão 10).

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
        # O almoço (intervalo entre o par 1 e o par 2) nunca é somado,
        # portanto não há necessidade de subtração adicional.
        worked: int = 0
        for i in range(0, len(punch_list), 2):
            entrada: datetime = punch_list[i]["time"]
            saida:   datetime = punch_list[i + 1]["time"]
            worked += int((saida - entrada).total_seconds() / 60)

        saldo_bruto: int = worked - expected_minutes

        # Rule #4: aplica tolerância
        saldo_final: int = _apply_tolerance(saldo_bruto, tolerance)

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
        current_lat: float | None = None,
        current_lng: float | None = None,
    ) -> "TimeEntry":  # type: ignore[name-defined]  # noqa: F821
        """
        Registra uma nova batida de ponto para o colaborador.

        Valida geofencing (Rule #2) quando coordenadas são fornecidas.
        Valida duplicata nos últimos DUPLICATE_WINDOW_SECONDS segundos.
        Após persistir a TimeEntry, recalcula o TimeDay do dia via processar_dia().
        Registra AuditLog com action=create, module='ponto'.

        Args:
            funcionario_id: PK do colaborador.
            punch_time:     Timestamp da batida em UTC. Se None, usa o momento atual.
            source:         Origem do registro ('employee', 'admin', 'KIOSK', 'QR').
            ator_id:        ID do funcionário autor da ação.
            current_lat:    Latitude atual do colaborador (graus decimais). None = sem validação GPS.
            current_lng:    Longitude atual do colaborador (graus decimais). None = sem validação GPS.

        Returns:
            Instância persistida de TimeEntry.

        Raises:
            GeofenceViolationError: Se as coordenadas estiverem fora de todas as zonas ativas.
            ValueError: Se o colaborador não for encontrado ou estiver inativo.
            ValueError: Se já existir uma batida nos últimos 5 segundos (anti-duplicata).
        """
        from app.models.funcionario import Funcionario
        from app.models.ponto import ProcessingStatus, PunchSource, PunchType, TimeDay, TimeEntry

        # Validação de geofencing (Rule #2) — aplicável APENAS a punches de autoatendimento
        # GPS é OBRIGATÓRIO apenas para: 'employee' (portal) e 'QR' (QR code).
        # Admin edits ('admin' source) NÃO exigem geofencing — são edições administrativas.
        # KIOSK pode ter regras próprias futuras.
        if source in ("employee", "QR"):
            from app.models.geofence import GeofenceLocation
            _zonas_ativas = db.session.query(GeofenceLocation).filter_by(is_active=True).count()
            if _zonas_ativas > 0:
                if current_lat is None or current_lng is None:
                    raise GeofenceViolationError(
                        "Localização GPS obrigatória. Permita o acesso à localização no navegador e tente novamente."
                    )
                if not PontoService._esta_dentro_do_geofence(current_lat, current_lng):
                    raise GeofenceViolationError(
                        "Você não está em uma localização autorizada para registrar o ponto."
                    )


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

        # Determina a jornada esperada para a data passada no processamento
        expected = _get_expected_minutes(funcionario, shift_date)

        # Pega ou cria o TimeDay para este dia
        time_day = (
            db.session.query(TimeDay)
            .filter_by(funcionario_id=funcionario_id, shift_date=shift_date)
            .first()
        )

        old_saldo_final = 0
        if not time_day:
            time_day = TimeDay(
                funcionario_id=funcionario_id,
                shift_date=shift_date,
                expected_minutes_snapshot=expected,
                tolerance_snapshot=10, # default 10min CLT
            )
            db.session.add(time_day)
        else:
            old_saldo_final = time_day.saldo_final_minutos

        # Reversão de débito provisório de ausência (auto_debit_aplicado).
        # O flag é limpo apenas quando existem batidas reais para recalcular —
        # sem batidas, o débito provisório continua em vigor como estado correto.
        # O mecanismo de delta abaixo já corrige banco_horas_acumulado automaticamente,
        # pois old_saldo_final retém o valor negativo do débito provisório.
        if time_day.auto_debit_aplicado and len(entries) > 0:
            time_day.auto_debit_aplicado = False

        # O cálculo usa sempre o snapshot do dia (preserva regra passada)
        result = PontoService.calculate_daily_balance(
            punch_list,
            expected_minutes=time_day.expected_minutes_snapshot,
            tolerance=time_day.tolerance_snapshot,
        )

        time_day.minutos_trabalhados = result["minutos_trabalhados"]
        time_day.saldo_calculado_minutos = result["saldo_calculado_minutos"]
        time_day.saldo_final_minutos    = result["saldo_calculado_minutos"]  # sem deduções manuais ainda
        time_day.needs_review           = result["needs_review"]

        # Cache do Banco de Horas
        delta = time_day.saldo_final_minutos - old_saldo_final
        if delta != 0:
            funcionario.banco_horas_acumulado += delta

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

    @staticmethod
    def deletar_batida_admin(
        time_entry_id: int,
        motivo: str,
        ator_id: int,
    ) -> dict[str, Any]:
        """
        Remove uma batida de ponto existente (operação exclusiva de admin).

        Antigravity Rule #1: registra AuditLog com snapshot completo antes da exclusão.
        Antigravity Rule #2: sem imports do Flask.
        Rule #3: motivo não vazio é obrigatório.

        Após deletar, recalcula o TimeDay do dia afetado via processar_dia() para
        refletir o novo saldo (possivelmente com batidas ímpares → needs_review=True).

        Args:
            time_entry_id: PK da batida a remover.
            motivo:        Justificativa da exclusão — obrigatória e não vazia.
            ator_id:       ID do admin que realiza a ação.

        Returns:
            Dict com id, shift_date e motivo da batida removida.

        Raises:
            ValueError: Se motivo for vazio.
            ValueError: Se a batida não for encontrada.
        """
        from app.models.ponto import TimeEntry

        BaseService._require(motivo, "motivo")

        entry = db.session.get(TimeEntry, time_entry_id)
        if not entry:
            raise ValueError(f"Batida {time_entry_id} não encontrada.")

        snapshot = {
            "funcionario_id": entry.funcionario_id,
            "punch_time": entry.punch_time.isoformat(),
            "shift_date": entry.shift_date.isoformat(),
            "punch_type": entry.punch_type.value,
            "source": entry.source.value,
            "admin_change_reason": motivo.strip(),
            "deleted_by_user_id": ator_id,
        }

        funcionario_id = entry.funcionario_id
        shift_date = entry.shift_date

        # Antigravity Rule #1 — audita antes de deletar para o entity_id ainda existir
        AuditService.log_delete(
            "ponto",
            entity_id=entry.id,
            previous_state=snapshot,
            actor_id=ator_id,
        )
        db.session.delete(entry)
        db.session.commit()

        # Recalcula o dia afetado (pode ficar com batidas ímpares → needs_review)
        PontoService.processar_dia(funcionario_id, shift_date)

        return {
            "id": time_entry_id,
            "shift_date": shift_date,
            "motivo": motivo,
        }

    @staticmethod
    def recalcular_banco_global(funcionario_id: int, ator_id: int | None = None) -> int:
        """
        Refaz a contagem total do banco de horas do zero para um funcionário.
        Cenário de uso: reparo de inconsistência ou reprocessamento massivo.

        Soma todos os saldos_finais_minutos de TimeDay MENOS 
        as deduções de saldo em TimeBankDeduction.
        Atualiza Funcionario.banco_horas_acumulado.
        """
        from app.models.funcionario import Funcionario
        from app.models.ponto import TimeDay, TimeBankDeduction
        from sqlalchemy import func

        funcionario = db.session.get(Funcionario, funcionario_id)
        if not funcionario:
            raise ValueError("Funcionário não encontrado.")

        old_banco = funcionario.banco_horas_acumulado

        soma_dias = int(db.session.query(func.sum(TimeDay.saldo_final_minutos)).filter(
            TimeDay.funcionario_id == funcionario_id
        ).scalar() or 0)

        soma_abatimentos = int(db.session.query(func.sum(TimeBankDeduction.minutos_abatidos)).filter(
            TimeBankDeduction.funcionario_id == funcionario_id
        ).scalar() or 0)

        novo_banco = int(soma_dias) - int(soma_abatimentos)

        funcionario.banco_horas_acumulado = novo_banco
        db.session.flush()

        AuditService.log_update(
            "ponto",
            funcionario_id,
            previous_state={"action": "recalcular_banco_global", "saldo_anterior": old_banco},
            new_state={"saldo_novo": novo_banco, "soma_dias": soma_dias, "soma_abatimentos": soma_abatimentos},
            actor_id=ator_id,
        )

        db.session.commit()
        return novo_banco

    @staticmethod
    def registrar_abatimento_horas(
        funcionario_id: int,
        deduction_type: str,
        minutos: int,
        data_registro,
        observacao: str,
        ator_id: int,
        pagamento_valor=None,
    ) -> "TimeBankDeduction": # type: ignore[name-defined]  # noqa: F821
        """
        Registra um pagamento de banco de horas ou desconto por folga.
        Isso reduz o saldo da variável em cache no Funcionario e adiciona 
        o registro em TimeBankDeduction.
        """
        from app.models.funcionario import Funcionario
        from app.models.ponto import TimeBankDeduction, DeductionType

        if minutos <= 0:
            raise ValueError("Minutos de abatimento devem ser maiores que zero.")

        funcionario = db.session.get(Funcionario, funcionario_id)
        if not funcionario:
            raise ValueError("Funcionário não encontrado.")

        ded_type = DeductionType(deduction_type)

        deduction = TimeBankDeduction(
            funcionario_id=funcionario_id,
            deduction_type=ded_type,
            minutos_abatidos=minutos,
            data_registro=data_registro,
            pagamento_valor=pagamento_valor,
            observacao=observacao
        )

        old_banco = funcionario.banco_horas_acumulado
        funcionario.banco_horas_acumulado -= minutos

        db.session.add(deduction)
        db.session.flush()

        AuditService.log_create(
            "ponto_abatimento",
            entity_id=deduction.id,
            new_state={
                "funcionario_id": funcionario_id,
                "deduction_type": ded_type.value,
                "minutos": minutos,
                "saldo_anterior": old_banco,
                "saldo_novo": funcionario.banco_horas_acumulado
            },
            actor_id=ator_id,
        )

        db.session.commit()
        return deduction
