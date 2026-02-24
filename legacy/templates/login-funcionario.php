<?php
/**
 * Template de Login do Funcionário - Versão Simplificada
 *
 * @package SISTUR
 */

// Verificar se já está logado
$session = SISTUR_Session::get_instance();
if ($session->is_employee_logged_in()) {
    wp_redirect(home_url('/painel-funcionario/'));
    exit;
}
?>

<div class="sistur-login-container">
    <div class="sistur-login-card">
        <!-- Logo ou ícone -->
        <div class="sistur-login-icon">
            <svg width="80" height="80" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.5"/>
                <path d="M12 8V12L15 15" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
        </div>

        <!-- Título -->
        <h1 class="sistur-login-title">Registrar Ponto</h1>
        <p class="sistur-login-subtitle">Digite seus dados para acessar</p>

        <!-- Mensagem de erro -->
        <div id="sistur-login-error" class="sistur-login-error" style="display: none;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
                <path d="M12 8V12M12 16H12.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
            <span id="sistur-error-text"></span>
        </div>

        <!-- Formulário -->
        <form id="sistur-login-form" class="sistur-login-form" data-inline-handler="true">
            <!-- Campo CPF -->
            <div class="sistur-input-group">
                <label for="sistur-cpf" class="sistur-input-label">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                        <path d="M20 21V19C20 17.9391 19.5786 16.9217 18.8284 16.1716C18.0783 15.4214 17.0609 15 16 15H8C6.93913 15 5.92172 15.4214 5.17157 16.1716C4.42143 16.9217 4 17.9391 4 19V21M16 7C16 9.20914 14.2091 11 12 11C9.79086 11 8 9.20914 8 7C8 4.79086 9.79086 3 12 3C14.2091 3 16 4.79086 16 7Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span>CPF</span>
                </label>
                <input
                    type="text"
                    id="sistur-cpf"
                    name="cpf"
                    class="sistur-input"
                    placeholder="000.000.000-00"
                    required
                    maxlength="14"
                    autocomplete="username"
                    inputmode="numeric"
                />
            </div>

            <!-- Campo Senha -->
            <div class="sistur-input-group">
                <label for="sistur-password" class="sistur-input-label">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                        <rect x="3" y="11" width="18" height="11" rx="2" stroke="currentColor" stroke-width="2"/>
                        <path d="M7 11V7C7 5.67392 7.52678 4.40215 8.46447 3.46447C9.40215 2.52678 10.6739 2 12 2C13.3261 2 14.5979 2.52678 15.5355 3.46447C16.4732 4.40215 17 5.67392 17 7V11" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <span>Senha</span>
                </label>
                <input
                    type="password"
                    id="sistur-password"
                    name="password"
                    class="sistur-input"
                    placeholder="Digite sua senha"
                    required
                    autocomplete="current-password"
                />
            </div>

            <!-- Botão de Login -->
            <button type="submit" class="sistur-login-btn" id="sistur-login-btn">
                <span id="sistur-btn-text">Entrar</span>
                <svg id="sistur-btn-icon" width="24" height="24" viewBox="0 0 24 24" fill="none">
                    <path d="M5 12H19M19 12L12 5M19 12L12 19" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
        </form>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    function showError(message) {
        $('#sistur-error-text').text(message);
        $('#sistur-login-error').fadeIn();
        setTimeout(function() {
            $('#sistur-login-error').fadeOut();
        }, 5000);
    }

    // Formatar CPF enquanto digita
    $('#sistur-cpf').on('input', function() {
        var value = $(this).val().replace(/\D/g, '');
        if (value.length > 11) {
            value = value.substr(0, 11);
        }

        if (value.length >= 9) {
            value = value.replace(/(\d{3})(\d{3})(\d{3})(\d{0,2})/, '$1.$2.$3-$4');
        } else if (value.length >= 6) {
            value = value.replace(/(\d{3})(\d{3})(\d{0,3})/, '$1.$2.$3');
        } else if (value.length >= 3) {
            value = value.replace(/(\d{3})(\d{0,3})/, '$1.$2');
        }

        $(this).val(value);
    });

    // Focar no CPF automaticamente
    setTimeout(function() {
        $('#sistur-cpf').focus();
    }, 300);

    // Submit do formulário
    $('#sistur-login-form').on('submit', function(e) {
        e.preventDefault();
        e.stopImmediatePropagation(); // Previne outros handlers de executar

        var cpf = $('#sistur-cpf').val();
        var password = $('#sistur-password').val();

        if (!cpf || !password) {
            showError('Por favor, preencha todos os campos');
            return;
        }

        $.ajax({
            url: '<?php echo admin_url('admin-ajax.php'); ?>',
            type: 'POST',
            data: {
                action: 'sistur_funcionario_login',
                cpf: cpf,
                password: password,
                nonce: '<?php echo wp_create_nonce('sistur_login_nonce'); ?>'
            },
            beforeSend: function() {
                $('#sistur-login-btn').addClass('loading').prop('disabled', true);
                $('#sistur-btn-text').text('Entrando...');
                $('#sistur-btn-icon').hide();
            },
            success: function(response) {
                if (response.success) {
                    $('#sistur-btn-text').text('Sucesso!');
                    $('#sistur-login-btn').removeClass('loading').addClass('success');
                    setTimeout(function() {
                        window.location.href = response.data.redirect;
                    }, 500);
                } else {
                    showError(response.data.message || 'CPF ou senha incorretos');
                    $('#sistur-login-btn').removeClass('loading').prop('disabled', false);
                    $('#sistur-btn-text').text('Entrar');
                    $('#sistur-btn-icon').show();
                }
            },
            error: function() {
                showError('Erro de conexão. Verifique sua internet e tente novamente.');
                $('#sistur-login-btn').removeClass('loading').prop('disabled', false);
                $('#sistur-btn-text').text('Entrar');
                $('#sistur-btn-icon').show();
            }
        });
    });
});
</script>

<style>
/* Modern & Relaxing Theme Variables */
:root {
    --sistur-relax-primary: #0d9488; /* Teal 600 */
    --sistur-relax-primary-dark: #0f766e; /* Teal 700 */
    --sistur-relax-primary-light: #ccfbf1; /* Teal 100 */
    
    --sistur-relax-bg: #f8fafc; /* Slate 50 */
    --sistur-relax-card-bg: #ffffff;
    
    --sistur-relax-text-main: #334155; /* Slate 700 */
    --sistur-relax-text-muted: #64748b; /* Slate 500 */
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    background-color: var(--sistur-relax-bg);
}

.sistur-login-container {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: linear-gradient(135deg, #f0fdfa 0%, #e0f2fe 100%); /* Mint to Sky soft gradient */
}

.sistur-login-card {
    background: white;
    border-radius: 24px;
    box-shadow: 0 20px 60px -10px rgba(13, 148, 136, 0.15);
    padding: 48px 40px;
    max-width: 440px;
    width: 100%;
    animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
    border: 1px solid white;
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(40px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.sistur-login-icon {
    text-align: center;
    color: var(--sistur-relax-primary);
    margin-bottom: 24px;
    background: var(--sistur-relax-primary-light);
    width: 80px;
    height: 80px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-left: auto;
    margin-right: auto;
}

.sistur-login-icon svg {
    width: 40px;
    height: 40px;
}

.sistur-login-title {
    font-size: 28px;
    font-weight: 800;
    text-align: center;
    color: #1a202c; /* Force dark/black */
    margin-bottom: 8px;
    letter-spacing: -0.5px;
}

.sistur-login-subtitle {
    font-size: 16px;
    text-align: center;
    color: var(--sistur-relax-text-muted);
    margin-bottom: 32px;
}

.sistur-login-error {
    background-color: #fef2f2;
    color: #ef4444;
    padding: 12px 16px;
    border-radius: 12px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 14px;
    animation: shake 0.4s;
    border: 1px solid #fee2e2;
}

@keyframes shake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-6px); }
    75% { transform: translateX(6px); }
}

.sistur-input-group {
    margin-bottom: 24px;
}

.sistur-input-label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    font-weight: 600;
    color: var(--sistur-relax-text-main);
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.sistur-input-label svg {
    color: var(--sistur-relax-primary);
}

.sistur-input {
    width: 100%;
    padding: 16px 18px;
    font-size: 16px;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    transition: all 0.2s;
    background-color: #f8fafc;
    color: var(--sistur-relax-text-main);
}

.sistur-input:focus {
    outline: none;
    border-color: var(--sistur-relax-primary);
    background-color: white;
    box-shadow: 0 0 0 4px var(--sistur-relax-primary-light);
}

.sistur-input::placeholder {
    color: #cbd5e1;
}

.sistur-login-btn {
    width: 100%;
    padding: 18px 24px;
    font-size: 16px;
    font-weight: 700;
    color: white;
    background: linear-gradient(135deg, #14b8a6 0%, #0d9488 100%);
    border: none;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    margin-top: 32px;
    box-shadow: 0 4px 6px -1px rgba(13, 148, 136, 0.3);
}

.sistur-login-btn:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 10px 15px -3px rgba(13, 148, 136, 0.4);
}

.sistur-login-btn:active:not(:disabled) {
    transform: translateY(0);
}

.sistur-login-btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

.sistur-login-btn.loading {
    background: #94a3b8;
}

.sistur-login-btn.success {
    background: #10b981;
}

/* Responsividade para dispositivos móveis */
@media (max-width: 480px) {
    .sistur-login-card {
        padding: 32px 24px;
    }
}
</style>
