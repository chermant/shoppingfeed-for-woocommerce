<?php

namespace ShoppingFeed\ShoppingFeedWC\Addons\Inventory\Marketplaces;

// Exit on direct access
defined( 'ABSPATH' ) || exit;

use ShoppingFeed\ShoppingFeedWC\Addons\Marketplace;
use ShoppingFeed\ShoppingFeedWC\Orders\Order\Metas;

class Cdiscount {
	use Marketplace;

	public function __construct() {
		add_action( 'sf_add_metas', array( $this, 'add_metas' ) );
	}

	/**
	 * @param $metas Metas
	 */
	public function add_metas( $metas ) {
		if (
			true !== $this->is_cdiscount( $metas->sf_order ) ||
			'CLogistique' !== $metas->sf_order->getPaymentInformation()['method']
		) {
			return;
		}

		$metas->add_meta(
			Metas::$dont_update_inventory,
			true
		);
	}
}
