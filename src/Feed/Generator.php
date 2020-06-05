<?php

namespace ShoppingFeed\ShoppingFeedWC\Feed;

// Exit on direct access
defined( 'ABSPATH' ) || exit;

use Exception;
use ShoppingFeed\Feed\ProductGenerator;
use ShoppingFeed\ShoppingFeedWC\Products\Product;
use ShoppingFeed\ShoppingFeedWC\Products\Products;
use ShoppingFeed\ShoppingFeedWC\ShoppingFeedHelper;
use WP_Error;

/**
 * Class Generator
 * @package ShoppingFeed\Feed
 */
class Generator {

	/** @var Platform */
	private $platform;

	private $uri;

	/**
	 * @var ProductGenerator
	 */
	private $generator;

	/**
	 * Option name to save last generation date
	 */
	const SF_FEED_LAST_GENERATION_DATE = 'SF_FEED_LAST_GENERATION';

	/**
	 * @var Generator
	 */
	private static $instance;

	/**
	 * Get the singleton instance.
	 *
	 * @return Generator
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new static();
		}

		return self::$instance;
	}

	/**
	 * Singleton instance can't be cloned.
	 */
	private function __clone() {
	}

	/**
	 * Singleton instance can't be serialized.
	 */
	private function __wakeup() {
	}

	/**
	 * Generator constructor.
	 */
	private function __construct() {
		$this->set_platform();
		$this->set_uri();
		$this->set_generator();
		$this->set_processors();
		$this->set_filters();
		$this->set_mappers();
	}

	/**
	 * Return generated feed content
	 *
	 * @param bool $no_cache
	 */
	public function render( $no_cache = false ) {
		$file_path = Uri::get_instance()->get_full_path();

		if ( true === $no_cache ) {
			$generate = $this->generate();
			if ( is_wp_error( $generate ) ) {
				ShoppingFeedHelper::get_logger()->error(
					sprintf(
						__( 'Cant display feed', 'shopping-feed' )
					),
					array(
						'source' => 'shopping-feed',
					)
				);
			}
		}

		if ( is_file( $file_path ) ) {
			header( 'Content-Type: application/xml; charset=utf-8' );
			header( 'Content-Length: ' . filesize( $file_path ) );
			nocache_headers();
			readfile( $file_path );
			exit;
		}

		wp_die( 'Feed not ready' );
	}

	/**
	 * Generate Feed
	 *
	 * @return WP_Error|void
	 */
	public function generate() {
		$products_list = Products::get_instance()->get_list();

		if ( empty( $products_list ) ) {
			return new WP_Error( 'shopping_feed_generation_error_no_product', 'No product to export' );
		}

		try {
			$this->generator->write( $products_list );
			$uri = Uri::get_full_path();
			rename( ShoppingFeedHelper::get_tmp_uri( $uri ), $uri );
			update_option( self::SF_FEED_LAST_GENERATION_DATE, date_i18n( 'd/m/Y H:m:s' ) );
		} catch ( Exception $e ) {
			return new WP_Error( 'shopping_feed_generation_error', $e->getMessage() );
		}
	}

	/**
	 * In some case, you may need to pre-process data before to map them.
	 * This can be achieved in mappers or in your dataset, but sometimes things have to be separated, so you can register processors that are executed before mappers, and prepare your data before the mapping process.
	 */
	private function set_processors() {
	}

	/**
	 * Filters are designed discard some items from the feed.
	 * Filters are executed after processors, because item must be completely filled before to make the decision to keep it or not.
	 */
	private function set_filters() {
		# Ignore all items with undefined price
		$this->generator->addFilter(
			function (
				array $sf_product
			) {
				$sf_product = reset( $sf_product );

				/** @var Product $sf_product */
				return ! empty( $sf_product->get_price() );
			}
		);
	}

	/**
	 * As stated above, at least one mapper must be registered, this is where you populate the Product instance, which is later converted to XML by the library
	 */
	private function set_mappers() {
		//Simple product mapping
		$this->generator->addMapper(
			function (
				array $sf_product, \ShoppingFeed\Feed\Product\Product $product
			) {
				$sf_product = reset( $sf_product );
				/** @var Product $sf_product */
				$product->setReference( $sf_product->get_sku() );
				$product->setName( $sf_product->get_name() );
				$product->setPrice( $sf_product->get_price() );

				if ( ! empty( $sf_product->get_ean() ) ) {
					$product->setGtin( $sf_product->get_ean() );
				}

				$product->setQuantity( $sf_product->get_quantity() );

				if ( ! empty( $sf_product->get_link() ) ) {
					$product->setLink( $sf_product->get_link() );
				}
				if ( ! empty( $sf_product->get_discount() ) ) {
					$product->addDiscount( $sf_product->get_discount() );
				}
				if ( ! empty( $sf_product->get_image_main() ) ) {
					$product->setMainImage( $sf_product->get_image_main() );
				}

				if ( ! empty( $sf_product->get_full_description() ) || ! empty( $sf_product->get_short_description() ) ) {
					$product->setDescription( $sf_product->get_full_description(), $sf_product->get_short_description() );
				}

				if ( ! empty( $sf_product->get_brand_name() ) ) {
					$product->setBrand( $sf_product->get_brand_name(), $sf_product->get_brand_link() );
				}

				if ( ! empty( $sf_product->get_category_name() ) ) {
					$product->setCategory( $sf_product->get_category_name(), $sf_product->get_category_link() );
				}

				if ( ! empty( $sf_product->get_attributes() ) ) {
					$product->setAttributes( $sf_product->get_attributes() );
				}

				if ( ! empty( $sf_product->get_shipping_methods() ) ) {
					foreach ( $sf_product->get_shipping_methods() as $shipping_method ) {
						$product->addShipping( $shipping_method['cost'], $shipping_method['description'] );
					}
				}

				if ( ! empty( $sf_product->get_images() ) ) {
					$product->setAdditionalImages( $sf_product->get_images() );
				}

			}
		);

		//Product with variations mapping
		$this->generator->addMapper(
			function (
				array $sf_product, \ShoppingFeed\Feed\Product\Product $product
			) {
				$sf_product = reset( $sf_product );
				/** @var Product $sf_product */

				if ( empty( $sf_product->get_variations() ) ) {
					return;
				}
				foreach ( $sf_product->get_variations() as $sf_product_variation ) {
					$variation = $product->createVariation();

					$variation
						->setReference( $sf_product_variation['sku'] )
						->setPrice( $sf_product_variation['price'] )
						->setQuantity( $sf_product_variation['quantity'] );

					if ( ! empty( $sf_product_variation['attributes'] ) ) {
						$variation
							->setAttributes( $sf_product_variation['attributes'] );
					}
					if ( ! empty( $sf_product_variation['discount'] ) ) {
						$variation
							->addDiscount( $sf_product_variation['discount'] );
					}
					if ( ! empty( $sf_product_variation['image_main'] ) ) {
						$variation
							->setMainImage( $sf_product_variation['image_main'] );
					}
				}
			}
		);
	}

	/**
	 * Set the platform
	 */
	private function set_platform() {
		if ( ! isset( $this->platform ) ) {
			$this->platform = Platform::get_instance();
		}
	}

	/**
	 * Set the uri
	 */
	private function set_uri() {
		if ( ! isset( $this->uri ) ) {
			$this->uri = ShoppingFeedHelper::get_tmp_uri( Uri::get_instance()->get_uri() );
		}
	}

	/**
	 * Instanciate an instance from ProductGenerator
	 */
	private function set_generator() {
		if ( ! isset( $this->generator ) ) {
			$this->generator = new ProductGenerator();
			$this->generator->setPlatform( $this->platform->get_name(), $this->platform->get_version() );
			$this->generator->setUri( $this->uri );
		}
	}
}