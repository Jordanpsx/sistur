<?php
/**
 * Classe de banco de dados para leads
 *
 * @package SISTUR
 */

class SISTUR_Leads_DB {

    /**
     * Tabela de leads
     */
    private $table_name;

    /**
     * Construtor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'sistur_leads';
    }

    /**
     * Inserir novo lead
     */
    public function insert($data) {
        global $wpdb;

        $defaults = array(
            'name' => '',
            'email' => '',
            'phone' => '',
            'source' => 'form_inicial',
            'status' => 'new',
            'notes' => ''
        );

        $data = wp_parse_args($data, $defaults);

        $result = $wpdb->insert(
            $this->table_name,
            $data,
            array('%s', '%s', '%s', '%s', '%s', '%s')
        );

        if ($result === false) {
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Obter leads com filtros
     */
    public function get_leads($args = array()) {
        global $wpdb;

        $defaults = array(
            'status' => null,
            'source' => null,
            'search' => '',
            'orderby' => 'created_at',
            'order' => 'DESC',
            'limit' => -1,
            'offset' => 0
        );

        $args = wp_parse_args($args, $defaults);

        $where = array('1=1');
        $where_values = array();

        if ($args['status']) {
            $where[] = 'status = %s';
            $where_values[] = $args['status'];
        }

        if ($args['source']) {
            $where[] = 'source = %s';
            $where_values[] = $args['source'];
        }

        if (!empty($args['search'])) {
            $where[] = '(name LIKE %s OR email LIKE %s OR phone LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }

        $where_clause = implode(' AND ', $where);
        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);

        $sql = "SELECT * FROM {$this->table_name} WHERE $where_clause";

        if (!empty($where_values)) {
            $sql = $wpdb->prepare($sql, $where_values);
        }

        if ($orderby) {
            $sql .= " ORDER BY $orderby";
        }

        if ($args['limit'] > 0) {
            $sql .= $wpdb->prepare(" LIMIT %d OFFSET %d", $args['limit'], $args['offset']);
        }

        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Obter contagem de leads por status
     */
    public function get_count_by_status() {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$this->table_name} GROUP BY status",
            ARRAY_A
        );

        $counts = array(
            'new' => 0,
            'contacted' => 0,
            'total' => 0
        );

        foreach ($results as $row) {
            $counts[$row['status']] = intval($row['count']);
            $counts['total'] += intval($row['count']);
        }

        return $counts;
    }

    /**
     * Atualizar status do lead
     */
    public function update_status($id, $status) {
        global $wpdb;

        return $wpdb->update(
            $this->table_name,
            array('status' => $status),
            array('id' => $id),
            array('%s'),
            array('%d')
        );
    }

    /**
     * Deletar lead
     */
    public function delete($id) {
        global $wpdb;

        return $wpdb->delete(
            $this->table_name,
            array('id' => $id),
            array('%d')
        );
    }

    /**
     * Obter lead por ID
     */
    public function get_lead($id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $id
        ), ARRAY_A);
    }
}
