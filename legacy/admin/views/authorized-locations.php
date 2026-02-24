<?php
/**
 * View de gerenciamento de localizações autorizadas
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
        <i class="dashicons dashicons-location"></i>
        Localizações Autorizadas (GPS)
    </h1>
    <button type="button" class="page-title-action" id="btn-add-location">
        Adicionar Nova Localização
    </button>
    <hr class="wp-header-end">

    <div class="sistur-card">
        <div class="card-header">
            <h2>Localizações Cadastradas</h2>
            <p class="description">
                Configure as localizações autorizadas para registro de ponto por GPS. Os funcionários só poderão registrar
                o ponto quando estiverem dentro do raio permitido de uma dessas localizações.
            </p>
        </div>

        <div class="card-body">
            <div id="locations-loading" class="loading-state">
                <span class="spinner is-active"></span>
                <p>Carregando localizações...</p>
            </div>

            <div id="locations-empty" class="empty-state" style="display: none;">
                <div class="empty-icon">
                    <i class="dashicons dashicons-location"></i>
                </div>
                <h3>Nenhuma localização cadastrada</h3>
                <p>Adicione localizações autorizadas para validação por GPS no registro de ponto.</p>
                <button type="button" class="button button-primary" id="btn-add-location-empty">
                    Adicionar Primeira Localização
                </button>
            </div>

            <table id="locations-table" class="wp-list-table widefat fixed striped" style="display: none;">
                <thead>
                    <tr>
                        <th class="column-name">Nome</th>
                        <th class="column-coords">Coordenadas</th>
                        <th class="column-radius">Raio (m)</th>
                        <th class="column-address">Endereço</th>
                        <th class="column-status">Status</th>
                        <th class="column-actions">Ações</th>
                    </tr>
                </thead>
                <tbody id="locations-tbody">
                    <!-- Preenchido via JavaScript -->
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal para adicionar/editar localização -->
    <div id="location-modal" class="sistur-modal" style="display: none;">
        <div class="sistur-modal-content">
            <div class="sistur-modal-header">
                <h2 id="modal-title">Adicionar Localização</h2>
                <button type="button" class="sistur-modal-close" id="close-modal">
                    <span class="dashicons dashicons-no"></span>
                </button>
            </div>

            <div class="sistur-modal-body">
                <form id="location-form">
                    <input type="hidden" id="location-id" name="id" value="">

                    <div class="form-group">
                        <label for="location-name">
                            Nome da Localização <span class="required">*</span>
                        </label>
                        <input
                            type="text"
                            id="location-name"
                            name="location_name"
                            class="regular-text"
                            placeholder="Ex: Escritório Principal"
                            required
                        >
                        <p class="description">Nome identificador para esta localização</p>
                    </div>

                    <div class="form-group">
                        <label for="location-latitude">
                            Latitude <span class="required">*</span>
                        </label>
                        <input
                            type="number"
                            id="location-latitude"
                            name="latitude"
                            class="regular-text"
                            placeholder="Ex: -23.5505199"
                            step="0.00000001"
                            min="-90"
                            max="90"
                            required
                        >
                        <p class="description">Latitude da localização autorizada (formato decimal)</p>
                    </div>

                    <div class="form-group">
                        <label for="location-longitude">
                            Longitude <span class="required">*</span>
                        </label>
                        <input
                            type="number"
                            id="location-longitude"
                            name="longitude"
                            class="regular-text"
                            placeholder="Ex: -46.6333094"
                            step="0.00000001"
                            min="-180"
                            max="180"
                            required
                        >
                        <p class="description">Longitude da localização autorizada (formato decimal)</p>
                    </div>

                    <div class="form-group">
                        <button type="button" class="button" id="btn-get-current-location">
                            📍 Obter Minha Localização Atual
                        </button>
                        <p class="description">Clique para preencher automaticamente com sua localização atual</p>
                    </div>

                    <div class="form-group">
                        <label for="location-radius">
                            Raio Permitido (metros) <span class="required">*</span>
                        </label>
                        <input
                            type="number"
                            id="location-radius"
                            name="radius_meters"
                            class="regular-text"
                            placeholder="100"
                            value="100"
                            min="1"
                            step="1"
                            required
                        >
                        <p class="description">
                            Raio em metros dentro do qual o funcionário pode registrar ponto. Padrão: 100 metros
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="location-address">
                            Endereço
                        </label>
                        <input
                            type="text"
                            id="location-address"
                            name="address"
                            class="regular-text"
                            placeholder="Ex: Rua ABC, 123 - Centro"
                        >
                        <p class="description">Endereço da localização (opcional)</p>
                    </div>

                    <div class="form-group">
                        <label for="location-description">
                            Descrição
                        </label>
                        <textarea
                            id="location-description"
                            name="description"
                            class="large-text"
                            rows="3"
                            placeholder="Ex: Escritório principal - Matriz"
                        ></textarea>
                        <p class="description">Informações adicionais sobre esta localização</p>
                    </div>

                    <div class="form-group">
                        <label for="location-status">
                            <input
                                type="checkbox"
                                id="location-status"
                                name="status"
                                value="1"
                                checked
                            >
                            Localização ativa
                        </label>
                        <p class="description">Desmarque para desativar temporariamente esta localização</p>
                    </div>
                </form>
            </div>

            <div class="sistur-modal-footer">
                <button type="button" class="button" id="cancel-modal">Cancelar</button>
                <button type="button" class="button button-primary" id="save-location">Salvar Localização</button>
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

.column-coords {
    width: 25%;
}

.column-radius {
    width: 10%;
}

.column-address {
    width: 20%;
}

.column-status {
    width: 10%;
}

.column-actions {
    width: 15%;
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
    let locations = [];
    const nonce = '<?php echo wp_create_nonce('sistur_location_nonce'); ?>';

    // Carregar localizações
    function loadLocations() {
        $('#locations-loading').show();
        $('#locations-empty').hide();
        $('#locations-table').hide();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_get_locations',
                nonce: nonce
            },
            success: function(response) {
                $('#locations-loading').hide();

                if (response.success) {
                    locations = response.data.locations;

                    if (locations.length === 0) {
                        $('#locations-empty').show();
                    } else {
                        renderLocations();
                        $('#locations-table').show();
                    }
                } else {
                    alert('Erro ao carregar localizações: ' + response.data.message);
                }
            },
            error: function() {
                $('#locations-loading').hide();
                alert('Erro de comunicação com o servidor');
            }
        });
    }

    // Renderizar tabela de localizações
    function renderLocations() {
        const tbody = $('#locations-tbody');
        tbody.empty();

        locations.forEach(function(location) {
            const statusBadge = location.status === '1'
                ? '<span class="status-badge active">Ativa</span>'
                : '<span class="status-badge inactive">Inativa</span>';

            const coords = parseFloat(location.latitude).toFixed(6) + ', ' + parseFloat(location.longitude).toFixed(6);

            const row = `
                <tr data-id="${location.id}">
                    <td><strong>${escapeHtml(location.location_name)}</strong></td>
                    <td><code>${coords}</code></td>
                    <td>${location.radius_meters}m</td>
                    <td>${location.address ? escapeHtml(location.address) : '—'}</td>
                    <td>${statusBadge}</td>
                    <td class="action-buttons">
                        <button class="btn-edit" data-id="${location.id}">Editar</button>
                        <button class="btn-delete" data-id="${location.id}">Excluir</button>
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

    // Obter localização atual
    $('#btn-get-current-location').on('click', function() {
        const btn = $(this);
        const originalText = btn.text();

        if (!navigator.geolocation) {
            alert('Seu navegador não suporta geolocalização');
            return;
        }

        btn.prop('disabled', true).text('📍 Obtendo localização...');

        navigator.geolocation.getCurrentPosition(
            function(position) {
                $('#location-latitude').val(position.coords.latitude);
                $('#location-longitude').val(position.coords.longitude);
                btn.prop('disabled', false).text(originalText);
                alert('Localização obtida com sucesso!');
            },
            function(error) {
                btn.prop('disabled', false).text(originalText);
                let errorMsg = 'Erro ao obter localização';

                switch(error.code) {
                    case error.PERMISSION_DENIED:
                        errorMsg = 'Permissão de localização negada. Permita o acesso à localização nas configurações do navegador.';
                        break;
                    case error.POSITION_UNAVAILABLE:
                        errorMsg = 'Localização indisponível.';
                        break;
                    case error.TIMEOUT:
                        errorMsg = 'Tempo esgotado ao obter localização.';
                        break;
                }

                alert(errorMsg);
            },
            {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 0
            }
        );
    });

    // Abrir modal para adicionar
    $('#btn-add-location, #btn-add-location-empty').on('click', function() {
        $('#modal-title').text('Adicionar Localização');
        $('#location-form')[0].reset();
        $('#location-id').val('');
        $('#location-status').prop('checked', true);
        $('#location-radius').val('100');
        $('#location-modal').fadeIn();
    });

    // Abrir modal para editar
    $(document).on('click', '.btn-edit', function() {
        const locationId = $(this).data('id');
        const location = locations.find(l => l.id == locationId);

        if (location) {
            $('#modal-title').text('Editar Localização');
            $('#location-id').val(location.id);
            $('#location-name').val(location.location_name);
            $('#location-latitude').val(location.latitude || '');
            $('#location-longitude').val(location.longitude || '');
            $('#location-radius').val(location.radius_meters || '100');
            $('#location-address').val(location.address || '');
            $('#location-description').val(location.description || '');
            $('#location-status').prop('checked', location.status === '1');
            $('#location-modal').fadeIn();
        }
    });

    // Fechar modal
    $('#close-modal, #cancel-modal').on('click', function() {
        $('#location-modal').fadeOut();
    });

    // Salvar localização
    $('#save-location').on('click', function() {
        const formData = {
            action: 'sistur_save_location',
            nonce: nonce,
            id: $('#location-id').val(),
            location_name: $('#location-name').val(),
            latitude: $('#location-latitude').val(),
            longitude: $('#location-longitude').val(),
            radius_meters: $('#location-radius').val() || 100,
            address: $('#location-address').val(),
            description: $('#location-description').val(),
            status: $('#location-status').is(':checked') ? 1 : 0
        };

        if (!formData.location_name || !formData.latitude || !formData.longitude) {
            alert('Nome e coordenadas são obrigatórios');
            return;
        }

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    $('#location-modal').fadeOut();
                    loadLocations();
                } else {
                    alert('Erro: ' + response.data.message);
                }
            },
            error: function() {
                alert('Erro de comunicação com o servidor');
            }
        });
    });

    // Deletar localização
    $(document).on('click', '.btn-delete', function() {
        if (!confirm('Tem certeza que deseja excluir esta localização?')) {
            return;
        }

        const locationId = $(this).data('id');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_delete_location',
                nonce: nonce,
                id: locationId
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    loadLocations();
                } else {
                    alert('Erro: ' + response.data.message);
                }
            },
            error: function() {
                alert('Erro de comunicação com o servidor');
            }
        });
    });

    // Carregar localizações ao iniciar
    loadLocations();
});
</script>
