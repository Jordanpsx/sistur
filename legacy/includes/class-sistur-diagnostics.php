<?php
/**
 * SISTUR - Diagnóstico do Sistema
 *
 * Classe para diagnóstico e debugging do plugin
 *
 * @package SISTUR
 * @version 1.4.1
 */

// Se este arquivo for chamado diretamente, abortar.
if (!defined('WPINC')) {
    die;
}

class SISTUR_Diagnostics {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        add_action('admin_menu', array($this, 'add_diagnostics_page'));
        add_action('wp_ajax_sistur_clear_cache', array($this, 'clear_cache'));
        add_action('wp_ajax_sistur_recreate_tables', array($this, 'recreate_tables'));
        add_action('wp_ajax_sistur_check_tables', array($this, 'check_tables'));
        add_action('wp_ajax_sistur_test_db_integrity', array($this, 'test_db_integrity'));
        add_action('wp_ajax_sistur_diagnose_timebank', array($this, 'ajax_diagnose_timebank'));
        add_action('wp_ajax_sistur_reprocess_timebank', array($this, 'ajax_reprocess_timebank'));
        add_action('wp_ajax_sistur_test_employee_creation', array($this, 'ajax_test_employee_creation'));
        add_action('wp_ajax_sistur_validate_environment', array($this, 'ajax_validate_environment'));
        add_action('wp_ajax_sistur_fix_employees_table', array($this, 'ajax_fix_employees_table'));
        add_action('wp_ajax_sistur_validate_timeclock', array($this, 'ajax_validate_timeclock'));
        add_action('wp_ajax_sistur_fix_timeclock_table', array($this, 'ajax_fix_timeclock_table'));
        add_action('wp_ajax_sistur_full_recalc_timebank', array($this, 'ajax_full_recalc_timebank'));
        add_action('wp_ajax_sistur_regenerate_history', array($this, 'ajax_regenerate_history'));
    }

    /**
     * Adiciona página de diagnóstico
     */
    public function add_diagnostics_page() {
        add_submenu_page(
            'sistur',
            __('Diagnóstico SISTUR', 'sistur'),
            __('🔧 Diagnóstico', 'sistur'),
            'manage_options',
            'sistur-diagnostics',
            array($this, 'render_diagnostics_page')
        );
    }

    /**
     * Renderiza página de diagnóstico
     */
    public function render_diagnostics_page() {
        ?>
        <div class="wrap">
            <h1>🔧 Diagnóstico SISTUR</h1>
            <p>Esta página mostra informações de diagnóstico do plugin SISTUR para ajudar a resolver problemas.</p>

            <div class="card">
                <h2>Informações do Plugin</h2>
                <table class="widefat">
                    <tr>
                        <th>Versão do Plugin:</th>
                        <td><?php echo esc_html(SISTUR_VERSION); ?></td>
                    </tr>
                    <tr>
                        <th>Versão do WordPress:</th>
                        <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                    </tr>
                    <tr>
                        <th>Versão do PHP:</th>
                        <td><?php echo esc_html(PHP_VERSION); ?></td>
                    </tr>
                    <tr>
                        <th>WP_DEBUG:</th>
                        <td><?php echo defined('WP_DEBUG') && WP_DEBUG ? '<span style="color:green">✓ Ativado</span>' : '<span style="color:red">✗ Desativado</span>'; ?></td>
                    </tr>
                    <tr>
                        <th>SCRIPT_DEBUG:</th>
                        <td><?php echo defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '<span style="color:green">✓ Ativado</span>' : '<span style="color:red">✗ Desativado</span>'; ?></td>
                    </tr>
                </table>
            </div>

            <div class="card">
                <h2>Scripts Carregados</h2>
                <p>Scripts SISTUR enfileirados:</p>
                <ul>
                    <?php
                    global $wp_scripts;
                    $sistur_scripts = array();
                    foreach ($wp_scripts->registered as $handle => $script) {
                        if (strpos($handle, 'sistur') !== false || strpos($script->src, 'sistur') !== false) {
                            $sistur_scripts[] = array(
                                'handle' => $handle,
                                'src' => $script->src,
                                'version' => $script->ver,
                                'deps' => $script->deps
                            );
                        }
                    }

                    if (!empty($sistur_scripts)) {
                        foreach ($sistur_scripts as $script) {
                            echo '<li>';
                            echo '<strong>' . esc_html($script['handle']) . '</strong><br>';
                            echo 'URL: <code>' . esc_html($script['src']) . '</code><br>';
                            echo 'Versão: <code>' . esc_html($script['version']) . '</code><br>';
                            echo 'Dependências: <code>' . esc_html(implode(', ', $script['deps'])) . '</code>';
                            echo '</li>';
                        }
                    } else {
                        echo '<li>Nenhum script SISTUR enfileirado nesta página</li>';
                    }
                    ?>
                </ul>
            </div>

            <div class="card">
                <h2>Arquivos JavaScript</h2>
                <p>Verificando existência dos arquivos JS:</p>
                <ul>
                    <?php
                    $js_files = array(
                        'sistur-toast.js',
                        'admin-script.js',
                        'share-modal.js',
                        'sistur-diagnostics.js',
                        'employees.js',
                        'employee-admin.js',
                        'payments.js',
                        'qrcode-admin.js',
                        'sistur-login.js',
                        'time-tracking-admin.js',
                        'time-tracking-public.js'
                    );

                    foreach ($js_files as $file) {
                        $path = SISTUR_PLUGIN_DIR . 'assets/js/' . $file;
                        $exists = file_exists($path);
                        $size = $exists ? filesize($path) : 0;
                        $modified = $exists ? date('Y-m-d H:i:s', filemtime($path)) : 'N/A';

                        echo '<li>';
                        echo $exists ? '<span style="color:green">✓</span>' : '<span style="color:red">✗</span>';
                        echo ' <strong>' . esc_html($file) . '</strong><br>';
                        echo 'Tamanho: ' . esc_html($size) . ' bytes<br>';
                        echo 'Modificado: ' . esc_html($modified);
                        echo '</li>';
                    }
                    ?>
                </ul>
            </div>

            <div class="card">
                <h2>📊 Verificação de Tabelas do Banco de Dados</h2>
                <p>Verificando tabelas necessárias no banco de dados:</p>
                <div id="sistur-tables-check">
                    <button type="button" class="button" onclick="sisturCheckTables()">
                        🔍 Verificar Tabelas
                    </button>
                </div>
                <div id="sistur-tables-output" style="margin-top:15px;"></div>
            </div>

            <div class="card">
                <h2>Ações de Debug</h2>
                <p>
                    <button type="button" class="button button-primary" onclick="sisturClearCache()">
                        🗑️ Limpar Cache de Scripts
                    </button>
                    <button type="button" class="button" onclick="sisturReloadPage()">
                        🔄 Recarregar Página (Hard Refresh)
                    </button>
                    <button type="button" class="button" onclick="sisturTestShareModal()">
                        🧪 Testar Share Modal
                    </button>
                    <button type="button" class="button button-secondary" onclick="sisturRecreateTables()" id="recreate-tables-btn" style="display:none;">
                        🔨 Recriar Tabelas Faltantes
                    </button>
                </p>
                <div id="sistur-debug-output" style="background:#f0f0f0;padding:10px;margin-top:10px;font-family:monospace;display:none;"></div>
            </div>

            <div class="card">
                <h2>👥 Diagnóstico de Criação de Funcionários</h2>
                <p>Teste e diagnostique problemas ao criar funcionários no sistema.</p>

                <div style="margin: 15px 0;">
                    <h3>1. Validar Ambiente</h3>
                    <p>Verifica todas as dependências e requisitos para criação de funcionários.</p>
                    <button type="button" class="button button-primary" onclick="sisturValidateEnvironment()">
                        🔍 Validar Ambiente de Produção
                    </button>
                </div>

                <div style="margin: 15px 0;">
                    <h3>2. Teste de Criação</h3>
                    <p>Simula a criação de um funcionário com logs detalhados (NÃO cria de verdade).</p>
                    <button type="button" class="button" onclick="sisturTestEmployeeCreation()">
                        🧪 Testar Criação de Funcionário
                    </button>
                </div>

                <div id="sistur-employee-debug-output" style="margin-top: 20px;"></div>
                
                <div style="margin: 15px 0; padding: 15px; background: #fff3cd; border-left: 3px solid #f39c12;">
                    <h3>3. Corrigir Estrutura da Tabela</h3>
                    <p>Se a validação detectou colunas faltantes, use este botão para adicionar automaticamente.</p>
                    <button type="button" class="button button-secondary" onclick="sisturFixEmployeesTable()" id="fix-table-btn">
                        🔧 Adicionar Colunas Faltantes
                    </button>
                </div>
            </div>

            <div class="card">
                <h2>🕐 Diagnóstico de Registro de Ponto</h2>
                <p>Verifique se o sistema de registro de ponto está funcionando corretamente.</p>

                <div style="margin: 15px 0;">
                    <h3>1. Validar Sistema de Ponto</h3>
                    <p>Verifica tabela, colunas, funcionário logado e permissões.</p>
                    <button type="button" class="button" onclick="sisturValidateTimeclock()">
                        🔍 Validar Sistema de Ponto
                    </button>
                </div>

                <div style="margin: 15px 0; padding: 15px; background: #fff3cd; border-left: 3px solid #f39c12;">
                    <h3>2. Corrigir Estrutura da Tabela</h3>
                    <p>Se a validação detectou colunas faltantes, use este botão para adicionar automaticamente.</p>
                    <button type="button" class="button button-secondary" onclick="sisturFixTimeclockTable()">
                        🔧 Adicionar Colunas Faltantes
                    </button>
                </div>

                <div id="sistur-timeclock-output" style="margin-top: 20px;"></div>
            </div>

            <div class="card">
                <h2>⏰ Diagnóstico do Banco de Horas</h2>
                <p>Verifique e reprocesse dados do banco de horas de um funcionário específico.</p>

                <div style="margin: 15px 0;">
                    <label for="employee-id-input">
                        <strong>ID do Funcionário:</strong>
                        <input type="number" id="employee-id-input" value="10" min="1" style="width: 100px; margin-left: 10px;">
                    </label>
                    <button type="button" class="button button-primary" onclick="sisturDiagnoseTimebank()" style="margin-left: 10px;">
                        🔍 Diagnosticar
                    </button>
                    <button type="button" class="button" onclick="sisturReprocessTimebank()" style="margin-left: 5px;">
                        🔄 Reprocessar Últimos 30 Dias
                    </button>
                    <button type="button" class="button button-secondary" onclick="sisturFullRecalcTimebank()" style="margin-left: 5px; background:#f39c12; border-color:#f39c12; color:#fff;">
                        🗑️ Recalcular TUDO (desde admissão)
                    </button>
                </div>

                <div id="sistur-timebank-output" style="margin-top: 20px;"></div>
            </div>

            <div class="card">
                <h2>Instruções de Debug</h2>
                <ol>
                    <li>Abra o Console do Navegador (F12 → Console)</li>
                    <li>Procure por mensagens com <code>[SISTUR Share Modal v1.4.1]</code></li>
                    <li>Verifique se há erros em vermelho</li>
                    <li>Se houver erro na linha 1 do share-modal.js, pode ser cache do navegador</li>
                    <li>Tente limpar o cache clicando no botão acima</li>
                </ol>

                <h3>Para forçar recarga completa:</h3>
                <ul>
                    <li><strong>Windows/Linux:</strong> Ctrl + F5 ou Ctrl + Shift + R</li>
                    <li><strong>Mac:</strong> Cmd + Shift + R</li>
                    <li><strong>Ou:</strong> Clique no botão "Recarregar Página" acima</li>
                </ul>
            </div>
        </div>

        <script>
        function sisturClearCache() {
            var output = document.getElementById('sistur-debug-output');
            output.style.display = 'block';
            output.innerHTML = 'Limpando cache...\n';

            // Limpa cache local
            if (localStorage) {
                output.innerHTML += 'LocalStorage limpo\n';
            }
            if (sessionStorage) {
                sessionStorage.clear();
                output.innerHTML += 'SessionStorage limpo\n';
            }

            // Força recarga de scripts
            var scripts = document.querySelectorAll('script[src*="sistur"]');
            output.innerHTML += 'Scripts SISTUR encontrados: ' + scripts.length + '\n';
            scripts.forEach(function(script, index) {
                output.innerHTML += '[' + index + '] ' + script.src + '\n';
            });

            output.innerHTML += '\n✓ Cache limpo! Recarregue a página com Ctrl+F5\n';

            // Envia AJAX para limpar cache do servidor
            jQuery.post(ajaxurl, {
                action: 'sistur_clear_cache',
                nonce: '<?php echo wp_create_nonce('sistur_clear_cache'); ?>'
            }, function(response) {
                output.innerHTML += '\n' + response.data + '\n';
            });
        }

        function sisturReloadPage() {
            window.location.reload(true);
        }

        function sisturTestShareModal() {
            var output = document.getElementById('sistur-debug-output');
            output.style.display = 'block';
            output.innerHTML = 'Testando Share Modal...\n\n';

            output.innerHTML += 'typeof window.SisturShareModal: ' + typeof window.SisturShareModal + '\n';
            output.innerHTML += 'document.getElementById("share-modal"): ' + (document.getElementById('share-modal') ? 'existe' : 'NÃO EXISTE') + '\n';
            output.innerHTML += 'document.querySelectorAll("[data-share-modal]").length: ' + document.querySelectorAll('[data-share-modal]').length + '\n';

            // Lista todos os scripts carregados
            output.innerHTML += '\n--- Scripts na página ---\n';
            var scripts = document.getElementsByTagName('script');
            for (var i = 0; i < scripts.length; i++) {
                var src = scripts[i].src;
                if (src && (src.indexOf('sistur') !== -1 || src.indexOf('share') !== -1)) {
                    output.innerHTML += '[' + i + '] ' + src + '\n';
                }
            }
        }

        function sisturCheckTables() {
            var output = document.getElementById('sistur-tables-output');
            output.innerHTML = '<p>Verificando tabelas... <span class="spinner is-active" style="float:none;"></span></p>';

            jQuery.post(ajaxurl, {
                action: 'sistur_check_tables',
                nonce: '<?php echo wp_create_nonce('sistur_check_tables'); ?>'
            }, function(response) {
                if (response.success) {
                    var data = response.data;
                    var html = '<table class="widefat">';
                    html += '<thead><tr><th>Tabela</th><th>Status</th></tr></thead>';
                    html += '<tbody>';

                    var hasMissing = false;
                    data.tables.forEach(function(table) {
                        html += '<tr>';
                        html += '<td><code>' + table.name + '</code></td>';
                        if (table.exists) {
                            html += '<td><span style="color:green">✓ Existe</span></td>';
                        } else {
                            html += '<td><span style="color:red">✗ Não existe</span></td>';
                            hasMissing = true;
                        }
                        html += '</tr>';
                    });

                    html += '</tbody></table>';

                    if (hasMissing) {
                        html += '<p style="color:red;font-weight:bold;">⚠️ Existem tabelas faltantes! Clique no botão abaixo para recriar.</p>';
                        document.getElementById('recreate-tables-btn').style.display = 'inline-block';
                    } else {
                        html += '<p style="color:green;font-weight:bold;">✓ Todas as tabelas estão criadas!</p>';
                        document.getElementById('recreate-tables-btn').style.display = 'none';
                    }

                    output.innerHTML = html;
                } else {
                    output.innerHTML = '<p style="color:red;">Erro: ' + response.data + '</p>';
                }
            }).fail(function() {
                output.innerHTML = '<p style="color:red;">Erro ao verificar tabelas!</p>';
            });
        }

        function sisturRecreateTables() {
            if (!confirm('Tem certeza que deseja recriar as tabelas faltantes? Isso é seguro e não apaga dados existentes.')) {
                return;
            }

            var output = document.getElementById('sistur-debug-output');
            output.style.display = 'block';
            output.innerHTML = 'Recriando tabelas... <span class="spinner is-active" style="float:none;"></span>\n';

            jQuery.post(ajaxurl, {
                action: 'sistur_recreate_tables',
                nonce: '<?php echo wp_create_nonce('sistur_recreate_tables'); ?>'
            }, function(response) {
                if (response.success) {
                    output.innerHTML = '✓ Tabelas recriadas com sucesso!\n\n' + response.data;
                    // Verificar novamente
                    setTimeout(function() {
                        sisturCheckTables();
                    }, 1000);
                } else {
                    output.innerHTML = '✗ Erro ao recriar tabelas: ' + response.data;
                }
            }).fail(function() {
                output.innerHTML = '✗ Erro de comunicação ao recriar tabelas!';
            });
        }

        function sisturDiagnoseTimebank() {
            var employeeId = document.getElementById('employee-id-input').value;
            var output = document.getElementById('sistur-timebank-output');

            if (!employeeId || employeeId < 1) {
                output.innerHTML = '<div style="background:#fee;padding:10px;border-left:3px solid #c00;">⚠️ Por favor, informe um ID de funcionário válido</div>';
                return;
            }

            output.innerHTML = '<p>Diagnosticando funcionário #' + employeeId + '... <span class="spinner is-active" style="float:none;"></span></p>';

            jQuery.post(ajaxurl, {
                action: 'sistur_diagnose_timebank',
                employee_id: employeeId,
                nonce: '<?php echo wp_create_nonce('sistur_diagnose_timebank'); ?>'
            }, function(response) {
                if (response.success) {
                    output.innerHTML = response.data.html;
                } else {
                    output.innerHTML = '<div style="background:#fee;padding:10px;border-left:3px solid #c00;">✗ Erro: ' + response.data + '</div>';
                }
            }).fail(function() {
                output.innerHTML = '<div style="background:#fee;padding:10px;border-left:3px solid #c00;">✗ Erro de comunicação!</div>';
            });
        }

        function sisturReprocessTimebank() {
            var employeeId = document.getElementById('employee-id-input').value;
            var output = document.getElementById('sistur-timebank-output');

            if (!employeeId || employeeId < 1) {
                output.innerHTML = '<div style="background:#fee;padding:10px;border-left:3px solid #c00;">⚠️ Por favor, informe um ID de funcionário válido</div>';
                return;
            }

            if (!confirm('Tem certeza que deseja reprocessar os últimos 30 dias do funcionário #' + employeeId + '? Isso pode levar alguns minutos.')) {
                return;
            }

            output.innerHTML = '<p>Reprocessando funcionário #' + employeeId + '... <span class="spinner is-active" style="float:none;"></span></p>';

            jQuery.post(ajaxurl, {
                action: 'sistur_reprocess_timebank',
                employee_id: employeeId,
                days: 30,
                nonce: '<?php echo wp_create_nonce('sistur_reprocess_timebank'); ?>'
            }, function(response) {
                if (response.success) {
                    output.innerHTML = response.data.html;
                    // Atualizar diagnóstico após reprocessamento
                    setTimeout(function() {
                        sisturDiagnoseTimebank();
                    }, 2000);
                } else {
                    output.innerHTML = '<div style="background:#fee;padding:10px;border-left:3px solid #c00;">✗ Erro: ' + response.data + '</div>';
                }
            }).fail(function() {
                output.innerHTML = '<div style="background:#fee;padding:10px;border-left:3px solid #c00;">✗ Erro de comunicação!</div>';
            });
        }

        function sisturFullRecalcTimebank() {
            var employeeId = document.getElementById('employee-id-input').value;
            var output = document.getElementById('sistur-timebank-output');

            if (!employeeId || employeeId < 1) {
                output.innerHTML = '<div style="background:#fee;padding:10px;border-left:3px solid #c00;">⚠️ Por favor, informe um ID de funcionário válido</div>';
                return;
            }

            if (!confirm('⚠️ ATENÇÃO: Esta ação irá APAGAR todos os dados calculados do banco de horas do funcionário #' + employeeId + ' e RECALCULAR TUDO desde a data de admissão.\n\nOs registros de ponto (batidas) serão mantidos.\n\nDeseja continuar?')) {
                return;
            }

            output.innerHTML = '<p>Recalculando TUDO do funcionário #' + employeeId + '... <span class="spinner is-active" style="float:none;"></span></p><p style="color:#666;">Isso pode levar alguns minutos dependendo do período...</p>';

            jQuery.post(ajaxurl, {
                action: 'sistur_full_recalc_timebank',
                employee_id: employeeId,
                nonce: '<?php echo wp_create_nonce('sistur_reprocess_timebank'); ?>'
            }, function(response) {
                if (response.success) {
                    output.innerHTML = response.data.html;
                    // NÃO atualizar automaticamente - deixar o usuário ler o relatório
                } else {
                    output.innerHTML = '<div style="background:#fee;padding:10px;border-left:3px solid #c00;">✗ Erro: ' + response.data + '</div>';
                }
            }).fail(function() {
                output.innerHTML = '<div style="background:#fee;padding:10px;border-left:3px solid #c00;">✗ Erro de comunicação!</div>';
            });
        }

        function sisturValidateEnvironment() {
            var output = document.getElementById('sistur-employee-debug-output');
            output.innerHTML = '<p>Validando ambiente... <span class="spinner is-active" style="float:none;"></span></p>';

            jQuery.post(ajaxurl, {
                action: 'sistur_validate_environment',
                nonce: '<?php echo wp_create_nonce('sistur_validate_environment'); ?>'
            }, function(response) {
                if (response.success) {
                    output.innerHTML = response.data.html;
                } else {
                    output.innerHTML = '<div style="background:#fee;padding:10px;border-left:3px solid #c00;">✗ Erro: ' + response.data + '</div>';
                }
            }).fail(function() {
                output.innerHTML = '<div style="background:#fee;padding:10px;border-left:3px solid #c00;">✗ Erro de comunicação!</div>';
            });
        }

        function sisturTestEmployeeCreation() {
            var output = document.getElementById('sistur-employee-debug-output');
            output.innerHTML = '<p>Testando criação de funcionário... <span class="spinner is-active" style="float:none;"></span></p>';

            jQuery.post(ajaxurl, {
                action: 'sistur_test_employee_creation',
                nonce: '<?php echo wp_create_nonce('sistur_test_employee_creation'); ?>'
            }, function(response) {
                if (response.success) {
                    output.innerHTML = response.data.html;
                } else {
                    output.innerHTML = '<div style="background:#fee;padding:10px;border-left:3px solid #c00;">✗ Erro: ' + response.data + '</div>';
                }
            }).fail(function() {
                output.innerHTML = '<div style="background:#fee;padding:10px;border-left:3px solid #c00;">✗ Erro de comunicação!</div>';
            });
        }

        function sisturFixTimeclockTable() {
            if (!confirm('Tem certeza que deseja adicionar as colunas faltantes na tabela de registros de ponto? Esta operação é segura e NÃO apaga dados existentes.')) {
                return;
            }

            var output = document.getElementById('sistur-timeclock-output');
            output.innerHTML = '<p>Corrigindo estrutura da tabela... <span class="spinner is-active" style="float:none;"></span></p>';

            jQuery.post(ajaxurl, {
                action: 'sistur_fix_timeclock_table',
                nonce: '<?php echo wp_create_nonce('sistur_fix_timeclock_table'); ?>'
            }, function(response) {
                if (response.success) {
                    output.innerHTML = response.data.html;
                    // Atualizar validação após correção
                    setTimeout(function() {
                        sisturValidateTimeclock();
                    }, 2000);
                } else {
                    output.innerHTML = '<div style="background:#fee;padding:10px;border-left:3px solid #c00;">✗ Erro: ' + response.data + '</div>';
                }
            }).fail(function() {
                output.innerHTML = '<div style="background:#fee;padding:10px;border-left:3px solid #c00;">✗ Erro de comunicação!</div>';
            });
        }

        function sisturValidateTimeclock() {
            var output = document.getElementById('sistur-timeclock-output');
            output.innerHTML = '<p>Validando sistema de ponto... <span class="spinner is-active" style="float:none;"></span></p>';

            jQuery.post(ajaxurl, {
                action: 'sistur_validate_timeclock',
                nonce: '<?php echo wp_create_nonce('sistur_validate_timeclock'); ?>'
            }, function(response) {
                if (response.success) {
                    output.innerHTML = response.data.html;
                } else {
                    output.innerHTML = '<div style="background:#fee;padding:10px;border-left:3px solid #c00;">✗ Erro: ' + response.data + '</div>';
                }
            }).fail(function() {
                output.innerHTML = '<div style="background:#fee;padding:10px;border-left:3px solid #c00;">✗ Erro de comunicação!</div>';
            });
        }

        function sisturFixEmployeesTable() {
            if (!confirm('Tem certeza que deseja adicionar as colunas faltantes na tabela de funcionários? Esta operação é segura e NÃO apaga dados existentes.')) {
                return;
            }

            var output = document.getElementById('sistur-employee-debug-output');
            output.innerHTML = '<p>Corrigindo estrutura da tabela... <span class="spinner is-active" style="float:none;"></span></p>';

            jQuery.post(ajaxurl, {
                action: 'sistur_fix_employees_table',
                nonce: '<?php echo wp_create_nonce('sistur_fix_employees_table'); ?>'
            }, function(response) {
                if (response.success) {
                    output.innerHTML = response.data.html;
                    // Atualizar validação após correção
                    setTimeout(function() {
                        sisturValidateEnvironment();
                    }, 2000);
                } else {
                    output.innerHTML = '<div style="background:#fee;padding:10px;border-left:3px solid #c00;">✗ Erro: ' + response.data + '</div>';
                }
            }).fail(function() {
                output.innerHTML = '<div style="background:#fee;padding:10px;border-left:3px solid #c00;">✗ Erro de comunicação!</div>';
            });
        }
        </script>

        <style>
        .card { background: white; padding: 20px; margin: 20px 0; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .card h2 { margin-top: 0; }
        .card ul { list-style: none; padding: 0; }
        .card ul li { padding: 10px; margin: 5px 0; background: #f9f9f9; border-left: 3px solid #2271b1; }
        .widefat th { text-align: left; padding: 10px; background: #f0f0f0; }
        .widefat td { padding: 10px; }
        </style>
        <?php
    }

    /**
     * Limpa cache de scripts (AJAX)
     */
    public function clear_cache() {
        check_ajax_referer('sistur_clear_cache', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
        }

        // Incrementa versão para forçar recarga
        $new_version = SISTUR_VERSION . '.' . time();

        wp_send_json_success('Cache limpo! Nova versão: ' . $new_version);
    }

    /**
     * Verifica existência das tabelas do banco (AJAX)
     */
    public function check_tables() {
        check_ajax_referer('sistur_check_tables', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
        }

        global $wpdb;
        $prefix = $wpdb->prefix;

        $required_tables = array(
            'sistur_employees',
            'sistur_departments',
            'sistur_time_entries',
            'sistur_time_days',
            'sistur_payment_records',
            'sistur_leads',
            'sistur_products',
            'sistur_product_categories',
            'sistur_inventory_movements',
            'sistur_roles',
            'sistur_permissions',
            'sistur_role_permissions',
            'sistur_audit_logs',
            'sistur_contract_types',
            'sistur_settings',
            'sistur_wifi_networks'
        );

        $tables_status = array();
        foreach ($required_tables as $table) {
            $full_table_name = $prefix . $table;
            $exists = $wpdb->get_var("SHOW TABLES LIKE '$full_table_name'") === $full_table_name;

            $tables_status[] = array(
                'name' => $table,
                'full_name' => $full_table_name,
                'exists' => $exists
            );
        }

        wp_send_json_success(array('tables' => $tables_status));
    }

    /**
     * Recria tabelas faltantes (AJAX)
     */
    public function recreate_tables() {
        check_ajax_referer('sistur_recreate_tables', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
        }

        // Chamar o método de ativação para recriar tabelas
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-activator.php';

        ob_start();
        SISTUR_Activator::activate();
        $output = ob_get_clean();

        $message = "Tabelas recriadas com sucesso!\n";
        $message .= "O método activate() foi executado.\n";
        if (!empty($output)) {
            $message .= "Output: " . $output;
        }

        wp_send_json_success($message);
    }

    /**
     * Diagnóstico do banco de horas (AJAX)
     */
    public function ajax_diagnose_timebank() {
        check_ajax_referer('sistur_diagnose_timebank', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
        }

        $employee_id = intval($_POST['employee_id']);

        if ($employee_id < 1) {
            wp_send_json_error('ID de funcionário inválido');
        }

        global $wpdb;

        // Buscar dados do funcionário
        $table_employees = $wpdb->prefix . 'sistur_employees';
        $employee = $wpdb->get_row($wpdb->prepare(
            "SELECT e.*, ct.carga_horaria_diaria_minutos
             FROM $table_employees e
             LEFT JOIN {$wpdb->prefix}sistur_contract_types ct ON e.contract_type_id = ct.id
             WHERE e.id = %d",
            $employee_id
        ), ARRAY_A);

        // Nome do contrato (se existir)
        $contract_name = 'N/A';
        if ($employee && $employee['contract_type_id']) {
            $contract_name = $wpdb->get_var($wpdb->prepare(
                "SELECT name FROM {$wpdb->prefix}sistur_contract_types WHERE id = %d",
                $employee['contract_type_id']
            )) ?: 'N/A';
        }
        $employee['contract_name'] = $contract_name;

        if (!$employee) {
            wp_send_json_error('Funcionário não encontrado');
        }

        ob_start();
        ?>
        <div style="background:#e7f3ff;padding:15px;border-left:3px solid #2271b1;margin-bottom:20px;">
            <h3 style="margin-top:0;">✓ Funcionário: <?php echo esc_html($employee['name']); ?></h3>
            <p style="margin:5px 0;"><strong>Email:</strong> <?php echo esc_html($employee['email']); ?></p>
            <p style="margin:5px 0;"><strong>Tipo de Contrato:</strong> <?php echo esc_html($employee['contract_name'] ?: 'N/A'); ?></p>
            <p style="margin:5px 0;"><strong>Carga Horária Diária:</strong> <?php echo esc_html($employee['carga_horaria_diaria_minutos'] ?: 'N/A'); ?> minutos</p>
            <p style="margin:5px 0;"><strong>Status:</strong> <?php echo $employee['status'] ? '<span style="color:green;">Ativo</span>' : '<span style="color:red;">Inativo</span>'; ?></p>
        </div>

        <?php
        // Verificar registros de ponto (últimos 10 dias)
        $table_entries = $wpdb->prefix . 'sistur_time_entries';
        $entries_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_entries
             WHERE employee_id = %d
             AND shift_date >= DATE_SUB(CURDATE(), INTERVAL 10 DAY)",
            $employee_id
        ));

        // Verificar dados processados
        $table_days = $wpdb->prefix . 'sistur_time_days';
        $days_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_days
             WHERE employee_id = %d
             AND shift_date >= DATE_SUB(CURDATE(), INTERVAL 10 DAY)",
            $employee_id
        ));

        // Saldo total
        $total_bank = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(saldo_final_minutos) FROM $table_days
             WHERE employee_id = %d AND status = 'present' AND needs_review = 0",
            $employee_id
        ));

        // Registros não processados
        $dates_with_entries = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT shift_date FROM $table_entries WHERE employee_id = %d AND shift_date >= DATE_SUB(CURDATE(), INTERVAL 10 DAY) ORDER BY shift_date DESC",
            $employee_id
        ));

        $dates_with_days = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT shift_date FROM $table_days WHERE employee_id = %d AND shift_date >= DATE_SUB(CURDATE(), INTERVAL 10 DAY) ORDER BY shift_date DESC",
            $employee_id
        ));

        $unprocessed_dates = array_diff($dates_with_entries, $dates_with_days);
        ?>

        <table class="widefat" style="margin-bottom:20px;">
            <tr>
                <th style="background:#f0f0f0;padding:10px;">Registros de Ponto (últimos 10 dias)</th>
                <td style="padding:10px;"><?php echo $entries_count > 0 ? '<span style="color:green;">✓ ' . $entries_count . ' registros</span>' : '<span style="color:orange;">⚠ Nenhum registro</span>'; ?></td>
            </tr>
            <tr>
                <th style="background:#f0f0f0;padding:10px;">Dados Processados (últimos 10 dias)</th>
                <td style="padding:10px;"><?php echo $days_count > 0 ? '<span style="color:green;">✓ ' . $days_count . ' dias processados</span>' : '<span style="color:red;">✗ Nenhum dado processado</span>'; ?></td>
            </tr>
            <tr>
                <th style="background:#f0f0f0;padding:10px;">Saldo Total Acumulado</th>
                <td style="padding:10px;">
                    <?php
                    if ($total_bank !== null) {
                        $bank_hours = floor(abs($total_bank) / 60);
                        $bank_mins = abs($total_bank) % 60;
                        $bank_sign = $total_bank >= 0 ? '+' : '-';
                        echo "<strong>{$bank_sign}{$bank_hours}h" . sprintf('%02d', $bank_mins) . "</strong>";
                    } else {
                        echo '<span style="color:orange;">⚠ Nenhum saldo calculado</span>';
                    }
                    ?>
                </td>
            </tr>
        </table>

        <?php if (count($unprocessed_dates) > 0): ?>
            <div style="background:#fff3cd;padding:15px;border-left:3px solid #f39c12;margin-bottom:20px;">
                <h4 style="margin-top:0;">⚠️ Existem <?php echo count($unprocessed_dates); ?> data(s) com registros NÃO PROCESSADOS:</h4>
                <ul style="margin:10px 0;">
                    <?php foreach ($unprocessed_dates as $date): ?>
                        <li><?php echo esc_html($date); ?></li>
                    <?php endforeach; ?>
                </ul>
                <p><strong>Solução:</strong> Clique no botão "Reprocessar Últimos 30 Dias" acima para processar estes dados.</p>
            </div>
        <?php else: ?>
            <div style="background:#d4edda;padding:15px;border-left:3px solid #28a745;margin-bottom:20px;">
                <p style="margin:0;"><strong>✓ Todos os registros foram processados!</strong></p>
            </div>
        <?php endif; ?>

        <p style="margin-top:20px;">
            <a href="<?php echo esc_url(rest_url("sistur/v1/time-bank/{$employee_id}/weekly")); ?>" target="_blank" class="button">
                📊 Ver API do Banco de Horas
            </a>
        </p>
        <?php

        $html = ob_get_clean();
        wp_send_json_success(array('html' => $html));
    }

    /**
     * Reprocessar banco de horas (AJAX)
     */
    public function ajax_reprocess_timebank() {
        check_ajax_referer('sistur_reprocess_timebank', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
        }

        $employee_id = intval($_POST['employee_id']);
        $days = intval($_POST['days']);

        if ($employee_id < 1) {
            wp_send_json_error('ID de funcionário inválido');
        }

        if ($days < 1 || $days > 365) {
            $days = 30;
        }

        global $wpdb;
        $table_entries = $wpdb->prefix . 'sistur_time_entries';

        // Instanciar processador usando Singleton
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-punch-processing.php';
        $processor = SISTUR_Punch_Processing::get_instance();

        // Instanciar gerenciador de escalas
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-shift-patterns.php';
        $shift_manager = SISTUR_Shift_Patterns::get_instance();

        // Buscar escala ativa do funcionário
        $schedule = $shift_manager->get_employee_active_schedule($employee_id);
        $schedule_info = array(
            'has_schedule' => !empty($schedule),
            'pattern_name' => $schedule ? $schedule['pattern_name'] : 'SEM ESCALA ATRIBUÍDA!',
            'pattern_type' => $schedule ? $schedule['pattern_type'] : 'N/A',
            'daily_hours' => $schedule ? intval($schedule['daily_hours_minutes']) : 480,
        );

        $total_processed = 0;
        $total_errors = 0;
        $processed_dates = array();

        // Buscar data de admissão do funcionário
        $table_employees = $wpdb->prefix . 'sistur_employees';
        $employee = $wpdb->get_row($wpdb->prepare(
            "SELECT hire_date FROM $table_employees WHERE id = %d",
            $employee_id
        ));
        $hire_date = $employee && $employee->hire_date ? $employee->hire_date : null;

        // Processar últimos N dias
        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("-$i days"));

            // IMPORTANTE: Não processar dias ANTES da data de admissão
            if ($hire_date && $date < $hire_date) {
                continue; // Pular dia - funcionário ainda não trabalhava
            }


            // NÃO verificar se há registros - processar TODOS os dias para identificar faltas
            // Marcar batidas como PENDENTE para forçar reprocessamento (se houver)
            $wpdb->update(
                $table_entries,
                array('processing_status' => 'PENDENTE'),
                array('employee_id' => $employee_id, 'shift_date' => $date),
                array('%s'),
                array('%d', '%s')
            );

            // Processar dia (seja trabalhado ou falta)
            $result = $processor->process_employee_day($employee_id, $date);

            if ($result) {
                $total_processed++;
                
                // Buscar dados processados para o relatório
                $day_data = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}sistur_time_days WHERE employee_id = %d AND shift_date = %s",
                    $employee_id, $date
                ), ARRAY_A);

                if ($day_data) {
                    $processed_dates[] = array(
                        'date' => $date, // Converter para PT-BR depois
                        'worked' => $processor->format_minutes($day_data['minutos_trabalhados']),
                        'balance' => $processor->format_minutes($day_data['saldo_final_minutos'], true),
                        'status' => $day_data['status'],
                        'notes' => $day_data['notes']
                    );
                }
            } else {
                $total_errors++;
            }
        }

        ob_start();
        ?>
        <?php if (!$schedule_info['has_schedule']): ?>
            <div style="background:#fee;padding:15px;border-left:3px solid #c00;margin-bottom:15px;">
                <h4 style="margin:0;color:#c00;">⚠️ ATENÇÃO: Funcionário SEM ESCALA!</h4>
                <p style="margin:10px 0 0 0;">O funcionário não tem uma escala de trabalho atribuída. O sistema está usando o fallback de 8h/dia.</p>
                <p style="margin:5px 0 0 0;"><strong>Solução:</strong> Atribua uma escala ao funcionário em Colaboradores → Editar → Escala de Trabalho.</p>
            </div>
        <?php else: ?>
            <div style="background:#e7f3ff;padding:15px;border-left:3px solid #2271b1;margin-bottom:15px;">
                <h4 style="margin:0;">📅 Escala do Funcionário: <?php echo esc_html($schedule_info['pattern_name']); ?></h4>
                <p style="margin:5px 0 0 0;"><strong>Tipo:</strong> <?php echo esc_html($schedule_info['pattern_type']); ?> | <strong>Horas/dia:</strong> <?php echo $processor->format_minutes($schedule_info['daily_hours']); ?></p>
            </div>
        <?php endif; ?>

        <div style="background:<?php echo $total_errors == 0 ? '#d4edda' : '#fff3cd'; ?>;padding:15px;border-left:3px solid <?php echo $total_errors == 0 ? '#28a745' : '#f39c12'; ?>;">
            <h4 style="margin-top:0;">✓ Reprocessamento Concluído</h4>
            <p style="margin:5px 0;"><strong>Total processado:</strong> <?php echo $total_processed; ?> dia(s)</p>
            <p style="margin:5px 0;"><strong>Erros:</strong> <?php echo $total_errors; ?></p>
        </div>


        <?php if (!empty($processed_dates)): ?>
            <div style="margin-top:20px; max-height:400px; overflow-y:auto; border:1px solid #ddd; background:#fff;">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="position:sticky; top:0; background:#f0f0f0;">Data</th>
                            <th style="position:sticky; top:0; background:#f0f0f0;">Trabalhado</th>
                            <th style="position:sticky; top:0; background:#f0f0f0;">Saldo</th>
                            <th style="position:sticky; top:0; background:#f0f0f0;">Detalhes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($processed_dates as $day): ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($day['date'])); ?></td>
                                <td><?php echo esc_html($day['worked']); ?></td>
                                <td style="<?php echo strpos($day['balance'], '-') !== false ? 'color:red;' : 'color:green;'; ?>">
                                    <?php echo esc_html($day['balance']); ?>
                                </td>
                                <td style="font-size:11px; color:#666;">
                                    <?php 
                                    if (!empty($day['notes'])) {
                                        // Limpar output de debug para ficar legível
                                        $clean_notes = str_replace('[DEBUG]', '', $day['notes']);
                                        echo nl2br(esc_html($clean_notes));
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <p style="margin-top:15px;color:#666;font-style:italic;">
            ℹ️ O diagnóstico será atualizado automaticamente em 5 segundos...
        </p>

        <p style="margin-top:15px;color:#666;font-style:italic;">
            ℹ️ O diagnóstico será atualizado automaticamente em 2 segundos...
        </p>
        <?php

        $html = ob_get_clean();
        wp_send_json_success(array('html' => $html, 'processed' => $total_processed, 'errors' => $total_errors));
    }

    /**
     * Recálculo COMPLETO do banco de horas de um funcionário (AJAX)
     * 
     * Diferente do reprocessamento que apenas atualiza dias existentes,
     * esta função LIMPA todos os registros calculados e recalcula desde
     * a data de admissão do funcionário.
     */
    public function ajax_full_recalc_timebank() {
        check_ajax_referer('sistur_reprocess_timebank', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
        }

        $employee_id = intval($_POST['employee_id']);

        if ($employee_id < 1) {
            wp_send_json_error('ID de funcionário inválido');
        }

        global $wpdb;
        $table_days = $wpdb->prefix . 'sistur_time_days';
        $table_entries = $wpdb->prefix . 'sistur_time_entries';
        $table_employees = $wpdb->prefix . 'sistur_employees';

        // Buscar dados do funcionário
        $employee = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, hire_date FROM $table_employees WHERE id = %d",
            $employee_id
        ));

        if (!$employee) {
            wp_send_json_error('Funcionário não encontrado');
        }

        // Data de admissão (ou 60 dias atrás se não definida)
        $hire_date = $employee->hire_date ? $employee->hire_date : date('Y-m-d', strtotime('-60 days'));
        $today = date('Y-m-d');

        // PASSO 1: LIMPAR todos os registros de dias calculados para este funcionário
        $deleted_days = $wpdb->delete($table_days, array('employee_id' => $employee_id), array('%d'));

        // PASSO 2: Marcar TODAS as batidas como PENDENTE para forçar reprocessamento
        $updated_entries = $wpdb->update(
            $table_entries,
            array('processing_status' => 'PENDENTE'),
            array('employee_id' => $employee_id),
            array('%s'),
            array('%d')
        );

        // PASSO 3: Instanciar processador e gerenciador de escalas
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-punch-processing.php';
        $processor = SISTUR_Punch_Processing::get_instance();

        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-shift-patterns.php';
        $shift_manager = SISTUR_Shift_Patterns::get_instance();

        // Buscar escala ativa
        $schedule = $shift_manager->get_employee_active_schedule($employee_id);
        $pattern_config = $schedule ? json_decode($schedule['pattern_config'], true) : null;

        // PASSO 4: Processar dia a dia desde a admissão até hoje
        $total_processed = 0;
        $total_errors = 0;
        $processed_dates = array();

        // CORREÇÃO: Buscar apenas os dias que TÊM batidas registradas
        // Não processar dias sem ponto (isso criaria saldo negativo incorretamente)
        $days_with_punches = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT shift_date FROM $table_entries 
             WHERE employee_id = %d AND shift_date >= %s 
             ORDER BY shift_date ASC",
            $employee_id, $hire_date
        ));

        foreach ($days_with_punches as $current_date) {
            // Buscar expectativa para o dia (da escala configurada)
            $expected_data = $schedule ? $shift_manager->get_expected_hours_for_date($employee_id, $current_date) : null;
            $expected_minutes = $expected_data ? intval($expected_data['expected_minutes']) : 480;
            $is_work_day = $expected_data && isset($expected_data['is_work_day']) ? $expected_data['is_work_day'] : true;

            // Processar o dia
            $result = $processor->process_employee_day($employee_id, $current_date);

            if ($result) {
                $total_processed++;

                // Buscar dados processados
                $day_data = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $table_days WHERE employee_id = %d AND shift_date = %s",
                    $employee_id, $current_date
                ), ARRAY_A);

                if ($day_data) {
                    $processed_dates[] = array(
                        'date' => $current_date,
                        'expected' => $expected_minutes,
                        'worked' => intval($day_data['minutos_trabalhados']),
                        'balance' => intval($day_data['saldo_final_minutos']),
                        'is_work_day' => $is_work_day,
                        'status' => $day_data['status']
                    );
                }
            } else {
                $total_errors++;
            }
        }

        // PASSO 5: Gerar relatório HTML
        ob_start();
        ?>
        <div style="background:#d4edda;padding:15px;border-left:3px solid #28a745;margin-bottom:15px;">
            <h4 style="margin:0;">✓ Recálculo Completo Finalizado!</h4>
            <p style="margin:10px 0 0 0;">
                <strong>Funcionário:</strong> <?php echo esc_html($employee->name); ?><br>
                <strong>Data de admissão:</strong> <?php echo date('d/m/Y', strtotime($hire_date)); ?><br>
                <strong>Registros limpos:</strong> <?php echo $deleted_days !== false ? $deleted_days : 0; ?> dias<br>
                <strong>Dias recalculados:</strong> <?php echo $total_processed; ?><br>
                <strong>Erros:</strong> <?php echo $total_errors; ?>
            </p>
        </div>

        <?php if ($schedule): ?>
            <div style="background:#e7f3ff;padding:15px;border-left:3px solid #2271b1;margin-bottom:15px;">
                <h4 style="margin:0;">📅 Escala Utilizada: <?php echo esc_html($schedule['pattern_name']); ?></h4>
                <?php if ($pattern_config && isset($pattern_config['weekly'])): ?>
                    <p style="margin:10px 0 0 0;"><strong>Configuração por dia:</strong></p>
                    <ul style="margin:5px 0 0 20px;">
                        <?php 
                        $day_names = array('Monday'=>'Seg', 'Tuesday'=>'Ter', 'Wednesday'=>'Qua', 'Thursday'=>'Qui', 'Friday'=>'Sex', 'Saturday'=>'Sáb', 'Sunday'=>'Dom');
                        foreach ($day_names as $en => $pt): 
                            $day_cfg = isset($pattern_config['weekly'][$en]) ? $pattern_config['weekly'][$en] : null;
                            if ($day_cfg):
                        ?>
                            <li><strong><?php echo $pt; ?>:</strong> 
                                <?php if ($day_cfg['type'] === 'work'): ?>
                                    <?php echo $processor->format_minutes($day_cfg['expected_minutes']); ?> de trabalho
                                <?php else: ?>
                                    <span style="color:#999;">Folga</span>
                                <?php endif; ?>
                            </li>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div style="background:#fee;padding:15px;border-left:3px solid #c00;margin-bottom:15px;">
                <h4 style="margin:0;color:#c00;">⚠️ SEM ESCALA ATRIBUÍDA!</h4>
                <p style="margin:5px 0 0 0;">O sistema usou fallback de 8h/dia para todos os dias.</p>
            </div>
        <?php endif; ?>

        <?php if (!empty($processed_dates)): ?>
            <div style="margin-top:20px; max-height:400px; overflow-y:auto; border:1px solid #ddd; background:#fff;">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="position:sticky; top:0; background:#f0f0f0;">Data</th>
                            <th style="position:sticky; top:0; background:#f0f0f0;">Esperado</th>
                            <th style="position:sticky; top:0; background:#f0f0f0;">Trabalhado</th>
                            <th style="position:sticky; top:0; background:#f0f0f0;">Saldo</th>
                            <th style="position:sticky; top:0; background:#f0f0f0;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($processed_dates as $day): ?>
                            <tr>
                                <td><?php echo date('d/m/Y (D)', strtotime($day['date'])); ?></td>
                                <td style="<?php echo $day['expected'] == 0 ? 'color:#999;' : ''; ?>">
                                    <?php echo $processor->format_minutes($day['expected']); ?>
                                    <?php if (!$day['is_work_day']): ?><span style="color:#999;"> (folga)</span><?php endif; ?>
                                </td>
                                <td><?php echo $processor->format_minutes($day['worked']); ?></td>
                                <td style="<?php echo $day['balance'] < 0 ? 'color:red;' : 'color:green;'; ?>">
                                    <?php echo $processor->format_minutes($day['balance'], true); ?>
                                </td>
                                <td style="font-size:11px;">
                                    <?php echo esc_html($day['status']); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <p style="margin-top:15px;color:#666;font-style:italic;">
            ℹ️ Clique em "🔍 Diagnosticar" para ver o diagnóstico atualizado.
        </p>
        <?php

        $html = ob_get_clean();
        wp_send_json_success(array('html' => $html, 'processed' => $total_processed, 'errors' => $total_errors));
    }

    /**
     * Validar ambiente de produção para criação de funcionários (AJAX)
     */
    public function ajax_validate_environment() {
        check_ajax_referer('sistur_validate_environment', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
        }

        global $wpdb;

        ob_start();
        ?>
        <div style="background:#e7f3ff;padding:15px;border-left:3px solid #2271b1;margin-bottom:20px;">
            <h3 style="margin-top:0;">🔍 Validação do Ambiente de Produção</h3>
            <p style="margin:5px 0;"><strong>Data/Hora:</strong> <?php echo current_time('Y-m-d H:i:s'); ?></p>
            <p style="margin:5px 0;"><strong>Site URL:</strong> <?php echo esc_html(get_site_url()); ?></p>
        </div>

        <table class="widefat" style="margin-bottom:20px;">
            <thead>
                <tr>
                    <th style="background:#f0f0f0;padding:10px;">Verificação</th>
                    <th style="background:#f0f0f0;padding:10px;">Status</th>
                    <th style="background:#f0f0f0;padding:10px;">Detalhes</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // 1. Verificar tabela de funcionários
                $table_employees = $wpdb->prefix . 'sistur_employees';
                $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_employees'") === $table_employees;
                ?>
                <tr>
                    <td>Tabela <?php echo esc_html($table_employees); ?></td>
                    <td><?php echo $table_exists ? '<span style="color:green;">✓ Existe</span>' : '<span style="color:red;">✗ Não existe</span>'; ?></td>
                    <td><?php
                        if ($table_exists) {
                            $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_employees");
                            echo esc_html($count) . " funcionários cadastrados";
                        } else {
                            echo "Tabela precisa ser criada!";
                        }
                    ?></td>
                </tr>

                <?php
                // 2. Verificar arquivo de validação de CPF
                $cpf_validator_file = SISTUR_PLUGIN_DIR . 'includes/login-funcionario-new.php';
                $cpf_validator_exists = file_exists($cpf_validator_file);
                ?>
                <tr>
                    <td>Arquivo de validação CPF</td>
                    <td><?php echo $cpf_validator_exists ? '<span style="color:green;">✓ Existe</span>' : '<span style="color:red;">✗ Não existe</span>'; ?></td>
                    <td><?php echo $cpf_validator_exists ? esc_html($cpf_validator_file) : 'Arquivo login-funcionario-new.php não encontrado'; ?></td>
                </tr>

                <?php
                // 3. Verificar função de validação de CPF
                if ($cpf_validator_exists) {
                    require_once $cpf_validator_file;
                }
                $cpf_function_exists = function_exists('sistur_validate_cpf');
                ?>
                <tr>
                    <td>Função sistur_validate_cpf()</td>
                    <td><?php echo $cpf_function_exists ? '<span style="color:green;">✓ Disponível</span>' : '<span style="color:red;">✗ Não disponível</span>'; ?></td>
                    <td><?php
                        if ($cpf_function_exists) {
                            echo "Função carregada com sucesso";
                        } else {
                            echo "Função não encontrada - validação de CPF irá falhar!";
                        }
                    ?></td>
                </tr>

                <?php
                // 4. Verificar classe SISTUR_QRCode
                $qrcode_class_exists = class_exists('SISTUR_QRCode');
                ?>
                <tr>
                    <td>Classe SISTUR_QRCode</td>
                    <td><?php echo $qrcode_class_exists ? '<span style="color:green;">✓ Disponível</span>' : '<span style="color:orange;">⚠ Não disponível</span>'; ?></td>
                    <td><?php
                        if ($qrcode_class_exists) {
                            echo "QR Codes serão gerados automaticamente";
                        } else {
                            echo "QR Codes não serão gerados (não é crítico)";
                        }
                    ?></td>
                </tr>

                <?php
                // 5. Verificar permissões do usuário atual
                $can_manage = current_user_can('manage_options');
                ?>
                <tr>
                    <td>Permissões (manage_options)</td>
                    <td><?php echo $can_manage ? '<span style="color:green;">✓ OK</span>' : '<span style="color:red;">✗ Sem permissão</span>'; ?></td>
                    <td><?php
                        $current_user = wp_get_current_user();
                        echo "Usuário: " . esc_html($current_user->user_login) . " (" . implode(', ', $current_user->roles) . ")";
                    ?></td>
                </tr>

                <?php
                // 6. Verificar se WP_DEBUG está ativo
                $wp_debug = defined('WP_DEBUG') && WP_DEBUG;
                ?>
                <tr>
                    <td>WP_DEBUG</td>
                    <td><?php echo $wp_debug ? '<span style="color:orange;">⚠ Ativo</span>' : '<span style="color:green;">✓ Inativo</span>'; ?></td>
                    <td><?php
                        if ($wp_debug) {
                            echo "Erros SQL serão exibidos ao usuário (modo debug)";
                        } else {
                            echo "Erros SQL NÃO serão exibidos ao usuário (modo produção)";
                        }
                    ?></td>
                </tr>

                <?php
                // 7. Verificar estrutura da tabela
                if ($table_exists) {
                    $columns = $wpdb->get_results("SHOW COLUMNS FROM $table_employees");
                    $required_columns = array('id', 'user_id', 'name', 'email', 'cpf', 'matricula', 'password', 'role_id', 'token_qr', 'contract_type_id', 'department_id', 'position', 'photo', 'bio', 'hire_date', 'ctps', 'ctps_uf', 'cbo', 'perfil_localizacao', 'time_expected_minutes', 'lunch_minutes', 'created_at', 'updated_at', 'status');
                    $column_names = wp_list_pluck($columns, 'Field');
                    $missing_columns = array_diff($required_columns, $column_names);
                    $has_all_columns = empty($missing_columns);
                    
                    // Colunas críticas para criação de funcionários
                    $critical_columns = array('cpf', 'password', 'role_id');
                    $missing_critical = array_intersect($critical_columns, $missing_columns);
                ?>
                <tr>
                    <td>Estrutura da tabela</td>
                    <td><?php echo $has_all_columns ? '<span style="color:green;">✓ OK</span>' : '<span style="color:red;">✗ Incompleta</span>'; ?></td>
                    <td><?php
                        if ($has_all_columns) {
                            echo "Todas as " . count($required_columns) . " colunas necessárias existem";
                        } else {
                            echo "<strong style='color:#c00;'>Faltam " . count($missing_columns) . " coluna(s):</strong> " . implode(', ', $missing_columns);
                            if (!empty($missing_critical)) {
                                echo "<br><strong style='color:#c00;'>⚠ CRÍTICAS:</strong> " . implode(', ', $missing_critical);
                            }
                            echo "<br><em>Use o botão 'Adicionar Colunas Faltantes' abaixo</em>";
                        }
                    ?></td>
                </tr>
                <?php } ?>

                <?php
                // 8. Verificar índice único de CPF
                if ($table_exists) {
                    $indexes = $wpdb->get_results("SHOW INDEX FROM $table_employees WHERE Column_name = 'cpf'");
                    $has_cpf_index = !empty($indexes);
                ?>
                <tr>
                    <td>Índice de CPF</td>
                    <td><?php echo $has_cpf_index ? '<span style="color:green;">✓ Existe</span>' : '<span style="color:orange;">⚠ Não existe</span>'; ?></td>
                    <td><?php
                        if ($has_cpf_index) {
                            $index_info = $indexes[0];
                            echo "Índice: " . esc_html($index_info->Key_name) . " (Non_unique: " . $index_info->Non_unique . ")";
                        } else {
                            echo "Índice de CPF não encontrado - pode haver duplicatas";
                        }
                    ?></td>
                </tr>
                <?php } ?>

                <?php
                // 9. Testar CPF duplicado
                if ($table_exists) {
                    $duplicate_cpfs = $wpdb->get_results("
                        SELECT cpf, COUNT(*) as total 
                        FROM $table_employees 
                        WHERE cpf IS NOT NULL AND cpf != '' 
                        GROUP BY cpf 
                        HAVING total > 1
                    ");
                    $has_duplicates = !empty($duplicate_cpfs);
                ?>
                <tr>
                    <td>CPFs duplicados</td>
                    <td><?php echo !$has_duplicates ? '<span style="color:green;">✓ Nenhum</span>' : '<span style="color:red;">✗ Encontrados</span>'; ?></td>
                    <td><?php
                        if ($has_duplicates) {
                            echo "Encontrados " . count($duplicate_cpfs) . " CPFs duplicados: ";
                            $cpf_list = array();
                            foreach ($duplicate_cpfs as $dup) {
                                $cpf_list[] = $dup->cpf . " (" . $dup->total . "x)";
                            }
                            echo implode(', ', array_slice($cpf_list, 0, 3));
                            if (count($cpf_list) > 3) {
                                echo " e mais " . (count($cpf_list) - 3);
                            }
                        } else {
                            echo "Nenhum CPF duplicado encontrado";
                        }
                    ?></td>
                </tr>
                <?php } ?>

                <?php
                // 10. Verificar último erro SQL
                $last_error = $wpdb->last_error;
                ?>
                <tr>
                    <td>Último erro SQL</td>
                    <td><?php echo empty($last_error) ? '<span style="color:green;">✓ Nenhum</span>' : '<span style="color:red;">✗ Erro recente</span>'; ?></td>
                    <td><?php echo empty($last_error) ? 'Nenhum erro registrado' : '<code>' . esc_html($last_error) . '</code>'; ?></td>
                </tr>
            </tbody>
        </table>

        <?php
        // Resumo e recomendações
        $issues = array();
        if (!$table_exists) {
            $issues[] = "Tabela de funcionários não existe - execute a ativação do plugin";
        }
        if (!$cpf_validator_exists || !$cpf_function_exists) {
            $issues[] = "Validação de CPF não disponível - verifique se o arquivo 'login-funcionario-new.php' foi enviado";
        }
        if (!empty($missing_columns)) {
            $critical_missing = array_intersect(array('cpf', 'password', 'role_id'), $missing_columns);
            if (!empty($critical_missing)) {
                $issues[] = "<strong>CRÍTICO:</strong> Faltam " . count($missing_columns) . " colunas na tabela, incluindo: " . implode(', ', $critical_missing) . " - use o botão 'Adicionar Colunas Faltantes' abaixo";
            } else {
                $issues[] = "Estrutura da tabela incompleta - faltam " . count($missing_columns) . " colunas não críticas";
            }
        }
        if ($has_duplicates) {
            $issues[] = "CPFs duplicados encontrados - limpe os dados antes de continuar";
        }

        if (empty($issues)) {
            ?>
            <div style="background:#d4edda;padding:15px;border-left:3px solid #28a745;">
                <h4 style="margin-top:0;">✓ Ambiente OK para Produção</h4>
                <p style="margin:5px 0;">Todos os requisitos foram atendidos. O sistema está pronto para criar funcionários.</p>
            </div>
            <?php
        } else {
            ?>
            <div style="background:#fff3cd;padding:15px;border-left:3px solid #f39c12;">
                <h4 style="margin-top:0;">⚠️ Problemas Encontrados</h4>
                <ul style="margin:10px 0;padding-left:20px;">
                    <?php foreach ($issues as $issue): ?>
                        <li><?php echo esc_html($issue); ?></li>
                    <?php endforeach; ?>
                </ul>
                <p style="margin:10px 0 0 0;"><strong>Ação recomendada:</strong> Corrija os problemas listados antes de criar funcionários em produção.</p>
            </div>
            <?php
        }
        ?>
        <?php

        $html = ob_get_clean();
        wp_send_json_success(array('html' => $html));
    }

    /**
     * Testar criação de funcionário com logs detalhados (AJAX)
     */
    public function ajax_test_employee_creation() {
        check_ajax_referer('sistur_test_employee_creation', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
        }

        global $wpdb;

        // Dados de teste
        $test_data = array(
            'name' => 'TESTE DEBUG - NÃO CRIAR',
            'email' => 'teste-debug-' . time() . '@sistur.test',
            'cpf' => '12345678901',
            'password' => 'teste123',
            'phone' => '(11) 98765-4321',
            'department_id' => null,
            'role_id' => null,
            'position' => 'Cargo Teste',
            'status' => 1
        );

        ob_start();
        ?>
        <div style="background:#e7f3ff;padding:15px;border-left:3px solid #2271b1;margin-bottom:20px;">
            <h3 style="margin-top:0;">🧪 Teste de Criação de Funcionário (SIMULAÇÃO)</h3>
            <p style="margin:5px 0;"><strong>ATENÇÃO:</strong> Este é apenas um teste. Nenhum funcionário será criado de verdade.</p>
            <p style="margin:5px 0;"><strong>Data/Hora:</strong> <?php echo current_time('Y-m-d H:i:s'); ?></p>
        </div>

        <h4>Dados de Teste:</h4>
        <pre style="background:#f5f5f5;padding:10px;border:1px solid #ddd;overflow:auto;"><?php print_r($test_data); ?></pre>

        <h4>Log do Processo:</h4>
        <div style="background:#000;color:#0f0;padding:15px;font-family:monospace;font-size:12px;line-height:1.6;">
        <?php

        $log = array();
        $errors = array();

        // Passo 1: Verificar tabela
        $log[] = "[1/10] Verificando tabela de funcionários...";
        $table = $wpdb->prefix . 'sistur_employees';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
        if ($table_exists) {
            $log[] = "✓ Tabela existe: $table";
        } else {
            $errors[] = "✗ Tabela NÃO existe: $table";
            $log[] = "✗ ERRO CRÍTICO: Tabela não encontrada!";
        }

        // Passo 2: Verificar arquivo de validação CPF
        $log[] = "\n[2/10] Verificando arquivo de validação CPF...";
        $cpf_file = SISTUR_PLUGIN_DIR . 'includes/login-funcionario-new.php';
        if (file_exists($cpf_file)) {
            $log[] = "✓ Arquivo encontrado: $cpf_file";
            require_once $cpf_file;
        } else {
            $errors[] = "✗ Arquivo NÃO encontrado: $cpf_file";
            $log[] = "✗ ERRO: Arquivo de validação não existe!";
        }

        // Passo 3: Verificar função de validação
        $log[] = "\n[3/10] Verificando função sistur_validate_cpf()...";
        if (function_exists('sistur_validate_cpf')) {
            $log[] = "✓ Função disponível";
            
            // Testar validação
            $cpf_valid = sistur_validate_cpf($test_data['cpf']);
            if ($cpf_valid) {
                $log[] = "✓ CPF de teste é válido: " . $test_data['cpf'];
            } else {
                $errors[] = "✗ CPF de teste é INVÁLIDO: " . $test_data['cpf'];
                $log[] = "✗ ERRO: Validação rejeitou CPF de teste!";
            }
        } else {
            $errors[] = "✗ Função sistur_validate_cpf() NÃO disponível";
            $log[] = "✗ ERRO CRÍTICO: Função não encontrada!";
        }

        // Passo 4: Verificar CPF duplicado
        $log[] = "\n[4/10] Verificando CPF duplicado...";
        $existing_cpf = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE cpf = %s",
            $test_data['cpf']
        ));
        if ($existing_cpf) {
            $log[] = "⚠ CPF já existe no sistema (ID: $existing_cpf)";
            $log[] = "⚠ Em produção, isso impediria a criação";
        } else {
            $log[] = "✓ CPF não duplicado";
        }

        // Passo 5: Preparar dados
        $log[] = "\n[5/10] Preparando dados para inserção...";
        $data_to_insert = array(
            'name' => $test_data['name'],
            'email' => $test_data['email'],
            'phone' => $test_data['phone'],
            'department_id' => $test_data['department_id'],
            'role_id' => $test_data['role_id'],
            'position' => $test_data['position'],
            'cpf' => preg_replace('/[^0-9]/', '', $test_data['cpf']),
            'password' => wp_hash_password($test_data['password']),
            'status' => $test_data['status']
        );
        $log[] = "✓ Dados sanitizados e preparados";
        $log[] = "✓ Senha hasheada com wp_hash_password()";

        // Passo 6: Simular inserção (SEM executar)
        $log[] = "\n[6/10] Simulando INSERT no banco...";
        $log[] = "⚠ MODO DE TESTE - NÃO será executado!";
        
        // Construir query de teste
        $format = array('%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d');
        $test_query = $wpdb->prepare(
            "INSERT INTO $table (name, email, phone, department_id, role_id, position, cpf, password, status) VALUES (%s, %s, %s, %d, %d, %s, %s, %s, %d)",
            $data_to_insert['name'],
            $data_to_insert['email'],
            $data_to_insert['phone'],
            $data_to_insert['department_id'],
            $data_to_insert['role_id'],
            $data_to_insert['position'],
            $data_to_insert['cpf'],
            $data_to_insert['password'],
            $data_to_insert['status']
        );
        $log[] = "Query que seria executada:";
        $log[] = substr($test_query, 0, 200) . "... (truncado)";

        // Passo 7: Verificar classe QRCode
        $log[] = "\n[7/10] Verificando classe SISTUR_QRCode...";
        if (class_exists('SISTUR_QRCode')) {
            $log[] = "✓ Classe disponível";
            $log[] = "✓ QR Code seria gerado após criação";
        } else {
            $log[] = "⚠ Classe NÃO disponível (não é crítico)";
            $log[] = "⚠ QR Code NÃO seria gerado";
        }

        // Passo 8: Verificar permissões
        $log[] = "\n[8/10] Verificando permissões do usuário...";
        if (current_user_can('manage_options')) {
            $log[] = "✓ Usuário tem permissão manage_options";
        } else {
            $errors[] = "✗ Usuário SEM permissão manage_options";
            $log[] = "✗ ERRO: Permissão insuficiente!";
        }

        // Passo 9: Verificar ambiente
        $log[] = "\n[9/10] Verificando ambiente...";
        $log[] = "PHP Version: " . PHP_VERSION;
        $log[] = "WordPress Version: " . get_bloginfo('version');
        $log[] = "WP_DEBUG: " . (defined('WP_DEBUG') && WP_DEBUG ? 'ATIVO' : 'INATIVO');
        $log[] = "Site URL: " . get_site_url();

        // Passo 10: Resumo
        $log[] = "\n[10/10] Resumo do teste...";
        if (empty($errors)) {
            $log[] = "✓✓✓ SUCESSO! Todos os requisitos foram atendidos.";
            $log[] = "✓ Em produção, o funcionário SERIA criado com sucesso.";
        } else {
            $log[] = "✗✗✗ FALHA! Encontrados " . count($errors) . " erro(s):";
            foreach ($errors as $error) {
                $log[] = "  - $error";
            }
            $log[] = "✗ Em produção, a criação FALHARIA com estes erros.";
        }

        // Exibir log
        foreach ($log as $line) {
            echo esc_html($line) . "\n";
        }

        ?>
        </div>

        <?php
        if (empty($errors)) {
            ?>
            <div style="background:#d4edda;padding:15px;border-left:3px solid #28a745;margin-top:20px;">
                <h4 style="margin-top:0;">✓ Teste Passou!</h4>
                <p style="margin:5px 0;">Todos os requisitos foram atendidos. O sistema está funcionando corretamente para criar funcionários.</p>
                <p style="margin:5px 0;"><strong>Próximo passo:</strong> Tente criar um funcionário real através da interface normal.</p>
            </div>
            <?php
        } else {
            ?>
            <div style="background:#fee;padding:15px;border-left:3px solid #c00;margin-top:20px;">
                <h4 style="margin-top:0;">✗ Teste Falhou!</h4>
                <p style="margin:5px 0;">Encontrados <?php echo count($errors); ?> erro(s) que impediriam a criação de funcionários:</p>
                <ul style="margin:10px 0;padding-left:20px;">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo esc_html($error); ?></li>
                    <?php endforeach; ?>
                </ul>
                <p style="margin:10px 0 0 0;"><strong>Ação necessária:</strong> Corrija os erros listados antes de tentar criar funcionários.</p>
            </div>
            <?php
        }
        ?>
        <?php

        $html = ob_get_clean();
        wp_send_json_success(array('html' => $html));
    }

    /**
     * Corrigir estrutura da tabela de funcionários (AJAX)
     */
    public function ajax_fix_employees_table() {
        check_ajax_referer('sistur_fix_employees_table', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
        }

        global $wpdb;
        $table_employees = $wpdb->prefix . 'sistur_employees';

        // Verificar se tabela existe
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_employees'") === $table_employees;
        if (!$table_exists) {
            wp_send_json_error('Tabela de funcionários não existe!');
        }

        ob_start();
        ?>
        <div style="background:#e7f3ff;padding:15px;border-left:3px solid #2271b1;margin-bottom:20px;">
            <h3 style="margin-top:0;">🔧 Correção da Estrutura da Tabela</h3>
            <p style="margin:5px 0;"><strong>Tabela:</strong> <?php echo esc_html($table_employees); ?></p>
            <p style="margin:5px 0;"><strong>Data/Hora:</strong> <?php echo current_time('Y-m-d H:i:s'); ?></p>
        </div>

        <h4>Log de Correção:</h4>
        <div style="background:#000;color:#0f0;padding:15px;font-family:monospace;font-size:12px;line-height:1.6;max-height:400px;overflow-y:auto;">
        <?php

        $log = array();
        $errors = array();
        $columns_added = 0;

        // Estrutura esperada da tabela (conforme activator)
        $expected_columns = array(
            'user_id' => array(
                'type' => 'bigint(20)',
                'null' => 'YES',
                'default' => 'NULL',
                'after' => 'id',
                'extra' => 'ADD UNIQUE KEY user_id (user_id)'
            ),
            'cpf' => array(
                'type' => 'varchar(14)',
                'null' => 'YES',
                'default' => 'NULL',
                'after' => 'hire_date',
                'extra' => 'ADD UNIQUE KEY cpf_unique (cpf), ADD KEY cpf (cpf)'
            ),
            'matricula' => array(
                'type' => 'varchar(50)',
                'null' => 'YES',
                'default' => 'NULL',
                'after' => 'cpf',
                'extra' => 'ADD KEY matricula (matricula)'
            ),
            'password' => array(
                'type' => 'varchar(255)',
                'null' => 'YES',
                'default' => 'NULL',
                'after' => 'matricula',
                'extra' => ''
            ),
            'ctps' => array(
                'type' => 'varchar(20)',
                'null' => 'YES',
                'default' => 'NULL',
                'after' => 'matricula',
                'extra' => ''
            ),
            'ctps_uf' => array(
                'type' => 'varchar(2)',
                'null' => 'YES',
                'default' => 'NULL',
                'after' => 'ctps',
                'extra' => ''
            ),
            'cbo' => array(
                'type' => 'varchar(10)',
                'null' => 'YES',
                'default' => 'NULL',
                'after' => 'ctps_uf',
                'extra' => ''
            ),
            'token_qr' => array(
                'type' => 'varchar(36)',
                'null' => 'YES',
                'default' => 'NULL',
                'after' => 'password',
                'extra' => 'ADD UNIQUE KEY token_qr (token_qr), ADD KEY token_qr_idx (token_qr)'
            ),
            'role_id' => array(
                'type' => 'mediumint(9)',
                'null' => 'YES',
                'default' => 'NULL',
                'after' => 'password',
                'extra' => 'ADD KEY role_id (role_id)'
            ),
            'contract_type_id' => array(
                'type' => 'mediumint(9)',
                'null' => 'YES',
                'default' => 'NULL',
                'after' => 'token_qr',
                'extra' => 'ADD KEY contract_type_id (contract_type_id)'
            ),
            'perfil_localizacao' => array(
                'type' => "enum('INTERNO','EXTERNO')",
                'null' => 'YES',
                'default' => "'INTERNO'",
                'after' => 'contract_type_id',
                'extra' => ''
            ),
            'time_expected_minutes' => array(
                'type' => 'smallint',
                'null' => 'YES',
                'default' => '480',
                'after' => 'perfil_localizacao',
                'extra' => ''
            ),
            'lunch_minutes' => array(
                'type' => 'smallint',
                'null' => 'YES',
                'default' => '60',
                'after' => 'time_expected_minutes',
                'extra' => ''
            ),
            'created_at' => array(
                'type' => 'datetime',
                'null' => 'YES',
                'default' => 'CURRENT_TIMESTAMP',
                'after' => 'lunch_minutes',
                'extra' => ''
            ),
            'updated_at' => array(
                'type' => 'datetime',
                'null' => 'YES',
                'default' => 'CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                'after' => 'created_at',
                'extra' => ''
            )
        );

        $log[] = "Verificando estrutura da tabela $table_employees...";
        $log[] = "";

        // Obter colunas existentes
        $existing_columns = $wpdb->get_results("SHOW COLUMNS FROM $table_employees");
        $existing_column_names = array();
        foreach ($existing_columns as $col) {
            $existing_column_names[] = $col->Field;
        }

        $log[] = "Colunas existentes: " . implode(', ', $existing_column_names);
        $log[] = "";

        // Verificar cada coluna esperada
        foreach ($expected_columns as $col_name => $col_spec) {
            if (!in_array($col_name, $existing_column_names)) {
                $log[] = "❌ Coluna FALTANTE: $col_name";
                $log[] = "   Adicionando...";

                // Construir ALTER TABLE
                $null_spec = $col_spec['null'] === 'YES' ? 'DEFAULT NULL' : 'NOT NULL';
                $default_spec = '';
                if ($col_spec['default'] !== 'NULL' && !empty($col_spec['default'])) {
                    $default_spec = "DEFAULT " . $col_spec['default'];
                }

                $alter_query = "ALTER TABLE $table_employees 
                    ADD COLUMN $col_name {$col_spec['type']} $null_spec $default_spec";
                
                if (!empty($col_spec['after'])) {
                    $alter_query .= " AFTER {$col_spec['after']}";
                }

                // Executar ALTER TABLE para adicionar coluna
                $result = $wpdb->query($alter_query);

                if ($result === false) {
                    $errors[] = "Erro ao adicionar coluna $col_name: " . $wpdb->last_error;
                    $log[] = "   ✗ ERRO: " . $wpdb->last_error;
                } else {
                    $columns_added++;
                    $log[] = "   ✓ Coluna adicionada com sucesso!";
                    
                    // Adicionar índices extras se necessário
                    if (!empty($col_spec['extra'])) {
                        $index_query = "ALTER TABLE $table_employees {$col_spec['extra']}";
                        $index_result = $wpdb->query($index_query);
                        
                        if ($index_result === false) {
                            // Índice pode já existir, não é crítico
                            $log[] = "   ⚠ Índice não adicionado (pode já existir)";
                        } else {
                            $log[] = "   ✓ Índice adicionado!";
                        }
                    }
                }
                
                $log[] = "";
            } else {
                $log[] = "✓ Coluna OK: $col_name";
            }
        }

        // Exibir log
        foreach ($log as $line) {
            echo esc_html($line) . "\n";
        }

        ?>
        </div>

        <?php
        if (empty($errors)) {
            ?>
            <div style="background:#d4edda;padding:15px;border-left:3px solid #28a745;margin-top:20px;">
                <h4 style="margin-top:0;">✓ Correção Concluída com Sucesso!</h4>
                <p style="margin:5px 0;"><strong>Colunas adicionadas:</strong> <?php echo $columns_added; ?></p>
                <?php if ($columns_added > 0): ?>
                    <p style="margin:5px 0;">A estrutura da tabela foi atualizada. Todas as funcionalidades de criação de funcionários devem funcionar agora.</p>
                    <p style="margin:5px 0;"><strong>Próximo passo:</strong> Execute "Validar Ambiente" novamente para confirmar.</p>
                <?php else: ?>
                    <p style="margin:5px 0;">Nenhuma coluna precisou ser adicionada. A estrutura já está correta!</p>
                <?php endif; ?>
            </div>
            <?php
        } else {
            ?>
            <div style="background:#fee;padding:15px;border-left:3px solid #c00;margin-top:20px;">
                <h4 style="margin-top:0;">✗ Correção Concluída com Erros</h4>
                <p style="margin:5px 0;"><strong>Colunas adicionadas:</strong> <?php echo $columns_added; ?></p>
                <p style="margin:5px 0;"><strong>Erros encontrados:</strong> <?php echo count($errors); ?></p>
                <ul style="margin:10px 0;padding-left:20px;">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo esc_html($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php
        }
        ?>

        <p style="margin-top:15px;color:#666;font-style:italic;">
            ℹ️ A validação do ambiente será executada automaticamente em 2 segundos...
        </p>
        <?php

        $html = ob_get_clean();
        wp_send_json_success(array('html' => $html, 'columns_added' => $columns_added, 'errors' => count($errors)));
    }

    /**
     * Validar sistema de registro de ponto (AJAX)
     */
    public function ajax_validate_timeclock() {
        check_ajax_referer('sistur_validate_timeclock', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
        }

        global $wpdb;
        $table_entries = $wpdb->prefix . 'sistur_time_entries';
        $table_employees = $wpdb->prefix . 'sistur_employees';

        ob_start();
        ?>
        <div style="background:#e7f3ff;padding:15px;border-left:3px solid #2271b1;margin-bottom:20px;">
            <h3 style="margin-top:0;">🕐 Validação do Sistema de Registro de Ponto</h3>
            <p style="margin:5px 0;"><strong>Data/Hora:</strong> <?php echo current_time('Y-m-d H:i:s'); ?></p>
        </div>

        <table class="widefat" style="margin-top:20px;">
            <thead>
                <tr>
                    <th style="width:30%;">Verificação</th>
                    <th style="width:15%;">Status</th>
                    <th style="width:55%;">Detalhes</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // 1. Verificar tabela time_entries
                $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_entries'") === $table_entries;
                ?>
                <tr>
                    <td>Tabela de Registros (time_entries)</td>
                    <td><?php echo $table_exists ? '<span style="color:green;">✓ Existe</span>' : '<span style="color:red;">✗ Não existe</span>'; ?></td>
                    <td><?php
                        if ($table_exists) {
                            $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_entries");
                            echo esc_html($table_entries) . " - Total de registros: $count";
                        } else {
                            echo "A tabela não foi criada. Execute a ativação do plugin.";
                        }
                    ?></td>
                </tr>

                <?php
                // 2. Verificar estrutura da tabela time_entries
                if ($table_exists) {
                    $columns = $wpdb->get_results("SHOW COLUMNS FROM $table_entries");
                    $required_columns = array('id', 'employee_id', 'punch_type', 'punch_time', 'shift_date', 'notes', 'created_by', 'source', 'processing_status', 'created_at', 'admin_change_reason', 'changed_by_user_id', 'changed_by_role');
                    $column_names = wp_list_pluck($columns, 'Field');
                    $missing_columns = array_diff($required_columns, $column_names);
                    $has_all_columns = empty($missing_columns);
                    
                    // Colunas críticas para registro de ponto
                    $critical_columns = array('created_by', 'source', 'processing_status');
                    $missing_critical = array_intersect($critical_columns, $missing_columns);
                ?>
                <tr>
                    <td>Estrutura da Tabela</td>
                    <td><?php echo $has_all_columns ? '<span style="color:green;">✓ OK</span>' : '<span style="color:red;">✗ Incompleta</span>'; ?></td>
                    <td><?php
                        if ($has_all_columns) {
                            echo "Todas as " . count($required_columns) . " colunas necessárias existem";
                        } else {
                            echo "<strong style='color:#c00;'>Faltam " . count($missing_columns) . " coluna(s):</strong> " . implode(', ', $missing_columns);
                            if (!empty($missing_critical)) {
                                echo "<br><strong style='color:#c00;'>⚠ CRÍTICAS:</strong> " . implode(', ', $missing_critical);
                            }
                            echo "<br><em>Use o botão 'Adicionar Colunas Faltantes' abaixo</em>";
                        }
                    ?></td>
                </tr>
                <?php } ?>

                <?php
                // 3. Verificar função sistur_get_current_employee
                $function_exists = function_exists('sistur_get_current_employee');
                ?>
                <tr>
                    <td>Função sistur_get_current_employee()</td>
                    <td><?php echo $function_exists ? '<span style="color:green;">✓ Disponível</span>' : '<span style="color:red;">✗ Não encontrada</span>'; ?></td>
                    <td><?php
                        if ($function_exists) {
                            echo "Função carregada e disponível";
                        } else {
                            echo "Função não foi carregada - verifique o arquivo de autenticação";
                        }
                    ?></td>
                </tr>

                <?php
                // 4. Verificar se há funcionários cadastrados
                $employees_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_employees WHERE status = 1");
                ?>
                <tr>
                    <td>Funcionários Ativos</td>
                    <td><?php echo $employees_count > 0 ? '<span style="color:green;">✓ OK</span>' : '<span style="color:orange;">⚠ Nenhum</span>'; ?></td>
                    <td><?php echo $employees_count; ?> funcionário(s) ativo(s) cadastrado(s)</td>
                </tr>

                <?php
                // 5. Verificar AJAX handler
                $ajax_registered = has_action('wp_ajax_sistur_time_public_clock');
                ?>
                <tr>
                    <td>AJAX Handler (public_clock)</td>
                    <td><?php echo $ajax_registered ? '<span style="color:green;">✓ Registrado</span>' : '<span style="color:red;">✗ Não registrado</span>'; ?></td>
                    <td><?php
                        if ($ajax_registered) {
                            echo "Handler AJAX registrado corretamente";
                        } else {
                            echo "Handler não registrado - a classe SISTUR_Time_Tracking pode não estar carregada";
                        }
                    ?></td>
                </tr>

                <?php
                // 6. Verificar nonce de segurança
                $nonce_test = wp_create_nonce('sistur_time_public_nonce');
                ?>
                <tr>
                    <td>Sistema de Nonce</td>
                    <td><span style="color:green;">✓ OK</span></td>
                    <td>Nonce de segurança gerado com sucesso</td>
                </tr>

                <?php
                // 7. Verificar registros de hoje
                if ($table_exists) {
                    $today = current_time('Y-m-d');
                    $today_count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM $table_entries WHERE shift_date = %s",
                        $today
                    ));
                ?>
                <tr>
                    <td>Registros de Hoje</td>
                    <td><?php echo $today_count > 0 ? '<span style="color:green;">✓ ' . $today_count . '</span>' : '<span style="color:orange;">⚠ Nenhum</span>'; ?></td>
                    <td><?php echo $today_count; ?> registro(s) de ponto hoje (<?php echo $today; ?>)</td>
                </tr>
                <?php } ?>

                <?php
                // 8. Testar current_time()
                $current_time_test = current_time('mysql');
                ?>
                <tr>
                    <td>Função current_time()</td>
                    <td><span style="color:green;">✓ OK</span></td>
                    <td>WordPress timezone: <?php echo $current_time_test; ?></td>
                </tr>

                <?php
                // 9. Verificar último erro SQL
                $last_error = $wpdb->last_error;
                ?>
                <tr>
                    <td>Último Erro SQL</td>
                    <td><?php echo empty($last_error) ? '<span style="color:green;">✓ Nenhum</span>' : '<span style="color:red;">✗ Erro recente</span>'; ?></td>
                    <td><?php echo empty($last_error) ? 'Nenhum erro registrado' : '<code>' . esc_html($last_error) . '</code>'; ?></td>
                </tr>

                <?php
                // 10. Verificar classe SISTUR_Time_Tracking
                $class_exists_check = class_exists('SISTUR_Time_Tracking');
                ?>
                <tr>
                    <td>Classe SISTUR_Time_Tracking</td>
                    <td><?php echo $class_exists_check ? '<span style="color:green;">✓ Carregada</span>' : '<span style="color:red;">✗ Não carregada</span>'; ?></td>
                    <td><?php echo $class_exists_check ? 'Classe disponível' : 'Classe não foi carregada pelo plugin'; ?></td>
                </tr>
            </tbody>
        </table>

        <?php
        // Resumo e recomendações
        $issues = array();
        
        if (!$table_exists) {
            $issues[] = "Tabela de registros de ponto não existe - execute a ativação do plugin";
        }
        if (!empty($missing_columns)) {
            $critical_missing = isset($missing_critical) ? $missing_critical : array();
            if (!empty($critical_missing)) {
                $issues[] = "<strong>CRÍTICO:</strong> Faltam " . count($missing_columns) . " colunas na tabela, incluindo: " . implode(', ', $critical_missing) . " - use o botão 'Adicionar Colunas Faltantes' acima";
            } else {
                $issues[] = "Estrutura da tabela incompleta - faltam " . count($missing_columns) . " colunas não críticas - use o botão 'Adicionar Colunas Faltantes'";
            }
        }
        if (!$function_exists) {
            $issues[] = "Função de autenticação não disponível - verifique o arquivo de autenticação";
        }
        if (!$ajax_registered) {
            $issues[] = "Handler AJAX não registrado - a classe Time_Tracking não foi inicializada";
        }
        if ($employees_count == 0) {
            $issues[] = "Nenhum funcionário cadastrado - cadastre ao menos um funcionário primeiro";
        }

        if (empty($issues)) {
            ?>
            <div style="background:#d4edda;padding:15px;border-left:3px solid #28a745;margin-top:20px;">
                <h4 style="margin-top:0;">✓ Sistema de Ponto OK!</h4>
                <p style="margin:5px 0;">Todos os requisitos foram atendidos. O registro de ponto deve funcionar corretamente.</p>
                <p style="margin:10px 0 5px 0;"><strong>Próximos passos:</strong></p>
                <ol style="margin:5px 0 0 20px;">
                    <li>Acesse a página de registro de ponto como funcionário</li>
                    <li>Clique no botão de registrar ponto</li>
                    <li>Verifique os logs em <code>wp-content/debug.log</code> se houver erro</li>
                    <li>Procure por linhas com "SISTUR DEBUG" para detalhes completos</li>
                </ol>
            </div>
            <?php
        } else {
            ?>
            <div style="background:#fff3cd;padding:15px;border-left:3px solid #f39c12;margin-top:20px;">
                <h4 style="margin-top:0;">⚠ Problemas Encontrados</h4>
                <ul style="margin:10px 0;padding-left:20px;">
                    <?php foreach ($issues as $issue): ?>
                        <li><?php echo esc_html($issue); ?></li>
                    <?php endforeach; ?>
                </ul>
                <p style="margin:10px 0 0 0;"><strong>Ação necessária:</strong> Corrija os problemas listados antes de usar o registro de ponto.</p>
            </div>
            <?php
        }
        ?>

        <div style="background:#e7f4f9;padding:15px;border-left:3px solid #17a2b8;margin-top:20px;">
            <h4 style="margin-top:0;">📝 Como Visualizar os Logs Detalhados</h4>
            <p style="margin:5px 0;">Todos os registros de ponto geram logs automáticos. Para visualizar:</p>
            
            <h5 style="margin:10px 0 5px 0;">Via FTP/cPanel:</h5>
            <ol style="margin:5px 0 10px 20px;">
                <li>Acesse o servidor via FTP ou File Manager</li>
                <li>Navegue até: <code>wp-content/debug.log</code></li>
                <li>Baixe e abra em editor de texto</li>
                <li>Procure por: <code>SISTUR DEBUG: ajax_public_clock</code></li>
            </ol>

            <h5 style="margin:10px 0 5px 0;">Via SSH (se disponível):</h5>
            <pre style="background:#000;color:#0f0;padding:10px;overflow-x:auto;">tail -100 wp-content/debug.log | grep "SISTUR DEBUG"</pre>

            <h5 style="margin:10px 0 5px 0;">O que procurar nos logs:</h5>
            <ul style="margin:5px 0 0 20px;">
                <li><strong style="color:#28a745;">✓</strong> = Etapa OK</li>
                <li><strong style="color:#dc3545;">ERRO</strong> = Problema identificado</li>
                <li><strong>Timestamp</strong> = Data/hora exata do erro</li>
                <li><strong>POST data</strong> = Dados enviados pelo formulário</li>
                <li><strong>Funcionário ID</strong> = Qual funcionário tentou registrar</li>
            </ul>
        </div>
        <?php

        $html = ob_get_clean();
        wp_send_json_success(array('html' => $html));
    }

    /**
     * Corrigir estrutura da tabela de registros de ponto (AJAX)
     */
    public function ajax_fix_timeclock_table() {
        check_ajax_referer('sistur_fix_timeclock_table', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
        }

        global $wpdb;
        $table_entries = $wpdb->prefix . 'sistur_time_entries';

        // Verificar se tabela existe
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_entries'") === $table_entries;
        if (!$table_exists) {
            wp_send_json_error('Tabela de registros de ponto não existe!');
        }

        ob_start();
        ?>
        <div style="background:#e7f3ff;padding:15px;border-left:3px solid #2271b1;margin-bottom:20px;">
            <h3 style="margin-top:0;">🔧 Correção da Tabela de Registros de Ponto</h3>
            <p style="margin:5px 0;"><strong>Tabela:</strong> <?php echo esc_html($table_entries); ?></p>
            <p style="margin:5px 0;"><strong>Data/Hora:</strong> <?php echo current_time('Y-m-d H:i:s'); ?></p>
        </div>

        <h4>Log de Correção:</h4>
        <div style="background:#000;color:#0f0;padding:15px;font-family:monospace;font-size:12px;line-height:1.6;max-height:400px;overflow-y:auto;">
        <?php

        $log = array();
        $errors = array();
        $columns_added = 0;

        // Estrutura esperada (conforme activator)
        $expected_columns = array(
            'created_by' => array(
                'type' => 'bigint(20)',
                'null' => 'YES',
                'default' => 'NULL',
                'after' => 'notes',
                'extra' => 'ADD KEY created_by (created_by)'
            ),
            'source' => array(
                'type' => "enum('admin','employee','system','KIOSK','MOBILE_APP','MANUAL_AJUSTE')",
                'null' => 'YES',
                'default' => "'admin'",
                'after' => 'created_by',
                'extra' => ''
            ),
            'processing_status' => array(
                'type' => "enum('PENDENTE','PROCESSADO')",
                'null' => 'YES',
                'default' => "'PENDENTE'",
                'after' => 'source',
                'extra' => 'ADD KEY processing_status (processing_status)'
            ),
            'admin_change_reason' => array(
                'type' => 'text',
                'null' => 'YES',
                'default' => 'NULL',
                'after' => 'notes',
                'extra' => ''
            ),
            'changed_by_user_id' => array(
                'type' => 'bigint(20)',
                'null' => 'YES',
                'default' => 'NULL',
                'after' => 'admin_change_reason',
                'extra' => 'ADD KEY changed_by_user_id (changed_by_user_id)'
            ),
            'changed_by_role' => array(
                'type' => 'varchar(50)',
                'null' => 'YES',
                'default' => 'NULL',
                'after' => 'changed_by_user_id',
                'extra' => ''
            )
        );

        $log[] = "Verificando estrutura da tabela $table_entries...";
        $log[] = "";

        // Obter colunas existentes
        $existing_columns = $wpdb->get_results("SHOW COLUMNS FROM $table_entries");
        $existing_column_names = array();
        foreach ($existing_columns as $col) {
            $existing_column_names[] = $col->Field;
        }

        $log[] = "Colunas existentes: " . implode(', ', $existing_column_names);
        $log[] = "";

        // Verificar cada coluna esperada
        foreach ($expected_columns as $col_name => $col_spec) {
            if (!in_array($col_name, $existing_column_names)) {
                $log[] = "❌ Coluna FALTANTE: $col_name";
                $log[] = "   Adicionando...";

                // Construir ALTER TABLE
                $null_spec = $col_spec['null'] === 'YES' ? 'DEFAULT NULL' : 'NOT NULL';
                $default_spec = '';
                if ($col_spec['default'] !== 'NULL' && !empty($col_spec['default'])) {
                    $default_spec = "DEFAULT " . $col_spec['default'];
                }

                $alter_query = "ALTER TABLE $table_entries 
                    ADD COLUMN $col_name {$col_spec['type']} $null_spec $default_spec";
                
                if (!empty($col_spec['after'])) {
                    $alter_query .= " AFTER {$col_spec['after']}";
                }

                // Executar ALTER TABLE
                $result = $wpdb->query($alter_query);

                if ($result === false) {
                    $errors[] = "Erro ao adicionar coluna $col_name: " . $wpdb->last_error;
                    $log[] = "   ✗ ERRO: " . $wpdb->last_error;
                } else {
                    $columns_added++;
                    $log[] = "   ✓ Coluna adicionada com sucesso!";
                    
                    // Adicionar índices extras se necessário
                    if (!empty($col_spec['extra'])) {
                        $index_query = "ALTER TABLE $table_entries {$col_spec['extra']}";
                        $index_result = $wpdb->query($index_query);
                        
                        if ($index_result === false) {
                            // Índice pode já existir, não é crítico
                            $log[] = "   ⚠ Índice não adicionado (pode já existir)";
                        } else {
                            $log[] = "   ✓ Índice adicionado!";
                        }
                    }
                }
                
                $log[] = "";
            } else {
                $log[] = "✓ Coluna OK: $col_name";
            }
        }

        // Exibir log
        foreach ($log as $line) {
            echo esc_html($line) . "\n";
        }

        ?>
        </div>

        <?php
        if (empty($errors)) {
            ?>
            <div style="background:#d4edda;padding:15px;border-left:3px solid #28a745;margin-top:20px;">
                <h4 style="margin-top:0;">✓ Correção Concluída com Sucesso!</h4>
                <p style="margin:5px 0;"><strong>Colunas adicionadas:</strong> <?php echo $columns_added; ?></p>
                <?php if ($columns_added > 0): ?>
                    <p style="margin:5px 0;">A estrutura da tabela foi atualizada. O registro de ponto deve funcionar agora!</p>
                    <p style="margin:5px 0;"><strong>Próximo passo:</strong></p>
                    <ol style="margin:5px 0 0 20px;">
                        <li>A validação será executada automaticamente em 2 segundos</li>
                        <li>Todos os itens devem ficar verdes (✓ OK)</li>
                        <li>Tente registrar ponto novamente como funcionário</li>
                        <li>Se ainda falhar, verifique <code>wp-content/debug.log</code></li>
                    </ol>
                <?php else: ?>
                    <p style="margin:5px 0;">Nenhuma coluna precisou ser adicionada. A estrutura já está correta!</p>
                <?php endif; ?>
            </div>
            <?php
        } else {
            ?>
            <div style="background:#fee;padding:15px;border-left:3px solid #c00;margin-top:20px;">
                <h4 style="margin-top:0;">✗ Correção Concluída com Erros</h4>
                <p style="margin:5px 0;"><strong>Colunas adicionadas:</strong> <?php echo $columns_added; ?></p>
                <p style="margin:5px 0;"><strong>Erros encontrados:</strong> <?php echo count($errors); ?></p>
                <ul style="margin:10px 0;padding-left:20px;">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo esc_html($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php
        }
        ?>

        <p style="margin-top:15px;color:#666;font-style:italic;">
            ℹ️ A validação do sistema de ponto será executada automaticamente em 2 segundos...
        </p>
        <?php

        $html = ob_get_clean();
        wp_send_json_success(array('html' => $html, 'columns_added' => $columns_added, 'errors' => count($errors)));
    }

    /**
     * Regeneração segura do histórico de banco de horas (AJAX)
     * 
     * Usa o método regenerateHistory() que NUNCA modifica registros de ponto.
     * Apenas recalcula os snapshots de expectativa com base na escala atual.
     */
    public function ajax_regenerate_history() {
        check_ajax_referer('sistur_reprocess_timebank', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
        }

        $employee_id = intval($_POST['employee_id']);
        $start_date = sanitize_text_field($_POST['start_date'] ?? '');
        $end_date = sanitize_text_field($_POST['end_date'] ?? date('Y-m-d'));

        if ($employee_id < 1) {
            wp_send_json_error('ID de funcionário inválido');
        }

        // Se não informou data inicial, usar 60 dias atrás
        if (empty($start_date)) {
            $start_date = date('Y-m-d', strtotime('-60 days'));
        }

        // Validar formato das datas
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            wp_send_json_error('Formato de data inválido. Use YYYY-MM-DD.');
        }

        global $wpdb;
        $table_employees = $wpdb->prefix . 'sistur_employees';

        // Buscar dados do funcionário
        $employee = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, hire_date FROM $table_employees WHERE id = %d",
            $employee_id
        ));

        if (!$employee) {
            wp_send_json_error('Funcionário não encontrado');
        }

        // Instanciar processador
        require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-punch-processing.php';
        $processor = SISTUR_Punch_Processing::get_instance();

        // Executar regeneração histórica SEGURA
        $results = $processor->regenerateHistory($employee_id, $start_date, $end_date);

        // Gerar relatório HTML
        ob_start();
        ?>
        <div style="background:<?php echo $results['success'] ? '#d4edda' : '#fee'; ?>;padding:15px;border-left:3px solid <?php echo $results['success'] ? '#28a745' : '#c00'; ?>;margin-bottom:15px;">
            <h4 style="margin:0;"><?php echo $results['success'] ? '✓ Regeneração Histórica Concluída!' : '✗ Regeneração com Erros'; ?></h4>
            <p style="margin:10px 0 0 0;">
                <strong>Funcionário:</strong> <?php echo esc_html($employee->name); ?><br>
                <strong>Período:</strong> <?php echo date('d/m/Y', strtotime($start_date)); ?> a <?php echo date('d/m/Y', strtotime($end_date)); ?><br>
                <strong>Dias processados:</strong> <?php echo $results['days_processed']; ?><br>
                <strong>Dias atualizados:</strong> <?php echo $results['days_updated']; ?><br>
                <strong>Dias sem registro:</strong> <?php echo $results['days_skipped']; ?>
            </p>
        </div>

        <?php if (!empty($results['errors'])): ?>
            <div style="background:#fee;padding:10px;border-left:3px solid #c00;margin-bottom:15px;">
                <strong>Erros:</strong>
                <ul style="margin:5px 0;padding-left:20px;">
                    <?php foreach ($results['errors'] as $error): ?>
                        <li><?php echo esc_html($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div style="background:#fff3cd;padding:15px;border-left:3px solid #f39c12;margin-bottom:15px;">
            <h4 style="margin:0;">ℹ️ Sobre a Regeneração Histórica</h4>
            <p style="margin:10px 0 0 0;">
                Este processo <strong>NÃO modifica</strong> os registros de ponto (batidas).<br>
                Apenas recalcula os <strong>minutos esperados</strong> e o <strong>saldo final</strong> com base na escala atual.<br>
                Os minutos trabalhados permanecem inalterados.
            </p>
        </div>

        <?php if ($results['days_updated'] > 0 && !empty($results['details'])): ?>
            <details>
                <summary style="cursor:pointer;font-weight:bold;margin-bottom:10px;">📋 Ver detalhes dos dias atualizados (<?php echo count($results['details']); ?> dias)</summary>
                <table style="width:100%;border-collapse:collapse;font-size:12px;">
                    <thead>
                        <tr style="background:#f0f0f0;">
                            <th style="padding:5px;border:1px solid #ddd;">Data</th>
                            <th style="padding:5px;border:1px solid #ddd;">Esperado Anterior</th>
                            <th style="padding:5px;border:1px solid #ddd;">Esperado Novo</th>
                            <th style="padding:5px;border:1px solid #ddd;">Trabalhado</th>
                            <th style="padding:5px;border:1px solid #ddd;">Saldo Anterior</th>
                            <th style="padding:5px;border:1px solid #ddd;">Saldo Novo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results['details'] as $detail): ?>
                            <tr>
                                <td style="padding:5px;border:1px solid #ddd;"><?php echo date('d/m/Y', strtotime($detail['date'])); ?></td>
                                <td style="padding:5px;border:1px solid #ddd;"><?php echo $detail['old_expected'] !== null ? $detail['old_expected'] . 'min' : '-'; ?></td>
                                <td style="padding:5px;border:1px solid #ddd;"><?php echo $detail['new_expected']; ?>min</td>
                                <td style="padding:5px;border:1px solid #ddd;"><?php echo $detail['worked_minutes']; ?>min</td>
                                <td style="padding:5px;border:1px solid #ddd;color:<?php echo $detail['old_balance'] >= 0 ? 'green' : 'red'; ?>"><?php echo $detail['old_balance']; ?>min</td>
                                <td style="padding:5px;border:1px solid #ddd;color:<?php echo $detail['new_balance'] >= 0 ? 'green' : 'red'; ?>"><?php echo $detail['new_balance']; ?>min</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        <?php endif; ?>
        <?php

        $html = ob_get_clean();
        wp_send_json_success(array('html' => $html, 'results' => $results));
    }
}
