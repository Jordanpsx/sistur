<?php
/**
 * Classe de integração com Elementor
 *
 * @package SISTUR
 */

class SISTUR_Elementor_Handler {

    /**
     * Instância única da classe (Singleton)
     */
    private static $instance = null;

    /**
     * Construtor privado (Singleton)
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Retorna instância única da classe
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Inicializa os hooks
     */
    private function init_hooks() {
        // Hook para capturar formulários do Elementor
        add_action('elementor_pro/forms/new_record', array($this, 'capture_form_submission'), 10, 2);
    }

    /**
     * Captura submissões de formulários Elementor
     */
    public function capture_form_submission($record, $handler) {
        // Esta função é chamada pelo sistema de Leads
        // Mantida aqui para compatibilidade
        do_action('sistur_elementor_form_submitted', $record, $handler);
    }
}

// Inicializar se Elementor estiver ativo
if (defined('ELEMENTOR_VERSION')) {
    SISTUR_Elementor_Handler::get_instance();
}
