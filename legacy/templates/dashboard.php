<?php
/**
 * Dashboard do Funcionário - Sistur
 * 
 * Tela inicial do sistema interno com acesso aos módulos principais.
 * Design modular, limpo e expansível.
 * 
 * @package SISTUR
 * @since 2.0.0
 */

// Verificar se o usuário está logado
if (!defined('ABSPATH')) {
    exit;
}

// Carregar CSS do dashboard
wp_enqueue_style('sistur-dashboard', SISTUR_PLUGIN_URL . 'assets/css/dashboard.css', array(), SISTUR_VERSION);

// Obter dados do funcionário logado (tentar múltiplas fontes)
$employee_id = 0;
$employee_name = 'Funcionário';

// Fonte 1: Sessão SISTUR
if (isset($_SESSION['sistur_employee_id']) && intval($_SESSION['sistur_employee_id']) > 0) {
    $employee_id = intval($_SESSION['sistur_employee_id']);
    $employee_name = isset($_SESSION['sistur_employee_name']) ? sanitize_text_field($_SESSION['sistur_employee_name']) : 'Funcionário';
}

// Fonte 2: Usuário WordPress logado
if (!$employee_id && is_user_logged_in()) {
    $current_user = wp_get_current_user();
    $employee_name = $current_user->display_name ?: $current_user->user_login;
    
    // Tentar encontrar o funcionário vinculado
    global $wpdb;
    $table_employees = $wpdb->prefix . 'sistur_employees';
    $employee = $wpdb->get_row($wpdb->prepare(
        "SELECT id, name FROM $table_employees WHERE user_id = %d AND status = 1",
        $current_user->ID
    ));
    
    if ($employee) {
        $employee_id = $employee->id;
        $employee_name = $employee->name;
    } else {
        // Usuário WordPress mas não é funcionário - ainda mostra o dashboard
        $employee_id = -1; // Flag para indicar "usuário WP mas não funcionário"
    }
}

// Definir URL de logout
$logout_url = $employee_id > 0 
    ? wp_nonce_url(admin_url('admin-ajax.php?action=sistur_logout'), 'sistur_logout')
    : wp_logout_url(home_url('/areafuncionario'));
?>

<div class="sistur-dashboard-wrapper">
    <!-- ========================================
         NAVBAR - Barra de Navegação Superior
    ======================================== -->
    <header class="sistur-navbar">
        <nav class="sistur-navbar__container">
            <!-- Logo -->
            <div class="sistur-navbar__brand">
                <a href="<?php echo esc_url(home_url('/areafuncionario')); ?>" class="sistur-navbar__logo">
                    <span class="sistur-navbar__logo-icon">🌻</span>
                    <span class="sistur-navbar__logo-text">Sistur</span>
                </a>
            </div>

            <!-- Ações da Navbar -->
            <div class="sistur-navbar__actions">
                <!-- Botão de Destaque: Bater Ponto -->
                <a href="<?php echo esc_url(home_url('/registrar-ponto')); ?>" class="sistur-btn sistur-btn--primary">
                    <span class="sistur-btn__icon">⏱️</span>
                    <span class="sistur-btn__text">Bater Ponto / Banco de Horas</span>
                </a>

                <?php if ($employee_id) : ?>
                <!-- Boas-vindas -->
                <div class="sistur-navbar__user">
                    <span class="sistur-navbar__greeting">Olá, <strong><?php echo esc_html($employee_name); ?></strong></span>
                </div>

                <!-- Link de Sair -->
                <a href="<?php echo esc_url($logout_url); ?>" class="sistur-navbar__logout" title="Sair">
                    <span class="sistur-navbar__logout-icon">🚪</span>
                    <span class="sistur-navbar__logout-text">Sair</span>
                </a>
                <?php else : ?>
                <!-- Link de Login -->
                <a href="<?php echo esc_url(home_url('/login-funcionario')); ?>" class="sistur-btn sistur-btn--secondary">
                    Entrar
                </a>
                <?php endif; ?>
            </div>
        </nav>
    </header>

    <!-- ========================================
         MAIN - Área Principal
    ======================================== -->
    <main class="sistur-main">
        <div class="sistur-container">
            
            <!-- Título da Seção -->
            <section class="sistur-section">
                <h1 class="sistur-section__title">Áreas de Acesso</h1>
                <p class="sistur-section__subtitle">Selecione um módulo para começar</p>
            </section>

            <!-- Grid de Módulos -->
            <section class="sistur-modules">
                <div class="sistur-modules__grid">

                    <!-- Card: RH -->
                    <article class="sistur-card sistur-card--rh">
                        <a href="<?php echo esc_url(home_url('/rh')); ?>" class="sistur-card__link">
                            <div class="sistur-card__icon">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                                </svg>
                            </div>
                            <h2 class="sistur-card__title">RH</h2>
                            <p class="sistur-card__description">Gestão de pessoas, folha de ponto e escalas de trabalho</p>
                        </a>
                    </article>

                    <!-- Card: Restaurante -->
                    <article class="sistur-card sistur-card--restaurante">
                        <a href="<?php echo esc_url(home_url('/restaurante')); ?>" class="sistur-card__link">
                            <div class="sistur-card__icon">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M11 9H9V2H7v7H5V2H3v7c0 2.12 1.66 3.84 3.75 3.97V22h2.5v-9.03C11.34 12.84 13 11.12 13 9V2h-2v7zm5-3v8h2.5v8H21V2c-2.76 0-5 2.24-5 4z"/>
                                </svg>
                            </div>
                            <h2 class="sistur-card__title">Restaurante</h2>
                            <p class="sistur-card__description">Estoque, cardápio e controle de insumos</p>
                        </a>
                    </article>

                    <!-- Card: Financeiro -->
                    <article class="sistur-card sistur-card--financeiro">
                        <a href="<?php echo esc_url(home_url('/financeiro')); ?>" class="sistur-card__link">
                            <div class="sistur-card__icon">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M3.5 18.49l6-6.01 4 4L22 6.92l-1.41-1.41-7.09 7.97-4-4L2 16.99z"/>
                                </svg>
                            </div>
                            <h2 class="sistur-card__title">Financeiro</h2>
                            <p class="sistur-card__description">Relatórios financeiros e análise de desempenho</p>
                        </a>
                    </article>

                    <!-- Card: Nova Área (Placeholder) -->
                    <article class="sistur-card sistur-card--placeholder">
                        <div class="sistur-card__link sistur-card__link--disabled">
                            <div class="sistur-card__icon">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/>
                                </svg>
                            </div>
                            <h2 class="sistur-card__title">Nova Área</h2>
                            <p class="sistur-card__description">Em breve novas funcionalidades</p>
                        </div>
                    </article>

                </div>
            </section>

        </div>
    </main>

    <!-- ========================================
         FOOTER - Rodapé
    ======================================== -->
    <footer class="sistur-footer">
        <div class="sistur-container">
            <p class="sistur-footer__text">
                © <?php echo date('Y'); ?> Sistur - Sistema de Turismo
            </p>
        </div>
    </footer>
</div>
