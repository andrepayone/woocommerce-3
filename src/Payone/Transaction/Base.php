<?php

namespace Payone\Transaction;

use Payone\Gateway\GatewayBase;
use Payone\Payone\Api\Request;
use Payone\Plugin;

class Base extends Request {
	/**
	 * @var GatewayBase
	 */
	private $gateway;

	public function __construct( $type ) {
		parent::__construct();

		$this->gateway = null;
		$this->set( 'request', $type );
	}

	/**
	 * @return bool
	 */
	public function should_submit_cart() {
		if ( ! $this->gateway ) {
			return false;
		}

		if ( $this->gateway->should_submit_cart() ) {
			return true;
		}

		// Bei Sicherer Rechnung immer den Warenkorb mitsenden
		return $this->gateway->id === \Payone\Gateway\SafeInvoice::GATEWAY_ID;
	}

	/**
	 * Requests like "debit" don't have an authorization method. Therefore only "" is returned.
	 *
	 * @return string
	 */
	public function get_authorization_method() {
		$authorization_method = $this->get( 'request' );
		if ( ! in_array( $authorization_method, [ 'authorization', 'preauthorization' ], true ) ) {
			$authorization_method = '';
		}

		return $authorization_method;
	}

	/**
	 * @param \Payone\Gateway\GatewayBase $gateway
	 */
	public function set_data_from_gateway( $gateway ) {
		$this->gateway = $gateway;
		$this
			->set_account_id( $this->gateway->get_account_id() )
			->set_merchant_id( $this->gateway->get_merchant_id() )
			->set_portal_id( $this->gateway->get_portal_id() )
			->set_key( $this->gateway->get_key() );
	}

	public function set_personal_data_from_order( \WC_Order $order ) {
		$this->set( 'lastname', $order->get_billing_last_name() );
		$this->set( 'firstname', $order->get_billing_first_name() );
		$this->set( 'street', $order->get_billing_address_1() );
		$this->set( 'adressaddition', $order->get_billing_address_2() );
		$this->set( 'zip', $order->get_billing_postcode() );
		$this->set( 'city', $order->get_billing_city() );
        $this->set( 'state', $order->get_billing_state() );
		$this->set( 'country', $order->get_billing_country() );
		$this->set( 'email', $order->get_billing_email() );
		$this->set( 'telephonenumber', $order->get_billing_phone() );
	}

    /**
     * Sets PAYONE API shipping data from the provided WooCommerce order object.
     *
     * @author Fabian Böttcher <fabian.boettcher@payone.de>
     * @param \WC_Order $order The WooCommerce order object.
     * @return void
     */
	protected function set_shipping_data_from_order( \WC_Order $order ) {
	    // Collect order shipping information.
	    $data = [
	        'shipping_firstname' => $order->get_shipping_first_name(),
	        'shipping_lastname' => $order->get_shipping_last_name(),
	        'shipping_company' => $order->get_shipping_company(),
	        'shipping_street' => $order->get_shipping_address_1(),
	        'shipping_zip' => $order->get_shipping_postcode(),
	        'shipping_addressaddition' => $order->get_shipping_address_2(),
	        'shipping_city' => $order->get_shipping_city(),
	        'shipping_state' => $order->get_shipping_state(),
	        'shipping_country' => $order->get_shipping_country(),
        ];

	    // Trim parameter values and set parameters null for empty strings.
	    $data = array_map(function ($value) {
	        return empty($value = trim($value)) ? null : $value;
        }, $data);

	    // Set valid PAYONE API shipping data.
	    foreach ($data as $name => $value) {
	        if ($value !== null) {
                $this->set( $name, $value );
            }
        }
    }

    /**
     * Sets PAYONE API customer IP from the provided WooCommerce order.
     *
     * @author Fabian Böttcher <fabian.boettcher@payone.de>
     * @param \WC_Order $order The WooCommerce order object.
     * @return void
     */
    protected function set_customer_ip_from_order( \WC_Order $order )
    {
        // Get IP from order object.
        $ip = get_post_meta($order->get_id(), '_customer_ip_address', true);

        // Validate the customer IP.
        $ip = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

        // Append a valid IP address to PAYONE API request data.
        if ($ip) {
            $this->set( 'ip', $ip );
        }
    }

	/**
	 * @param \WC_Order $order
	 *
	 * @return int
	 */
	protected function get_next_sequencenumber( \WC_Order $order ) {
		$sequencenumber = 1 + (int)$order->get_meta( '_sequencenumber');
		$order->update_meta_data( '_sequencenumber', $sequencenumber );
		$order->save_meta_data();

		return $sequencenumber;
	}

	/**
	 * @param \WC_Order $order
	 * @param int $sequencenumber
	 */
	protected function set_sequencenumber( \WC_Order $order, $sequencenumber ) {
		$order->update_meta_data( '_sequencenumber', $sequencenumber );
		$order->save_meta_data();
	}

	public function add_article_list_to_transaction( \WC_Order $order ) {
		$article_list = $this->get_article_list_for_transaction( $order );
		foreach ( $article_list as $n => $article) {
			$this->set( 'id[' . $n . ']', $article[ 'id' ] );
			$this->set( 'pr[' . $n . ']', $article[ 'pr' ] );
			$this->set( 'no[' . $n . ']', $article[ 'no' ] );
			$this->set( 'de[' . $n . ']', $article[ 'de' ] );
			$this->set( 'va[' . $n . ']', $article[ 'va' ] );
		}
	}

	protected function get_article_list_for_transaction( \WC_Order $order ) {

	    $articles = [];
	    $discounts = [];
        $n = 1;
		foreach ( $order->get_items() as $item_id => $item_data ) {
            $product = $item_data->get_product();
            $data = $item_data->get_data();
            $va = (int)round(100 * Plugin::get_tax_rate_for_item_data( $data ) );
            $price_all = $data[ 'subtotal' ] + $data[ 'subtotal_tax' ];
            $discount = $price_all - ($data[ 'total' ] + $data[ 'total_tax']);
            $discount = (int)round( 100 * $discount );
            $price_one = $price_all / $item_data->get_quantity();
            $price = (int)round( 100 * $price_one );
            $articles[ $n ] = [
				'id' => $product->get_sku(),
				'pr' => $price,
				'no' => $item_data->get_quantity(),
				'de' => $product->get_name(),
				'va' => $va,
			];
			$n++;

			if (!isset($discounts[$va])) {
			    $discounts[$va] = 0;
            }
			$discounts[$va] += $discount;
		}
		foreach ( $order->get_shipping_methods() as $item_id => $item_data ) {
			$data = $item_data->get_data();
            $va = Plugin::get_tax_rate_for_item_data( $data );
			$price = (int)round( 100 * ( $data[ 'total' ] + $data[ 'total_tax' ] ) );
			$articles[ $n ] = [
				'id' => "shipment{$item_id}",
				'pr' => $price,
				'no' => 1,
				'de' => $data[ 'name' ],
				'va' => 100 * $va,
			];
			$n++;
		}

		$discountIdx = 1;
		foreach ( $discounts as $discountVa => $discount ) {
		    if ( $discount > 0 ) {
                $articles[ $n ] = [
                    'id' => "voucher{$discountIdx}",
                    'pr' => - $discount,
                    'no' => 1,
                    'de' => __( 'Discount', 'payone-woocommerce-3' ),
                    'va' => $discountVa,
                ];
                $n++;
                $discountIdx++;
            }
        }

		return $articles;
	}
}
