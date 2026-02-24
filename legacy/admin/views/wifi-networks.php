<?php
/**
 * View de gerenciamento de redes Wi-Fi autorizadas
 *
 * @package SISTUR
 */

// Prevenir acesso direto
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap sistur-wrap">
    <h1 class="wp-heading-inline">
        <i class="dashicons dashicons-wifi"></i>
        Redes Wi-Fi Autorizadas
    </h1>
    <button type="button" class="page-title-action" id="btn-add-network">
        Adicionar Nova Rede
    </button>
    <hr class="wp-header-end">

    <div class="sistur-card">
        <div class="card-header">
            <h2>Redes Wi-Fi Cadastradas</h2>
            <p class="description">
                Configure as redes Wi-Fi autorizadas para registro de ponto. Os funcionários só poderão registrar
                o ponto quando conectados a uma dessas redes.
            </p>
        </div>

        <div class="card-body">
            <div id="wifi-networks-loading" class="loading-state">
                <span class="spinner is-active"></span>
                <p>Carregando redes...</p>
            </div>

            <div id="wifi-networks-empty" class="empty-state" style="display: none;">
                <div class="empty-icon">
                    <i class="dashicons dashicons-wifi"></i>
                </div>
                <h3>Nenhuma rede cadastrada</h3>
                <p>Adicione redes Wi-Fi autorizadas para controlar onde os funcionários podem registrar ponto.</p>
                <button type="button" class="button button-primary" id="btn-add-network-empty">
                    Adicionar Primeira Rede
                </button>
            </div>

            <table id="wifi-networks-table" class="wp-list-table widefat fixed striped" style="display: none;">
                <thead>
                    <tr>
                        <th class="column-name">Nome</th>
                        <th class="column-ssid">SSID</th>
                        <th class="column-bssid">BSSID (MAC)</th>
                        <th class="column-description">Descrição</th>
                        <th class="column-status">Status</th>
                        <th class="column-actions">Ações</th>
                    </tr>
                </thead>
                <tbody id="wifi-networks-tbody">
                    <!-- Preenchido via JavaScript -->
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal para adicionar/editar rede -->
    <div id="wifi-network-modal" class="sistur-modal" style="display: none;">
        <div class="sistur-modal-content">
            <div class="sistur-modal-header">
                <h2 id="modal-title">Adicionar Rede Wi-Fi</h2>
                <button type="button" class="sistur-modal-close" id="close-modal">
                    <span class="dashicons dashicons-no"></span>
                </button>
            </div>

            <div class="sistur-modal-body">
                <form id="wifi-network-form">
                    <input type="hidden" id="network-id" name="id" value="">

                    <div class="form-group">
                        <label for="network-name">
                            Nome da Rede <span class="required">*</span>
                        </label>
                        <input
                            type="text"
                            id="network-name"
                            name="network_name"
                            class="regular-text"
                            placeholder="Ex: Wi-Fi Escritório Principal"
                            required
                        >
                        <p class="description">Nome identificador para esta rede (apenas para identificação interna)</p>
                    </div>

                    <div class="form-group">
                        <label for="network-ssid">
                            SSID (Nome da Rede Wi-Fi) <span class="required">*</span>
                        </label>
                        <input
                            type="text"
                            id="network-ssid"
                            name="network_ssid"
                            class="regular-text"
                            placeholder="Ex: MinhRede-WiFi"
                            required
                        >
                        <p class="description">
                            Nome exato da rede Wi-Fi conforme aparece nos dispositivos.
                            <strong>Atenção:</strong> É case-sensitive (diferencia maiúsculas e minúsculas).
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="network-bssid">
                            BSSID (Endereço MAC do Roteador)
                        </label>
                        <input
                            type="text"
                            id="network-bssid"
                            name="network_bssid"
                            class="regular-text"
                            placeholder="Ex: AA:BB:CC:DD:EE:FF"
                            pattern="[0-9A-Fa-f:]{17}"
                        >
                        <p class="description">
                            Opcional. Endereço MAC do roteador para maior segurança. Formato: AA:BB:CC:DD:EE:FF
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="network-description">
                            Descrição
                        </label>
                        <textarea
                            id="network-description"
                            name="description"
                            class="large-text"
                            rows="3"
                            placeholder="Ex: Rede do escritório principal na Rua ABC, 123"
                        ></textarea>
                        <p class="description">Informações adicionais sobre a localização ou uso desta rede</p>
                    </div>

                    <div class="form-group">
                        <label for="network-status">
                            <input
                                type="checkbox"
                                id="network-status"
                                name="status"
                                value="1"
                                checked
                            >
                            Rede ativa
                        </label>
                        <p class="description">Desmarque para desativar temporariamente esta rede</p>
                    </div>
                </form>
            </div>

            <div class="sistur-modal-footer">
                <button type="button" class="button" id="cancel-modal">Cancelar</button>
                <button type="button" class="button button-primary" id="save-network">Salvar Rede</button>
            </div>
        </div>
    </div>
</div>

<style>
.sistur-wrap {
    margin: 20px 20px 0 0;
}

.sistur-card {
    background: #fff;
    border: 1px solid #c3c4c7;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    margin-top: 20px;
}

.card-header {
    padding: 20px;
    border-bottom: 1px solid #c3c4c7;
}

.card-header h2 {
    margin: 0 0 8px 0;
    font-size: 18px;
}

.card-header .description {
    margin: 0;
    color: #646970;
}

.card-body {
    padding: 20px;
}

.loading-state {
    text-align: center;
    padding: 40px 20px;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
}

.empty-icon {
    font-size: 64px;
    color: #c3c4c7;
    margin-bottom: 20px;
}

.empty-icon .dashicons {
    width: 64px;
    height: 64px;
    font-size: 64px;
}

.empty-state h3 {
    margin: 0 0 10px 0;
    color: #1d2327;
}

.empty-state p {
    margin: 0 0 20px 0;
    color: #646970;
}

/* Modal */
.sistur-modal {
    position: fixed;
    z-index: 100000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
}

.sistur-modal-content {
    background-color: #fff;
    border-radius: 4px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.3);
    width: 90%;
    max-width: 600px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
}

.sistur-modal-header {
    padding: 20px;
    border-bottom: 1px solid #dcdcde;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.sistur-modal-header h2 {
    margin: 0;
    font-size: 18px;
}

.sistur-modal-close {
    background: none;
    border: none;
    cursor: pointer;
    padding: 4px;
    color: #646970;
}

.sistur-modal-close:hover {
    color: #d63638;
}

.sistur-modal-body {
    padding: 20px;
    overflow-y: auto;
}

.sistur-modal-footer {
    padding: 20px;
    border-top: 1px solid #dcdcde;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
}

.form-group .required {
    color: #d63638;
}

.form-group .description {
    margin: 5px 0 0 0;
    color: #646970;
    font-size: 13px;
}

.column-name {
    width: 20%;
}

.column-ssid {
    width: 20%;
}

.column-bssid {
    width: 18%;
}

.column-description {
    width: 25%;
}

.column-status {
    width: 10%;
}

.column-actions {
    width: 12%;
}

.status-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 500;
}

.status-badge.active {
    background-color: #d4edda;
    color: #155724;
}

.status-badge.inactive {
    background-color: #f8d7da;
    color: #721c24;
}

.action-buttons {
    display: flex;
    gap: 5px;
}

.btn-edit, .btn-delete {
    padding: 4px 8px;
    cursor: pointer;
    border: none;
    border-radius: 3px;
    font-size: 13px;
}

.btn-edit {
    background-color: #2271b1;
    color: #fff;
}

.btn-edit:hover {
    background-color: #135e96;
}

.btn-delete {
    background-color: #d63638;
    color: #fff;
}

.btn-delete:hover {
    background-color: #b32d2e;
}
</style>

<script>
jQuery(document).ready(function($) {
    let networks = [];
    const nonce = '<?php echo wp_create_nonce('sistur_wifi_nonce'); ?>';

    // Carregar redes
    function loadNetworks() {
        $('#wifi-networks-loading').show();
        $('#wifi-networks-empty').hide();
        $('#wifi-networks-table').hide();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_get_wifi_networks',
                nonce: nonce
            },
            success: function(response) {
                $('#wifi-networks-loading').hide();

                if (response.success) {
                    networks = response.data.networks;

                    if (networks.length === 0) {
                        $('#wifi-networks-empty').show();
                    } else {
                        renderNetworks();
                        $('#wifi-networks-table').show();
                    }
                } else {
                    alert('Erro ao carregar redes: ' + response.data.message);
                }
            },
            error: function() {
                $('#wifi-networks-loading').hide();
                alert('Erro de comunicação com o servidor');
            }
        });
    }

    // Renderizar tabela de redes
    function renderNetworks() {
        const tbody = $('#wifi-networks-tbody');
        tbody.empty();

        networks.forEach(function(network) {
            const statusBadge = network.status === '1'
                ? '<span class="status-badge active">Ativa</span>'
                : '<span class="status-badge inactive">Inativa</span>';

            const row = `
                <tr data-id="${network.id}">
                    <td><strong>${escapeHtml(network.network_name)}</strong></td>
                    <td><code>${escapeHtml(network.network_ssid)}</code></td>
                    <td>${network.network_bssid ? '<code>' + escapeHtml(network.network_bssid) + '</code>' : '—'}</td>
                    <td>${network.description ? escapeHtml(network.description) : '—'}</td>
                    <td>${statusBadge}</td>
                    <td class="action-buttons">
                        <button class="btn-edit" data-id="${network.id}">Editar</button>
                        <button class="btn-delete" data-id="${network.id}">Excluir</button>
                    </td>
                </tr>
            `;
            tbody.append(row);
        });
    }

    // Escape HTML
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }

    // Abrir modal para adicionar
    $('#btn-add-network, #btn-add-network-empty').on('click', function() {
        $('#modal-title').text('Adicionar Rede Wi-Fi');
        $('#wifi-network-form')[0].reset();
        $('#network-id').val('');
        $('#network-status').prop('checked', true);
        $('#wifi-network-modal').fadeIn();
    });

    // Abrir modal para editar
    $(document).on('click', '.btn-edit', function() {
        const networkId = $(this).data('id');
        const network = networks.find(n => n.id == networkId);

        if (network) {
            $('#modal-title').text('Editar Rede Wi-Fi');
            $('#network-id').val(network.id);
            $('#network-name').val(network.network_name);
            $('#network-ssid').val(network.network_ssid);
            $('#network-bssid').val(network.network_bssid || '');
            $('#network-description').val(network.description || '');
            $('#network-status').prop('checked', network.status === '1');
            $('#wifi-network-modal').fadeIn();
        }
    });

    // Fechar modal
    $('#close-modal, #cancel-modal').on('click', function() {
        $('#wifi-network-modal').fadeOut();
    });

    // Salvar rede
    $('#save-network').on('click', function() {
        const formData = {
            action: 'sistur_save_wifi_network',
            nonce: nonce,
            id: $('#network-id').val(),
            network_name: $('#network-name').val(),
            network_ssid: $('#network-ssid').val(),
            network_bssid: $('#network-bssid').val(),
            description: $('#network-description').val(),
            status: $('#network-status').is(':checked') ? 1 : 0
        };

        if (!formData.network_name || !formData.network_ssid) {
            alert('Nome e SSID são obrigatórios');
            return;
        }

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    $('#wifi-network-modal').fadeOut();
                    loadNetworks();
                } else {
                    alert('Erro: ' + response.data.message);
                }
            },
            error: function() {
                alert('Erro de comunicação com o servidor');
            }
        });
    });

    // Deletar rede
    $(document).on('click', '.btn-delete', function() {
        if (!confirm('Tem certeza que deseja excluir esta rede Wi-Fi?')) {
            return;
        }

        const networkId = $(this).data('id');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_delete_wifi_network',
                nonce: nonce,
                id: networkId
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    loadNetworks();
                } else {
                    alert('Erro: ' + response.data.message);
                }
            },
            error: function() {
                alert('Erro de comunicação com o servidor');
            }
        });
    });

    // Carregar redes ao iniciar
    loadNetworks();
});
</script>
