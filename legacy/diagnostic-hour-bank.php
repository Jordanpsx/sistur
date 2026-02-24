<?php
/**
 * Script de diagnóstico para verificar configuração de jornada de trabalho
 * Este script verifica se todos os funcionários têm a jornada corretamente configurada
 */

// Carregar WordPress
require_once('../../../wp-load.php');

global $wpdb;

echo "=== DIAGNÓSTICO DE JORNADA DE TRABALHO ===\n\n";

// 1. Verificar tipos de contrato cadastrados
echo "1. TIPOS DE CONTRATO CADASTRADOS:\n";
$contract_types = $wpdb->get_results("
    SELECT id, descricao, carga_horaria_diaria_minutos, carga_horaria_semanal_minutos
    FROM {$wpdb->prefix}sistur_contract_types
    ORDER BY id
");

if (empty($contract_types)) {
    echo "   ⚠️  NENHUM tipo de contrato cadastrado!\n\n";
} else {
    foreach ($contract_types as $ct) {
        echo "   - ID {$ct->id}: {$ct->descricao}\n";
        echo "     Diária: {$ct->carga_horaria_diaria_minutos} min (" . ($ct->carga_horaria_diaria_minutos / 60) . "h)\n";
        echo "     Semanal: {$ct->carga_horaria_semanal_minutos} min (" . ($ct->carga_horaria_semanal_minutos / 60) . "h)\n\n";
    }
}

// 2. Verificar funcionários
echo "2. FUNCIONÁRIOS E SUAS JORNADAS:\n";
$employees = $wpdb->get_results("
    SELECT
        e.id,
        e.name,
        e.contract_type_id,
        e.time_expected_minutes,
        e.status,
        ct.descricao as tipo_contrato,
        ct.carga_horaria_diaria_minutos as carga_contrato
    FROM {$wpdb->prefix}sistur_employees e
    LEFT JOIN {$wpdb->prefix}sistur_contract_types ct ON e.contract_type_id = ct.id
    WHERE e.status = 1
    ORDER BY e.id
");

if (empty($employees)) {
    echo "   ⚠️  NENHUM funcionário ativo cadastrado!\n\n";
} else {
    foreach ($employees as $emp) {
        echo "   - ID {$emp->id}: {$emp->name}\n";

        // Calcular carga horária efetiva (mesma lógica do código)
        $expected_minutes = $emp->carga_contrato
            ? intval($emp->carga_contrato)
            : intval($emp->time_expected_minutes);

        echo "     Contract Type ID: " . ($emp->contract_type_id ? $emp->contract_type_id : "NULL (sem tipo de contrato)") . "\n";

        if ($emp->contract_type_id) {
            echo "     Tipo de Contrato: {$emp->tipo_contrato}\n";
            echo "     Carga (do contrato): {$emp->carga_contrato} min (" . ($emp->carga_contrato / 60) . "h/dia)\n";
        } else {
            echo "     ⚠️  Sem tipo de contrato vinculado\n";
        }

        echo "     time_expected_minutes (fallback): {$emp->time_expected_minutes} min (" . ($emp->time_expected_minutes / 60) . "h/dia)\n";
        echo "     ✅ Carga EFETIVA sendo usada: {$expected_minutes} min (" . ($expected_minutes / 60) . "h/dia)\n\n";
    }
}

// 3. Verificar se há funcionários sem jornada definida
echo "3. VERIFICAÇÃO DE PROBLEMAS:\n";
$problematic_employees = $wpdb->get_results("
    SELECT
        e.id,
        e.name,
        e.contract_type_id,
        e.time_expected_minutes,
        ct.carga_horaria_diaria_minutos as carga_contrato
    FROM {$wpdb->prefix}sistur_employees e
    LEFT JOIN {$wpdb->prefix}sistur_contract_types ct ON e.contract_type_id = ct.id
    WHERE e.status = 1
    AND (
        (e.contract_type_id IS NULL AND (e.time_expected_minutes IS NULL OR e.time_expected_minutes = 0))
        OR (e.contract_type_id IS NOT NULL AND ct.id IS NULL)
    )
");

if (empty($problematic_employees)) {
    echo "   ✅ Todos os funcionários têm jornada configurada corretamente!\n\n";
} else {
    echo "   ⚠️  FUNCIONÁRIOS COM PROBLEMAS NA CONFIGURAÇÃO:\n";
    foreach ($problematic_employees as $emp) {
        echo "   - ID {$emp->id}: {$emp->name}\n";
        if ($emp->contract_type_id && !$emp->carga_contrato) {
            echo "     ❌ ERRO: Tem contract_type_id={$emp->contract_type_id} mas o tipo de contrato NÃO EXISTE!\n";
        } else {
            echo "     ❌ ERRO: Sem tipo de contrato E sem time_expected_minutes configurado!\n";
        }
    }
    echo "\n";
}

// 4. Verificar um exemplo de cálculo
echo "4. TESTE DE CÁLCULO (último dia processado):\n";
$last_day = $wpdb->get_row("
    SELECT
        td.*,
        e.name as employee_name,
        e.contract_type_id,
        e.time_expected_minutes,
        ct.carga_horaria_diaria_minutos as carga_contrato
    FROM {$wpdb->prefix}sistur_time_days td
    JOIN {$wpdb->prefix}sistur_employees e ON td.employee_id = e.id
    LEFT JOIN {$wpdb->prefix}sistur_contract_types ct ON e.contract_type_id = ct.id
    WHERE td.status = 'present'
    ORDER BY td.shift_date DESC, td.id DESC
    LIMIT 1
");

if ($last_day) {
    $expected_used = $last_day->carga_contrato
        ? intval($last_day->carga_contrato)
        : intval($last_day->time_expected_minutes);

    echo "   Funcionário: {$last_day->employee_name} (ID: {$last_day->employee_id})\n";
    echo "   Data: {$last_day->shift_date}\n";
    echo "   Minutos trabalhados: {$last_day->minutos_trabalhados} min (" . round($last_day->minutos_trabalhados / 60, 2) . "h)\n";
    echo "   Carga esperada usada no cálculo: {$expected_used} min (" . ($expected_used / 60) . "h)\n";
    echo "   Saldo calculado: {$last_day->saldo_calculado_minutos} min\n";
    echo "   Saldo final: {$last_day->saldo_final_minutos} min\n\n";

    // Verificar se o cálculo está correto
    $calculated_saldo = $last_day->minutos_trabalhados - $expected_used;
    if ($calculated_saldo != $last_day->saldo_calculado_minutos) {
        echo "   ⚠️  INCONSISTÊNCIA NO CÁLCULO!\n";
        echo "   Esperado: {$calculated_saldo} min\n";
        echo "   Registrado: {$last_day->saldo_calculado_minutos} min\n";
    } else {
        echo "   ✅ Cálculo está correto!\n";
    }
} else {
    echo "   ⚠️  Nenhum dia processado encontrado\n";
}

echo "\n=== FIM DO DIAGNÓSTICO ===\n";
