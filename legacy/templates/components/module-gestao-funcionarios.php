<?php
/**
 * Componente: Módulo de Gestão de Funcionários
 * 
 * Este módulo agora integra diretamente o Módulo de RH completo dentro do Portal.
 * Funcionalidades:
 * - Dashboard de RH
 * - Gestão de Colaboradores
 * - Banco de Horas e Ponto
 * 
 * @package SISTUR
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Renderizar módulo de RH completo em modo "embedded" (sem header duplicado)
echo '<div class="portal-embedded-rh-module">';
echo SISTUR_RH_Module::get_instance()->render(true);
echo '</div>';
?>