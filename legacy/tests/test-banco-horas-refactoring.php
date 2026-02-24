<?php
/**
 * Testes Automatizados para Refatoração do Banco de Horas
 * 
 * @package SISTUR
 * @subpackage Tests
 */

class SISTUR_Banco_Horas_Test extends WP_UnitTestCase {

    private $processor;
    private $test_employee_id;
    private $test_date;

    /**
     * Configuração antes de cada teste
     */
    public function setUp(): void {
        parent::setUp();
        
        global $wpdb;
        $this->processor = SISTUR_Punch_Processing::get_instance();
        $this->test_date = current_time('Y-m-d');
        
        // Criar funcionário de teste
        $wpdb->insert(
            $wpdb->prefix . 'sistur_employees',
            array(
                'name' => 'Test Employee',
                'email' => 'test@example.com',
                'cpf' => '12345678901',
                'contract_type_id' => 1
            )
        );
        $this->test_employee_id = $wpdb->insert_id;
    }

    /**
     * Limpeza após cada teste
     */
    public function tearDown(): void {
        global $wpdb;
        
        // Limpar dados de teste
        $wpdb->delete(
            $wpdb->prefix . 'sistur_employees',
            array('id' => $this->test_employee_id)
        );
        
        $wpdb->delete(
            $wpdb->prefix . 'sistur_time_entries',
            array('employee_id' => $this->test_employee_id)
        );
        
        $wpdb->delete(
            $wpdb->prefix . 'sistur_time_days',
            array('employee_id' => $this->test_employee_id)
        );
        
        parent::tearDown();
    }

    /**
     * Teste 1: Processamento síncrono ao registrar ponto
     */
    public function test_synchronous_processing_on_punch() {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_time_entries';
        
        // Registrar uma batida
        $wpdb->insert(
            $table,
            array(
                'employee_id' => $this->test_employee_id,
                'punch_time' => $this->test_date . ' 09:00:00',
                'shift_date' => $this->test_date,
                'punch_type' => 'clock_in',
                'source' => 'TEST',
                'processing_status' => 'PENDENTE'
            )
        );
        
        // Processar o dia
        $result = $this->processor->process_employee_day($this->test_employee_id, $this->test_date);
        
        // Verificar se o processamento foi bem-sucedido
        $this->assertTrue($result, 'O processamento do dia deveria retornar true');
        
        // Verificar se o status foi atualizado para PROCESSADO
        $entry = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE employee_id = %d AND shift_date = %s",
            $this->test_employee_id,
            $this->test_date
        ));
        
        $this->assertEquals('PROCESSADO', $entry->processing_status, 'A batida deveria estar marcada como PROCESSADO');
    }

    /**
     * Teste 2: Cálculo correto de horas extras
     */
    public function test_overtime_calculation() {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_time_entries';
        
        // Inserir 4 batidas simulando um dia com hora extra
        // Jornada: 09:00-12:00 (3h) + 13:00-19:00 (6h) = 9h total (540 min)
        // Esperado: 8h (480 min)
        // Hora extra: 1h (60 min)
        
        $batidas = array(
            array('punch_time' => '09:00:00', 'punch_type' => 'clock_in'),
            array('punch_time' => '12:00:00', 'punch_type' => 'lunch_start'),
            array('punch_time' => '13:00:00', 'punch_type' => 'lunch_end'),
            array('punch_time' => '19:00:00', 'punch_type' => 'clock_out'),
        );
        
        foreach ($batidas as $batida) {
            $wpdb->insert(
                $table,
                array(
                    'employee_id' => $this->test_employee_id,
                    'punch_time' => $this->test_date . ' ' . $batida['punch_time'],
                    'shift_date' => $this->test_date,
                    'punch_type' => $batida['punch_type'],
                    'source' => 'TEST',
                    'processing_status' => 'PENDENTE'
                )
            );
        }
        
        // Processar o dia
        $this->processor->process_employee_day($this->test_employee_id, $this->test_date);
        
        // Verificar o resultado
        $table_days = $wpdb->prefix . 'sistur_time_days';
        $day_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_days WHERE employee_id = %d AND shift_date = %s",
            $this->test_employee_id,
            $this->test_date
        ));
        
        // Minutos trabalhados = 180 (09:00-12:00) + 360 (13:00-19:00) = 540
        $this->assertEquals(540, $day_data->minutos_trabalhados, 'Deveria ter trabalhado 540 minutos (9h)');
        
        // Hora extra = 540 - 480 = 60 minutos
        $overtime = max(0, $day_data->minutos_trabalhados - 480);
        $this->assertEquals(60, $overtime, 'Deveria ter 60 minutos (1h) de hora extra');
    }

    /**
     * Teste 3: Recálculo automático ao editar folha
     */
    public function test_automatic_recalculation_on_edit() {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_time_entries';
        $table_days = $wpdb->prefix . 'sistur_time_days';
        
        // Inserir batidas iniciais
        $wpdb->insert(
            $table,
            array(
                'employee_id' => $this->test_employee_id,
                'punch_time' => $this->test_date . ' 09:00:00',
                'shift_date' => $this->test_date,
                'punch_type' => 'clock_in',
                'source' => 'TEST',
                'processing_status' => 'PENDENTE'
            )
        );
        $entry_id = $wpdb->insert_id;
        
        $wpdb->insert(
            $table,
            array(
                'employee_id' => $this->test_employee_id,
                'punch_time' => $this->test_date . ' 18:00:00',
                'shift_date' => $this->test_date,
                'punch_type' => 'clock_out',
                'source' => 'TEST',
                'processing_status' => 'PENDENTE'
            )
        );
        
        // Processar inicialmente
        $this->processor->process_employee_day($this->test_employee_id, $this->test_date);
        
        // Verificar tempo inicial (9h = 540 min)
        $day_data_before = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_days WHERE employee_id = %d AND shift_date = %s",
            $this->test_employee_id,
            $this->test_date
        ));
        $this->assertEquals(540, $day_data_before->minutos_trabalhados, 'Tempo inicial deveria ser 540 minutos');
        
        // Editar a primeira batida para 08:00
        $wpdb->update(
            $table,
            array(
                'punch_time' => $this->test_date . ' 08:00:00',
                'processing_status' => 'PENDENTE'
            ),
            array('id' => $entry_id)
        );
        
        // Reprocessar
        $this->processor->process_employee_day($this->test_employee_id, $this->test_date);
        
        // Verificar tempo atualizado (10h = 600 min)
        $day_data_after = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_days WHERE employee_id = %d AND shift_date = %s",
            $this->test_employee_id,
            $this->test_date
        ));
        $this->assertEquals(600, $day_data_after->minutos_trabalhados, 'Tempo atualizado deveria ser 600 minutos');
    }

    /**
     * Teste 4: Formato de exibição de horas extras
     */
    public function test_overtime_format() {
        // Criar instância do processor para acessar o método format_minutes
        $reflection = new ReflectionClass($this->processor);
        $method = $reflection->getMethod('format_minutes');
        $method->setAccessible(true);
        
        // Testar vários casos
        $this->assertEquals('2h02', $method->invoke($this->processor, 122), '122 minutos deveria ser formatado como 2h02');
        $this->assertEquals('1h30', $method->invoke($this->processor, 90), '90 minutos deveria ser formatado como 1h30');
        $this->assertEquals('30min', $method->invoke($this->processor, 30), '30 minutos deveria ser formatado como 30min');
        $this->assertEquals('+2h02', $method->invoke($this->processor, 122, true), '122 minutos com sinal deveria ser formatado como +2h02');
        $this->assertEquals('-1h30', $method->invoke($this->processor, -90, true), '-90 minutos com sinal deveria ser formatado como -1h30');
    }

    /**
     * Teste 5: Algoritmo de pares fechados
     */
    public function test_closed_pairs_algorithm() {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_time_entries';
        $table_days = $wpdb->prefix . 'sistur_time_days';
        
        // Inserir 3 batidas (entrada, saída almoço, entrada almoço) - par incompleto
        $batidas = array(
            array('punch_time' => '09:00:00', 'punch_type' => 'clock_in'),
            array('punch_time' => '12:00:00', 'punch_type' => 'lunch_start'),
            array('punch_time' => '13:00:00', 'punch_type' => 'lunch_end'),
        );
        
        foreach ($batidas as $batida) {
            $wpdb->insert(
                $table,
                array(
                    'employee_id' => $this->test_employee_id,
                    'punch_time' => $this->test_date . ' ' . $batida['punch_time'],
                    'shift_date' => $this->test_date,
                    'punch_type' => $batida['punch_type'],
                    'source' => 'TEST',
                    'processing_status' => 'PENDENTE'
                )
            );
        }
        
        // Processar o dia
        $this->processor->process_employee_day($this->test_employee_id, $this->test_date);
        
        // Verificar o resultado
        $day_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_days WHERE employee_id = %d AND shift_date = %s",
            $this->test_employee_id,
            $this->test_date
        ));
        
        // Apenas o primeiro par (09:00-12:00) deve ser contabilizado = 180 min
        $this->assertEquals(180, $day_data->minutos_trabalhados, 'Deveria contabilizar apenas o par fechado (180 min)');
        
        // Deveria estar marcado para revisão
        $this->assertEquals(1, $day_data->needs_review, 'Dia com par incompleto deveria estar marcado para revisão');
    }

    /**
     * Teste 6: Endpoint de reprocessamento em massa
     */
    public function test_bulk_reprocessing_endpoint() {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_time_entries';
        
        // Inserir batidas em múltiplos dias
        $dates = array(
            date('Y-m-d', strtotime('-2 days')),
            date('Y-m-d', strtotime('-1 day')),
            current_time('Y-m-d')
        );
        
        foreach ($dates as $date) {
            $wpdb->insert(
                $table,
                array(
                    'employee_id' => $this->test_employee_id,
                    'punch_time' => $date . ' 09:00:00',
                    'shift_date' => $date,
                    'punch_type' => 'clock_in',
                    'source' => 'TEST',
                    'processing_status' => 'PENDENTE'
                )
            );
            
            $wpdb->insert(
                $table,
                array(
                    'employee_id' => $this->test_employee_id,
                    'punch_time' => $date . ' 18:00:00',
                    'shift_date' => $date,
                    'punch_type' => 'clock_out',
                    'source' => 'TEST',
                    'processing_status' => 'PENDENTE'
                )
            );
        }
        
        // Simular requisição de reprocessamento
        $request = new WP_REST_Request('POST', '/sistur/v1/reprocess');
        $request->set_param('employee_id', $this->test_employee_id);
        $request->set_param('start_date', $dates[0]);
        $request->set_param('end_date', $dates[2]);
        
        $response = $this->processor->api_reprocess_days($request);
        $data = $response->get_data();
        
        // Verificar se processou todos os dias
        $this->assertTrue($data['success'], 'Reprocessamento deveria ser bem-sucedido');
        $this->assertEquals(3, $data['processed_count'], 'Deveria ter processado 3 dias');
    }
}
