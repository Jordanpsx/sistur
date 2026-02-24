<?php
/**
 * Página Administrativa: Abatimentos Pendentes de Banco de Horas
 *
 * Lista todos os abatimentos pendentes de aprovação
 * e permite aprovar ou rejeitar
 *
 * @package SISTUR
 * @since 1.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Verificar permissão
if (!current_user_can('manage_options')) {
    wp_die(__('Você não tem permissão para acessar esta página.'));
}

global $wpdb;
?>

<div class="wrap">
    <h1 class="wp-heading-inline">Abatimentos de Banco de Horas</h1>

    <a href="#" class="page-title-action btn-abater-banco-horas" style="margin-left: 10px;">+ Novo Abatimento</a>
    <a href="#" id="btn-manage-holidays" class="page-title-action" style="margin-left: 10px;">⚙ Gerenciar Feriados</a>

    <hr class="wp-header-end">

    <!-- Filtros -->
    <div class="tablenav top" style="margin: 20px 0;">
        <div class="alignleft actions">
            <select id="filter-status" style="padding: 5px;">
                <option value="">Todos os Status</option>
                <option value="pendente" selected>Pendentes</option>
                <option value="aprovado">Aprovados</option>
                <option value="rejeitado">Rejeitados</option>
            </select>

            <select id="filter-type" style="padding: 5px; margin-left: 5px;">
                <option value="">Todos os Tipos</option>
                <option value="folga">Folga</option>
                <option value="pagamento">Pagamento</option>
            </select>

            <button type="button" class="button" id="btn-apply-filters">Aplicar Filtros</button>
        </div>

        <div class="alignright">
            <button type="button" class="button" id="btn-refresh-list">🔄 Atualizar</button>
        </div>
    </div>

    <!-- Tabela de Abatimentos Pendentes -->
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width: 20%;">Funcionário</th>
                <th style="width: 10%;">Tipo</th>
                <th style="width: 10%;">Horas</th>
                <th style="width: 12%;">Valor</th>
                <th style="width: 15%;">Data Solicitação</th>
                <th style="width: 10%;">Status</th>
                <th style="width: 10%;">Solicitado por</th>
                <th style="width: 13%;">Ações</th>
            </tr>
        </thead>
        <tbody id="pending-deductions-list">
            <tr>
                <td colspan="8" style="text-align: center; padding: 30px;">
                    <span style="color: #999;">Carregando...</span>
                </td>
            </tr>
        </tbody>
    </table>

    <!-- Paginação (se necessário) -->
    <div class="tablenav bottom">
        <div class="alignleft actions">
            <span id="total-records">Total: 0 registros</span>
        </div>
    </div>

    <!-- Cards de Resumo -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 30px;">
        <div style="background: #fff; border-left: 4px solid #ff9800; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h3 style="margin: 0 0 10px 0; font-size: 14px; color: #666;">Pendentes de Aprovação</h3>
            <p style="margin: 0; font-size: 32px; font-weight: bold; color: #ff9800;" id="stat-pendentes">0</p>
        </div>

        <div style="background: #fff; border-left: 4px solid #4caf50; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h3 style="margin: 0 0 10px 0; font-size: 14px; color: #666;">Aprovados (Mês Atual)</h3>
            <p style="margin: 0; font-size: 32px; font-weight: bold; color: #4caf50;" id="stat-aprovados">0</p>
        </div>

        <div style="background: #fff; border-left: 4px solid #f44336; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h3 style="margin: 0 0 10px 0; font-size: 14px; color: #666;">Rejeitados (Mês Atual)</h3>
            <p style="margin: 0; font-size: 32px; font-weight: bold; color: #f44336;" id="stat-rejeitados">0</p>
        </div>

        <div style="background: #fff; border-left: 4px solid #2196f3; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h3 style="margin: 0 0 10px 0; font-size: 14px; color: #666;">Total Pago (Mês Atual)</h3>
            <p style="margin: 0; font-size: 32px; font-weight: bold; color: #2196f3;" id="stat-total-pago">R$ 0,00</p>
        </div>
    </div>
</div>

<?php
// Incluir os modais
include_once(dirname(__FILE__) . '/modals.php');
?>

<script>
jQuery(document).ready(function($) {
    'use strict';

    // Carregar lista inicial
    loadDeductionsList();

    // Botão atualizar
    $('#btn-refresh-list').on('click', function() {
        loadDeductionsList();
    });

    // Aplicar filtros
    $('#btn-apply-filters').on('click', function() {
        loadDeductionsList();
    });

    /**
     * Carrega lista de abatimentos
     */
    function loadDeductionsList() {
        const status = $('#filter-status').val();
        const type = $('#filter-type').val();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_get_all_deductions',
                nonce: sisturTimebankNonce.nonce,
                status: status,
                type: type
            },
            beforeSend: function() {
                $('#pending-deductions-list').html('<tr><td colspan="8" style="text-align: center; padding: 30px;"><span style="color: #999;">Carregando...</span></td></tr>');
            },
            success: function(response) {
                if (response.success) {
                    displayDeductions(response.data.deductions);
                    updateStats(response.data.stats);
                } else {
                    $('#pending-deductions-list').html('<tr><td colspan="8" style="text-align: center; padding: 30px; color: red;">Erro ao carregar dados</td></tr>');
                }
            },
            error: function() {
                $('#pending-deductions-list').html('<tr><td colspan="8" style="text-align: center; padding: 30px; color: red;">Erro ao conectar com o servidor</td></tr>');
            }
        });
    }

    /**
     * Exibe lista de abatimentos
     */
    function displayDeductions(deductions) {
        const $list = $('#pending-deductions-list');

        if (!deductions || deductions.length === 0) {
            $list.html('<tr><td colspan="8" style="text-align: center; padding: 30px;"><span style="color: #999;">Nenhum registro encontrado</span></td></tr>');
            $('#total-records').text('Total: 0 registros');
            return;
        }

        let html = '';
        deductions.forEach(function(item) {
            const hours = (item.minutes_deducted / 60).toFixed(2);
            const typeLabel = item.deduction_type === 'folga' ? '🏖 Folga' : '💰 Pagamento';
            const amount = item.payment_amount ? 'R$ ' + parseFloat(item.payment_amount).toFixed(2) : '-';

            let statusBadge = '';
            if (item.approval_status === 'pendente') {
                statusBadge = '<span style="background: #ff9800; color: white; padding: 3px 8px; border-radius: 3px; font-size: 11px;">PENDENTE</span>';
            } else if (item.approval_status === 'aprovado') {
                statusBadge = '<span style="background: #4caf50; color: white; padding: 3px 8px; border-radius: 3px; font-size: 11px;">APROVADO</span>';
            } else {
                statusBadge = '<span style="background: #f44336; color: white; padding: 3px 8px; border-radius: 3px; font-size: 11px;">REJEITADO</span>';
            }

            const createdByName = item.created_by_name || 'Sistema';

            html += '<tr>';
            html += '<td><strong>' + item.employee_name + '</strong></td>';
            html += '<td>' + typeLabel + '</td>';
            html += '<td>' + hours + 'h</td>';
            html += '<td>' + amount + '</td>';
            html += '<td>' + formatDate(item.created_at) + '</td>';
            html += '<td>' + statusBadge + '</td>';
            html += '<td>' + createdByName + '</td>';
            html += '<td>';

            if (item.approval_status === 'pendente') {
                html += '<button class="button button-small btn-approve-deduction" data-id="' + item.id + '" data-action="aprovado" style="background: #4caf50; color: white; border: none; margin-right: 5px;">✓ Aprovar</button>';
                html += '<button class="button button-small btn-approve-deduction" data-id="' + item.id + '" data-action="rejeitado" style="background: #f44336; color: white; border: none;">✗ Rejeitar</button>';
            } else {
                html += '<button class="button button-small" onclick="viewDeductionDetails(' + item.id + ')">👁 Ver Detalhes</button>';
            }

            html += '</td>';
            html += '</tr>';
        });

        $list.html(html);
        $('#total-records').text('Total: ' + deductions.length + ' registros');
    }

    /**
     * Atualiza estatísticas
     */
    function updateStats(stats) {
        if (!stats) return;

        $('#stat-pendentes').text(stats.pendentes || 0);
        $('#stat-aprovados').text(stats.aprovados || 0);
        $('#stat-rejeitados').text(stats.rejeitados || 0);
        $('#stat-total-pago').text('R$ ' + (parseFloat(stats.total_pago || 0)).toFixed(2).replace('.', ','));
    }

    /**
     * Formata data
     */
    function formatDate(dateString) {
        if (!dateString) return '-';
        const date = new Date(dateString);
        return date.toLocaleDateString('pt-BR') + ' ' + date.toLocaleTimeString('pt-BR', {hour: '2-digit', minute: '2-digit'});
    }

    /**
     * Ver detalhes (função global para onclick)
     */
    window.viewDeductionDetails = function(deductionId) {
        // Implementar modal de detalhes
        alert('Detalhes do abatimento ID: ' + deductionId);
    };
});
</script>
