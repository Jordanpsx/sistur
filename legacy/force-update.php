<?php
chdir('c:/xampp/htdocs/trabalho/wordpress');
require_once('wp-load.php');
require_once(WP_CONTENT_DIR . '/plugins/sistur2/includes/class-sistur-stock-installer.php');

echo "Forcing Stock Installer update...\n";
SISTUR_Stock_Installer::install();
echo "Update completed.\n";
