/**
 * SISTUR Stock Settings - JavaScript Module
 * 
 * Handles inline editing of units and CRUD operations for storage locations.
 * Uses vanilla JavaScript with Fetch API for real-time updates.
 * 
 * @package SISTUR
 * @since 2.1.0
 */

const SisturSettings = (function () {
    'use strict';

    /** @type {Array} */
    let unitsCache = [];
    /** @type {Array} */
    let locationsCache = [];

    /**
     * Initialize the module
     */
    function init() {
        loadUnits();
        loadLocations();
        bindEvents();
    }

    /**
     * Bind all event handlers
     */
    function bindEvents() {
        // Modal close handlers
        document.querySelectorAll('.sistur-modal-close, .sistur-modal-cancel').forEach(btn => {
            btn.addEventListener('click', function () {
                this.closest('.sistur-modal').style.display = 'none';
            });
        });

        document.querySelectorAll('.sistur-modal').forEach(modal => {
            modal.addEventListener('click', function (e) {
                if (e.target === this) this.style.display = 'none';
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.sistur-modal').forEach(m => m.style.display = 'none');
            }
        });

        // Unit modal
        document.getElementById('btn-add-unit')?.addEventListener('click', () => openUnitModal());
        document.getElementById('btn-save-unit')?.addEventListener('click', saveUnit);

        // Location modal
        document.getElementById('btn-add-location')?.addEventListener('click', () => openLocationModal());
        document.getElementById('btn-save-location')?.addEventListener('click', saveLocation);

        // Tab Switching
        document.querySelectorAll('.nav-tab').forEach(tab => {
            tab.addEventListener('click', (e) => {
                e.preventDefault();
                const target = tab.dataset.tab;
                switchTab(target);
            });
        });
    }

    /**
     * Switch between tabs
     * @param {string} tabName 
     */
    function switchTab(tabName) {
        // Update tabs
        document.querySelectorAll('.nav-tab').forEach(t => {
            t.classList.remove('nav-tab-active');
            if (t.dataset.tab === tabName) t.classList.add('nav-tab-active');
        });

        // Update content
        document.querySelectorAll('.sistur-tab-content').forEach(c => {
            c.classList.remove('active');
            c.style.display = 'none';
        });

        const activeContent = document.getElementById(`tab-${tabName}`);
        if (activeContent) {
            activeContent.style.display = 'block';
            // Small delay to trigger animation
            setTimeout(() => activeContent.classList.add('active'), 10);
        }
    }

    /**
     * API call helper
     * @param {string} endpoint 
     * @param {Object} options 
     * @returns {Promise<any>}
     */
    async function apiCall(endpoint, options = {}) {
        const url = SisturSettingsConfig.apiBase + endpoint;
        const defaultOptions = {
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': SisturSettingsConfig.nonce
            }
        };

        const response = await fetch(url, { ...defaultOptions, ...options });
        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.message || 'Erro na operação');
        }

        return data;
    }

    // ===================================================================
    // Units Management
    // ===================================================================

    /**
     * Load and render units
     */
    async function loadUnits() {
        const tbody = document.getElementById('units-tbody');

        try {
            const units = await apiCall('units');
            unitsCache = units;
            renderUnitsTable(units);
        } catch (error) {
            tbody.innerHTML = `<tr><td colspan="6" style="color: #d63638; text-align: center;">${error.message}</td></tr>`;
        }
    }

    /**
     * Render units table with editable cells
     * @param {Array} units 
     */
    function renderUnitsTable(units) {
        const tbody = document.getElementById('units-tbody');

        if (!units || units.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">Nenhuma unidade cadastrada.</td></tr>';
            return;
        }

        const typeLabels = {
            'dimensional': 'Dimensional',
            'unitary': 'Unitário'
        };

        tbody.innerHTML = units.map(unit => `
            <tr data-id="${unit.id}">
                <td>${unit.id}</td>
                <td>
                    <span class="editable-cell" data-field="name" data-id="${unit.id}">${escapeHtml(unit.name)}</span>
                </td>
                <td>
                    <span class="editable-cell" data-field="symbol" data-id="${unit.id}">${escapeHtml(unit.symbol)}</span>
                </td>
                <td>
                    <span class="type-label type-${unit.type}">${typeLabels[unit.type] || unit.type}</span>
                </td>
                <td>
                    ${unit.is_system == 1 ? '<span class="badge-system">Sistema</span>' : '-'}
                </td>
                <td>
                    ${unit.is_system != 1 ?
                `<button type="button" class="button button-small btn-delete-unit" data-id="${unit.id}" title="Excluir">
                            <span class="dashicons dashicons-trash" style="font-size: 14px; width: 14px; height: 14px;"></span>
                        </button>`
                : '-'}
                </td>
            </tr>
        `).join('');

        // Bind editable cells
        tbody.querySelectorAll('.editable-cell').forEach(cell => {
            cell.addEventListener('click', handleCellEdit);
        });

        // Bind delete buttons
        tbody.querySelectorAll('.btn-delete-unit').forEach(btn => {
            btn.addEventListener('click', () => deleteUnit(btn.dataset.id));
        });
    }

    /**
     * Handle inline cell editing
     * @param {Event} e 
     */
    function handleCellEdit(e) {
        const cell = e.currentTarget;
        if (cell.classList.contains('editing')) return;

        const field = cell.dataset.field;
        const id = cell.dataset.id;
        const currentValue = cell.textContent;

        cell.classList.add('editing');
        cell.innerHTML = `<input type="text" value="${escapeHtml(currentValue)}" data-original="${escapeHtml(currentValue)}">`;

        const input = cell.querySelector('input');
        input.focus();
        input.select();

        // Save on blur or Enter
        input.addEventListener('blur', () => finishCellEdit(cell, field, id));
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                input.blur();
            }
            if (e.key === 'Escape') {
                input.value = input.dataset.original;
                input.blur();
            }
        });
    }

    /**
     * Finish cell editing and save to API
     * @param {HTMLElement} cell 
     * @param {string} field 
     * @param {string} id 
     */
    async function finishCellEdit(cell, field, id) {
        const input = cell.querySelector('input');
        if (!input) return;

        const newValue = input.value.trim();
        const originalValue = input.dataset.original;

        cell.classList.remove('editing');
        cell.textContent = newValue || originalValue;

        if (newValue && newValue !== originalValue) {
            cell.classList.add('status-saving');

            try {
                await apiCall(`units/${id}`, {
                    method: 'PUT',
                    body: JSON.stringify({ [field]: newValue })
                });

                cell.classList.remove('status-saving');
                cell.classList.add('status-saved');
                setTimeout(() => cell.classList.remove('status-saved'), 500);

                showNotice('Unidade atualizada!', 'success');
            } catch (error) {
                cell.textContent = originalValue;
                cell.classList.remove('status-saving');
                showNotice(error.message, 'error');
            }
        }
    }

    /**
     * Open unit modal for adding new unit
     * @param {Object|null} unit 
     */
    function openUnitModal(unit = null) {
        const modal = document.getElementById('modal-unit');
        const form = document.getElementById('form-unit');
        const title = document.getElementById('modal-unit-title');

        form.reset();
        document.getElementById('unit-id').value = '';

        if (unit) {
            title.textContent = 'Editar Unidade';
            document.getElementById('unit-id').value = unit.id;
            document.getElementById('unit-name').value = unit.name || '';
            document.getElementById('unit-symbol').value = unit.symbol || '';
            document.getElementById('unit-type').value = unit.type || 'unitary';
        } else {
            title.textContent = 'Nova Unidade';
        }

        modal.style.display = 'flex';
        document.getElementById('unit-name').focus();
    }

    /**
     * Save unit from modal
     */
    async function saveUnit() {
        const form = document.getElementById('form-unit');
        const id = document.getElementById('unit-id').value;

        const data = {
            name: document.getElementById('unit-name').value.trim(),
            symbol: document.getElementById('unit-symbol').value.trim(),
            type: document.getElementById('unit-type').value
        };

        if (!data.name || !data.symbol) {
            showNotice('Nome e símbolo são obrigatórios', 'error');
            return;
        }

        const btn = document.getElementById('btn-save-unit');
        btn.disabled = true;
        btn.textContent = 'Salvando...';

        try {
            if (id) {
                await apiCall(`units/${id}`, { method: 'PUT', body: JSON.stringify(data) });
            } else {
                await apiCall('units', { method: 'POST', body: JSON.stringify(data) });
            }

            document.getElementById('modal-unit').style.display = 'none';
            loadUnits();
            showNotice('Unidade salva com sucesso!', 'success');
        } catch (error) {
            showNotice(error.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Salvar';
        }
    }

    /**
     * Delete unit
     * @param {string} id 
     */
    async function deleteUnit(id) {
        if (!confirm(SisturSettingsConfig.i18n.confirmDelete)) return;

        try {
            await apiCall(`units/${id}`, { method: 'DELETE' });
            loadUnits();
            showNotice('Unidade excluída!', 'success');
        } catch (error) {
            showNotice(error.message, 'error');
        }
    }

    // ===================================================================
    // Locations Management
    // ===================================================================

    /**
     * Load and render locations
     */
    async function loadLocations() {
        const tbody = document.getElementById('locations-tbody');

        try {
            const locations = await apiCall('locations');
            locationsCache = locations;
            renderLocationsTable(locations);
        } catch (error) {
            tbody.innerHTML = `<tr><td colspan="5" style="color: #d63638; text-align: center;">${error.message}</td></tr>`;
        }
    }

    /**
     * Render locations table
     * @param {Array} locations 
     */
    function renderLocationsTable(locations) {
        const tbody = document.getElementById('locations-tbody');

        if (!locations || locations.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align: center;">Nenhum local cadastrado.</td></tr>';
            return;
        }

        const typeEmojis = {
            'warehouse': '🏪',
            'refrigerator': '❄️',
            'freezer': '🧊',
            'shelf': '📦',
            'other': '📍'
        };

        const typeLabels = {
            'warehouse': 'Depósito',
            'refrigerator': 'Refrigerador',
            'freezer': 'Freezer',
            'shelf': 'Prateleira',
            'other': 'Outro'
        };

        tbody.innerHTML = locations.map(loc => `
            <tr data-id="${loc.id}">
                <td>${loc.id}</td>
                <td>
                    <span class="editable-cell" data-field="name" data-id="${loc.id}" data-type="location">${escapeHtml(loc.name)}</span>
                </td>
                <td><code>${escapeHtml(loc.code)}</code></td>
                <td>
                    <span class="type-label type-${loc.location_type}">
                        ${typeEmojis[loc.location_type] || ''} ${typeLabels[loc.location_type] || loc.location_type}
                    </span>
                </td>
                <td>
                    <button type="button" class="button button-small btn-edit-location" data-id="${loc.id}" title="Editar">
                        <span class="dashicons dashicons-edit" style="font-size: 14px; width: 14px; height: 14px;"></span>
                    </button>
                    <button type="button" class="button button-small btn-delete-location" data-id="${loc.id}" title="Excluir">
                        <span class="dashicons dashicons-trash" style="font-size: 14px; width: 14px; height: 14px;"></span>
                    </button>
                </td>
            </tr>
        `).join('');

        // Bind editable cells
        tbody.querySelectorAll('.editable-cell[data-type="location"]').forEach(cell => {
            cell.addEventListener('click', handleLocationCellEdit);
        });

        // Bind edit buttons
        tbody.querySelectorAll('.btn-edit-location').forEach(btn => {
            btn.addEventListener('click', () => {
                const loc = locationsCache.find(l => l.id == btn.dataset.id);
                if (loc) openLocationModal(loc);
            });
        });

        // Bind delete buttons
        tbody.querySelectorAll('.btn-delete-location').forEach(btn => {
            btn.addEventListener('click', () => deleteLocation(btn.dataset.id));
        });
    }

    /**
     * Handle inline cell editing for locations
     * @param {Event} e 
     */
    function handleLocationCellEdit(e) {
        const cell = e.currentTarget;
        if (cell.classList.contains('editing')) return;

        const field = cell.dataset.field;
        const id = cell.dataset.id;
        const currentValue = cell.textContent;

        cell.classList.add('editing');
        cell.innerHTML = `<input type="text" value="${escapeHtml(currentValue)}" data-original="${escapeHtml(currentValue)}">`;

        const input = cell.querySelector('input');
        input.focus();
        input.select();

        input.addEventListener('blur', () => finishLocationCellEdit(cell, field, id));
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
            if (e.key === 'Escape') { input.value = input.dataset.original; input.blur(); }
        });
    }

    /**
     * Finish location cell editing
     * @param {HTMLElement} cell 
     * @param {string} field 
     * @param {string} id 
     */
    async function finishLocationCellEdit(cell, field, id) {
        const input = cell.querySelector('input');
        if (!input) return;

        const newValue = input.value.trim();
        const originalValue = input.dataset.original;

        cell.classList.remove('editing');
        cell.textContent = newValue || originalValue;

        if (newValue && newValue !== originalValue) {
            cell.classList.add('status-saving');

            try {
                await apiCall(`locations/${id}`, {
                    method: 'PUT',
                    body: JSON.stringify({ [field]: newValue })
                });

                cell.classList.remove('status-saving');
                cell.classList.add('status-saved');
                setTimeout(() => cell.classList.remove('status-saved'), 500);

                showNotice('Local atualizado!', 'success');
            } catch (error) {
                cell.textContent = originalValue;
                cell.classList.remove('status-saving');
                showNotice(error.message, 'error');
            }
        }
    }

    /**
     * Open location modal
     * @param {Object|null} location 
     */
    function openLocationModal(location = null) {
        const modal = document.getElementById('modal-location');
        const form = document.getElementById('form-location');
        const title = document.getElementById('modal-location-title');

        form.reset();
        document.getElementById('location-id').value = '';

        if (location) {
            title.textContent = 'Editar Local';
            document.getElementById('location-id').value = location.id;
            document.getElementById('location-name').value = location.name || '';
            document.getElementById('location-code').value = location.code || '';
            document.getElementById('location-type').value = location.location_type || 'warehouse';
            document.getElementById('location-description').value = location.description || '';
        } else {
            title.textContent = 'Novo Local';
        }

        modal.style.display = 'flex';
        document.getElementById('location-name').focus();
    }

    /**
     * Save location from modal
     */
    async function saveLocation() {
        const id = document.getElementById('location-id').value;

        const data = {
            name: document.getElementById('location-name').value.trim(),
            code: document.getElementById('location-code').value.trim(),
            location_type: document.getElementById('location-type').value,
            description: document.getElementById('location-description').value.trim()
        };

        if (!data.name) {
            showNotice('Nome é obrigatório', 'error');
            return;
        }

        const btn = document.getElementById('btn-save-location');
        btn.disabled = true;
        btn.textContent = 'Salvando...';

        try {
            if (id) {
                await apiCall(`locations/${id}`, { method: 'PUT', body: JSON.stringify(data) });
            } else {
                await apiCall('locations', { method: 'POST', body: JSON.stringify(data) });
            }

            document.getElementById('modal-location').style.display = 'none';
            loadLocations();
            showNotice('Local salvo com sucesso!', 'success');
        } catch (error) {
            showNotice(error.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Salvar';
        }
    }

    /**
     * Delete location
     * @param {string} id 
     */
    async function deleteLocation(id) {
        if (!confirm(SisturSettingsConfig.i18n.confirmDelete)) return;

        try {
            await apiCall(`locations/${id}`, { method: 'DELETE' });
            loadLocations();
            showNotice('Local excluído!', 'success');
        } catch (error) {
            showNotice(error.message, 'error');
        }
    }

    // ===================================================================
    // Utilities
    // ===================================================================

    /**
     * Show notification
     * @param {string} message 
     * @param {string} type 
     */
    function showNotice(message, type = 'success') {
        if (typeof sisturToast !== 'undefined') {
            sisturToast.show(message, type);
            return;
        }

        const wrap = document.querySelector('.wrap');
        const notice = document.createElement('div');
        notice.className = `notice notice-${type} is-dismissible`;
        notice.innerHTML = `<p>${message}</p><button type="button" class="notice-dismiss"></button>`;

        document.querySelectorAll('.wrap > .notice').forEach(n => n.remove());
        wrap.insertBefore(notice, wrap.querySelector('hr.wp-header-end').nextSibling);

        setTimeout(() => notice.remove(), 4000);
        notice.querySelector('.notice-dismiss')?.addEventListener('click', () => notice.remove());
    }

    /**
     * Escape HTML
     * @param {string} text 
     * @returns {string}
     */
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Public API
    return {
        init,
        loadUnits,
        loadLocations
    };
})();

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', SisturSettings.init);
