<?php
/*
Plugin Name: Events Manager Pro - PayFast Gateway
Plugin URI: http://wp-events-plugin.com
Description: PayFast gateway add-on for Events Manager Pro
Version: 1.1.0
Depends: Events Manager Pro
Author: PayFast
*/

function em_pro_gateway_payfast_init() {
	// Add-ons
	include('gateway.payfast.php');
}
// Set when to run the plugin : after EM is loaded.
add_action( 'em_gateways_init', 'em_pro_gateway_payfast_init', 100 );

function em_pro_gateway_payfast_currency($currencies){
	$currencies->names['ZAR'] = 'ZAR - South African Rand'; //textual representation of the currency
	$currencies->symbols['ZAR'] = 'ZAR'; //If the symbol requires an entity, like for � it's &euro;
	$currencies->true_symbols['ZAR'] = 'R'; //The actual symbol used, e.g. for Euros it's �
	return $currencies;
}
// Add the ZAR currency to EM
add_filter('em_get_currencies','em_pro_gateway_payfast_currency');
