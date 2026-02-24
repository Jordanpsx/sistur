<?php
/**
 * Componente: Módulo de Perfil
 * @package SISTUR
 * @since 2.0.0
 */

$session = SISTUR_Session::get_instance();
$portal = SISTUR_Portal::get_instance();
$employee = $portal->get_current_employee_with_role();
?>

<div class="module-perfil">
    <h2><?php _e('Meu Perfil', 'sistur'); ?></h2>

    <div class="perfil-card">
        <div class="perfil-avatar">
            <span class="avatar-initials"><?php 
                $parts = explode(' ', $employee['name']);
                echo strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
            ?></span>
        </div>
        
        <div class="perfil-info">
            <h3><?php echo esc_html($employee['name']); ?></h3>
            <?php if (!empty($employee['role_name'])): ?>
                <span class="role-badge"><?php echo esc_html($employee['role_name']); ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="perfil-details">
        <div class="detail-group">
            <label><?php _e('E-mail', 'sistur'); ?></label>
            <p><?php echo esc_html($employee['email']); ?></p>
        </div>
        
        <div class="detail-group">
            <label><?php _e('CPF', 'sistur'); ?></label>
            <p><?php echo esc_html($employee['cpf'] ?? '---'); ?></p>
        </div>

        <?php if (!empty($employee['department_name'])): ?>
        <div class="detail-group">
            <label><?php _e('Departamento', 'sistur'); ?></label>
            <p><?php echo esc_html($employee['department_name']); ?></p>
        </div>
        <?php endif; ?>

        <?php if (!empty($employee['hire_date'])): ?>
        <div class="detail-group">
            <label><?php _e('Data de Admissão', 'sistur'); ?></label>
            <p><?php echo esc_html(date_i18n('d/m/Y', strtotime($employee['hire_date']))); ?></p>
        </div>
        <?php endif; ?>

        <?php if (!empty($employee['daily_hours'])): ?>
        <div class="detail-group">
            <label><?php _e('Jornada Diária', 'sistur'); ?></label>
            <p><?php echo esc_html($employee['daily_hours']); ?> horas</p>
        </div>
        <?php endif; ?>
    </div>

    <div class="perfil-actions">
        <a href="<?php echo home_url('/painel-funcionario/?tab=perfil'); ?>" class="btn-edit-profile">
            <span class="dashicons dashicons-edit"></span>
            <?php _e('Editar Perfil', 'sistur'); ?>
        </a>
    </div>
</div>

<style>
.module-perfil h2 { margin:0 0 25px; font-size:1.5rem; color:#1e293b; border-bottom:2px solid #8b5cf6; padding-bottom:10px; display:inline-block; }

.perfil-card { display:flex; align-items:center; gap:20px; padding:24px; background:linear-gradient(135deg,#8b5cf6 0%,#7c3aed 100%); border-radius:16px; margin-bottom:30px; color:white; }

.perfil-avatar { width:80px; height:80px; border-radius:50%; background:rgba(255,255,255,0.2); display:flex; align-items:center; justify-content:center; }

.avatar-initials { font-size:2rem; font-weight:700; color:white; }

.perfil-info h3 { margin:0 0 8px; font-size:1.5rem; color:white; }

.perfil-info .role-badge { background:rgba(255,255,255,0.2); padding:4px 12px; border-radius:20px; font-size:.85rem; }

.perfil-details { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:20px; margin-bottom:30px; }

.detail-group { background:#f8fafc; padding:16px; border-radius:10px; }

.detail-group label { display:block; font-size:.8rem; color:#64748b; text-transform:uppercase; font-weight:600; margin-bottom:4px; }

.detail-group p { margin:0; font-size:1.1rem; color:#1e293b; font-weight:500; }

.perfil-actions { text-align:center; }

.btn-edit-profile { display:inline-flex; align-items:center; gap:8px; padding:12px 24px; background:linear-gradient(135deg,#8b5cf6,#7c3aed); color:white; text-decoration:none; border-radius:10px; font-weight:600; transition:all .2s; }

.btn-edit-profile:hover { box-shadow:0 6px 12px rgba(139,92,246,.3); transform:translateY(-2px); color:white; }

@media(max-width:768px) { .perfil-card{flex-direction:column;text-align:center} }
</style>
