<?php
/**
 * Script de diagnóstico do banco de dados SISTUR
 * Verifica a estrutura real da tabela de funcionários
 *
 * Acesso: http://seu-site.com/wp-content/plugins/sistur/diagnostico-bd.php
 */

// Carregar WordPress
require_once('../../../wp-load.php');

// Verificar permissões
if (!current_user_can('manage_options')) {
    wp_die('Você não tem permissão para acessar esta página.');
}

global $wpdb;
$table = $wpdb->prefix . 'sistur_employees';

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnóstico BD - SISTUR</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f0f1;
            padding: 20px;
            margin: 0;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #1d2327;
            margin-bottom: 10px;
        }
        h2 {
            color: #2271b1;
            margin-top: 30px;
            border-bottom: 2px solid #2271b1;
            padding-bottom: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border: 1px solid #dcdcde;
        }
        th {
            background: #f0f6fc;
            font-weight: 600;
            color: #1d2327;
        }
        tr:nth-child(even) {
            background: #f6f7f7;
        }
        .success {
            color: #00a32a;
            font-weight: bold;
        }
        .error {
            color: #d63638;
            font-weight: bold;
        }
        .warning {
            color: #dba617;
            font-weight: bold;
        }
        .info-box {
            background: #f0f6fc;
            border-left: 4px solid #2271b1;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .error-box {
            background: #fcf0f1;
            border-left: 4px solid #d63638;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        code {
            background: #f6f7f7;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
        }
        pre {
            background: #1d2327;
            color: #f0f0f1;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            font-size: 13px;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin: 20px 0;
        }
        .stat-box {
            background: #f0f6fc;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            border: 1px solid #dcdcde;
        }
        .stat-number {
            font-size: 36px;
            font-weight: bold;
            color: #2271b1;
        }
        .stat-label {
            color: #646970;
            margin-top: 5px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Diagnóstico do Banco de Dados SISTUR</h1>
        <p style="color: #646970;">Última verificação: <?php echo date('d/m/Y H:i:s'); ?></p>

        <h2>📊 1. Estrutura da Tabela <?php echo $table; ?></h2>

        <?php
        // Verificar se a tabela existe
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");

        if (!$table_exists):
        ?>
            <div class="error-box">
                <strong class="error">❌ ERRO: Tabela não existe!</strong><br>
                A tabela <code><?php echo $table; ?></code> não foi encontrada no banco de dados.<br>
                Execute a ativação do plugin para criar as tabelas.
            </div>
        <?php else: ?>
            <div class="info-box">
                <strong class="success">✅ Tabela existe</strong><br>
                A tabela <code><?php echo $table; ?></code> foi encontrada no banco de dados.
            </div>

            <?php
            // Listar todas as colunas
            $columns = $wpdb->get_results("SHOW COLUMNS FROM $table");
            ?>

            <h3>Colunas da Tabela (<?php echo count($columns); ?> colunas)</h3>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Nome da Coluna</th>
                        <th>Tipo</th>
                        <th>Nulo</th>
                        <th>Chave</th>
                        <th>Default</th>
                        <th>Extra</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $required_columns = ['id', 'name', 'cpf', 'token_qr', 'status', 'role_id'];
                    $i = 1;
                    foreach ($columns as $column):
                        $is_required = in_array($column->Field, $required_columns);
                        $row_class = $is_required ? 'style="background: #f0f6fc; font-weight: 600;"' : '';
                    ?>
                        <tr <?php echo $row_class; ?>>
                            <td><?php echo $i++; ?></td>
                            <td>
                                <code><?php echo $column->Field; ?></code>
                                <?php if ($is_required) echo ' ⭐'; ?>
                            </td>
                            <td><?php echo $column->Type; ?></td>
                            <td><?php echo $column->Null; ?></td>
                            <td><?php echo $column->Key ?: '-'; ?></td>
                            <td><?php echo $column->Default !== null ? $column->Default : 'NULL'; ?></td>
                            <td><?php echo $column->Extra ?: '-'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p style="color: #646970; font-size: 13px;">⭐ = Coluna crítica para o sistema</p>

            <h2>🔍 2. Validação de Colunas Críticas</h2>

            <?php
            $critical_columns = [
                'id' => 'ID do funcionário',
                'name' => 'Nome do funcionário',
                'email' => 'Email',
                'cpf' => 'CPF',
                'password' => 'Senha',
                'role_id' => 'ID do papel/função',
                'token_qr' => 'Token UUID para QR Code',
                'contract_type_id' => 'Tipo de contrato',
                'status' => 'Status ativo/inativo'
            ];

            $missing_columns = [];
            $existing_columns = array_column($columns, 'Field');

            foreach ($critical_columns as $col => $description) {
                $exists = in_array($col, $existing_columns);

                echo '<div style="padding: 10px; margin: 5px 0; border-left: 4px solid ' . ($exists ? '#00a32a' : '#d63638') . '; background: ' . ($exists ? '#f0f6f0' : '#fcf0f1') . ';">';
                echo $exists ? '<span class="success">✅</span>' : '<span class="error">❌</span>';
                echo " <code>$col</code> - $description";
                echo '</div>';

                if (!$exists) {
                    $missing_columns[] = $col;
                }
            }
            ?>

            <?php if (!empty($missing_columns)): ?>
                <div class="error-box" style="margin-top: 20px;">
                    <strong class="error">⚠️ ATENÇÃO: Colunas Faltando!</strong><br>
                    As seguintes colunas críticas não existem na tabela:<br>
                    <ul>
                        <?php foreach ($missing_columns as $col): ?>
                            <li><code><?php echo $col; ?></code></li>
                        <?php endforeach; ?>
                    </ul>
                    <strong>Solução:</strong> Desative e reative o plugin para executar as migrações.
                </div>
            <?php endif; ?>

            <h2>👥 3. Funcionários Cadastrados</h2>

            <?php
            // Contar funcionários
            $total_employees = $wpdb->get_var("SELECT COUNT(*) FROM $table");
            $active_employees = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 1");
            $with_token = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE token_qr IS NOT NULL AND token_qr != ''");
            ?>

            <div class="stats">
                <div class="stat-box">
                    <div class="stat-number"><?php echo $total_employees; ?></div>
                    <div class="stat-label">Total de Funcionários</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?php echo $active_employees; ?></div>
                    <div class="stat-label">Funcionários Ativos</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?php echo $with_token; ?></div>
                    <div class="stat-label">Com Token QR</div>
                </div>
            </div>

            <?php if ($total_employees > 0): ?>
                <?php
                // Listar funcionários
                $employees = $wpdb->get_results("SELECT id, name, cpf, email, role_id, token_qr, status FROM $table ORDER BY id DESC LIMIT 10");
                ?>

                <h3>Últimos 10 Funcionários Cadastrados</h3>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nome</th>
                            <th>CPF</th>
                            <th>Email</th>
                            <th>Role ID</th>
                            <th>Token QR</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employees as $emp): ?>
                            <tr>
                                <td><?php echo $emp->id; ?></td>
                                <td><?php echo esc_html($emp->name); ?></td>
                                <td><?php echo esc_html($emp->cpf); ?></td>
                                <td><?php echo esc_html($emp->email ?: '-'); ?></td>
                                <td><?php echo $emp->role_id ?: 'NULL'; ?></td>
                                <td>
                                    <?php if (!empty($emp->token_qr)): ?>
                                        <code style="font-size: 10px;"><?php echo substr($emp->token_qr, 0, 20); ?>...</code>
                                    <?php else: ?>
                                        <span class="error">Sem token</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($emp->status == 1): ?>
                                        <span class="success">Ativo</span>
                                    <?php else: ?>
                                        <span class="error">Inativo</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="info-box">
                    <strong>ℹ️ Nenhum funcionário cadastrado</strong><br>
                    Crie funcionários em <strong>Admin → RH → Funcionários</strong>
                </div>
            <?php endif; ?>

            <h2>🔧 4. Teste de Query da Página de QR Codes</h2>

            <?php
            // Testar a query exata que a página de QR codes usa
            $qr_query = "SELECT id, name, cpf, token_qr, status FROM $table WHERE status = 1 ORDER BY name";
            $qr_results = $wpdb->get_results($qr_query, ARRAY_A);
            $qr_count = count($qr_results);
            ?>

            <div class="info-box">
                <strong>Query testada:</strong><br>
                <pre><?php echo $qr_query; ?></pre>
                <strong>Resultado:</strong>
                <?php if ($qr_count > 0): ?>
                    <span class="success">✅ <?php echo $qr_count; ?> funcionário(s) encontrado(s)</span>
                <?php else: ?>
                    <span class="error">❌ Nenhum funcionário encontrado</span>
                <?php endif; ?>
            </div>

            <?php if ($qr_count > 0): ?>
                <h3>Funcionários Retornados pela Query</h3>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nome</th>
                            <th>CPF</th>
                            <th>Token QR</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($qr_results as $emp): ?>
                            <tr>
                                <td><?php echo $emp['id']; ?></td>
                                <td><?php echo esc_html($emp['name']); ?></td>
                                <td><?php echo esc_html($emp['cpf']); ?></td>
                                <td>
                                    <?php if (!empty($emp['token_qr'])): ?>
                                        <span class="success">✅ Tem token</span>
                                    <?php else: ?>
                                        <span class="warning">⚠️ Sem token</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="success"><?php echo $emp['status']; ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2>🗄️ 5. Informações do Banco de Dados</h2>

            <table>
                <tr>
                    <th>Informação</th>
                    <th>Valor</th>
                </tr>
                <tr>
                    <td>Nome da Tabela</td>
                    <td><code><?php echo $table; ?></code></td>
                </tr>
                <tr>
                    <td>Charset</td>
                    <td><code><?php echo $wpdb->charset; ?></code></td>
                </tr>
                <tr>
                    <td>Collate</td>
                    <td><code><?php echo $wpdb->collate; ?></code></td>
                </tr>
                <tr>
                    <td>WordPress DB Version</td>
                    <td><code><?php echo get_option('db_version'); ?></code></td>
                </tr>
                <tr>
                    <td>SISTUR DB Version</td>
                    <td><code><?php echo get_option('sistur_db_version', 'Não definida'); ?></code></td>
                </tr>
            </table>

        <?php endif; ?>

        <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #dcdcde; text-align: center;">
            <a href="<?php echo admin_url('admin.php?page=sistur-qrcodes'); ?>" style="padding: 10px 20px; background: #2271b1; color: white; text-decoration: none; border-radius: 4px; display: inline-block;">
                ← Voltar para QR Codes
            </a>
            <a href="<?php echo admin_url('plugins.php'); ?>" style="padding: 10px 20px; background: #646970; color: white; text-decoration: none; border-radius: 4px; display: inline-block; margin-left: 10px;">
                Ver Plugins
            </a>
        </div>
    </div>
</body>
</html>
