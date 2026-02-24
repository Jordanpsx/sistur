<?php
/**
 * View de gestão de funções/cargos
 *
 * @package SISTUR
 * @deprecated Esta página foi substituída pela interface integrada em Departamentos
 *
 * ATENÇÃO: Esta view não é mais usada diretamente. O gerenciamento de funções
 * foi integrado na página de Departamentos para melhor organização e contexto visual.
 *
 * Se você chegou aqui diretamente, será redirecionado para a página de Departamentos.
 */

// Prevenir acesso direto
if (!defined('ABSPATH')) {
    exit;
}

// Redirecionar para a nova página integrada
wp_redirect(admin_url('admin.php?page=sistur-departments'));
exit;

// O código abaixo é mantido apenas para referência histórica
// ====================================================================

$permissions_manager = SISTUR_Permissions::get_instance();
?>

<div class="wrap sistur-wrap">
    <h1 class="wp-heading-inline">
        <i class="dashicons dashicons-id"></i>
        Funções/Cargos
    </h1>
    <button type="button" class="page-title-action" id="btn-add-role">
        Adicionar Nova Função
    </button>
    <hr class="wp-header-end">

    <div class="sistur-card">
        <div class="card-header">
            <h2>Funções e Cargos</h2>
            <p class="description">
                Gerencie as funções/cargos dos funcionários. Você pode vincular funções a departamentos específicos
                e definir permissões para cada função.
            </p>
        </div>

        <div class="card-body">
            <table class="wp-list-table widefat fixed striped" id="roles-table">
                <thead>
                    <tr>
                        <th style="width: 5%;">ID</th>
                        <th style="width: 25%;">Nome</th>
                        <th style="width: 30%;">Descrição</th>
                        <th style="width: 20%;">Departamento</th>
                        <th style="width: 10%;">Admin</th>
                        <th style="width: 10%;">Ações</th>
                    </tr>
                </thead>
                <tbody id="roles-list">
                    <tr>
                        <td colspan="6" style="text-align: center;">
                            <span class="spinner is-active" style="float: none;"></span>
                            Carregando funções...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal para adicionar/editar função -->
<div id="role-modal" class="sistur-modal" style="display: none;">
    <div class="sistur-modal-content">
        <div class="sistur-modal-header">
            <h2 id="modal-title">Adicionar Função</h2>
            <span class="sistur-modal-close">&times;</span>
        </div>
        <div class="sistur-modal-body">
            <form id="role-form">
                <input type="hidden" id="role-id" name="id">

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="role-name">Nome da Função <span class="required">*</span></label></th>
                        <td>
                            <input type="text" id="role-name" name="name" class="regular-text" required>
                            <p class="description">Ex: Gerente de Operações, Recepcionista, etc.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="role-description">Descrição</label></th>
                        <td>
                            <textarea id="role-description" name="description" class="large-text" rows="3"></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="role-department">Departamento</label></th>
                        <td>
                            <select id="role-department" name="department_id">
                                <option value="">-- Todas as áreas --</option>
                            </select>
                            <p class="description">Vincule esta função a um departamento específico (opcional)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="role-is-admin">Super Administrador?</label></th>
                        <td>
                            <input type="checkbox" id="role-is-admin" name="is_admin" value="1">
                            <label for="role-is-admin">Esta função tem acesso total ao sistema</label>
                        </td>
                    </tr>
                </table>

                <div id="permissions-section" style="margin-top: 20px;">
                    <h3>Permissões</h3>
                    <p class="description">Selecione as permissões que esta função terá no sistema</p>
                    <div id="permissions-list" style="margin-top: 15px;">
                        <p style="text-align: center;">
                            <span class="spinner is-active" style="float: none;"></span>
                            Carregando permissões...
                        </p>
                    </div>
                </div>
            </form>
        </div>
        <div class="sistur-modal-footer">
            <button type="button" class="button button-secondary" id="btn-cancel-role">Cancelar</button>
            <button type="button" class="button button-primary" id="btn-save-role">Salvar</button>
        </div>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    const modal = $('#role-modal');
    const form = $('#role-form');
    const table = $('#roles-list');
    let departments = [];
    let allPermissions = [];

    // Carregar departamentos
    function loadDepartments() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'get_all_departments',
                nonce: '<?php echo wp_create_nonce('sistur_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    departments = response.data;
                    renderDepartmentOptions();
                }
            }
        });
    }

    // Renderizar opções de departamento
    function renderDepartmentOptions() {
        let options = '<option value="">-- Todas as áreas --</option>';
        departments.forEach(function(dept) {
            options += '<option value="' + dept.id + '">' + dept.name + '</option>';
        });
        $('#role-department').html(options);
    }

    // Carregar permissões
    function loadPermissions() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_get_permissions',
                nonce: '<?php echo wp_create_nonce('sistur_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    allPermissions = response.data;
                    renderPermissions();
                }
            }
        });
    }

    // Renderizar lista de permissões
    function renderPermissions(selectedPermissions = []) {
        if (!allPermissions || Object.keys(allPermissions).length === 0) {
            $('#permissions-list').html('<p>Nenhuma permissão disponível</p>');
            return;
        }

        let html = '';
        for (let module in allPermissions) {
            html += '<div class="permission-module" style="margin-bottom: 20px;">';
            html += '<h4 style="margin-bottom: 10px; border-bottom: 1px solid #ddd; padding-bottom: 5px;">' + module.charAt(0).toUpperCase() + module.slice(1) + '</h4>';
            html += '<div style="margin-left: 15px;">';

            allPermissions[module].forEach(function(perm) {
                const isChecked = selectedPermissions.includes(perm.id) ? 'checked' : '';
                html += '<label style="display: block; margin-bottom: 5px;">';
                html += '<input type="checkbox" name="permissions[]" value="' + perm.id + '" ' + isChecked + '> ';
                html += perm.label;
                if (perm.description) {
                    html += ' <span style="color: #666; font-size: 12px;">(' + perm.description + ')</span>';
                }
                html += '</label>';
            });

            html += '</div>';
            html += '</div>';
        }

        $('#permissions-list').html(html);
    }

    // Carregar funções/roles
    function loadRoles() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_get_roles',
                nonce: '<?php echo wp_create_nonce('sistur_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    renderRoles(response.data);
                } else {
                    table.html('<tr><td colspan="6" style="text-align: center;">Erro ao carregar funções</td></tr>');
                }
            }
        });
    }

    // Renderizar lista de funções
    function renderRoles(roles) {
        if (roles.length === 0) {
            table.html('<tr><td colspan="6" style="text-align: center;">Nenhuma função cadastrada</td></tr>');
            return;
        }

        let html = '';
        roles.forEach(function(role) {
            const dept = departments.find(d => d.id == role.department_id);
            const deptName = dept ? dept.name : '-';

            html += '<tr>';
            html += '<td>' + role.id + '</td>';
            html += '<td><strong>' + role.name + '</strong></td>';
            html += '<td>' + (role.description || '-') + '</td>';
            html += '<td>' + deptName + '</td>';
            html += '<td>' + (role.is_admin == '1' ? '<span class="badge badge-success">Sim</span>' : '<span class="badge badge-inactive">Não</span>') + '</td>';
            html += '<td>';
            html += '<button class="button button-small btn-edit-role" data-id="' + role.id + '">Editar</button> ';

            // Não permitir excluir se for admin ou se tiver funcionários vinculados
            if (role.is_admin != '1') {
                html += '<button class="button button-small btn-delete-role" data-id="' + role.id + '">Excluir</button>';
            }
            html += '</td>';
            html += '</tr>';
        });

        table.html(html);
    }

    // Abrir modal para adicionar
    $('#btn-add-role').on('click', function() {
        form[0].reset();
        $('#role-id').val('');
        $('#modal-title').text('Adicionar Função');
        renderPermissions([]);
        modal.show();
    });

    // Abrir modal para editar
    $(document).on('click', '.btn-edit-role', function() {
        const id = $(this).data('id');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_get_role',
                id: id,
                nonce: '<?php echo wp_create_nonce('sistur_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    const role = response.data;
                    $('#role-id').val(role.id);
                    $('#role-name').val(role.name);
                    $('#role-description').val(role.description);
                    $('#role-department').val(role.department_id || '');
                    $('#role-is-admin').prop('checked', role.is_admin == '1');
                    $('#modal-title').text('Editar Função');

                    // Carregar permissões da função
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'sistur_get_role_permissions',
                            role_id: id,
                            nonce: '<?php echo wp_create_nonce('sistur_nonce'); ?>'
                        },
                        success: function(permResponse) {
                            if (permResponse.success) {
                                renderPermissions(permResponse.data);
                            }
                        }
                    });

                    modal.show();
                }
            }
        });
    });

    // Controlar visibilidade de permissões baseado em is_admin
    $('#role-is-admin').on('change', function() {
        if ($(this).is(':checked')) {
            $('#permissions-section').hide();
        } else {
            $('#permissions-section').show();
        }
    });

    // Salvar função
    $('#btn-save-role').on('click', function() {
        const formData = new FormData(form[0]);
        formData.append('action', 'sistur_save_role');
        formData.append('nonce', '<?php echo wp_create_nonce('sistur_nonce'); ?>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    modal.hide();
                    loadRoles();
                    alert('Função salva com sucesso!');
                } else {
                    alert('Erro ao salvar função: ' + response.data);
                }
            }
        });
    });

    // Excluir função
    $(document).on('click', '.btn-delete-role', function() {
        if (!confirm('Tem certeza que deseja excluir esta função?')) {
            return;
        }

        const id = $(this).data('id');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_delete_role',
                id: id,
                nonce: '<?php echo wp_create_nonce('sistur_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    loadRoles();
                    alert('Função excluída com sucesso!');
                } else {
                    alert('Erro ao excluir: ' + response.data);
                }
            }
        });
    });

    // Fechar modal
    $('.sistur-modal-close, #btn-cancel-role').on('click', function() {
        modal.hide();
    });

    // Carregar dados ao iniciar
    loadDepartments();
    loadPermissions();
    loadRoles();
});
</script>

<style>
.sistur-modal {
    position: fixed;
    z-index: 100000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0,0,0,0.4);
}

.sistur-modal-content {
    background-color: #fefefe;
    margin: 3% auto;
    padding: 0;
    border: 1px solid #888;
    width: 70%;
    max-width: 900px;
    box-shadow: 0 4px 8px 0 rgba(0,0,0,0.2);
}

.sistur-modal-header {
    padding: 20px;
    background-color: #0073aa;
    color: white;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.sistur-modal-header h2 {
    margin: 0;
    color: white;
}

.sistur-modal-close {
    color: white;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
}

.sistur-modal-close:hover,
.sistur-modal-close:focus {
    color: #ddd;
}

.sistur-modal-body {
    padding: 20px;
    max-height: 70vh;
    overflow-y: auto;
}

.sistur-modal-footer {
    padding: 15px 20px;
    background-color: #f5f5f5;
    text-align: right;
    border-top: 1px solid #ddd;
}

.badge {
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: bold;
}

.badge-success {
    background-color: #46b450;
    color: white;
}

.badge-inactive {
    background-color: #999;
    color: white;
}

.required {
    color: red;
}

.permission-module {
    background-color: #f9f9f9;
    padding: 15px;
    border-radius: 4px;
}
</style>
