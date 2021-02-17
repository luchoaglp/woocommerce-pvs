<?php

/**
 * Plugin Name: PVS Botón de pago para WooCommerce
 * Plugin URI: https://pvs.com.ar
 * Description: Integración del Botón de Pago en plataformas de terceros, de manera simple y segura.
 * Version: 1.0.0
 * Author: PVS
 * Author URI: https://pvs.com.ar
 * Text Domain: woocommerce-pvs
 * WC requires at least: 4.7.1
 * WC tested up to: 4.8.0
 */

if( !in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) return;

/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action( 'plugins_loaded', 'woocommerce_pvs_init', 11 );

function woocommerce_pvs_init() {
    if( class_exists( 'WC_Payment_Gateway' ) ) {
        class WC_PVS_Gateway extends WC_Payment_Gateway {

            const TEST_CALLBACK_URL = 'https://dev-wsbdp.pvssa.com.ar';
            const CALLBACK_URL = 'https://wsbdp.pvssa.com.ar';
            
            public function __construct() {
                $this->id = 'woocommerce_pvs'; // payment gateway plugin ID
                $this->icon = apply_filters( 'woocommerce_pvs_icon', plugins_url( '/assets/images/pvslogo.svg', __FILE__ ) );
                $this->title = 'Paga de una manera simple, rápida y segura.';
                $this->method_title = 'PVS Botón de pago';
                $this->method_description = $this->getMethodDescription( 'Integración del Botón de Pago de manera simple y segura.' );
                $this->description = $this->getDescription( 'Aumenta tus ventas aceptando:' );
                $this->has_fields = false;

                // Method with all the options fields
                $this->init_form_fields();

                $this->testmode = 'yes' === $this->get_option( 'testmode' );
                $this->auth_token = $this->testmode ? 
                    trim( $this->get_option( 'test_auth_token' ) ) :
                    trim( $this->get_option( 'auth_token' ) );

                $this->callback_url = $this->testmode ? 
                    self::TEST_CALLBACK_URL :
                    self::CALLBACK_URL;

                $this->description = trim( $this->get_option( 'description' ) );

                add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            }

            public function init_form_fields() {
                $this->form_fields = apply_filters('my_payment_fields', 
                    array(
                        'enabled' => array(
                            'title' => 'Habilitar/Desabilitar',
                            'type' => 'checkbox',
                            'label' => 'Habilitar PVS Botón de pago',
                            'default' => 'no'
                        ),
                        'testmode' => array(
                            'title'       => 'Entorno de Prueba',
                            'label'       => 'Activar modo de Prueba',
                            'type'        => 'checkbox',
                            'description' => 'Se puede usar el modo de prueba para poder realizar las pruebas de integración.',
                            'default'     => 'yes',
                            'desc_tip'    => true,
                        ),
                        'test_auth_token' => array(
                            'title'       => 'ID de Cliente de Prueba',
                            'type'        => 'text',
                            'default'     => '71946d6f6c5fd7e031f49b5191910e8b'
                        ),
                        'auth_token' => array(
                            'title'       => 'ID de Cliente de Producción',
                            'type'        => 'text',
                            'placeholder' => 'Ingrese su ID'
                        ),
                        'description' => array(
                            'title'       => 'Descripción',
                            'type'        => 'textarea'
                        )
                    )
                );
            }

            /*
            * We're processing the payments here
            */
            public function process_payment( $order_id ) {

                global $woocommerce;
                
                // Get Order
                $order = wc_get_order( $order_id );

                // Order amount
                $amount = $order->get_total();
                $amount = str_replace( ".", "", $amount );

                $home_url = get_home_url();
                // $home_url = 'http://201.212.231.16/wordpress';

                $body = array(
                    "clientId"    => $this->auth_token,
                    "amount"      => $amount,
                    "code"        => $order_id,
                    "callbackUrl" => $home_url . '/wp-json/woocommerce-pvs/v1/callback',
                    "redirectUrl" => array(
                        "authorized" => $this->get_return_url( $order ) . '&status=success',
                        "error"      => $this->get_return_url( $order ) . '&status=failure'
                    ),
                    "description" => $this->description
                );

                $args = array(
                    'method'      => 'POST',
                    'timeout'     => 45,
                    'redirection' => 5,  
                    'blocking'    => true, 
                    'httpversion' => '1.0',
                    'sslverify'   => false,
                    'headers'     => array('Content-Type' => 'application/json; charset=utf-8'),
                    'body'        => json_encode($body),
                );

                /*
                * Your API interaction could be built with wp_remote_post()
                */
                $response = wp_remote_post( $this->callback_url . '/client/payment/token', $args );
                
                if( $response['response']['code'] !== 200 ) {
                    
                    $body = $response['body'];

                    $pos = strpos( $body, '[E_PB_001]' );
                    
                    if( $pos ) {
                        $err = explode( '[E_PB_001]', $body );
                        $msg = explode( PHP_EOL, $err[1] );
                        wc_add_notice( $msg[0], 'error' );
                        return;
                    }

                } elseif( is_wp_error( $response ) ) {
                    wc_add_notice( 'Error de conexión, intentalo más tarde.', 'error' );
                    return;
                }

                if( $order->has_status( 'pending' ) ) {

                    $body = json_decode( $response['body'], true );

                    $token = $body['token'];

                    return array(
                        'result' => 'success',
                        'redirect' => $this->callback_url . '/pay?pvs_token=' . $token . '&style=full'
                    );
                }
            }

            private function getMethodDescription( $description ) {
                return '<div class="mp-header-logo">
                    <div class="mp-left-header">
                        <img class="mp-img-fluid" src="' . plugins_url( '/assets/images/pvslogo.svg', __FILE__ ) . '" alt="PVS">
                    </div>
                    <div>' . $description . '</div>
                </div>';
            }

            private function getDescription( $description ) {
                return '<div class="mp-header-logo">
                    <div>' . $description . '</div>
                    <hr>
                    <div>  
                        <img class="mp-img-fluid" src="' . plugins_url( '/assets/images/tarjetas.png', __FILE__ ) . '" alt="Tarjetas">
                    </div>
                </div>';
            }
        }
    }
}

add_filter( 'woocommerce_thankyou_order_received_text', 'woocommerce_pvs_order_received_text', 10, 2 );

function woocommerce_pvs_order_received_text( $str, $order ) {
	if ( function_exists( 'is_order_received_page' ) && 
	     is_order_received_page() ) {
            if( isset( $_GET['status'] ) ) {
                switch( trim( $_GET['status'] ) ) {
                    case 'success':
                        $str = '<h4>Felicitaciones por tu compra.</h4>';
                        break;
                    case 'failure':
                        $str = '<h4>Se ha producido un error, intentalo más tarde.</h4>';
                        break;
                }

            }
	}
	return $str;
}

/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
add_filter( 'woocommerce_payment_gateways', 'add_to_woocommerce_pvs_gateway' );

function add_to_woocommerce_pvs_gateway( $gateways ) {
    $gateways[] = 'WC_PVS_Gateway';
    return $gateways;
}

add_action( 'rest_api_init', 'add_callback_url_to_woocommerce_pvs_gateway' );

function add_callback_url_to_woocommerce_pvs_gateway() {
    register_rest_route( 
        'woocommerce-pvs/v1', // Namespace
        'callback',           // Endpoint
        array(
            "methods"             => 'POST',
            "callback"            => 'woocommerce_pvs_callback',
            "permission_callback" => '__return_true'
        )
    );
}

function woocommerce_pvs_callback( $request_data ) {

    global $woocommerce;

    $parameters = $request_data->get_params();

    $file = fopen( dirname( __FILE__ ) . "/log.txt",'a+' );
    fwrite( $file, $parameters['status'] . "\n");
    fwrite( $file, print_r($parameters, 1));
    fwrite( $file, "-----------------------------------------------\n" );
    fclose( $file );

    $status = $parameters['status'];

    if( $status === 'OK' ) {
        $paymentCode = $parameters['paymentCode'];
        $message = $parameters['message'];

        $order = wc_get_order( $paymentCode );

        //if( $order->has_status( 'pending' ) ) {
            $order->update_status( 'completed' );
            $order->reduce_order_stock();
        //}

        $data = array();

        $data['order'] = $paymentCode;
        $data['message'] = $message;
        
        return $data;
    }
}