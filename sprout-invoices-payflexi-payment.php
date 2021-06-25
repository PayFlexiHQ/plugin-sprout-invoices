<?php
/*
Plugin Name: PayFlexi Instalment Payment Gateway for Sprout Invoices
Plugin URI: https://developers.payflexi.co/
Description: Accept Full or Instalment Payments with PayFlexi for Sprout Invoices. Give your customers the option to spread the cost of an invoice  in several installments and automate recollection.
Author: PayFlexi
Version: 1.0
Author URI: https://payflexi.co
*/

/**
 * Plugin File
 */
define('SI_ADDON_PAYFLEXI_VERSION', '1.0');
define('SI_ADDON_PAYFLEXI_DOWNLOAD_ID', 141);
define('SI_ADDON_PAYFLEXI_FILE', __FILE__);
define('SI_ADDON_PAYFLEXI_NAME', 'Sprout Invoices PayFlexi Instalment Payments');
define('SI_ADDON_PAYFLEXI_URL', plugins_url('', __FILE__));


// Load up the processor before updates
add_action('si_payment_processors_loaded', 'si_load_payflexi');
function si_load_payflexi()
{
    include_once 'SI_Payflexi.php';
}
