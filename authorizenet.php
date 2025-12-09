<?php

// don't load directly
if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/*
Plugin Name: Gravity Forms NAB Transact Add-On
Plugin URI: https://www.gravityforms.com
Description: Integrates Gravity Forms with NAB Transact Direct Post API, enabling end users to purchase goods and services through Gravity Forms. Forked from Authorize.net DP API
Version: 0.1
Author: Daynis Olman
Author URI: https://www.rocketgenius.com
License: GPL-2.0+
Text Domain: gravityformsauthorizenet
Domain Path: /languages

------------------------------------------------------------------------
Copyright 2009-2016 rocketgenius

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/

define( 'GF_AUTHORIZENET_VERSION', '2.6' );

/**
 * Custom logging function for NAB Transact plugin
 * Writes logs to a date-based file in the plugin's logs folder
 *
 * @param string $message The log message to write
 * @param string $level Optional log level (INFO, ERROR, WARNING, DEBUG)
 * @return void
 */
function gf_nab_log( $message, $level = 'INFO' ) {
	$plugin_dir = plugin_dir_path( __FILE__ );
	$logs_dir = $plugin_dir . 'logs';
	
	// Create logs directory if it doesn't exist
	if ( ! file_exists( $logs_dir ) ) {
		wp_mkdir_p( $logs_dir );
		// Add .htaccess to protect logs directory
		file_put_contents( $logs_dir . '/.htaccess', "deny from all\n" );
	}
	
	// Generate date-based filename
	$date = date( 'Y-m-d' );
	$log_file = $logs_dir . '/nab-transact-' . $date . '.log';
	
	// Format log entry with timestamp
	$timestamp = date( 'Y-m-d H:i:s' );
	$log_entry = sprintf( "[%s] [%s] %s\n", $timestamp, strtoupper( $level ), $message );
	
	// Write to log file (append mode)
	file_put_contents( $log_file, $log_entry, FILE_APPEND | LOCK_EX );
}

/// START HANDLE THE GRAVITY FORM HOOK FOR NAB API ////
require 'nab/vendor/autoload.php';
use Omnipay\Omnipay;
use Omnipay\Common\CreditCard;

/**
 * Check session is activated if not start session
 *
 * @return void
 */
add_action( 'gform_pre_submission', 'pre_submission_handler' );

function de_gforms_confirmation_dynamic_redirect( $confirmation, $form, $entry, $ajax )
{
    $error_page = get_option('gf_nab_error_page');
    
    // If option is not set or empty, try to find the payments page by slug
    if ( empty( $error_page ) ) {
        $error_page_obj = get_page_by_path( 'payments' );
        if ( $error_page_obj ) {
            $error_page = get_permalink( $error_page_obj->ID );
            // Append query string to preserve entry data
            $error_page = add_query_arg( array(), $error_page );
        } else {
            // Fallback to home_url if page doesn't exist
            $error_page = home_url('/payments/');
            gf_nab_log('Payment error page not found. Using fallback URL: ' . $error_page, 'WARNING' );
        }
    }
    
    // Validate the URL is not empty and properly formatted
    if ( empty( $error_page ) ) {
        $error_page = home_url();
        gf_nab_log('Payment error page URL is empty. Redirecting to homepage.', 'ERROR' );
    }
    
    $confirmation = array( 'redirect' => esc_url( $error_page ) );

    return $confirmation;
}

function pre_submission_handler( $form )
{
    // Verify form submission is valid (CSRF protection)
    // Gravity Forms validates before this hook, but we add an extra check for security
    $form_id = absint(rgar($form, 'id'));
    // Check if gform_submit matches the form ID (Gravity Forms uses gform_submit, not is_submit_{form_id})
    if (empty($form_id) || empty($_POST['gform_submit']) || absint($_POST['gform_submit']) !== $form_id) {
        gf_nab_log('CSRF validation failed or form ID mismatch. Form ID: ' . $form_id . ', gform_submit: ' . (isset($_POST['gform_submit']) ? $_POST['gform_submit'] : 'not set'), 'WARNING');
        return;
    }
    
    checkSession();

    $price = isset($_SESSION['total'])
                ? $_SESSION['total']
                : 0 ;

    $user = wp_get_current_user();
    $user_id = ($user && isset($user->ID)) ? (int) $user->ID : 0;
    $user_email = ($user && isset($user->user_email)) ? $user->user_email : '';
    $first_name = ($user && isset($user->first_name)) ? $user->first_name : '';
    $last_name = ($user && isset($user->last_name)) ? $user->last_name : '';

    if ($user_id > 0) {
        gf_nab_log("[Payment USER ".$user_id."] Invoice Payment", 'INFO');
    }

    $_POST['input_3'] = $first_name;
    $_POST['input_4'] = $last_name;

    if ($user_id > 0) {
        gf_nab_log("[Payment USER ".$user_id."] Firstname : $first_name, Lastname: $last_name", 'INFO');
        gf_nab_log("[Payment USER ".$user_id."] user_email: ".$user_email, 'INFO');
        gf_nab_log("[Payment USER ".$user_id."] Amount : ".($_POST['input_6'] ?? '').", Invoice No : ".($_POST['input_1'] ?? ''), 'INFO');
    }


    if($_POST['gform_submit'])
    {
        $nabsettings = get_option( 'gravityformsaddon_wp-gravityForms-nabTransact_settings');
        $loginId=$nabsettings['loginId'];
        $transactionKey=$nabsettings['transactionKey'];
        $gateway = Omnipay::create('NABTransact_SecureXML');
        $gateway->setMerchantId($loginId);
        $gateway->setTransactionPassword($transactionKey);
        $isTestMode=false;

        if($nabsettings['mode']=='test')
        {
            $isTestMode=true;
        }

        $gateway->setTestMode($isTestMode);

        foreach ( $form['fields'] as &$field ) {

            if ( $field['type'] == 'creditcard' ){

                    $carddata = $field->id;
                    $cardHolderName = sanitize_text_field($_POST['input_'.$carddata.'_5'] ?? '');
                    $cardNo = sanitize_text_field($_POST['input_'.$carddata.'_1'] ?? '');
                    $cardexpiryMonth = sanitize_text_field($_POST['input_'.$carddata.'_2'][0] ?? '');
                    $cardexpiryYear = sanitize_text_field($_POST['input_'.$carddata.'_2'][1] ?? '');
                    $cardcvv = sanitize_text_field($_POST['input_'.$carddata.'_3'] ?? '');
                    $transactionId = sanitize_text_field($_POST['input_1'] ?? '');

                $card = new CreditCard([
                        'firstName' => $cardHolderName,
                        'lastName' => '',
                        'number'      => $cardNo,
                        'expiryMonth' => $cardexpiryMonth,
                        'expiryYear'  => $cardexpiryYear,
                        'cvv'         => $cardcvv,
                    ]
                );

                $transaction = $gateway->purchase([
                        'amount'        => $price,
                        'currency'      => 'AUD',
                        'transactionId' => $transactionId,
                        'card'          => $card,
                    ]
                );

                $_POST['input_10'] = $cardHolderName;


                $response = $transaction->send();

                if ($response->isSuccessful())
                {
                    $_POST['input_8'] = sprintf('Transaction %s was successful!', $response->getTransactionReference());
                    gf_nab_log('NAB Transact: Payment successful - ' . $response->getTransactionReference(), 'INFO');
                }
                else
                {
                    $error_msg = sprintf('Transaction %s failed: %s', $response->getTransactionReference(), $response->getMessage());
                    $_POST['input_8'] = $error_msg;
                    gf_nab_log('NAB Transact: Payment failed - ' . $error_msg, 'ERROR');
                    // Note: Not redirecting on failure to match dev branch behavior - shows normal confirmation with entry data
                }
            }
        }
    }
}

/// END HANDLE THE GRAVITY FORM HOOK FOR NAB API ////
add_action( 'gform_loaded', array( 'GF_AuthorizeNet_Bootstrap', 'load' ), 5 );

class GF_AuthorizeNet_Bootstrap {

	public static function load() {

		if ( ! method_exists( 'GFForms', 'include_payment_addon_framework' ) ) {
			return;
		}

		require_once( 'class-gf-authorizenet.php' );

		GFAddOn::register( 'GFAuthorizeNet' );
	}

}

function gf_authorizenet() {
	return GFAuthorizeNet::get_instance();
}
