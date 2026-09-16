<?php
/**
 * Main plugin class.
 *
 * @package WodoboMixMatchBoxBuilder
 */

defined( 'ABSPATH' ) || exit;

final class PPBB_Plugin {

	/** @var PPBB_Plugin|null */
	private static $instance = null;

	/** @var bool */
	private $assets_enqueued = false;

	/** @var bool */
	private $auto_mount_printed = false;

	/**
	 * Singleton.
	 *
	 * @return PPBB_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_block' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ), 30 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_cart_button_style' ), 999 );
		add_action( 'woocommerce_before_add_to_cart_form', array( $this, 'render_auto_mount' ), 1 );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_page' ), 90 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
		// Run after native Mix & Match's Store API response formatter (priority 10).
		add_filter( 'rest_request_after_callbacks', array( $this, 'clean_container_store_api_description' ), 30, 3 );
		add_filter( 'woocommerce_hydration_request_after_callbacks', array( $this, 'clean_container_store_api_description' ), 30, 3 );
		add_filter( 'body_class', array( $this, 'body_classes' ) );
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'product_data_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'product_data_panel' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'product_content_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product_design' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product_content' ) );
	}

	/**
	 * Guard against theme-generated CSS leaking into a Mix & Match container's
	 * Cart/Checkout Block product description.
	 *
	 * WooCommerce formats the cart item from the product's view-context short
	 * description, then Mix & Match appends its native Edit selections action.
	 * Some theme output can intermittently put raw Nectarblocks CSS into that
	 * view-context value even when the saved short description is empty. Only
	 * when the exact CSS signature occurs on a native container, rebuild the
	 * description from the unfiltered stored product excerpt and preserve the
	 * existing native editing link. Do not modify cart metadata, prices, orders,
	 * other items, or the saved product description.
	 *
	 * @param mixed $response REST response.
	 * @param mixed $server   REST server (unused).
	 * @param mixed $request  REST request.
	 * @return mixed
	 */
	public function clean_container_store_api_description( $response, $server, $request ) {
		if ( is_wp_error( $response ) || ! is_object( $response ) || ! method_exists( $response, 'get_data' ) || ! method_exists( $response, 'set_data' ) || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return $response;
		}

		// Only WooCommerce's Store API (including its server-side hydration).
		if ( false === strpos( (string) $request->get_route(), 'wc/store/' ) || ! function_exists( 'WC' ) || ! WC()->cart || ! function_exists( 'wc_mnm_is_container_cart_item' ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) || empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
			return $response;
		}

		$cart = WC()->cart->get_cart();
		$changed = false;
		foreach ( $data['items'] as &$item ) {
			if ( ! is_array( $item ) || empty( $item['key'] ) || ! isset( $item['short_description'] ) || ! is_string( $item['short_description'] ) ) {
				continue;
			}

			// This exact signature was captured in the customer's broken checkout.
			// An ordinary short description, even with inline styling, is untouched.
			if ( ! preg_match( '/(?:span\.)?nectar-blocks-text\s*\{\s*display\s*:\s*block\s*\}/i', html_entity_decode( $item['short_description'], ENT_QUOTES, 'UTF-8' ) ) ) {
				continue;
			}

			$cart_item = isset( $cart[ $item['key'] ] ) ? $cart[ $item['key'] ] : null;
			if ( ! is_array( $cart_item ) || ! wc_mnm_is_container_cart_item( $cart_item ) || empty( $cart_item['data'] ) || ! is_object( $cart_item['data'] ) || ! method_exists( $cart_item['data'], 'get_short_description' ) ) {
				continue;
			}

			// Preserve the existing native link (including its query arguments),
			// rather than synthesizing a competing editing implementation.
			$edit_link = '';
			if ( preg_match( '~<p\s+class="wc-block-cart-item__edit"\s*>.*?</p>~is', $item['short_description'], $matches ) ) {
				$edit_link = $matches[0];
			}

			// 'edit' reads the stored description without theme view-context
			// filters, and avoids feeding it back through wc_format_content.
			$stored = (string) $cart_item['data']->get_short_description( 'edit' );
			if ( preg_match( '/(?:span\.)?nectar-blocks-text\s*\{\s*display\s*:\s*block\s*\}/i', $stored ) ) {
				$stored = '';
			}
			$stored = trim( wp_strip_all_tags( $stored ) );
			$excerpt = '' !== $stored ? '<p class="wc-block-components-product-metadata__description-text">' . esc_html( wp_trim_words( $stored, 12, '…' ) ) . '</p>' : '';
			$item['short_description'] = $edit_link . $excerpt;
			$changed = true;
		}
		unset( $item );

		if ( $changed ) {
			$response->set_data( $data );
		}
		return $response;
	}

	/**
	 * Register the optional Gutenberg/Nectarblocks mount block.
	 */
	public function register_block() {
		wp_register_script(
			'ppbb-block-editor',
			PPBB_URL . 'assets/js/block-editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			PPBB_VERSION,
			true
		);

		register_block_type(
			PPBB_PATH . 'blocks/box-builder',
			array(
				'render_callback' => array( $this, 'render_block' ),
			)
		);
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings() {
		register_setting(
			'ppbb_settings',
			'ppbb_auto_enable',
			array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);

		register_setting(
			'ppbb_settings',
			'ppbb_hide_native_intro',
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);

		register_setting( 'ppbb_settings', 'ppbb_default_desktop_columns', array( 'type' => 'integer', 'default' => 3, 'sanitize_callback' => array( $this, 'sanitize_desktop_columns' ) ) );
		register_setting( 'ppbb_settings', 'ppbb_default_tablet_columns', array( 'type' => 'integer', 'default' => 2, 'sanitize_callback' => array( $this, 'sanitize_tablet_columns' ) ) );
		register_setting( 'ppbb_settings', 'ppbb_default_mobile_columns', array( 'type' => 'integer', 'default' => 2, 'sanitize_callback' => array( $this, 'sanitize_mobile_columns' ) ) );
		register_setting( 'ppbb_settings', 'ppbb_default_slot_columns', array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => array( $this, 'sanitize_slot_columns' ) ) );
		register_setting( 'ppbb_settings', 'ppbb_default_image_style', array( 'type' => 'string', 'default' => 'landscape-4-3', 'sanitize_callback' => array( $this, 'sanitize_image_style' ) ) );
		register_setting( 'ppbb_settings', 'ppbb_default_mobile_bar_style', array( 'type' => 'string', 'default' => 'prominent', 'sanitize_callback' => array( $this, 'sanitize_mobile_bar_style' ) ) );
		register_setting( 'ppbb_settings', 'ppbb_default_title_size_mode', array( 'type' => 'string', 'default' => 'auto', 'sanitize_callback' => array( $this, 'sanitize_design_mode' ) ) );
		register_setting( 'ppbb_settings', 'ppbb_default_title_size_value', array( 'type' => 'string', 'default' => '', 'sanitize_callback' => array( $this, 'sanitize_design_number' ) ) );
		register_setting( 'ppbb_settings', 'ppbb_default_title_size_unit', array( 'type' => 'string', 'default' => 'rem', 'sanitize_callback' => array( $this, 'sanitize_css_unit' ) ) );
		register_setting( 'ppbb_settings', 'ppbb_default_tile_gap_mode', array( 'type' => 'string', 'default' => 'auto', 'sanitize_callback' => array( $this, 'sanitize_design_mode' ) ) );
		register_setting( 'ppbb_settings', 'ppbb_default_tile_gap_value', array( 'type' => 'string', 'default' => '', 'sanitize_callback' => array( $this, 'sanitize_design_number' ) ) );
		register_setting( 'ppbb_settings', 'ppbb_default_tile_gap_unit', array( 'type' => 'string', 'default' => 'px', 'sanitize_callback' => array( $this, 'sanitize_css_unit' ) ) );
		register_setting( 'ppbb_settings', 'ppbb_default_high_contrast_color', array( 'type' => 'string', 'default' => '', 'sanitize_callback' => array( $this, 'sanitize_optional_hex_color' ) ) );
	}

	public function sanitize_desktop_columns( $value ) { return max( 1, min( 5, absint( $value ) ) ); }
	public function sanitize_tablet_columns( $value ) { return max( 1, min( 4, absint( $value ) ) ); }
	public function sanitize_mobile_columns( $value ) { return max( 1, min( 2, absint( $value ) ) ); }
	public function sanitize_slot_columns( $value ) { $value = absint( $value ); return $value > 12 ? 12 : $value; }
	public function sanitize_image_style( $value ) {
		$allowed = array( 'circle', 'square', 'landscape-4-3', 'landscape-3-2', 'wide-16-9' );
		return in_array( $value, $allowed, true ) ? $value : 'landscape-4-3';
	}
	public function sanitize_mobile_bar_style( $value ) {
		$allowed = array( 'compact', 'standard', 'prominent', 'high-contrast' );
		return in_array( $value, $allowed, true ) ? $value : 'prominent';
	}

	public function sanitize_design_mode( $value ) {
		$allowed = array( 'auto', 'custom', 'inherit', 'theme' );
		return in_array( $value, $allowed, true ) ? $value : 'auto';
	}

	public function sanitize_css_unit( $value ) {
		$allowed = array( 'px', 'em', 'rem', 'vw', 'vh' );
		return in_array( $value, $allowed, true ) ? $value : 'rem';
	}

	public function sanitize_design_number( $value ) {
		if ( '' === $value || null === $value ) { return ''; }
		$value = (float) $value;
		$value = max( 0, min( 200, $value ) );
		return rtrim( rtrim( number_format( $value, 3, '.', '' ), '0' ), '.' );
	}

	public function sanitize_optional_hex_color( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) { return ''; }
		$clean = sanitize_hex_color( $value );
		return $clean ? $clean : '';
	}

	private function css_dimension( $value, $unit ) {
		$value = $this->sanitize_design_number( $value );
		if ( '' === $value ) { return ''; }
		return $value . $this->sanitize_css_unit( $unit );
	}

	private function sanitize_css_dimension( $value ) {
		$value = trim( (string) $value );
		return preg_match( '/^(?:\d+(?:\.\d+)?|\.\d+)(?:px|em|rem|vw|vh)$/', $value ) ? $value : '';
	}


	/**
	 * Global plugin-owned design defaults. Themes may still supply color/font tokens,
	 * but no theme builder is required to configure the component.
	 *
	 * @return array
	 */
	private function global_design_defaults() {
		$title_mode = $this->sanitize_design_mode( get_option( 'ppbb_default_title_size_mode', 'auto' ) );
		$gap_mode   = $this->sanitize_design_mode( get_option( 'ppbb_default_tile_gap_mode', 'auto' ) );
		return array(
			'desktopColumns'  => $this->sanitize_desktop_columns( get_option( 'ppbb_default_desktop_columns', 3 ) ),
			'tabletColumns'   => $this->sanitize_tablet_columns( get_option( 'ppbb_default_tablet_columns', 2 ) ),
			'mobileColumns'   => $this->sanitize_mobile_columns( get_option( 'ppbb_default_mobile_columns', 2 ) ),
			'slotColumns'     => $this->sanitize_slot_columns( get_option( 'ppbb_default_slot_columns', 0 ) ),
			'imageStyle'      => $this->sanitize_image_style( get_option( 'ppbb_default_image_style', 'landscape-4-3' ) ),
			'mobileBarStyle'  => $this->sanitize_mobile_bar_style( get_option( 'ppbb_default_mobile_bar_style', 'prominent' ) ),
			'titleSize'       => 'custom' === $title_mode ? $this->css_dimension( get_option( 'ppbb_default_title_size_value', '' ), get_option( 'ppbb_default_title_size_unit', 'rem' ) ) : '',
			'tileGap'         => 'custom' === $gap_mode ? $this->css_dimension( get_option( 'ppbb_default_tile_gap_value', '' ), get_option( 'ppbb_default_tile_gap_unit', 'px' ) ) : '',
			'highContrastColor' => $this->sanitize_optional_hex_color( get_option( 'ppbb_default_high_contrast_color', '' ) ),
			'showFilters'     => true,
			'showDetails'     => true,
		);
	}

	/**
	 * Resolve product-level visual overrides on top of global defaults.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	private function resolved_design( $product_id ) {
		$design = $this->global_design_defaults();
		$map = array(
			'desktopColumns' => '_ppbb_desktop_columns',
			'tabletColumns'  => '_ppbb_tablet_columns',
			'mobileColumns'  => '_ppbb_mobile_columns',
			'slotColumns'    => '_ppbb_slot_columns',
			'imageStyle'     => '_ppbb_image_style',
			'mobileBarStyle' => '_ppbb_mobile_bar_style',
		);
		foreach ( $map as $key => $meta_key ) {
			$value = get_post_meta( $product_id, $meta_key, true );
			if ( '' === $value || 'inherit' === $value ) {
				continue;
			}
			switch ( $key ) {
				case 'desktopColumns': $design[ $key ] = $this->sanitize_desktop_columns( $value ); break;
				case 'tabletColumns':  $design[ $key ] = $this->sanitize_tablet_columns( $value ); break;
				case 'mobileColumns':  $design[ $key ] = $this->sanitize_mobile_columns( $value ); break;
				case 'slotColumns':    $design[ $key ] = $this->sanitize_slot_columns( $value ); break;
				case 'imageStyle':     $design[ $key ] = $this->sanitize_image_style( $value ); break;
				case 'mobileBarStyle': $design[ $key ] = $this->sanitize_mobile_bar_style( $value ); break;
			}
		}

		$title_mode = get_post_meta( $product_id, '_ppbb_title_size_mode', true );
		if ( 'auto' === $title_mode ) {
			$design['titleSize'] = '';
		} elseif ( 'custom' === $title_mode ) {
			$design['titleSize'] = $this->css_dimension( get_post_meta( $product_id, '_ppbb_title_size_value', true ), get_post_meta( $product_id, '_ppbb_title_size_unit', true ) );
		}

		$gap_mode = get_post_meta( $product_id, '_ppbb_tile_gap_mode', true );
		if ( 'auto' === $gap_mode ) {
			$design['tileGap'] = '';
		} elseif ( 'custom' === $gap_mode ) {
			$design['tileGap'] = $this->css_dimension( get_post_meta( $product_id, '_ppbb_tile_gap_value', true ), get_post_meta( $product_id, '_ppbb_tile_gap_unit', true ) );
		}

		$contrast_mode = get_post_meta( $product_id, '_ppbb_high_contrast_color_mode', true );
		if ( 'theme' === $contrast_mode ) {
			$design['highContrastColor'] = '';
		} elseif ( 'custom' === $contrast_mode ) {
			$design['highContrastColor'] = $this->sanitize_optional_hex_color( get_post_meta( $product_id, '_ppbb_high_contrast_color', true ) );
		}

		return $design;
	}

	/**
	 * Load the native WordPress color picker on the Box Builder settings screen
	 * and WooCommerce product editor.
	 */
	public function enqueue_admin_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) { return; }
		$is_box_builder = isset( $_GET['page'] ) && 'ppbb-status' === sanitize_key( wp_unslash( $_GET['page'] ) );
		$is_product     = 'product' === $screen->post_type && in_array( $screen->base, array( 'post', 'post-new' ), true );
		if ( ! $is_box_builder && ! $is_product ) { return; }

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		if ( $is_product && function_exists( 'wp_enqueue_editor' ) ) { wp_enqueue_editor(); }
		wp_add_inline_script(
			'wp-color-picker',
			"jQuery(function($){ $('.ppbb-color-field').wpColorPicker(); function ppbbToggleContentAccordion(position){ var checked = $('#_ppbb_' + position + '_accordion').is(':checked'); $('#_ppbb_' + position + '_accordion_title').closest('.form-field').toggle(checked); } ['above','below_main','below_sidebar'].forEach(function(position){ ppbbToggleContentAccordion(position); $('#_ppbb_' + position + '_accordion').on('change', function(){ ppbbToggleContentAccordion(position); }); }); function ppbbToggleBuilderIntro(){ var mode = $('#_ppbb_intro_mode').val() || 'default'; $('.ppbb-intro-custom-field').closest('.form-field').toggle(mode === 'custom'); } function ppbbToggleDesignDetails(){ var titleMode = $('#_ppbb_title_size_mode').val() || ''; $('.ppbb-title-custom').toggle(titleMode === 'custom'); var gapMode = $('#_ppbb_tile_gap_mode').val() || ''; $('.ppbb-gap-custom').toggle(gapMode === 'custom'); var contrastMode = $('#_ppbb_high_contrast_color_mode').val() || ''; $('.ppbb-contrast-custom').toggle(contrastMode === 'custom'); } function ppbbToggleGlobalDesignDetails(){ var titleMode = $('#ppbb_default_title_size_mode').val() || 'auto'; $('[name=ppbb_default_title_size_value], [name=ppbb_default_title_size_unit]').toggle(titleMode === 'custom'); var gapMode = $('#ppbb_default_tile_gap_mode').val() || 'auto'; $('[name=ppbb_default_tile_gap_value], [name=ppbb_default_tile_gap_unit]').toggle(gapMode === 'custom'); } ppbbToggleBuilderIntro(); ppbbToggleDesignDetails(); ppbbToggleGlobalDesignDetails(); $('#_ppbb_intro_mode').on('change', ppbbToggleBuilderIntro); $('#_ppbb_title_size_mode, #_ppbb_tile_gap_mode, #_ppbb_high_contrast_color_mode').on('change', ppbbToggleDesignDetails); $('#ppbb_default_title_size_mode, #ppbb_default_tile_gap_mode').on('change', ppbbToggleGlobalDesignDetails); });"
		);
		if ( $is_product ) {
			wp_add_inline_script(
				'wp-color-picker',
				"jQuery(function($){ function ppbbTogglePurchaseBadge(){ var isBadge = $('#_ppbb_purchase_note_title_style').val() === 'badge'; $('.ppbb-purchase-note-badge-field').closest('.form-field').toggle(isBadge); } ppbbTogglePurchaseBadge(); $('#_ppbb_purchase_note_title_style').on('change', ppbbTogglePurchaseBadge); });"
			);
		}

		if ( $is_product ) {
			wp_add_inline_style(
				'wp-color-picker',
				'#ppbb_design_product_data .ppbb-design-wrap{padding:12px}.ppbb-design-intro{margin:0 0 16px}.ppbb-design-section{margin:0 0 18px;padding:14px;border:1px solid #dcdcde;border-radius:8px;background:#fff}.ppbb-design-section h4{margin:0 0 12px;font-size:14px}.ppbb-design-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px 18px}.ppbb-design-field{min-width:0}.ppbb-design-field .form-field{padding:0!important;margin:0!important}.ppbb-design-field .form-field label{float:none!important;width:auto!important;margin:0 0 6px!important;display:block;font-weight:600}.ppbb-design-field .form-field select,.ppbb-design-field .form-field input[type=number],.ppbb-design-field .form-field input[type=text]{float:none!important;width:100%!important;max-width:100%!important;margin:0!important}.ppbb-design-field .woocommerce-help-tip{float:right}.ppbb-design-inline{display:grid;grid-template-columns:minmax(0,1fr) 90px;gap:8px}.ppbb-design-field .wp-picker-container{display:block}.ppbb-design-field .wp-picker-container .wp-color-result{margin:0 0 6px}.ppbb-design-note{margin:7px 0 0;color:#646970;font-size:12px;line-height:1.4}@media(max-width:1100px){#ppbb_design_product_data .ppbb-design-grid{grid-template-columns:1fr}}'
			);
		}
	}

	/**
	 * Add a Mix & Match-only design tab to Product data.
	 *
	 * @param array $tabs Existing tabs.
	 * @return array
	 */
	public function product_data_tab( $tabs ) {
		$tabs['ppbb_design'] = array(
			'label'    => __( 'Box Builder Design', 'picky-plate-box-builder' ),
			'target'   => 'ppbb_design_product_data',
			'class'    => array( 'show_if_mix-and-match' ),
			'priority' => 61,
		);
		$tabs['ppbb_content'] = array(
			'label'    => __( 'Box Builder Content', 'picky-plate-box-builder' ),
			'target'   => 'ppbb_content_product_data',
			'class'    => array( 'show_if_mix-and-match' ),
			'priority' => 62,
		);
		return $tabs;
	}

	/**
	 * Product-level design overrides. These alter presentation only.
	 */
	public function product_data_panel() {
		global $post;
		$global = $this->global_design_defaults();
		?>
		<div id="ppbb_design_product_data" class="panel woocommerce_options_panel hidden show_if_mix-and-match">
			<div class="ppbb-design-wrap">
				<p class="ppbb-design-intro"><strong><?php esc_html_e( 'Mix & Match Box Builder design', 'picky-plate-box-builder' ); ?></strong><br><span class="description"><?php esc_html_e( 'Optional overrides for this box only. Leave a field on “Use global default” to inherit WooCommerce → Box Builder settings. These settings never change Mix & Match pricing, inventory, validation, or subscription logic.', 'picky-plate-box-builder' ); ?></span></p>

				<div class="ppbb-design-section">
					<h4><?php esc_html_e( 'Layout', 'picky-plate-box-builder' ); ?></h4>
					<div class="ppbb-design-grid">
						<div class="ppbb-design-field">
							<?php woocommerce_wp_select( array(
								'id' => '_ppbb_desktop_columns',
								'label' => __( 'Protein grid columns', 'picky-plate-box-builder' ),
								'description' => __( 'Desktop target. The grid automatically steps down only when the builder becomes too narrow (for example 4 → 3 → 2 and 3 → 2).', 'picky-plate-box-builder' ),
								'desc_tip' => true,
								'options' => array( '' => sprintf( __( 'Use global default (%d)', 'picky-plate-box-builder' ), $global['desktopColumns'] ), '2' => '2', '3' => '3', '4' => '4', '5' => '5' ),
							) ); ?>
						</div>
						<div class="ppbb-design-field">
							<?php woocommerce_wp_select( array(
								'id' => '_ppbb_slot_columns',
								'label' => __( 'Selection slot columns', 'picky-plate-box-builder' ),
								'description' => __( 'Controls how many small “Your Box” selection slots appear across before wrapping to another row. Auto adapts to the box size.', 'picky-plate-box-builder' ),
								'desc_tip' => true,
								'options' => array( '' => __( 'Use global default', 'picky-plate-box-builder' ), '0' => __( 'Auto', 'picky-plate-box-builder' ), '3' => '3', '4' => '4', '5' => '5', '6' => '6', '7' => '7', '8' => '8', '9' => '9', '10' => '10', '11' => '11', '12' => '12' ),
							) ); ?>
						</div>
					</div>
				</div>

				<div class="ppbb-design-section">
					<h4><?php esc_html_e( 'Protein tiles', 'picky-plate-box-builder' ); ?></h4>
					<div class="ppbb-design-grid">
						<div class="ppbb-design-field">
							<?php woocommerce_wp_select( array(
								'id' => '_ppbb_image_style',
								'label' => __( 'Protein image shape', 'picky-plate-box-builder' ),
								'description' => __( 'Circle is inset. Square and landscape options use full-bleed card imagery.', 'picky-plate-box-builder' ),
								'desc_tip' => true,
								'options' => array( '' => __( 'Use global default', 'picky-plate-box-builder' ), 'circle' => __( 'Circle', 'picky-plate-box-builder' ), 'square' => __( 'Square 1:1 — full bleed', 'picky-plate-box-builder' ), 'landscape-4-3' => __( 'Landscape 4:3 — full bleed', 'picky-plate-box-builder' ), 'landscape-3-2' => __( 'Landscape 3:2 — full bleed', 'picky-plate-box-builder' ), 'wide-16-9' => __( 'Wide 16:9 — full bleed', 'picky-plate-box-builder' ) ),
							) ); ?>
						</div>
						<div class="ppbb-design-field">
							<?php woocommerce_wp_select( array(
								'id' => '_ppbb_tile_gap_mode',
								'label' => __( 'Tile spacing', 'picky-plate-box-builder' ),
								'description' => __( 'Controls the horizontal and vertical gap between protein tiles. Automatic keeps the built-in responsive spacing.', 'picky-plate-box-builder' ),
								'desc_tip' => true,
								'options' => array( '' => __( 'Use global default', 'picky-plate-box-builder' ), 'auto' => __( 'Automatic responsive (override global)', 'picky-plate-box-builder' ), 'custom' => __( 'Custom spacing (override global)', 'picky-plate-box-builder' ) ),
							) ); ?>
							<div class="ppbb-gap-custom ppbb-design-inline">
								<?php woocommerce_wp_text_input( array(
									'id' => '_ppbb_tile_gap_value',
									'label' => __( 'Spacing value', 'picky-plate-box-builder' ),
									'type' => 'number',
									'custom_attributes' => array( 'step' => '0.01', 'min' => '0' ),
								) ); ?>
								<?php woocommerce_wp_select( array(
									'id' => '_ppbb_tile_gap_unit',
									'label' => __( 'Unit', 'picky-plate-box-builder' ),
									'options' => array( 'px' => 'px', 'em' => 'em', 'rem' => 'rem', 'vw' => 'vw', 'vh' => 'vh' ),
								) ); ?>
							</div>
						</div>
					</div>
				</div>

				<div class="ppbb-design-section">
					<h4><?php esc_html_e( 'Protein titles', 'picky-plate-box-builder' ); ?></h4>
					<div class="ppbb-design-grid">
						<div class="ppbb-design-field">
							<?php woocommerce_wp_select( array(
								'id' => '_ppbb_title_size_mode',
								'label' => __( 'Product title size', 'picky-plate-box-builder' ),
								'description' => __( 'Controls the protein name inside each tile. Automatic keeps the built-in responsive sizing.', 'picky-plate-box-builder' ),
								'desc_tip' => true,
								'options' => array( '' => __( 'Use global default', 'picky-plate-box-builder' ), 'auto' => __( 'Automatic responsive (override global)', 'picky-plate-box-builder' ), 'custom' => __( 'Custom size (override global)', 'picky-plate-box-builder' ) ),
							) ); ?>
						</div>
						<div class="ppbb-design-field ppbb-title-custom">
							<div class="ppbb-design-inline">
								<?php woocommerce_wp_text_input( array(
									'id' => '_ppbb_title_size_value',
									'label' => __( 'Title size value', 'picky-plate-box-builder' ),
									'type' => 'number',
									'custom_attributes' => array( 'step' => '0.01', 'min' => '0' ),
								) ); ?>
								<?php woocommerce_wp_select( array(
									'id' => '_ppbb_title_size_unit',
									'label' => __( 'Unit', 'picky-plate-box-builder' ),
									'options' => array( 'px' => 'px', 'em' => 'em', 'rem' => 'rem', 'vw' => 'vw', 'vh' => 'vh' ),
								) ); ?>
							</div>
						</div>
					</div>
				</div>

				<div class="ppbb-design-section">
					<h4><?php esc_html_e( 'Mobile floating box', 'picky-plate-box-builder' ); ?></h4>
					<div class="ppbb-design-grid">
						<div class="ppbb-design-field">
							<?php woocommerce_wp_select( array(
								'id' => '_ppbb_mobile_bar_style',
								'label' => __( 'Floating box style', 'picky-plate-box-builder' ),
								'description' => __( 'Prominent uses a nearly full-width floating card. High Contrast uses the same footprint with a dark background and light text for maximum visibility.', 'picky-plate-box-builder' ),
								'desc_tip' => true,
								'options' => array( '' => __( 'Use global default', 'picky-plate-box-builder' ), 'compact' => __( 'Compact', 'picky-plate-box-builder' ), 'standard' => __( 'Standard', 'picky-plate-box-builder' ), 'prominent' => __( 'Prominent', 'picky-plate-box-builder' ), 'high-contrast' => __( 'High Contrast — dark', 'picky-plate-box-builder' ) ),
							) ); ?>
						</div>
						<div class="ppbb-design-field">
							<?php woocommerce_wp_select( array(
								'id' => '_ppbb_high_contrast_color_mode',
								'label' => __( 'High Contrast color', 'picky-plate-box-builder' ),
								'description' => __( 'Used when this box displays the High Contrast mobile floating box.', 'picky-plate-box-builder' ),
								'desc_tip' => true,
								'options' => array( '' => __( 'Use global default', 'picky-plate-box-builder' ), 'theme' => __( 'Use theme dark color', 'picky-plate-box-builder' ), 'custom' => __( 'Custom color', 'picky-plate-box-builder' ) ),
							) ); ?>
							<div class="ppbb-contrast-custom">
								<?php woocommerce_wp_text_input( array(
									'id' => '_ppbb_high_contrast_color',
									'label' => __( 'Custom color', 'picky-plate-box-builder' ),
									'class' => 'ppbb-color-field short',
									'description' => __( 'Standard WordPress color picker.', 'picky-plate-box-builder' ),
									'desc_tip' => true,
								) ); ?>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Product-specific content displayed immediately above and/or below the
	 * entire custom builder. Each region can remain open or use a native,
	 * accessible <details> accordion.
	 */
	public function product_content_panel() {
		global $post;
		if ( ! $post ) { return; }

		$above_content = (string) get_post_meta( $post->ID, '_ppbb_above_content', true );
		$below_main_content = (string) get_post_meta( $post->ID, '_ppbb_below_main_content', true );
		if ( '' === trim( wp_strip_all_tags( $below_main_content ) ) ) {
			// Backward compatibility with beta11's single full-width below region.
			$below_main_content = (string) get_post_meta( $post->ID, '_ppbb_below_content', true );
		}
		$below_sidebar_content = (string) get_post_meta( $post->ID, '_ppbb_below_sidebar_content', true );
		$purchase_note_content = (string) get_post_meta( $post->ID, '_ppbb_purchase_note_content', true );
		$purchase_note_style = (string) get_post_meta( $post->ID, '_ppbb_purchase_note_title_style', true );
		if ( ! in_array( $purchase_note_style, array( 'standard', 'badge' ), true ) ) { $purchase_note_style = 'standard'; }
		$purchase_note_shape = (string) get_post_meta( $post->ID, '_ppbb_purchase_note_badge_shape', true );
		if ( ! in_array( $purchase_note_shape, array( 'square', 'pill' ), true ) ) { $purchase_note_shape = 'pill'; }
		$intro_mode = (string) get_post_meta( $post->ID, '_ppbb_intro_mode', true );
		if ( ! in_array( $intro_mode, array( 'default', 'custom', 'hidden' ), true ) ) { $intro_mode = 'default'; }
		?>
		<div id="ppbb_content_product_data" class="panel woocommerce_options_panel hidden show_if_mix-and-match">
			<div class="options_group">
				<p style="padding:0 12px;margin:12px 0 4px;"><strong><?php esc_html_e( 'Box Builder content & SEO', 'picky-plate-box-builder' ); ?></strong></p>
				<p class="description" style="padding:0 12px;margin-bottom:14px;"><?php esc_html_e( 'These fields are rendered server-side in the product page HTML, so search engines can crawl them. The full editors support semantic headings, links, lists, bold text and media. SEO-plugin content scores may not count custom fields even though the content is visible to search engines.', 'picky-plate-box-builder' ); ?></p>

				<?php
				woocommerce_wp_select( array(
					'id'      => '_ppbb_intro_mode',
					'label'   => __( 'Builder heading area', 'picky-plate-box-builder' ),
					'value'   => $intro_mode,
					'options' => array(
						'default' => __( 'Use default heading + instruction', 'picky-plate-box-builder' ),
						'custom'  => __( 'Use custom heading + instruction', 'picky-plate-box-builder' ),
						'hidden'  => __( 'Hide it (use Above content instead)', 'picky-plate-box-builder' ),
					),
					'description' => __( 'Controls the built-in “Choose Your Proteins / Choose X items…” area.', 'picky-plate-box-builder' ),
					'desc_tip' => true,
				) );
				woocommerce_wp_text_input( array(
					'id'          => '_ppbb_intro_heading',
					'label'       => __( 'Custom builder heading', 'picky-plate-box-builder' ),
					'value'       => (string) get_post_meta( $post->ID, '_ppbb_intro_heading', true ),
					'placeholder' => __( 'Choose Your Proteins', 'picky-plate-box-builder' ),
					'class'       => 'short ppbb-intro-custom-field',
				) );
				woocommerce_wp_text_input( array(
					'id'          => '_ppbb_intro_instruction',
					'label'       => __( 'Custom instruction', 'picky-plate-box-builder' ),
					'value'       => (string) get_post_meta( $post->ID, '_ppbb_intro_instruction', true ),
					'placeholder' => __( 'Choose {max} items for your box.', 'picky-plate-box-builder' ),
					'description' => __( 'Optional tokens: {max}, {min}, and {count}.', 'picky-plate-box-builder' ),
					'desc_tip'    => true,
					'class'       => 'short ppbb-intro-custom-field',
				) );
				?>

				<div class="ppbb-admin-content-region" style="padding:8px 12px 18px;border-top:1px solid #eee;">
					<h4 style="margin:10px 0 4px;"><?php esc_html_e( 'Above the whole builder', 'picky-plate-box-builder' ); ?></h4>
					<p class="description" style="margin:0 0 10px;"><?php esc_html_e( 'Full-width content above the protein grid and Your Box column.', 'picky-plate-box-builder' ); ?></p>
					<?php $this->render_admin_rich_editor( 'above', $above_content, 8 ); ?>
				</div>

				<div class="ppbb-admin-content-region" style="padding:8px 12px 18px;border-top:1px solid #eee;">
					<h4 style="margin:10px 0 4px;"><?php esc_html_e( 'Below the protein grid', 'picky-plate-box-builder' ); ?></h4>
					<p class="description" style="margin:0 0 10px;"><?php esc_html_e( 'Appears directly below the left/main protein column. On mobile this remains below the protein grid.', 'picky-plate-box-builder' ); ?></p>
					<?php $this->render_admin_rich_editor( 'below_main', $below_main_content, 8 ); ?>
				</div>

				<div class="ppbb-admin-content-region" style="padding:8px 12px 18px;border-top:1px solid #eee;">
					<h4 style="margin:10px 0 4px;"><?php esc_html_e( 'Below the Your Box sidebar', 'picky-plate-box-builder' ); ?></h4>
					<p class="description" style="margin:0 0 10px;"><?php esc_html_e( 'Appears directly below the desktop Your Box card. On mobile, where the sidebar becomes the floating box, this content flows below the main product-grid content instead of entering the floating drawer.', 'picky-plate-box-builder' ); ?></p>
					<?php $this->render_admin_rich_editor( 'below_sidebar', $below_sidebar_content, 8 ); ?>
				</div>

				<div class="ppbb-admin-content-region" style="padding:8px 12px 18px;border-top:1px solid #eee;">
					<h4 style="margin:10px 0 4px;"><?php esc_html_e( 'Below purchase options (inside Your Box)', 'picky-plate-box-builder' ); ?></h4>
					<p class="description" style="margin:0 0 10px;"><?php esc_html_e( 'Optional note between the purchase choices (and delivery frequency) and Number of boxes / Add My Box. Shown for one-time and subscription purchases, both on desktop and in the mobile View Box drawer. No subscription settings are changed.', 'picky-plate-box-builder' ); ?></p>
					<?php
					woocommerce_wp_text_input( array(
						'id'          => '_ppbb_purchase_note_title',
						'label'       => __( 'Section title (optional)', 'picky-plate-box-builder' ),
						'value'       => (string) get_post_meta( $post->ID, '_ppbb_purchase_note_title', true ),
						'placeholder' => __( 'GOOD TO KNOW', 'picky-plate-box-builder' ),
						'description' => __( 'A smaller title displayed above the rich-text message.', 'picky-plate-box-builder' ),
						'desc_tip'    => true,
					) );
					woocommerce_wp_select( array(
						'id'      => '_ppbb_purchase_note_title_style',
						'label'   => __( 'Title appearance', 'picky-plate-box-builder' ),
						'value'   => $purchase_note_style,
						'options' => array(
							'standard' => __( 'Standard small text', 'picky-plate-box-builder' ),
							'badge'    => __( 'Highlighted badge', 'picky-plate-box-builder' ),
						),
					) );
					woocommerce_wp_select( array(
						'id'      => '_ppbb_purchase_note_badge_shape',
						'label'   => __( 'Badge shape', 'picky-plate-box-builder' ),
						'value'   => $purchase_note_shape,
						'options' => array(
							'square' => __( 'Square / softly rounded', 'picky-plate-box-builder' ),
							'pill'   => __( 'Pill', 'picky-plate-box-builder' ),
						),
						'class' => 'ppbb-purchase-note-badge-field',
					) );
					woocommerce_wp_text_input( array(
						'id'          => '_ppbb_purchase_note_badge_bg',
						'label'       => __( 'Badge background', 'picky-plate-box-builder' ),
						'value'       => (string) get_post_meta( $post->ID, '_ppbb_purchase_note_badge_bg', true ),
						'class'       => 'ppbb-color-field ppbb-purchase-note-badge-field',
						'placeholder' => __( 'Theme accent dark', 'picky-plate-box-builder' ),
						'description' => __( 'Leave blank to inherit the theme color.', 'picky-plate-box-builder' ),
					) );
					woocommerce_wp_text_input( array(
						'id'          => '_ppbb_purchase_note_badge_fg',
						'label'       => __( 'Badge text color', 'picky-plate-box-builder' ),
						'value'       => (string) get_post_meta( $post->ID, '_ppbb_purchase_note_badge_fg', true ),
						'class'       => 'ppbb-color-field ppbb-purchase-note-badge-field',
						'placeholder' => '#ffffff',
						'description' => __( 'Leave blank for white.', 'picky-plate-box-builder' ),
					) );
					?>
					<p style="padding:0;margin:10px 0 7px;font-weight:600;"><?php esc_html_e( 'Message below the section title', 'picky-plate-box-builder' ); ?></p>
					<?php $this->render_purchase_note_editor( $purchase_note_content ); ?>
				</div>
			</div>
		</div>
		<?php
	}


	/**
	 * Render a full WordPress editor plus optional accordion controls.
	 *
	 * @param string $position Region key.
	 * @param string $content  Existing HTML.
	 * @param int    $rows     Editor rows.
	 */
	private function render_admin_rich_editor( $position, $content, $rows = 8 ) {
		$editor_id = 'ppbb_' . $position . '_content_editor';
		wp_editor(
			$content,
			$editor_id,
			array(
				'textarea_name' => '_ppbb_' . $position . '_content',
				'textarea_rows' => absint( $rows ),
				'media_buttons' => true,
				'teeny'         => false,
				'quicktags'     => true,
				'tinymce'       => array(
					'toolbar1'      => 'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,undo,redo',
					'toolbar2'      => 'strikethrough,hr,forecolor,pastetext,removeformat,charmap,outdent,indent',
					'block_formats' => 'Paragraph=p;Heading 1=h1;Heading 2=h2;Heading 3=h3;Heading 4=h4;Heading 5=h5;Heading 6=h6',
				),
			)
		);
		woocommerce_wp_checkbox( array(
			'id'          => '_ppbb_' . $position . '_accordion',
			'label'       => __( 'Display in accordion', 'picky-plate-box-builder' ),
			'description' => __( 'If unchecked, the content is displayed openly.', 'picky-plate-box-builder' ),
		) );
		woocommerce_wp_text_input( array(
			'id'          => '_ppbb_' . $position . '_accordion_title',
			'label'       => __( 'Accordion label', 'picky-plate-box-builder' ),
			'placeholder' => __( 'More information', 'picky-plate-box-builder' ),
			'description' => __( 'Used only when this content region is displayed in an accordion.', 'picky-plate-box-builder' ),
			'desc_tip'    => true,
		) );
	}

	/**
	 * An open-only note inside the purchasing panel. This intentionally doesn't
	 * inherit accordion behavior from the three surrounding content regions.
	 */
	private function render_purchase_note_editor( $content ) {
		wp_editor(
			$content,
			'ppbb_purchase_note_content_editor',
			array(
				'textarea_name' => '_ppbb_purchase_note_content',
				'textarea_rows' => 5,
				'media_buttons' => true,
				'teeny'         => false,
				'quicktags'     => true,
				'tinymce'       => array(
					'toolbar1'      => 'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,undo,redo',
					'toolbar2'      => 'strikethrough,hr,forecolor,pastetext,removeformat,charmap,outdent,indent',
					'block_formats' => 'Paragraph=p;Heading 2=h2;Heading 3=h3;Heading 4=h4;Heading 5=h5;Heading 6=h6',
				),
			)
		);
	}

	/**
	 * Save the product-specific content that surrounds the builder.
	 *
	 * @param WC_Product $product Product object.
	 */
	public function save_product_content( $product ) {
		if ( ! is_a( $product, 'WC_Product' ) ) { return; }

		$intro_mode = isset( $_POST['_ppbb_intro_mode'] ) ? sanitize_key( wp_unslash( $_POST['_ppbb_intro_mode'] ) ) : 'default';
		if ( ! in_array( $intro_mode, array( 'default', 'custom', 'hidden' ), true ) ) { $intro_mode = 'default'; }
		$product->update_meta_data( '_ppbb_intro_mode', $intro_mode );

		foreach ( array( '_ppbb_intro_heading', '_ppbb_intro_instruction' ) as $intro_key ) {
			$value = isset( $_POST[ $intro_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $intro_key ] ) ) : '';
			if ( '' === $value ) { $product->delete_meta_data( $intro_key ); }
			else { $product->update_meta_data( $intro_key, $value ); }
		}

		foreach ( array( 'above', 'below_main', 'below_sidebar' ) as $position ) {
			$content_key = '_ppbb_' . $position . '_content';
			$title_key   = '_ppbb_' . $position . '_accordion_title';
			$toggle_key  = '_ppbb_' . $position . '_accordion';

			$content = isset( $_POST[ $content_key ] ) ? wp_kses_post( wp_unslash( $_POST[ $content_key ] ) ) : '';
			$title   = isset( $_POST[ $title_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $title_key ] ) ) : '';
			$toggle  = isset( $_POST[ $toggle_key ] ) ? 'yes' : 'no';

			if ( '' === trim( wp_strip_all_tags( $content ) ) ) { $product->delete_meta_data( $content_key ); }
			else { $product->update_meta_data( $content_key, $content ); }
			if ( '' === $title ) { $product->delete_meta_data( $title_key ); }
			else { $product->update_meta_data( $title_key, $title ); }
			$product->update_meta_data( $toggle_key, $toggle );
		}

		// Standalone presentation-only purchase note: no subscription plan or
		// purchase-type fields are read or written here.
		if ( isset( $_POST['_ppbb_purchase_note_content'] ) ) {
			$note = wp_kses_post( wp_unslash( $_POST['_ppbb_purchase_note_content'] ) );
			if ( '' === trim( wp_strip_all_tags( $note ) ) ) { $product->delete_meta_data( '_ppbb_purchase_note_content' ); }
			else { $product->update_meta_data( '_ppbb_purchase_note_content', $note ); }
		}
		if ( isset( $_POST['_ppbb_purchase_note_title'] ) ) {
			$title = sanitize_text_field( wp_unslash( $_POST['_ppbb_purchase_note_title'] ) );
			if ( '' === $title ) { $product->delete_meta_data( '_ppbb_purchase_note_title' ); }
			else { $product->update_meta_data( '_ppbb_purchase_note_title', $title ); }
		}
		if ( isset( $_POST['_ppbb_purchase_note_title_style'] ) ) {
			$style = sanitize_key( wp_unslash( $_POST['_ppbb_purchase_note_title_style'] ) );
			$product->update_meta_data( '_ppbb_purchase_note_title_style', in_array( $style, array( 'standard', 'badge' ), true ) ? $style : 'standard' );
		}
		if ( isset( $_POST['_ppbb_purchase_note_badge_shape'] ) ) {
			$shape = sanitize_key( wp_unslash( $_POST['_ppbb_purchase_note_badge_shape'] ) );
			$product->update_meta_data( '_ppbb_purchase_note_badge_shape', in_array( $shape, array( 'square', 'pill' ), true ) ? $shape : 'pill' );
		}
		foreach ( array( '_ppbb_purchase_note_badge_bg', '_ppbb_purchase_note_badge_fg' ) as $color_key ) {
			if ( ! isset( $_POST[ $color_key ] ) ) { continue; }
			$color = $this->sanitize_optional_hex_color( wp_unslash( $_POST[ $color_key ] ) );
			if ( '' === $color ) { $product->delete_meta_data( $color_key ); }
			else { $product->update_meta_data( $color_key, $color ); }
		}

		// Stop rendering the legacy beta11 region after the new left-column field is saved.
		if ( isset( $_POST['_ppbb_below_main_content'] ) ) {
			$product->delete_meta_data( '_ppbb_below_content' );
			$product->delete_meta_data( '_ppbb_below_accordion' );
			$product->delete_meta_data( '_ppbb_below_accordion_title' );
		}
	}

	/**
	 * Render one product-specific content region around the builder.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $position   above|below.
	 * @return string
	 */
	private function render_content_region( $product_id, $position ) {
		$allowed = array( 'above', 'below_main', 'below_sidebar' );
		if ( ! in_array( $position, $allowed, true ) ) { $position = 'above'; }
		$content = (string) get_post_meta( $product_id, '_ppbb_' . $position . '_content', true );

		// Seamless beta11 migration: the old single below region becomes the new
		// main-column below region until the product is saved in beta12.
		if ( 'below_main' === $position && '' === trim( wp_strip_all_tags( $content ) ) ) {
			$content = (string) get_post_meta( $product_id, '_ppbb_below_content', true );
			$legacy = true;
		} else {
			$legacy = false;
		}
		if ( '' === trim( wp_strip_all_tags( $content ) ) ) { return ''; }

		$content = wpautop( wp_kses_post( $content ) );
		$meta_position = $legacy ? 'below' : $position;
		$is_accordion = 'yes' === get_post_meta( $product_id, '_ppbb_' . $meta_position . '_accordion', true );
		$class = 'ppbb-unit-content ppbb-unit-content--' . str_replace( '_', '-', $position );

		if ( ! $is_accordion ) {
			return '<div class="' . esc_attr( $class . ' ppbb-unit-content--open' ) . '">' . $content . '</div>';
		}

		$title = trim( (string) get_post_meta( $product_id, '_ppbb_' . $meta_position . '_accordion_title', true ) );
		if ( '' === $title ) { $title = __( 'More information', 'picky-plate-box-builder' ); }
		return '<details class="' . esc_attr( $class . ' ppbb-unit-content--accordion' ) . '"><summary>' . esc_html( $title ) . '</summary><div class="ppbb-accordion-body">' . $content . '</div></details>';
	}

	/**
	 * Store one sanitized note template in server-rendered product HTML. JS clones
	 * it into both summary views, without changing any native commerce fields.
	 *
	 * @param int $product_id Mix & Match product ID.
	 * @return string
	 */
	private function render_purchase_note_template( $product_id ) {
		$title   = trim( (string) get_post_meta( $product_id, '_ppbb_purchase_note_title', true ) );
		$content = (string) get_post_meta( $product_id, '_ppbb_purchase_note_content', true );
		if ( '' === $title && '' === trim( wp_strip_all_tags( $content ) ) ) { return ''; }

		$style = (string) get_post_meta( $product_id, '_ppbb_purchase_note_title_style', true );
		$style = 'badge' === $style ? 'badge' : 'standard';
		$shape = (string) get_post_meta( $product_id, '_ppbb_purchase_note_badge_shape', true );
		$shape = 'square' === $shape ? 'square' : 'pill';
		$bg    = $this->sanitize_optional_hex_color( get_post_meta( $product_id, '_ppbb_purchase_note_badge_bg', true ) );
		$fg    = $this->sanitize_optional_hex_color( get_post_meta( $product_id, '_ppbb_purchase_note_badge_fg', true ) );
		$vars  = '';
		if ( 'badge' === $style && '' !== $title ) {
			if ( $bg ) { $vars .= '--ppbb-note-badge-bg:' . $bg . ';'; }
			if ( $fg ) { $vars .= '--ppbb-note-badge-fg:' . $fg . ';'; }
		}
		$style_attr = $vars ? ' style="' . esc_attr( $vars ) . '"' : '';
		$html = '<aside class="ppbb-purchase-note ppbb-purchase-note--' . esc_attr( $style ) . ' ppbb-purchase-note--' . esc_attr( $shape ) . '"' . $style_attr . '>';
		if ( '' !== $title ) { $html .= '<div class="ppbb-purchase-note-title">' . esc_html( $title ) . '</div>'; }
		if ( '' !== trim( wp_strip_all_tags( $content ) ) ) { $html .= '<div class="ppbb-purchase-note-body">' . wpautop( wp_kses_post( $content ) ) . '</div>'; }
		$html .= '</aside>';
		return '<template class="ppbb-purchase-note-template">' . $html . '</template>';
	}

	/**
	 * Save product-level design overrides.
	 *
	 * @param WC_Product $product Product object.
	 */
	public function save_product_design( $product ) {
		if ( ! is_a( $product, 'WC_Product' ) ) { return; }
		$fields = array(
			'_ppbb_desktop_columns' => 'desktop',
			'_ppbb_slot_columns' => 'slots',
			'_ppbb_image_style' => 'image',
			'_ppbb_mobile_bar_style' => 'bar',
			'_ppbb_title_size_mode' => 'mode',
			'_ppbb_title_size_value' => 'number',
			'_ppbb_title_size_unit' => 'unit',
			'_ppbb_tile_gap_mode' => 'mode',
			'_ppbb_tile_gap_value' => 'number',
			'_ppbb_tile_gap_unit' => 'unit',
			'_ppbb_high_contrast_color_mode' => 'contrast_mode',
			'_ppbb_high_contrast_color' => 'color',
		);
		foreach ( $fields as $key => $type ) {
			if ( ! isset( $_POST[ $key ] ) ) { continue; }
			$value = wc_clean( wp_unslash( $_POST[ $key ] ) );
			if ( '' === $value ) { $product->delete_meta_data( $key ); continue; }
			switch ( $type ) {
				case 'desktop': $value = (string) $this->sanitize_desktop_columns( $value ); break;
				case 'slots': $value = (string) $this->sanitize_slot_columns( $value ); break;
				case 'image': $value = $this->sanitize_image_style( $value ); break;
				case 'bar': $value = $this->sanitize_mobile_bar_style( $value ); break;
				case 'mode': $value = in_array( $value, array( 'auto', 'custom' ), true ) ? $value : ''; break;
				case 'contrast_mode': $value = in_array( $value, array( 'theme', 'custom' ), true ) ? $value : ''; break;
				case 'number': $value = $this->sanitize_design_number( $value ); break;
				case 'unit': $value = $this->sanitize_css_unit( $value ); break;
				case 'color': $value = $this->sanitize_optional_hex_color( $value ); break;
			}
			$product->update_meta_data( $key, $value );
		}
	}

	/**
	 * Front-end body classes for optional presentation behaviors.
	 *
	 * @param array $classes Existing body classes.
	 * @return array
	 */
	public function body_classes( $classes ) {
		$product = $this->current_mnm_product();
		if ( $product && get_option( 'ppbb_hide_native_intro', false ) ) {
			$classes[] = 'ppbb-hide-native-intro';
		}
		return $classes;
	}

	/**
	 * Determine whether current queried product is native Woo MNM.
	 *
	 * @return WC_Product|false
	 */
	private function current_mnm_product() {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product || ! function_exists( 'wc_mnm_is_product_container_type' ) || ! wc_mnm_is_product_container_type( $product ) ) {
			return false;
		}

		return $product;
	}

	/**
	 * Enqueue frontend assets only on native MNM product pages.
	 */
	public function enqueue_frontend_assets() {
		$product = $this->current_mnm_product();
		if ( ! $product ) {
			return;
		}

		$deps = array( 'jquery', 'wc-add-to-cart-mnm' );
		if ( class_exists( 'WCS_ATT_Product' ) ) {
			$deps[] = 'wcsatt-frontend';
		}
		if ( wp_script_is( 'wc-add-to-cart-mnm-apfs', 'registered' ) ) {
			$deps[] = 'wc-add-to-cart-mnm-apfs';
		}

		wp_enqueue_style(
			'ppbb-frontend',
			PPBB_URL . 'assets/css/frontend.css',
			array(),
			PPBB_VERSION
		);

		wp_enqueue_script(
			'ppbb-frontend',
			PPBB_URL . 'assets/js/frontend.js',
			$deps,
			PPBB_VERSION,
			true
		);

		wp_localize_script(
			'ppbb-frontend',
			'ppbbSettings',
			array(
				'version'   => PPBB_VERSION,
				'productId' => $product->get_id(),
				'restUrl'   => esc_url_raw( rest_url( 'ppbb/v1/product/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'currency'  => get_woocommerce_currency(),
				'i18n'      => array(
					'chooseProteins'   => __( 'Choose Your Proteins', 'picky-plate-box-builder' ),
					'all'              => __( 'All', 'picky-plate-box-builder' ),
					'details'          => __( 'Details', 'picky-plate-box-builder' ),
					'yourBox'          => __( 'Your Box', 'picky-plate-box-builder' ),
					'selected'         => __( 'selected', 'picky-plate-box-builder' ),
					'itemsSelected'    => __( 'items selected', 'picky-plate-box-builder' ),
					'itemSelected'     => __( 'item selected', 'picky-plate-box-builder' ),
					'oneTime'          => __( 'One-time purchase', 'picky-plate-box-builder' ),
					'subscribe'        => __( 'Subscribe', 'picky-plate-box-builder' ),
					'subscribeSave'    => __( 'Subscribe & save %s%%', 'picky-plate-box-builder' ),
					'addBox'           => __( 'Add My Box', 'picky-plate-box-builder' ),
					'continueBuilding' => __( 'Continue Building', 'picky-plate-box-builder' ),
					'viewBox'          => __( 'View Box', 'picky-plate-box-builder' ),
					'clearBox'         => __( 'Clear box', 'picky-plate-box-builder' ),
					'boxFull'          => __( 'Your box is full. Remove an item before adding another.', 'picky-plate-box-builder' ),
					'needMore'         => __( '%s more item%s needed', 'picky-plate-box-builder' ),
					'numberBoxes'      => __( 'Number of boxes', 'picky-plate-box-builder' ),
					'deliveryFrequency' => __( 'Delivery frequency', 'picky-plate-box-builder' ),
					'close'            => __( 'Close', 'picky-plate-box-builder' ),
					'loading'          => __( 'Loading details…', 'picky-plate-box-builder' ),
					'errorDetails'     => __( 'Product details could not be loaded.', 'picky-plate-box-builder' ),
				),
			)
		);

		$this->assets_enqueued = true;
	}

	/**
	 * Style the native Mix & Match Edit selections link in either cart layout.
	 *
	 * The link and its edit URL remain entirely owned by Woo Mix & Match.
	 * Keep this tiny stylesheet available on public pages because Cart Block
	 * implementations can omit the classic is_cart() / woocommerce-cart markers.
	 * CSS selectors target only the Mix & Match edit action.
	 */
	public function enqueue_cart_button_style() {
		// Cart Block rendering and custom cart templates can bypass the classic
		// WooCommerce is_cart() / body-class checks. This tiny stylesheet is safe
		// to enqueue on all public pages because its selectors target only the
		// official Mix & Match cart editing links, not ordinary site buttons.
		wp_enqueue_style(
			'ppbb-cart-button',
			PPBB_URL . 'assets/css/cart-button.css',
			array(),
			PPBB_VERSION
		);
	}

	/**
	 * Automatic mount: keeps deployment simple. JS prefers a manually placed block mount if one exists.
	 */
	public function render_auto_mount() {
		if ( $this->auto_mount_printed || ! get_option( 'ppbb_auto_enable', true ) ) {
			return;
		}

		global $product;
		if ( ! $product || ! function_exists( 'wc_mnm_is_product_container_type' ) || ! wc_mnm_is_product_container_type( $product ) ) {
			return;
		}

		$this->auto_mount_printed = true;
		echo $this->mount_markup( $product->get_id(), $this->resolved_design( $product->get_id() ), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Dynamic block render callback.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render_block( $attributes ) {
		$product = $this->current_mnm_product();
		if ( ! $product ) {
			return '';
		}

		$design = $this->resolved_design( $product->get_id() );
		// The block is optional placement. Plugin/product settings remain the primary
		// visual configuration so the builder is not tied to any theme builder.
		if ( isset( $attributes['desktopColumns'] ) && absint( $attributes['desktopColumns'] ) > 0 ) { $design['desktopColumns'] = $this->sanitize_desktop_columns( $attributes['desktopColumns'] ); }
		if ( isset( $attributes['tabletColumns'] ) && absint( $attributes['tabletColumns'] ) > 0 ) { $design['tabletColumns'] = $this->sanitize_tablet_columns( $attributes['tabletColumns'] ); }
		if ( isset( $attributes['mobileColumns'] ) && absint( $attributes['mobileColumns'] ) > 0 ) { $design['mobileColumns'] = $this->sanitize_mobile_columns( $attributes['mobileColumns'] ); }
		if ( isset( $attributes['showFilters'] ) ) { $design['showFilters'] = (bool) $attributes['showFilters']; }
		if ( isset( $attributes['showDetails'] ) ) { $design['showDetails'] = (bool) $attributes['showDetails']; }

		$wrapper_attributes = get_block_wrapper_attributes(
			array(
				'class' => 'ppbb-block-wrap',
			)
		);

		return sprintf(
			'<div %1$s>%2$s</div>',
			$wrapper_attributes,
			$this->mount_markup( $product->get_id(), $design, false )
		);
	}

	/**
	 * Render a safe mount element. The actual builder is generated only after native Woo MNM initializes.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $attributes Display behavior.
	 * @param bool  $auto Automatic mount flag.
	 * @return string
	 */
	private function mount_markup( $product_id, $attributes = array(), $auto = false ) {
		$defaults = $this->global_design_defaults();
		$attributes = wp_parse_args( $attributes, $defaults );
		$desktop = $this->sanitize_desktop_columns( $attributes['desktopColumns'] );
		$tablet  = $this->sanitize_tablet_columns( $attributes['tabletColumns'] );
		$mobile  = $this->sanitize_mobile_columns( $attributes['mobileColumns'] );
		$slots   = $this->sanitize_slot_columns( $attributes['slotColumns'] );
		$image   = $this->sanitize_image_style( $attributes['imageStyle'] );
		$bar     = $this->sanitize_mobile_bar_style( $attributes['mobileBarStyle'] );
		$title   = $this->sanitize_css_dimension( isset( $attributes['titleSize'] ) ? $attributes['titleSize'] : '' );
		$gap     = $this->sanitize_css_dimension( isset( $attributes['tileGap'] ) ? $attributes['tileGap'] : '' );
		$contrast = $this->sanitize_optional_hex_color( isset( $attributes['highContrastColor'] ) ? $attributes['highContrastColor'] : '' );
		$filters = ! isset( $attributes['showFilters'] ) || (bool) $attributes['showFilters'];
		$details = ! isset( $attributes['showDetails'] ) || (bool) $attributes['showDetails'];

		$intro_mode = (string) get_post_meta( $product_id, '_ppbb_intro_mode', true );
		if ( ! in_array( $intro_mode, array( 'default', 'custom', 'hidden' ), true ) ) { $intro_mode = 'default'; }
		$intro_heading = '';
		$intro_instruction = '';
		if ( 'custom' === $intro_mode ) {
			$intro_heading = trim( (string) get_post_meta( $product_id, '_ppbb_intro_heading', true ) );
			$intro_instruction = trim( (string) get_post_meta( $product_id, '_ppbb_intro_instruction', true ) );
		}

		$mount = sprintf(
			'<div class="ppbb-mount" data-ppbb-product="%1$d" data-ppbb-auto="%2$s" data-ppbb-desktop-columns="%3$d" data-ppbb-tablet-columns="%4$d" data-ppbb-mobile-columns="%5$d" data-ppbb-filters="%6$s" data-ppbb-details="%7$s" data-ppbb-slot-columns="%8$d" data-ppbb-image-style="%9$s" data-ppbb-mobile-bar-style="%10$s" data-ppbb-title-size="%11$s" data-ppbb-tile-gap="%12$s" data-ppbb-high-contrast-color="%13$s" data-ppbb-intro-mode="%14$s" data-ppbb-intro-heading="%15$s" data-ppbb-intro-instruction="%16$s" data-ppbb-sold-individually="%17$s"><div class="ppbb-preinit" aria-hidden="true"></div></div>',
			absint( $product_id ),
			$auto ? '1' : '0',
			$desktop,
			$tablet,
			$mobile,
			$filters ? '1' : '0',
			$details ? '1' : '0',
			$slots,
			esc_attr( $image ),
			esc_attr( $bar ),
			esc_attr( $title ),
			esc_attr( $gap ),
			esc_attr( $contrast ),
			esc_attr( $intro_mode ),
			esc_attr( $intro_heading ),
			esc_attr( $intro_instruction ),
			( ( $box_product = wc_get_product( $product_id ) ) && $box_product->is_sold_individually() ) ? '1' : '0'
		);

		$above         = $this->render_content_region( $product_id, 'above' );
		$below_main    = $this->render_content_region( $product_id, 'below_main' );
		$below_sidebar = $this->render_content_region( $product_id, 'below_sidebar' );
		$purchase_note = $this->render_purchase_note_template( $product_id );

		return '<div class="ppbb-unit" data-ppbb-unit-auto="' . ( $auto ? '1' : '0' ) . '">' . $above . $mount . $purchase_note . $below_main . $below_sidebar . '</div>';
	}

	/**
	 * REST endpoint for lightweight product detail drawers.
	 */
	public function register_rest_routes() {
		register_rest_route(
			'ppbb/v1',
			'/product/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_product_details' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'validate_callback' => static function ( $param ) {
							return absint( $param ) > 0;
						},
					),
				),
			)
		);
	}

	/**
	 * Return product details safe for the customer-facing modal.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_product_details( WP_REST_Request $request ) {
		$product = wc_get_product( absint( $request['id'] ) );
		if ( ! $product ) {
			return new WP_Error( 'ppbb_not_found', __( 'Product not found.', 'picky-plate-box-builder' ), array( 'status' => 404 ) );
		}

		$post_status = get_post_status( $product->get_id() );
		if ( 'publish' !== $post_status && ! current_user_can( 'edit_post', $product->get_id() ) ) {
			return new WP_Error( 'ppbb_not_public', __( 'Product not available.', 'picky-plate-box-builder' ), array( 'status' => 404 ) );
		}

		$description = $product->get_short_description();
		if ( '' === trim( wp_strip_all_tags( $description ) ) ) {
			$description = wp_trim_words( wp_strip_all_tags( $product->get_description() ), 90, '…' );
			$description = $description ? wpautop( esc_html( $description ) ) : '';
		} else {
			$description = $this->sanitize_product_detail_html( $description );
		}

		$attributes = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! is_a( $attribute, 'WC_Product_Attribute' ) || ! $attribute->get_visible() ) {
				continue;
			}
			$name = wc_attribute_label( $attribute->get_name(), $product );
			if ( $attribute->is_taxonomy() ) {
				$values = wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) );
			} else {
				$values = $attribute->get_options();
			}
			$attributes[] = array(
				'name'   => wp_strip_all_tags( $name ),
				'values' => array_map( 'wp_strip_all_tags', (array) $values ),
			);
		}

		$image_id = $product->get_image_id();
		$image    = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_single' ) : wc_placeholder_img_src( 'woocommerce_single' );

		return rest_ensure_response(
			array(
				'id'          => $product->get_id(),
				'name'        => $product->get_name(),
				'image'       => $image,
				'priceHtml'   => $product->get_price_html(),
				'description' => $description,
				'attributes'  => $attributes,
				'permalink'   => get_permalink( $product->get_id() ),
			)
		);
	}

	/**
	 * Keep detail content intentionally conservative: no style/script payloads from builders.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function sanitize_product_detail_html( $html ) {
		$html = preg_replace( '#<(style|script)[^>]*>.*?</\1>#is', '', (string) $html );
		$html = strip_shortcodes( $html );
		return wp_kses_post( wpautop( $html ) );
	}

	/**
	 * WooCommerce submenu diagnostics/settings page.
	 */
	public function register_admin_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Mix & Match Box Builder', 'picky-plate-box-builder' ),
			__( 'Box Builder', 'picky-plate-box-builder' ),
			'manage_woocommerce',
			'ppbb-status',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Diagnostic rows.
	 *
	 * @return array
	 */
	private function diagnostics() {
		$theme       = wp_get_theme();
		$mnm_version = defined( 'WC_MNM_VERSION' ) ? WC_MNM_VERSION : '—';
		$wcs_version = class_exists( 'WC_Subscriptions' ) ? WC_Subscriptions::$version : '—';
		$mnm_note    = 'Native engine not detected.';
		$wcs_note    = 'Subscriptions not detected.';

		if ( defined( 'WC_MNM_VERSION' ) ) {
			if ( version_compare( WC_MNM_VERSION, '3.0.0', '>=' ) ) {
				$mnm_note = 'Untested major version. Capability checks and native fallback remain enabled.';
			} elseif ( version_compare( WC_MNM_VERSION, '2.9.0', '>=' ) ) {
				$mnm_note = 'Tested 2.9.x baseline.';
			} else {
				$mnm_note = 'Older than the tested 2.9.x baseline.';
			}
		}

		if ( class_exists( 'WC_Subscriptions' ) ) {
			$wcs_note = version_compare( WC_Subscriptions::$version, '10.0.0', '>=' )
				? 'Untested major version. Verify on staging.'
				: 'Tested 9.2.x baseline.';
		}

		$not_sold_separately_available = defined( 'WC_MNM_VERSION' ) && version_compare( WC_MNM_VERSION, '2.9.0', '>=' );

		return array(
			array( 'WooCommerce', defined( 'WC_VERSION' ) ? WC_VERSION : '—', class_exists( 'WooCommerce' ), 'Core commerce engine.' ),
			array( 'Woo Mix & Match', $mnm_version, function_exists( 'wc_mnm_is_product_container_type' ), $mnm_note ),
			array( 'MNM Not Sold Separately', $not_sold_separately_available ? 'Available' : 'Unavailable', $not_sold_separately_available, 'Native Mix & Match 2.9+ control for box-only child products.' ),
			array( 'Woo Subscriptions', $wcs_version, class_exists( 'WC_Subscriptions' ), $wcs_note ),
			array( 'APFS / product plans', class_exists( 'WCS_ATT_Product' ) ? 'Available' : '—', class_exists( 'WCS_ATT_Product' ), 'Used for one-time vs subscription choices.' ),
			array( 'Nectarblocks', defined( 'NECTAR_BLOCKS_VERSION' ) ? NECTAR_BLOCKS_VERSION : 'Not detected', defined( 'NECTAR_BLOCKS_VERSION' ), 'Optional theme integration. Builder works without Nectarblocks and only inherits its CSS variables when available.' ),
			array( 'Active theme', $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ), true, 'Theme markup is not modified.' ),
		);
	}

	/**
	 * Render admin page.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Mix & Match Box Builder', 'picky-plate-box-builder' ); ?></h1>
			<p><?php esc_html_e( 'Compatibility and fallback status for the custom Mix & Match presentation layer.', 'picky-plate-box-builder' ); ?></p>
			<p style="max-width:900px;"><strong><?php esc_html_e( 'Built by Wodobo Labs', 'picky-plate-box-builder' ); ?></strong> <?php esc_html_e( '— a Wodobo initiative. This plugin is designed to augment WooCommerce Mix and Match Products, not replace it. The native extension remains the source of truth for container rules, pricing, validation, inventory, cart/order structure, and related commerce logic.', 'picky-plate-box-builder' ); ?></p>
			<p style="max-width:900px;"><?php esc_html_e( 'More details:', 'picky-plate-box-builder' ); ?> <a href="https://wodobolabs.com/">wodobolabs.com</a> &nbsp;·&nbsp; <?php esc_html_e( 'Help and support:', 'picky-plate-box-builder' ); ?> <a href="https://wodobo.com/contact/">wodobo.com/contact</a></p>

			<table class="widefat striped" style="max-width:900px;margin:20px 0;">
				<thead><tr><th><?php esc_html_e( 'Dependency', 'picky-plate-box-builder' ); ?></th><th><?php esc_html_e( 'Version / state', 'picky-plate-box-builder' ); ?></th><th><?php esc_html_e( 'Status', 'picky-plate-box-builder' ); ?></th><th><?php esc_html_e( 'Notes', 'picky-plate-box-builder' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $this->diagnostics() as $row ) : ?>
					<tr><td><?php echo esc_html( $row[0] ); ?></td><td><?php echo esc_html( $row[1] ); ?></td><td><?php echo $row[2] ? '✅' : '⚠️'; ?></td><td><?php echo esc_html( $row[3] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p><strong><?php esc_html_e( 'Tested baseline:', 'picky-plate-box-builder' ); ?></strong> WooCommerce 11.1.x, Mix & Match 2.9.x, Woo Subscriptions 9.2.x, Nectarblocks 3.3.x.</p>
			<p><?php esc_html_e( 'The builder uses capability checks and keeps the native Woo Mix & Match form in the page as a safe fallback. If the custom interface cannot initialize, the native interface remains available.', 'picky-plate-box-builder' ); ?></p>

			<form method="post" action="options.php" style="margin-top:28px;max-width:900px;">
				<?php settings_fields( 'ppbb_settings' ); ?>
				<h2><?php esc_html_e( 'Behavior', 'picky-plate-box-builder' ); ?></h2>
				<p><label>
					<input type="checkbox" name="ppbb_auto_enable" value="1" <?php checked( get_option( 'ppbb_auto_enable', true ) ); ?> />
					<?php esc_html_e( 'Automatically replace the native Mix & Match presentation on single product pages after compatibility checks pass.', 'picky-plate-box-builder' ); ?>
				</label></p>
				<p><label>
					<input type="checkbox" name="ppbb_hide_native_intro" value="1" <?php checked( get_option( 'ppbb_hide_native_intro', false ) ); ?> />
					<?php esc_html_e( 'Hide the native Woo/Nectarblocks product gallery + title/price intro on Mix & Match product pages.', 'picky-plate-box-builder' ); ?>
				</label></p>
				<p class="description"><?php esc_html_e( 'Use this when Nectarblocks supplies the product heading/intro elsewhere or when you want the box builder to begin immediately. The setting does not remove the native Mix & Match form used as the commerce engine.', 'picky-plate-box-builder' ); ?></p>
				<p class="description"><?php esc_html_e( 'The optional “Mix & Match Box Builder” block can be used for placement, but it is not required. Automatic enhancement plus the design settings below work with any compatible theme.', 'picky-plate-box-builder' ); ?></p>

				<h2 style="margin-top:30px;"><?php esc_html_e( 'Design defaults', 'picky-plate-box-builder' ); ?></h2>
				<p><?php esc_html_e( 'These are the site-wide defaults for every Mix & Match box. Individual boxes can override them under Product data → Box Builder Design.', 'picky-plate-box-builder' ); ?></p>
				<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><label for="ppbb_default_desktop_columns"><?php esc_html_e( 'Protein grid columns', 'picky-plate-box-builder' ); ?></label></th><td><select id="ppbb_default_desktop_columns" name="ppbb_default_desktop_columns"><?php for ( $i = 2; $i <= 5; $i++ ) : ?><option value="<?php echo esc_attr( $i ); ?>" <?php selected( get_option( 'ppbb_default_desktop_columns', 3 ), $i ); ?>><?php echo esc_html( $i ); ?></option><?php endfor; ?></select><p class="description"><?php esc_html_e( 'Desktop target. The builder automatically steps down based on its available width: 4 → 3 → 2, while 3 stays at 3 until it needs 2.', 'picky-plate-box-builder' ); ?></p></td></tr>
				<tr><th scope="row"><label for="ppbb_default_slot_columns"><?php esc_html_e( 'Selection slot columns', 'picky-plate-box-builder' ); ?></label></th><td><select id="ppbb_default_slot_columns" name="ppbb_default_slot_columns"><option value="0" <?php selected( get_option( 'ppbb_default_slot_columns', 0 ), 0 ); ?>><?php esc_html_e( 'Auto by box size', 'picky-plate-box-builder' ); ?></option><?php for ( $i = 3; $i <= 12; $i++ ) : ?><option value="<?php echo esc_attr( $i ); ?>" <?php selected( get_option( 'ppbb_default_slot_columns', 0 ), $i ); ?>><?php echo esc_html( $i ); ?></option><?php endfor; ?></select><p class="description"><?php esc_html_e( 'How many small “Your Box” item slots appear in each row.', 'picky-plate-box-builder' ); ?></p></td></tr>
				<tr><th scope="row"><label for="ppbb_default_image_style"><?php esc_html_e( 'Protein image shape', 'picky-plate-box-builder' ); ?></label></th><td><select id="ppbb_default_image_style" name="ppbb_default_image_style"><?php $image_style = get_option( 'ppbb_default_image_style', 'landscape-4-3' ); ?><option value="circle" <?php selected( $image_style, 'circle' ); ?>><?php esc_html_e( 'Circle', 'picky-plate-box-builder' ); ?></option><option value="square" <?php selected( $image_style, 'square' ); ?>><?php esc_html_e( 'Square 1:1 — full bleed', 'picky-plate-box-builder' ); ?></option><option value="landscape-4-3" <?php selected( $image_style, 'landscape-4-3' ); ?>><?php esc_html_e( 'Landscape 4:3 — full bleed', 'picky-plate-box-builder' ); ?></option><option value="landscape-3-2" <?php selected( $image_style, 'landscape-3-2' ); ?>><?php esc_html_e( 'Landscape 3:2 — full bleed', 'picky-plate-box-builder' ); ?></option><option value="wide-16-9" <?php selected( $image_style, 'wide-16-9' ); ?>><?php esc_html_e( 'Wide 16:9 — full bleed', 'picky-plate-box-builder' ); ?></option></select></td></tr>
				<tr><th scope="row"><label for="ppbb_default_mobile_bar_style"><?php esc_html_e( 'Mobile floating box', 'picky-plate-box-builder' ); ?></label></th><td><?php $bar_style = get_option( 'ppbb_default_mobile_bar_style', 'prominent' ); ?><select id="ppbb_default_mobile_bar_style" name="ppbb_default_mobile_bar_style"><option value="compact" <?php selected( $bar_style, 'compact' ); ?>><?php esc_html_e( 'Compact', 'picky-plate-box-builder' ); ?></option><option value="standard" <?php selected( $bar_style, 'standard' ); ?>><?php esc_html_e( 'Standard', 'picky-plate-box-builder' ); ?></option><option value="prominent" <?php selected( $bar_style, 'prominent' ); ?>><?php esc_html_e( 'Prominent', 'picky-plate-box-builder' ); ?></option><option value="high-contrast" <?php selected( $bar_style, 'high-contrast' ); ?>><?php esc_html_e( 'High Contrast — dark', 'picky-plate-box-builder' ); ?></option></select><p class="description"><?php esc_html_e( 'Prominent is the default floating card. High Contrast keeps the same footprint but reverses it to a dark surface with light text.', 'picky-plate-box-builder' ); ?></p></td></tr>
				<tr><th scope="row"><label for="ppbb_default_title_size_mode"><?php esc_html_e( 'Product title size', 'picky-plate-box-builder' ); ?></label></th><td>
					<?php $title_mode = get_option( 'ppbb_default_title_size_mode', 'auto' ); ?>
					<select id="ppbb_default_title_size_mode" name="ppbb_default_title_size_mode"><option value="auto" <?php selected( $title_mode, 'auto' ); ?>><?php esc_html_e( 'Automatic responsive', 'picky-plate-box-builder' ); ?></option><option value="custom" <?php selected( $title_mode, 'custom' ); ?>><?php esc_html_e( 'Custom size', 'picky-plate-box-builder' ); ?></option></select>
					<input type="number" step="0.01" min="0" name="ppbb_default_title_size_value" value="<?php echo esc_attr( get_option( 'ppbb_default_title_size_value', '' ) ); ?>" style="width:90px;margin-left:8px;" />
					<?php $title_unit = get_option( 'ppbb_default_title_size_unit', 'rem' ); ?>
					<select name="ppbb_default_title_size_unit" style="margin-left:4px;"><?php foreach ( array( 'px', 'em', 'rem', 'vw', 'vh' ) as $unit ) : ?><option value="<?php echo esc_attr( $unit ); ?>" <?php selected( $title_unit, $unit ); ?>><?php echo esc_html( $unit ); ?></option><?php endforeach; ?></select>
					<p class="description"><?php esc_html_e( 'Automatic keeps the responsive defaults. Custom accepts px, em, rem, vw, or vh. rem/em are usually the safest choices for typography. A product-level setting other than “Use global default” intentionally overrides this site-wide value.', 'picky-plate-box-builder' ); ?></p>
				</td></tr>
				<tr><th scope="row"><label for="ppbb_default_tile_gap_mode"><?php esc_html_e( 'Tile spacing', 'picky-plate-box-builder' ); ?></label></th><td>
					<?php $gap_mode = get_option( 'ppbb_default_tile_gap_mode', 'auto' ); ?>
					<select id="ppbb_default_tile_gap_mode" name="ppbb_default_tile_gap_mode"><option value="auto" <?php selected( $gap_mode, 'auto' ); ?>><?php esc_html_e( 'Automatic responsive', 'picky-plate-box-builder' ); ?></option><option value="custom" <?php selected( $gap_mode, 'custom' ); ?>><?php esc_html_e( 'Custom spacing', 'picky-plate-box-builder' ); ?></option></select>
					<input type="number" step="0.01" min="0" name="ppbb_default_tile_gap_value" value="<?php echo esc_attr( get_option( 'ppbb_default_tile_gap_value', '' ) ); ?>" style="width:90px;margin-left:8px;" />
					<?php $gap_unit = get_option( 'ppbb_default_tile_gap_unit', 'px' ); ?>
					<select name="ppbb_default_tile_gap_unit" style="margin-left:4px;"><?php foreach ( array( 'px', 'em', 'rem', 'vw', 'vh' ) as $unit ) : ?><option value="<?php echo esc_attr( $unit ); ?>" <?php selected( $gap_unit, $unit ); ?>><?php echo esc_html( $unit ); ?></option><?php endforeach; ?></select>
					<p class="description"><?php esc_html_e( 'Sets both horizontal and vertical spacing between protein tiles. Automatic retains tighter spacing on smaller screens. A product-level setting other than “Use global default” intentionally overrides this site-wide value.', 'picky-plate-box-builder' ); ?></p>
				</td></tr>
				<tr><th scope="row"><label for="ppbb_default_high_contrast_color"><?php esc_html_e( 'High Contrast color', 'picky-plate-box-builder' ); ?></label></th><td>
					<input type="text" id="ppbb_default_high_contrast_color" class="ppbb-color-field" name="ppbb_default_high_contrast_color" value="<?php echo esc_attr( get_option( 'ppbb_default_high_contrast_color', '' ) ); ?>" data-default-color="" />
					<p class="description"><?php esc_html_e( 'Optional. Leave blank to use the theme dark color. This controls the background of the High Contrast mobile floating box.', 'picky-plate-box-builder' ); ?></p>
				</td></tr>
				</tbody></table>
				<?php submit_button(); ?>
			</form>

			<hr style="max-width:900px;margin:32px 0 24px;" />
			<div style="max-width:900px;">
				<h2><?php esc_html_e( 'Protein sales restrictions', 'picky-plate-box-builder' ); ?></h2>
				<p><?php esc_html_e( 'WooCommerce Mix and Match 2.9+ already includes the safest box-only control, so Mix & Match Box Builder intentionally does not duplicate it.', 'picky-plate-box-builder' ); ?></p>
				<ol>
					<li><?php esc_html_e( 'Edit an individual protein product.', 'picky-plate-box-builder' ); ?></li>
					<li><?php esc_html_e( 'Open Product data → Inventory and enable “Not sold separately”.', 'picky-plate-box-builder' ); ?></li>
					<li><?php esc_html_e( 'If the protein should also disappear from the Shop/search/catalog, set its WooCommerce Catalog visibility to Hidden.', 'picky-plate-box-builder' ); ?></li>
				</ol>
				<p class="description"><?php esc_html_e( 'The product remains a normal WooCommerce product for pricing, stock, images and Mix & Match selection, but native Mix & Match prevents standalone purchase.', 'picky-plate-box-builder' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Dependency notice only when the plugin cannot possibly work.
	 */
	public function dependency_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$missing = array();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$missing[] = 'WooCommerce';
		}
		if ( ! function_exists( 'wc_mnm_is_product_container_type' ) ) {
			$missing[] = 'WooCommerce Mix and Match Products';
		}
		if ( $missing ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html__( 'Mix & Match Box Builder:', 'picky-plate-box-builder' ),
				esc_html( sprintf( __( 'Missing required dependency: %s. The plugin will stay dormant until it is available.', 'picky-plate-box-builder' ), implode( ', ', $missing ) ) )
			);
		}
	}
}
