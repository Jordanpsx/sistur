<?php
/**
 * Página temporária para visualizar QR codes dos funcionários
 *
 * ATENÇÃO: Este arquivo é apenas para uso interno/desenvolvimento.
 * Não deixe em produção sem proteção de senha!
 *
 * Acesso: http://seu-site.com/wp-content/plugins/sistur/visualizar-qrcodes.php
 */

// Carregar WordPress
require_once('../../../wp-load.php');

// Verificar se usuário é admin
if (!current_user_can('manage_options')) {
    wp_die('Você não tem permissão para acessar esta página.');
}

global $wpdb;
$table = $wpdb->prefix . 'sistur_employees';

// Buscar todos os funcionários ativos com token
$employees = $wpdb->get_results(
    "SELECT id, name, cpf, token_qr FROM $table WHERE status = 1 ORDER BY name",
    ARRAY_A
);

// Diretório e URL dos QR codes
$upload_dir = wp_upload_dir();
$qrcode_dir = $upload_dir['basedir'] . '/sistur/qrcodes/';
$qrcode_url = $upload_dir['baseurl'] . '/sistur/qrcodes/';

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Codes dos Funcionários - SISTUR</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #f0f0f1;
            padding: 20px;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #1d2327;
            margin-bottom: 10px;
            font-size: 28px;
        }
        .subtitle {
            color: #646970;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .stats {
            background: #f0f6fc;
            border-left: 4px solid #0071a1;
            padding: 15px;
            margin-bottom: 30px;
            border-radius: 4px;
        }
        .stats strong {
            color: #0071a1;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .qr-card {
            border: 1px solid #dcdcde;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            background: #fff;
            transition: all 0.3s;
        }
        .qr-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transform: translateY(-2px);
        }
        .qr-card h3 {
            color: #1d2327;
            margin-bottom: 8px;
            font-size: 16px;
            font-weight: 600;
        }
        .qr-card .cpf {
            color: #646970;
            font-size: 13px;
            margin-bottom: 15px;
            font-family: 'Courier New', monospace;
        }
        .qr-card .token {
            color: #0071a1;
            font-size: 11px;
            margin-bottom: 15px;
            word-break: break-all;
            background: #f0f6fc;
            padding: 8px;
            border-radius: 4px;
        }
        .qr-image {
            width: 200px;
            height: 200px;
            margin: 15px auto;
            border: 2px solid #dcdcde;
            border-radius: 4px;
            background: white;
        }
        .qr-image img {
            width: 100%;
            height: 100%;
            display: block;
        }
        .no-qr {
            color: #d63638;
            font-style: italic;
            font-size: 13px;
            padding: 20px;
        }
        .actions {
            margin-top: 15px;
            display: flex;
            gap: 10px;
            justify-content: center;
        }
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s;
        }
        .btn-primary {
            background: #2271b1;
            color: white;
        }
        .btn-primary:hover {
            background: #135e96;
        }
        .btn-secondary {
            background: #dcdcde;
            color: #1d2327;
        }
        .btn-secondary:hover {
            background: #c3c4c7;
        }
        .no-employees {
            text-align: center;
            padding: 60px 20px;
            color: #646970;
        }
        .no-employees svg {
            width: 80px;
            height: 80px;
            margin-bottom: 20px;
            opacity: 0.3;
        }
        @media print {
            body {
                background: white;
                padding: 0;
            }
            .container {
                box-shadow: none;
            }
            .actions, .subtitle {
                display: none;
            }
            .grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📱 QR Codes dos Funcionários</h1>
        <p class="subtitle">Visualize e baixe os QR codes gerados para cada funcionário</p>

        <?php if (empty($employees)): ?>
            <div class="no-employees">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
                <h2>Nenhum funcionário encontrado</h2>
                <p>Crie funcionários no admin do WordPress para gerar QR codes.</p>
            </div>
        <?php else: ?>
            <div class="stats">
                <strong><?php echo count($employees); ?></strong> funcionário(s) encontrado(s)
            </div>

            <div class="grid">
                <?php foreach ($employees as $employee): ?>
                    <?php
                        // Buscar arquivo com pattern (inclui hash no nome)
                        $qr_pattern = $qrcode_dir . 'employee_' . $employee['id'] . '_*.png';
                        $qr_files = glob($qr_pattern);
                        $has_qr = !empty($qr_files);
                        $qr_path = $has_qr ? $qr_files[0] : '';
                        $qr_filename = $has_qr ? basename($qr_path) : '';
                        $qr_url = $has_qr ? $qrcode_url . $qr_filename : '';
                    ?>
                    <div class="qr-card">
                        <h3><?php echo esc_html($employee['name']); ?></h3>
                        <div class="cpf">CPF: <?php echo esc_html($employee['cpf']); ?></div>

                        <?php if (!empty($employee['token_qr'])): ?>
                            <div class="token" title="Token UUID">
                                🔑 <?php echo esc_html($employee['token_qr']); ?>
                            </div>
                        <?php endif; ?>

                        <div class="qr-image">
                            <?php if ($has_qr): ?>
                                <img src="<?php echo esc_url($qr_url); ?>?v=<?php echo time(); ?>"
                                     alt="QR Code de <?php echo esc_attr($employee['name']); ?>">
                            <?php else: ?>
                                <div class="no-qr">QR Code não encontrado</div>
                            <?php endif; ?>
                        </div>

                        <div class="actions">
                            <?php if ($has_qr): ?>
                                <a href="<?php echo esc_url($qr_url); ?>"
                                   class="btn btn-primary"
                                   download="qrcode_<?php echo sanitize_title($employee['name']); ?>.png">
                                    ⬇️ Baixar
                                </a>
                                <a href="<?php echo esc_url($qr_url); ?>"
                                   class="btn btn-secondary"
                                   target="_blank">
                                    🔍 Abrir
                                </a>
                            <?php else: ?>
                                <span class="btn btn-secondary" style="opacity: 0.5; cursor: not-allowed;">
                                    Sem QR Code
                                </span>
                            <?php endif; ?>
                        </div>

                        <div style="margin-top: 10px; font-size: 12px; color: #646970;">
                            ID: <?php echo $employee['id']; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #dcdcde; text-align: center; color: #646970; font-size: 13px;">
            <p>💡 <strong>Dica:</strong> Use Ctrl+P para imprimir todos os QR codes desta página</p>
            <p style="margin-top: 10px;">
                <a href="<?php echo admin_url('admin.php?page=sistur-employees'); ?>" class="btn btn-secondary">
                    ← Voltar para Funcionários
                </a>
            </p>
        </div>
    </div>

    <script>
        // Log dos URLs no console para fácil acesso
        console.log('=== QR Codes dos Funcionários ===');
        <?php foreach ($employees as $employee): ?>
            <?php
                $qr_pattern_js = $qrcode_dir . 'employee_' . $employee['id'] . '_*.png';
                $qr_files_js = glob($qr_pattern_js);
                $qr_url_js = !empty($qr_files_js) ? $qrcode_url . basename($qr_files_js[0]) : 'Não gerado';
            ?>
            console.log('<?php echo esc_js($employee['name']); ?> (ID: <?php echo $employee['id']; ?>): <?php echo esc_js($qr_url_js); ?>');
        <?php endforeach; ?>
        console.log('================================');
    </script>
</body>
</html>
