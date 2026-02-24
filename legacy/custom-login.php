<?php
/**
 * Customização de Login do WordPress
 *
 * @package SISTUR
 */

// Se este arquivo for chamado diretamente, abortar
if (!defined('WPINC')) {
    die;
}

/**
 * Customizar URL de login
 */
add_filter('login_url', 'sistur_custom_login_url', 10, 3);
function sistur_custom_login_url($login_url, $redirect, $force_reauth) {
    // Manter URL padrão para admins
    if (is_admin()) {
        return $login_url;
    }

    // Redirecionar para login de funcionário se configurado
    $custom_login = home_url('/login-funcionario/');
    if ($redirect) {
        $custom_login = add_query_arg('redirect_to', urlencode($redirect), $custom_login);
    }

    return $custom_login;
}

/**
 * Redirecionar logout
 */
add_filter('logout_redirect', 'sistur_logout_redirect', 10, 3);
function sistur_logout_redirect($redirect_to, $requested_redirect_to, $user) {
    // Destruir sessão de funcionário se existir
    $session = SISTUR_Session::get_instance();
    $session->destroy_employee_session();

    // Redirecionar para login de funcionário
    return home_url('/login-funcionario/');
}

/**
 * Modificar link de Login/Logout
 */
add_filter('loginout', 'sistur_custom_loginout_link', 10, 2);
function sistur_custom_loginout_link($link, $redirect) {
    // Se estiver logado como funcionário
    $session = SISTUR_Session::get_instance();
    if ($session->is_employee_logged_in()) {
        $employee = $session->get_employee_data();
        $logout_url = add_query_arg('sistur_logout', '1', home_url('/painel-funcionario/'));
        return '<a href="' . esc_url($logout_url) . '">' . sprintf(__('Logout (%s)', 'sistur'), esc_html($employee['nome'])) . '</a>';
    }

    return $link;
}

/**
 * Modificar título de login
 */
add_filter('login_headertitle', 'sistur_login_header_title');
function sistur_login_header_title($title) {
    return get_bloginfo('name');
}

/**
 * Modificar URL do logo de login
 */
add_filter('login_headerurl', 'sistur_login_header_url');
function sistur_login_header_url($url) {
    return home_url();
}

/**
 * Customizar estilos da página de login
 */
add_action('login_enqueue_scripts', 'sistur_login_styles');
function sistur_login_styles() {
    ?>
    <style type="text/css">
        #login h1 a, .login h1 a {
            background-image: none;
            background-size: auto;
            width: auto;
            height: auto;
            text-indent: 0;
        }
        body.login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .login form {
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        .login #backtoblog a,
        .login #nav a {
            color: white;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
        }
    </style>
    <?php
}
