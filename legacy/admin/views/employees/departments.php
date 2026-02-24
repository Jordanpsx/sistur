<?php
/**
 * Página de Gerenciamento de Departamentos e Funções
 *
 * @package SISTUR
 */

if (!current_user_can('manage_options')) {
    wp_die(__('Você não tem permissão para acessar esta página.', 'sistur'));
}

$departments = SISTUR_Employees::get_all_departments();
$permissions = SISTUR_Permissions::get_instance();
$roles = $permissions->get_all_roles();

// Agrupar funções por departamento
$roles_by_department = array();
foreach ($roles as $role) {
    $dept_id = $role['department_id'] ?? 'unassigned';
    if (!isset($roles_by_department[$dept_id])) {
        $roles_by_department[$dept_id] = array();
    }
    $roles_by_department[$dept_id][] = $role;
}
?>

<style>
.sistur-departments-page {
    background: white;
    padding: 20px;
    margin-top: 20px;
    border-radius: 8px;
}

.department-card {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    margin-bottom: 20px;
    overflow: hidden;
}

.department-header {
    background: #f9fafb;
    padding: 15px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    transition: background 0.2s;
}

.department-header:hover {
    background: #f3f4f6;
}

.department-title {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1;
}

.department-title h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
}

.department-badge {
    background: #3b82f6;
    color: white;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
}

.department-actions {
    display: flex;
    gap: 8px;
}

.department-content {
    padding: 20px;
    display: none;
}

.department-content.active {
    display: block;
}

.department-description {
    color: #6b7280;
    margin-bottom: 15px;
}

.roles-section {
    margin-top: 15px;
}

.roles-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.roles-header h4 {
    margin: 0;
    font-size: 14px;
    font-weight: 600;
    color: #374151;
}

.role-item {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 12px 15px;
    margin-bottom: 8px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.role-info h5 {
    margin: 0 0 4px 0;
    font-size: 14px;
    font-weight: 600;
}

.role-info p {
    margin: 0;
    font-size: 12px;
    color: #6b7280;
}

.role-actions {
    display: flex;
    gap: 5px;
}

.admin-badge {
    background: #10b981;
    color: white;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 500;
    margin-left: 8px;
}

.empty-roles {
    text-align: center;
    padding: 30px;
    color: #9ca3af;
}

.sistur-modal {
    display: none;
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
    border-radius: 8px;
}

.sistur-modal-header {
    padding: 20px;
    background-color: #0073aa;
    color: white;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-radius: 8px 8px 0 0;
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

.sistur-modal-close:hover {
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
    border-radius: 0 0 8px 8px;
}

.permission-module {
    background-color: #f9f9f9;
    padding: 15px;
    border-radius: 4px;
    margin-bottom: 15px;
}
</style>

<div class="wrap">
    <h1>
        <?php _e('Departamentos e Funções', 'sistur'); ?>
        <button class="button button-primary" id="sistur-add-department">
            <?php _e('Adicionar Departamento', 'sistur'); ?>
        </button>
    </h1>

    <div class="sistur-departments-page">
        <?php if (!empty($departments)) : ?>
            <?php foreach ($departments as $dept) : ?>
                <?php
                $dept_roles = $roles_by_department[$dept['id']] ?? array();
                $roles_count = count($dept_roles);
                ?>
                <div class="department-card">
                    <div class="department-header" data-dept-id="<?php echo $dept['id']; ?>">
                        <div class="department-title">
                            <h3><?php echo esc_html($dept['name']); ?></h3>
                            <span class="department-badge"><?php echo $roles_count; ?> <?php echo $roles_count === 1 ? 'função' : 'funções'; ?></span>
                        </div>
                        <div class="department-actions">
                            <button class="button button-small sistur-edit-department" data-id="<?php echo $dept['id']; ?>" onclick="event.stopPropagation();">
                                <?php _e('Editar', 'sistur'); ?>
                            </button>
                            <button class="button button-small sistur-delete-department" data-id="<?php echo $dept['id']; ?>" onclick="event.stopPropagation();">
                                <?php _e('Excluir', 'sistur'); ?>
                            </button>
                        </div>
                    </div>

                    <div class="department-content" id="dept-content-<?php echo $dept['id']; ?>">
                        <?php if (!empty($dept['description'])) : ?>
                            <div class="department-description">
                                <strong>Descrição:</strong> <?php echo esc_html($dept['description']); ?>
                            </div>
                        <?php endif; ?>

                        <div class="roles-section">
                            <div class="roles-header">
                                <h4>Funções deste Departamento</h4>
                                <button class="button button-small button-primary sistur-add-role" data-dept-id="<?php echo $dept['id']; ?>">
                                    Adicionar Função
                                </button>
                            </div>

                            <?php if (!empty($dept_roles)) : ?>
                                <?php foreach ($dept_roles as $role) : ?>
                                    <div class="role-item">
                                        <div class="role-info">
                                            <h5>
                                                <?php echo esc_html($role['name']); ?>
                                                <?php if ($role['is_admin']) : ?>
                                                    <span class="admin-badge">Admin</span>
                                                <?php endif; ?>
                                            </h5>
                                            <?php if (!empty($role['description'])) : ?>
                                                <p><?php echo esc_html($role['description']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="role-actions">
                                            <button class="button button-small sistur-edit-role" data-id="<?php echo $role['id']; ?>">
                                                Editar
                                            </button>
                                            <?php if (!$role['is_admin']) : ?>
                                                <button class="button button-small sistur-delete-role" data-id="<?php echo $role['id']; ?>">
                                                    Excluir
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <div class="empty-roles">
                                    Nenhuma função cadastrada neste departamento
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php
            // Funções não atribuídas a nenhum departamento
            $unassigned_roles = $roles_by_department['unassigned'] ?? array();
            if (!empty($unassigned_roles)) :
            ?>
                <div class="department-card">
                    <div class="department-header" data-dept-id="unassigned">
                        <div class="department-title">
                            <h3>Funções Gerais (Sem Departamento)</h3>
                            <span class="department-badge"><?php echo count($unassigned_roles); ?> <?php echo count($unassigned_roles) === 1 ? 'função' : 'funções'; ?></span>
                        </div>
                        <div class="department-actions">
                            <button class="button button-small button-primary sistur-add-role" data-dept-id="">
                                Adicionar Função Geral
                            </button>
                        </div>
                    </div>

                    <div class="department-content" id="dept-content-unassigned">
                        <div class="roles-section">
                            <?php foreach ($unassigned_roles as $role) : ?>
                                <div class="role-item">
                                    <div class="role-info">
                                        <h5>
                                            <?php echo esc_html($role['name']); ?>
                                            <?php if ($role['is_admin']) : ?>
                                                <span class="admin-badge">Admin</span>
                                            <?php endif; ?>
                                        </h5>
                                        <?php if (!empty($role['description'])) : ?>
                                            <p><?php echo esc_html($role['description']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="role-actions">
                                        <button class="button button-small sistur-edit-role" data-id="<?php echo $role['id']; ?>">
                                            Editar
                                        </button>
                                        <?php if (!$role['is_admin']) : ?>
                                            <button class="button button-small sistur-delete-role" data-id="<?php echo $role['id']; ?>">
                                                Excluir
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        <?php else : ?>
            <p><?php _e('Nenhum departamento cadastrado.', 'sistur'); ?></p>
        <?php endif; ?>
    </div>
</div>

<!-- Modal de Departamento -->
<div id="sistur-department-modal" class="sistur-modal">
    <div class="sistur-modal-content">
        <div class="sistur-modal-header">
            <h2 id="department-modal-title">Adicionar Departamento</h2>
            <span class="sistur-modal-close">&times;</span>
        </div>
        <form id="sistur-department-form">
            <div class="sistur-modal-body">
                <input type="hidden" id="department-id" name="id" value="0" />

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="department-name">Nome *</label></th>
                        <td>
                            <input type="text" name="name" id="department-name" class="regular-text" required />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="department-description">Descrição</label></th>
                        <td>
                            <textarea name="description" id="department-description" rows="4" class="large-text"></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="department-status">Status</label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="status" id="department-status" value="1" checked />
                                Departamento Ativo
                            </label>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="sistur-modal-footer">
                <button type="button" class="button" id="sistur-cancel-department">Cancelar</button>
                <button type="submit" class="button button-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal de Função -->
<div id="sistur-role-modal" class="sistur-modal">
    <div class="sistur-modal-content">
        <div class="sistur-modal-header">
            <h2 id="role-modal-title">Adicionar Função</h2>
            <span class="sistur-modal-close">&times;</span>
        </div>
        <form id="sistur-role-form">
            <div class="sistur-modal-body">
                <input type="hidden" id="role-id" name="id">
                <input type="hidden" id="role-department" name="department_id">

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="role-name">Nome da Função *</label></th>
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
            </div>

            <div class="sistur-modal-footer">
                <button type="button" class="button" id="sistur-cancel-role">Cancelar</button>
                <button type="submit" class="button button-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script type="text/javascript">
var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
var sisturNonce = '<?php echo wp_create_nonce('sistur_nonce'); ?>';

jQuery(document).ready(function($) {
    let allPermissions = [];

    // Toggle de departamentos
    $('.department-header').on('click', function() {
        const content = $(this).next('.department-content');
        content.toggleClass('active');
    });

    // ========== DEPARTAMENTOS ==========

    // Adicionar departamento
    $('#sistur-add-department').on('click', function() {
        $('#sistur-department-form')[0].reset();
        $('#department-id').val('');
        $('#department-modal-title').text('Adicionar Departamento');
        $('#sistur-department-modal').show();
    });

    // Editar departamento
    $(document).on('click', '.sistur-edit-department', function() {
        const id = $(this).data('id');

        $.ajax({
            url: ajaxurl,
            type: 'GET',
            data: {
                action: 'get_department',
                nonce: sisturNonce,
                id: id
            },
            success: function(response) {
                if (response.success) {
                    const dept = response.data.department;
                    $('#department-id').val(dept.id);
                    $('#department-name').val(dept.name);
                    $('#department-description').val(dept.description);
                    $('#department-status').prop('checked', dept.status == 1);
                    $('#department-modal-title').text('Editar Departamento');
                    $('#sistur-department-modal').show();
                } else {
                    alert(response.data.message || 'Erro ao carregar departamento.');
                }
            }
        });
    });

    // Salvar departamento
    $('#sistur-department-form').on('submit', function(e) {
        e.preventDefault();

        const formData = {
            action: 'save_department',
            nonce: sisturNonce,
            id: $('#department-id').val(),
            name: $('#department-name').val(),
            description: $('#department-description').val(),
            status: $('#department-status').is(':checked') ? 1 : 0
        };

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    alert('Departamento salvo com sucesso!');
                    location.reload();
                } else {
                    alert(response.data.message || 'Erro ao salvar departamento.');
                }
            }
        });
    });

    // Excluir departamento
    $(document).on('click', '.sistur-delete-department', function() {
        if (!confirm('Tem certeza que deseja excluir este departamento?')) {
            return;
        }

        const id = $(this).data('id');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'delete_department',
                nonce: sisturNonce,
                id: id
            },
            success: function(response) {
                if (response.success) {
                    alert('Departamento excluído com sucesso!');
                    location.reload();
                } else {
                    alert(response.data.message || 'Erro ao excluir departamento.');
                }
            }
        });
    });

    // ========== FUNÇÕES/ROLES ==========

    // Carregar permissões
    function loadPermissions() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_get_permissions',
                nonce: sisturNonce
            },
            success: function(response) {
                if (response.success) {
                    allPermissions = response.data;
                    renderPermissions();
                } else {
                    console.error('Erro ao carregar permissões:', response);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error ao carregar permissões:', {
                    status: xhr.status,
                    statusText: xhr.statusText,
                    responseText: xhr.responseText,
                    error: error
                });
            }
        });
    }

    // Renderizar permissões
    function renderPermissions(selectedPermissions = []) {
        if (!allPermissions || Object.keys(allPermissions).length === 0) {
            $('#permissions-list').html('<p>Nenhuma permissão disponível</p>');
            return;
        }

        let html = '';
        for (let module in allPermissions) {
            const moduleData = allPermissions[module];
            const moduleName = moduleData.module_name || module.charAt(0).toUpperCase() + module.slice(1);
            const permissions = moduleData.permissions || [];

            html += '<div class="permission-module">';
            html += '<h4 style="margin-bottom: 10px; border-bottom: 1px solid #ddd; padding-bottom: 5px;">' + moduleName + '</h4>';
            html += '<div style="margin-left: 15px;">';

            permissions.forEach(function(perm) {
                // Use .some() with loose equality (==) to handle string/int id mismatches
                const isChecked = selectedPermissions.some(function(id) { return id == perm.id; }) ? 'checked' : '';
                html += '<label style="display: block; margin-bottom: 5px;">';
                html += '<input type="checkbox" name="permissions[]" value="' + perm.id + '" ' + isChecked + '> ';
                html += perm.label;
                if (perm.description) {
                    html += ' <span style="color: #666; font-size: 12px;">(' + perm.description + ')</span>';
                }
                html += '</label>';
            });

            html += '</div></div>';
        }

        $('#permissions-list').html(html);
    }

    // Adicionar função
    $(document).on('click', '.sistur-add-role', function() {
        const deptId = $(this).data('dept-id');
        $('#sistur-role-form')[0].reset();
        $('#role-id').val('');
        $('#role-department').val(deptId);
        $('#role-modal-title').text('Adicionar Função');
        renderPermissions([]);
        $('#sistur-role-modal').show();
    });

    // Editar função
    $(document).on('click', '.sistur-edit-role', function() {
        const id = $(this).data('id');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_get_role',
                id: id,
                nonce: sisturNonce
            },
            success: function(response) {
                if (response.success) {
                    const role = response.data;
                    $('#role-id').val(role.id);
                    $('#role-name').val(role.name);
                    $('#role-description').val(role.description);
                    $('#role-department').val(role.department_id || '');
                    $('#role-is-admin').prop('checked', role.is_admin == '1');
                    $('#role-modal-title').text('Editar Função');

                    // Carregar permissões da função
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'sistur_get_role_permissions',
                            role_id: id,
                            nonce: sisturNonce
                        },
                        success: function(permResponse) {
                            if (permResponse.success) {
                                // EXTRACT IDs from objects so .includes() inside renderPermissions works
                                const selectedIds = permResponse.data.map(function(p) {
                                    return p.id;
                                });
                                renderPermissions(selectedIds);
                            }
                        }
                    });

                    $('#sistur-role-modal').show();
                }
            }
        });
    });

    // Salvar função
    $('#sistur-role-form').on('submit', function(e) {
        e.preventDefault();

        const formData = new FormData(this);
        formData.append('action', 'sistur_save_role');
        formData.append('nonce', sisturNonce);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    alert('Função salva com sucesso!');
                    location.reload();
                } else {
                    alert('Erro ao salvar função: ' + response.data);
                }
            }
        });
    });

    // Excluir função
    $(document).on('click', '.sistur-delete-role', function() {
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
                nonce: sisturNonce
            },
            success: function(response) {
                if (response.success) {
                    alert('Função excluída com sucesso!');
                    location.reload();
                } else {
                    alert('Erro ao excluir: ' + response.data);
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

    // Fechar modais
    $('.sistur-modal-close, #sistur-cancel-department, #sistur-cancel-role').on('click', function() {
        $('.sistur-modal').hide();
    });

    // Fechar ao clicar fora
    $('.sistur-modal').on('click', function(e) {
        if (e.target === this) {
            $(this).hide();
        }
    });

    // Inicializar
    loadPermissions();
});
</script>
