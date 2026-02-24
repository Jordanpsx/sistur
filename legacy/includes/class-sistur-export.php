<?php
/**
 * SISTUR Export Helper
 * Sistema de exportação CSV/Excel
 *
 * @package SISTUR
 * @version 1.2.0
 */

class SISTUR_Export {

    /**
     * Exportar dados para CSV
     *
     * @param array $data Dados para exportar
     * @param string $filename Nome do arquivo
     * @param array $headers Cabeçalhos das colunas
     */
    public static function to_csv($data, $filename = 'export.csv', $headers = array()) {
        // Definir headers HTTP
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Abrir output
        $output = fopen('php://output', 'w');

        // BOM para UTF-8 (para Excel reconhecer acentos)
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        // Escrever cabeçalhos
        if (!empty($headers)) {
            fputcsv($output, $headers);
        } elseif (!empty($data)) {
            fputcsv($output, array_keys($data[0]));
        }

        // Escrever dados
        foreach ($data as $row) {
            fputcsv($output, $row);
        }

        fclose($output);
        exit;
    }

    /**
     * Exportar para Excel (HTML table que Excel reconhece)
     *
     * @param array $data Dados
     * @param string $filename Nome do arquivo
     * @param string $title Título da planilha
     */
    public static function to_excel($data, $filename = 'export.xls', $title = 'Dados') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
        echo '<head>';
        echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />';
        echo '<!--[if gte mso 9]><xml>';
        echo '<x:ExcelWorkbook>';
        echo '<x:ExcelWorksheets>';
        echo '<x:ExcelWorksheet>';
        echo '<x:Name>' . htmlspecialchars($title) . '</x:Name>';
        echo '<x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>';
        echo '</x:ExcelWorksheet>';
        echo '</x:ExcelWorksheets>';
        echo '</x:ExcelWorkbook>';
        echo '</xml><![endif]-->';
        echo '</head>';
        echo '<body>';
        echo '<table border="1">';

        // Cabeçalhos
        if (!empty($data)) {
            echo '<tr>';
            foreach (array_keys($data[0]) as $header) {
                echo '<th>' . htmlspecialchars($header) . '</th>';
            }
            echo '</tr>';

            // Dados
            foreach ($data as $row) {
                echo '<tr>';
                foreach ($row as $cell) {
                    echo '<td>' . htmlspecialchars($cell) . '</td>';
                }
                echo '</tr>';
            }
        }

        echo '</table>';
        echo '</body>';
        echo '</html>';
        exit;
    }

    /**
     * Preparar dados de funcionários para export
     *
     * @param array $employees Funcionários
     * @return array Dados formatados
     */
    public static function prepare_employees_export($employees) {
        $data = array();

        foreach ($employees as $emp) {
            $data[] = array(
                'ID' => $emp['id'],
                'Nome' => $emp['name'],
                'Email' => $emp['email'] ?? '',
                'Telefone' => $emp['phone'] ?? '',
                'CPF' => $emp['cpf'] ?? '',
                'Cargo' => $emp['position'] ?? '',
                'Departamento' => $emp['department_name'] ?? '',
                'Data Contratação' => $emp['hire_date'] ?? '',
                'Status' => $emp['status'] == 1 ? 'Ativo' : 'Inativo'
            );
        }

        return $data;
    }

    /**
     * Preparar dados de ponto para export
     *
     * @param array $entries Registros de ponto
     * @return array Dados formatados
     */
    public static function prepare_time_tracking_export($entries) {
        $data = array();

        foreach ($entries as $entry) {
            $data[] = array(
                'Funcionário' => $entry['employee_name'] ?? '',
                'Data' => date('d/m/Y', strtotime($entry['shift_date'])),
                'Tipo' => $entry['punch_type'],
                'Horário' => date('H:i:s', strtotime($entry['punch_time'])),
                'Observações' => $entry['notes'] ?? ''
            );
        }

        return $data;
    }

    /**
     * Preparar dados de leads para export
     *
     * @param array $leads Leads
     * @return array Dados formatados
     */
    public static function prepare_leads_export($leads) {
        $data = array();

        foreach ($leads as $lead) {
            $data[] = array(
                'ID' => $lead['id'],
                'Nome' => $lead['name'],
                'Email' => $lead['email'] ?? '',
                'Telefone' => $lead['phone'] ?? '',
                'Origem' => $lead['source'] ?? '',
                'Status' => $lead['status'] ?? '',
                'Data Criação' => date('d/m/Y H:i', strtotime($lead['created_at'])),
                'Observações' => $lead['notes'] ?? ''
            );
        }

        return $data;
    }

    /**
     * Preparar dados de pagamentos para export
     *
     * @param array $payments Pagamentos
     * @return array Dados formatados
     */
    public static function prepare_payments_export($payments) {
        $data = array();

        foreach ($payments as $payment) {
            $data[] = array(
                'Funcionário' => $payment['employee_name'] ?? '',
                'Tipo' => $payment['payment_type'],
                'Valor' => 'R$ ' . number_format($payment['amount'], 2, ',', '.'),
                'Data Pagamento' => date('d/m/Y', strtotime($payment['payment_date'])),
                'Período Início' => $payment['period_start'] ? date('d/m/Y', strtotime($payment['period_start'])) : '',
                'Período Fim' => $payment['period_end'] ? date('d/m/Y', strtotime($payment['period_end'])) : '',
                'Observações' => $payment['notes'] ?? ''
            );
        }

        return $data;
    }
}
