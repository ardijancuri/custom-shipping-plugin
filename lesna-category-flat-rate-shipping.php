<?php
/**
 * Plugin Name: Lesna Category Flat Rate Shipping
 * Description: Assign WooCommerce flat rate shipping methods to specific product categories.
 * Version: 1.0.0
 * Author: Codex
 * Text Domain: lesna-category-flat-rate-shipping
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

final class Lesna_Category_Flat_Rate_Shipping {
	const METHOD_ID  = 'flat_rate';
	const OPTION_KEY = 'product_categories';
	const FIELD_TYPE = 'lesna_category_checkboxes';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Cached flat rate category settings keyed by shipping instance ID.
	 *
	 * @var array<int, int[]>
	 */
	private $instance_category_cache = array();

	/**
	 * Boot the plugin.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register bootstrap hooks.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ), 20 );
	}

	/**
	 * Initialize WooCommerce integrations after plugins are loaded.
	 */
	public function init() {
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Shipping_Zones' ) ) {
			if ( is_admin() ) {
				add_action( 'admin_notices', array( $this, 'render_missing_woocommerce_notice' ) );
			}

			return;
		}

		add_filter(
			'woocommerce_shipping_instance_form_fields_' . self::METHOD_ID,
			array( $this, 'add_category_field' ),
			20
		);
		add_filter(
			'woocommerce_generate_' . self::FIELD_TYPE . '_html',
			array( $this, 'render_category_checkboxes_field' ),
			20,
			4
		);
		add_filter( 'woocommerce_package_rates', array( $this, 'filter_package_rates' ), 20, 2 );
	}

	/**
	 * Render an admin notice if WooCommerce is not active.
	 */
	public function render_missing_woocommerce_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Lesna Category Flat Rate Shipping requires WooCommerce to be active.', 'lesna-category-flat-rate-shipping' )
		);
	}

	/**
	 * Add product category restrictions to the flat rate settings.
	 *
	 * @param array $fields Flat rate instance fields.
	 * @return array
	 */
	public function add_category_field( $fields ) {
		$fields[ self::OPTION_KEY ] = array(
			'title'       => __( 'Product categories', 'lesna-category-flat-rate-shipping' ),
			'type'        => self::FIELD_TYPE,
			'class'       => 'lesna-category-checkbox-list',
			'css'         => 'max-height: 220px; overflow: auto; padding: 8px 12px; border: 1px solid #dcdcde; border-radius: 4px; background: #fff;',
			'default'     => array(),
			'desc_tip'    => true,
			'description' => __(
				'Check one or more categories to make this flat rate available for matching products. Leave all checkboxes unchecked to keep this rate available for all products. Child categories are not matched unless checked explicitly.',
				'lesna-category-flat-rate-shipping'
			),
			'custom_attributes' => array(),
			'sanitize_callback' => array( $this, 'sanitize_category_ids' ),
		);

		return $fields;
	}

	/**
	 * Render the category checkbox field in the flat rate settings form.
	 *
	 * @param string $field_html  Existing field markup.
	 * @param string $key         Field key.
	 * @param array  $data        Field configuration.
	 * @param object $wc_settings Current WooCommerce settings object.
	 * @return string
	 */
	public function render_category_checkboxes_field( $field_html, $key, $data, $wc_settings ) {
		if ( ! $wc_settings instanceof WC_Shipping_Method || self::OPTION_KEY !== $key ) {
			return $field_html;
		}

		$field_key  = $wc_settings->get_field_key( $key );
		$field_id   = $field_key . '_container';
		$defaults   = array(
			'title'             => '',
			'class'             => '',
			'css'               => '',
			'desc_tip'          => false,
			'description'       => '',
			'custom_attributes' => array(),
			'disabled'          => false,
		);
		$data       = wp_parse_args( $data, $defaults );
		$value      = $this->sanitize_category_ids( $wc_settings->get_option( $key, array() ) );
		$categories = $this->get_hierarchical_product_categories();

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_id ); ?>"><?php echo wp_kses_post( $data['title'] ); ?> <?php echo $wc_settings->get_tooltip_html( $data ); // WPCS: XSS ok. ?></label>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
					<div id="<?php echo esc_attr( $field_id ); ?>" class="<?php echo esc_attr( $data['class'] ); ?>" style="<?php echo esc_attr( $data['css'] ); ?>">
						<?php if ( empty( $categories ) ) : ?>
							<p><?php esc_html_e( 'No product categories found.', 'lesna-category-flat-rate-shipping' ); ?></p>
						<?php else : ?>
							<?php foreach ( $categories as $category ) : ?>
								<label style="display:block; margin-left: <?php echo esc_attr( 18 * (int) $category['depth'] ); ?>px; margin-bottom: 6px;">
									<input
										type="checkbox"
										name="<?php echo esc_attr( $field_key ); ?>[]"
										value="<?php echo esc_attr( $category['term_id'] ); ?>"
										<?php checked( in_array( $category['term_id'], $value, true ) ); ?>
										<?php disabled( $data['disabled'], true ); ?>
										<?php echo $wc_settings->get_custom_attribute_html( $data ); // WPCS: XSS ok. ?>
									/>
									<span><?php echo esc_html( $category['name'] ); ?></span>
								</label>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
					<?php echo $wc_settings->get_description_html( $data ); // WPCS: XSS ok. ?>
				</fieldset>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}

	/**
	 * Remove category-restricted flat rates that do not match the package contents.
	 *
	 * @param array $rates   Calculated package rates.
	 * @param array $package Package data from WooCommerce.
	 * @return array
	 */
	public function filter_package_rates( $rates, $package ) {
		if ( empty( $rates ) || empty( $package['contents'] ) || ! is_array( $rates ) ) {
			return $rates;
		}

		foreach ( $rates as $rate_key => $rate ) {
			if ( ! $rate instanceof WC_Shipping_Rate ) {
				continue;
			}

			if ( self::METHOD_ID !== $rate->get_method_id() ) {
				continue;
			}

			$category_ids = $this->get_flat_rate_category_ids( (int) $rate->get_instance_id() );

			if ( empty( $category_ids ) ) {
				continue;
			}

			if ( ! $this->package_has_matching_category( $package, $category_ids ) ) {
				unset( $rates[ $rate_key ] );
			}
		}

		return $rates;
	}

	/**
	 * Sanitize selected category IDs from the settings form.
	 *
	 * @param mixed $value Raw value from the request or settings.
	 * @return int[]
	 */
	public function sanitize_category_ids( $value ) {
		if ( ! is_array( $value ) ) {
			$value = is_null( $value ) || '' === $value ? array() : array( $value );
		}

		return array_values(
			array_unique(
				array_filter(
					array_map( 'absint', array_map( 'wp_unslash', $value ) )
				)
			)
		);
	}

	/**
	 * Build the product category list in parent/child order for checkbox rendering.
	 *
	 * @return array<int, array<string, int|string>>
	 */
	private function get_hierarchical_product_categories() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$children = array();

		foreach ( $terms as $term ) {
			$children[ (int) $term->parent ][] = $term;
		}

		foreach ( $children as $parent_id => $branch_terms ) {
			usort(
				$branch_terms,
				static function ( $left, $right ) {
					return strcasecmp( $left->name, $right->name );
				}
			);

			$children[ $parent_id ] = $branch_terms;
		}

		return $this->flatten_category_tree( $children );
	}

	/**
	 * Flatten category terms into a hierarchical list with depth metadata.
	 *
	 * @param array<int, array<int, WP_Term>> $children Terms keyed by parent ID.
	 * @param int                             $parent_id Parent term ID.
	 * @param int                             $depth     Tree depth.
	 * @return array<int, array<string, int|string>>
	 */
	private function flatten_category_tree( $children, $parent_id = 0, $depth = 0 ) {
		if ( empty( $children[ $parent_id ] ) ) {
			return array();
		}

		$flat = array();

		foreach ( $children[ $parent_id ] as $term ) {
			$flat[] = array(
				'term_id' => (int) $term->term_id,
				'name'    => $term->name,
				'depth'   => $depth,
			);

			$flat = array_merge(
				$flat,
				$this->flatten_category_tree( $children, (int) $term->term_id, $depth + 1 )
			);
		}

		return $flat;
	}

	/**
	 * Fetch selected category IDs for a flat rate shipping instance.
	 *
	 * @param int $instance_id Flat rate instance ID.
	 * @return int[]
	 */
	private function get_flat_rate_category_ids( $instance_id ) {
		if ( $instance_id <= 0 ) {
			return array();
		}

		if ( array_key_exists( $instance_id, $this->instance_category_cache ) ) {
			return $this->instance_category_cache[ $instance_id ];
		}

		$shipping_method = WC_Shipping_Zones::get_shipping_method( $instance_id );

		if ( ! $shipping_method || ! is_callable( array( $shipping_method, 'get_instance_option' ) ) ) {
			$this->instance_category_cache[ $instance_id ] = array();
			return $this->instance_category_cache[ $instance_id ];
		}

		$category_ids = $shipping_method->get_instance_option( self::OPTION_KEY, array() );

		if ( ! is_array( $category_ids ) ) {
			$category_ids = array( $category_ids );
		}

		$category_ids = $this->sanitize_category_ids( $category_ids );

		$this->instance_category_cache[ $instance_id ] = $category_ids;

		return $this->instance_category_cache[ $instance_id ];
	}

	/**
	 * Check whether a package contains any shippable product from the selected categories.
	 *
	 * @param array $package      Shipping package.
	 * @param int[] $category_ids Product category term IDs.
	 * @return bool
	 */
	private function package_has_matching_category( $package, $category_ids ) {
		foreach ( (array) $package['contents'] as $item ) {
			if ( empty( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
				continue;
			}

			$product = $item['data'];

			if ( ! $product->needs_shipping() ) {
				continue;
			}

			$product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

			if ( $product_id && has_term( $category_ids, 'product_cat', $product_id ) ) {
				return true;
			}
		}

		return false;
	}
}

Lesna_Category_Flat_Rate_Shipping::instance();
