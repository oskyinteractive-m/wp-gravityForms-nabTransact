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

    $confirmation = array( 'redirect' => site_url().'/payment-error/' );

    return $confirmation;
}

function pre_submission_handler( $form )
{
    checkSession();

    $price = isset($_SESSION['total'])
                ? $_SESSION['total']
                : 0 ;

    // Stage 2 & 3 have different form ID's. This will synched prior to going live
    //$formk = RGFormsModel::get_form_meta($_POST['gform_submit']);
    //$field = RGFormsModel::get_field($formk)



    $user = wp_get_current_user();

    error_log("[Payment USER ".$user->data->ID."] Invoice Payment", 0);

    $_POST['input_3'] = $user->first_name;
    $_POST['input_4'] = $user->last_name;
    // $_POST['input_5'] = ($user->email) ? $user->email : $user->data->user_email;

    error_log("[Payment USER ".$user->data->ID."] Fistname : $user->first_name, Lastname: $user->last_name", 0);
    error_log("[Payment USER ".$user->data->ID."] user->email :$user->email, user->data->user_email: ".$user->data->user_email, 0);
    error_log("[Payment USER ".$user->data->ID."] Amount : ".$_POST['input_6'].", Invoice No : ".$_POST['input_1'], 0);
    // $user->data->user_pass = 'xxxx';
    // error_log("[Payment USER ".$user->data->ID."] " . json_encode($user), 0);


    if($_POST['gform_submit'])
    {
        $nabsettings = get_option( 'gravityformsaddon_wp-gravityForms-nabTransact_settings');
        $loginId=$nabsettings['loginId'];
        $transactionKey=$nabsettings['transactionKey'];
        $gateway = Omnipay::create('NABTransact_SecureXML');
        $gateway->setMerchantId($loginId);
        $gateway->setTransactionPassword($transactionKey);
        $isTestMode=false;

        error_log("Error Test Oracle database not available!", 0);


        if($nabsettings['mode']=='test')
        {
            $isTestMode=true;
        }

        $gateway->setTestMode($isTestMode);

        foreach ( $form['fields'] as &$field ) {

            if ( $field['type'] == 'creditcard' ){

                    $carddata = $field->id;
                    $cardHolderName=$_POST['input_'.$carddata.'_5'];
                    $cardNo=$_POST['input_'.$carddata.'_1'];
                    $cardexpiryMonth=$_POST['input_'.$carddata.'_2'][0];
                    $cardexpiryYear=$_POST['input_'.$carddata.'_2'][1];
                    $cardcvv=$_POST['input_'.$carddata.'_3'];
                    $transactionId=$_POST['input_1'];

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
                    $_POST['input_8'] = 'Success';

                    $data['status'] = sprintf('Transaction %s was successful!', $response->getTransactionReference());
                    //header('location:' . get_site_url() . '/payment-status?' . http_build_query($data));
                }
                else
                {
                    $_POST['input_8'] = 'Failed : ' . $response->getMessage();

                    $data['status'] = sprintf('Transaction %s failed: %s', $response->getTransactionReference(), $response->getMessage());
                    //header('location:' . get_site_url() . '/payment-status?' . http_build_query($data));
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
