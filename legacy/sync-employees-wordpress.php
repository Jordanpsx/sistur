<?php
/**
 * Script de sincronização de funcionários SISTUR com usuários WordPress
 *
 * Este script:
 * 1. Adiciona a coluna user_id à tabela wp_sistur_employees (se não existir)
 * 2. Sincroniza funcionários existentes com usuários WordPress pelo email
 * 3. Cria registros de funcionários para usuários WordPress que não têm
 *
 * @package SISTUR
 */

// Carregar WordPress
require_once(dirname(__FILE__) . '/../../../wp-load.php');

// Verificar permissões
if (!current_user_can('manage_options')) {
    die('Acesso negado. Você precisa ser administrador.');
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sincronização SISTUR - Usuários WordPress</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            max-width: 900px;
            margin: 40px auto;
            padding: 0 20px;
            background: #f0f0f1;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        h1 {
            color: #1d2327;
            border-bottom: 2px solid #2271b1;
            padding-bottom: 10px;
        }
        h2 {
            color: #2271b1;
            margin-top: 30px;
        }
        .status {
            padding: 15px;
            margin: 15px 0;
            border-radius: 4px;
            border-left: 4px solid;
        }
        .status.success {
            background: #d4edda;
            border-color: #28a745;
            color: #155724;
        }
        .status.error {
            background: #f8d7da;
            border-color: #dc3545;
            color: #721c24;
        }
        .status.warning {
            background: #fff3cd;
            border-color: #ffc107;
            color: #856404;
        }
        .status.info {
            background: #d1ecf1;
            border-color: #17a2b8;
            color: #0c5460;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #2271b1;
            color: white;
        }
        tr:hover {
            background: #f5f5f5;
        }
        .button {
            display: inline-block;
            padding: 10px 20px;
            background: #2271b1;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }
        .button:hover {
            background: #135e96;
        }
        .button.secondary {
            background: #6c757d;
        }
        .button.secondary:hover {
            background: #5a6268;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔄 Sincronização SISTUR com WordPress</h1>
        <p>Este script sincroniza os funcionários SISTUR com os usuários WordPress.</p>

<?php

global $wpdb;
$table = $wpdb->prefix . 'sistur_employees';

// Passo 1: Verificar/Adicionar coluna user_id
echo '<h2>Passo 1: Verificar estrutura do banco de dados</h2>';

$user_id_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'user_id'");

if (empty($user_id_exists)) {
    echo '<div class="status warning">Coluna user_id não existe. Adicionando...</div>';

    $result = $wpdb->query("ALTER TABLE $table
        ADD COLUMN user_id bigint(20) DEFAULT NULL AFTER id,
        ADD UNIQUE KEY user_id (user_id)");

    if ($result === false) {
        echo '<div class="status error">❌ Erro ao adicionar coluna: ' . $wpdb->last_error . '</div>';
    } else {
        echo '<div class="status success">✅ Coluna user_id adicionada com sucesso!</div>';
    }
} else {
    echo '<div class="status success">✅ Coluna user_id já existe.</div>';
}

// Passo 2: Sincronizar funcionários existentes
echo '<h2>Passo 2: Sincronizar funcionários existentes com usuários WordPress</h2>';

$employees = $wpdb->get_results(
    "SELECT id, name, email, user_id FROM $table WHERE email IS NOT NULL AND email != ''",
    ARRAY_A
);

if (empty($employees)) {
    echo '<div class="status warning">Nenhum funcionário com email encontrado.</div>';
} else {
    echo '<div class="status info">Encontrados ' . count($employees) . ' funcionários com email.</div>';

    $synced = 0;
    $already_synced = 0;
    $not_found = 0;

    echo '<table>';
    echo '<thead><tr><th>ID</th><th>Nome</th><th>Email</th><th>Status</th><th>User ID</th></tr></thead>';
    echo '<tbody>';

    foreach ($employees as $employee) {
        echo '<tr>';
        echo '<td>' . $employee['id'] . '</td>';
        echo '<td>' . esc_html($employee['name']) . '</td>';
        echo '<td>' . esc_html($employee['email']) . '</td>';

        if ($employee['user_id']) {
            echo '<td>✅ Já sincronizado</td>';
            echo '<td>' . $employee['user_id'] . '</td>';
            $already_synced++;
        } else {
            $user = get_user_by('email', $employee['email']);

            if ($user) {
                $wpdb->update(
                    $table,
                    array('user_id' => $user->ID),
                    array('id' => $employee['id']),
                    array('%d'),
                    array('%d')
                );

                echo '<td>✅ Sincronizado agora</td>';
                echo '<td>' . $user->ID . '</td>';
                $synced++;
            } else {
                echo '<td>⚠️ Usuário não encontrado</td>';
                echo '<td>-</td>';
                $not_found++;
            }
        }

        echo '</tr>';
    }

    echo '</tbody></table>';

    echo '<div class="status success">Resumo da sincronização:<br>';
    echo '- Já sincronizados: ' . $already_synced . '<br>';
    echo '- Sincronizados agora: ' . $synced . '<br>';
    echo '- Não encontrados: ' . $not_found . '</div>';
}

// Passo 3: Criar funcionários para usuários WordPress sem registro
echo '<h2>Passo 3: Criar funcionários para usuários WordPress</h2>';

// Buscar usuários WordPress que NÃO têm registro de funcionário
$users = get_users(array(
    'role__in' => array('employee', 'subscriber', 'administrator')
));

$created = 0;
$skipped = 0;

echo '<table>';
echo '<thead><tr><th>User ID</th><th>Nome</th><th>Email</th><th>Role</th><th>Status</th></tr></thead>';
echo '<tbody>';

foreach ($users as $user) {
    // Verificar se já existe funcionário para este user_id
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table WHERE user_id = %d",
        $user->ID
    ));

    echo '<tr>';
    echo '<td>' . $user->ID . '</td>';
    echo '<td>' . esc_html($user->display_name) . '</td>';
    echo '<td>' . esc_html($user->user_email) . '</td>';
    echo '<td>' . implode(', ', $user->roles) . '</td>';

    if ($existing) {
        echo '<td>✅ Já existe</td>';
        $skipped++;
    } else {
        // Criar novo funcionário
        $data = array(
            'user_id' => $user->ID,
            'name' => $user->display_name ? $user->display_name : $user->user_login,
            'email' => $user->user_email,
            'phone' => get_user_meta($user->ID, 'billing_phone', true),
            'department_id' => null,
            'role_id' => null,
            'position' => '',
            'photo' => '',
            'bio' => '',
            'hire_date' => current_time('Y-m-d'),
            'cpf' => '',
            'matricula' => '',
            'ctps' => '',
            'ctps_uf' => '',
            'cbo' => '',
            'time_expected_minutes' => 480,
            'lunch_minutes' => 60,
            'status' => 1
        );

        $format = array(
            '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s',
            '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d'
        );

        $result = $wpdb->insert($table, $data, $format);

        if ($result === false) {
            echo '<td>❌ Erro: ' . $wpdb->last_error . '</td>';
        } else {
            $employee_id = $wpdb->insert_id;

            // Gerar token e QR code
            if (class_exists('SISTUR_QRCode')) {
                $qrcode = SISTUR_QRCode::get_instance();
                $qrcode->generate_token_for_employee($employee_id);
                $qrcode->generate_qrcode($employee_id);
            }

            echo '<td>✅ Criado (ID: ' . $employee_id . ')</td>';
            $created++;
        }
    }

    echo '</tr>';
}

echo '</tbody></table>';

echo '<div class="status success">Resumo:<br>';
echo '- Funcionários criados: ' . $created . '<br>';
echo '- Já existiam: ' . $skipped . '</div>';

?>

        <h2>✅ Sincronização concluída!</h2>
        <p>
            <a href="<?php echo admin_url('admin.php?page=sistur-employees'); ?>" class="button">Ver Funcionários</a>
            <a href="<?php echo admin_url(); ?>" class="button secondary">Voltar ao Painel</a>
        </p>
    </div>
</body>
</html>
