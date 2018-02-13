<?php

namespace Payone;

use Payone\Database\Migration;
use Payone\Transaction\Log;

defined( 'ABSPATH' ) or die( 'Direct access not allowed' );

class Plugin {
	const CALLBACK_SLUG = 'payone-callback';

	public function init() {
		$migration = new Migration();
		$migration->run();

		if ( is_admin() ) {
			$settings = new \Payone\Admin\Settings();
			$settings->init();
		}

		$gateways = [
			\Payone\Gateway\CreditCard::GATEWAY_ID      => new \Payone\Gateway\CreditCard(),
			\Payone\Gateway\SepaDirectDebit::GATEWAY_ID => new \Payone\Gateway\SepaDirectDebit(),
			\Payone\Gateway\PrePayment::GATEWAY_ID      => new \Payone\Gateway\PrePayment(),
			\Payone\Gateway\Invoice::GATEWAY_ID         => new \Payone\Gateway\Invoice(),
		];

		foreach ( $gateways as $gateway ) {
			add_filter( 'woocommerce_payment_gateways', [ $gateway, 'add' ] );
		}

		add_action( 'woocommerce_order_status_changed', [ $this, 'order_status_changed' ], 10, 3 );
	}

	public function add_callback_url() {
		add_rewrite_rule( '^' . self::CALLBACK_SLUG . '/?$', 'index.php?' . self::CALLBACK_SLUG . '=true', 'top' );
		add_filter( 'query_vars', [ $this, 'add_rewrite_var' ] );
		add_action( 'template_redirect', [ $this, 'catch_payone_callback' ] );
	}

	public function add_rewrite_var( $vars ) {
		$vars[] = self::CALLBACK_SLUG;

		return $vars;
	}

	public function catch_payone_callback() {
		if ( get_query_var( self::CALLBACK_SLUG ) ) {

			$response = 'ERROR';
			if ( $this->is_valid_callback() ) {
				$this->debug_payone_callback();
				Log::constructFromPostVars();

				$response = 'TSOK';
			}

			echo $response;
			exit();
		}
	}

	public function order_status_changed( $id, $from_status, $to_status ) {
		// @todo Muss PAYONE kontaktiert werden?
	}

	private function is_valid_callback() {
		$options = get_option( \Payone\Admin\Option\Account::OPTION_NAME );

		return isset( $_POST['key'] ) && $_POST['key'] === hash( 'md5', $options['key'] );
	}

	private function debug_payone_callback() {
		if ( ! defined( 'PAYONE_LOCALDEV' ) || ! PAYONE_LOCALDEV ) {
			$message = json_encode( $_SERVER ) . "\n\n" . json_encode( $_POST ) . "\n\n";
			mail( 'dirk@pooliestudios.com', '[PAYONE CALLBACK]', $message );
		}
	}
}