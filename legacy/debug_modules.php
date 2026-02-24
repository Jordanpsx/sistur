<?php
require_once('wp-load.php');
global $wpdb;
$table = $wpdb->prefix . 'sistur_module_config';
$results = $wpdb->get_results("SELECT * FROM $table");
print_r($results);
