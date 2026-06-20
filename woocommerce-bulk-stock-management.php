<?php
/**
 * Plugin Name: WooCommerce Bulk Stock Management
 * Plugin URI: https://woocommerce.com/products/bulk-stock-management/
 * Description: Bulk edit stock levels and print out stock reports right from WooCommerce admin.
 * Version: 2.3.3
 * Author: WooCommerce
 * Author URI: http://woocommerce.com
 * Text Domain: woocommerce-bulk-stock-management
 * Requires at least: 6.7
 * Tested up to: 6.9
 * WC tested up to: 10.3
 * WC requires at least: 10.1
 * Requires PHP: 7.4
 * PHP tested up to: 8.4
 * Requires Plugins: woocommerce
 * Woo: 18670:02f4328d52f324ebe06a78eaaae7934f
 *
 * Copyright: © 2023 WooCommerce
 * License: GNU General Public License v3.0
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package woocommerce-bulk-stock-management
 */

require_once __DIR__ . '/vendor/autoload.php';

use Automattic\WooCommerce\BulkStockManagement\RequestUtil as Request;

// Plugin init hook.
add_action( 'plugins_loaded', 'wc_bulk_stock_management_init' );

// Declare compatibility with HPOS.
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

register_deactivation_hook( __FILE__, 'wc_bulk_stock_management_deactivate' );

/**
 * Initialize plugin.
 */
function wc_bulk_stock_management_init() {

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'wc_bulk_stock_management_woocommerce_deactivated' );
		return;
	}

	if ( class_exists( 'WC_Bulk_Stock_Management' ) ) {
		return;
	}

	define( 'WC_BULK_STOCK_MANAGEMENT_VERSION', '2.3.3' ); // WRCS: DEFINED_VERSION.

	/**
	 * WC_Bulk_Stock_Management class
	 */
	class WC_Bulk_Stock_Management {

		/**
		 * Instance of WC_Stock_Management_List_Table.
		 *
		 * @var WC_Stock_Management_List_Table
		 */
		protected $stock_list_table;

		/**
		 * Constructor
		 */
		public function __construct() {
			// Set the screen option.
			add_filter( 'set-screen-option', array( $this, 'set_screen_option' ), 99, 3 );

			add_filter( 'woocommerce_screen_ids', array( $this, 'add_screen_id' ) );
			add_action( 'init', array( $this, 'load_plugin_textdomain' ) );
			add_action( 'admin_menu', array( $this, 'register_menu' ) );
			add_action( 'init', array( $this, 'print_stock_report' ) );
			add_action( 'admin_notices', array( $this, 'maybe_show_plugin_screen_notice' ) );
			add_action( 'wp_ajax_wc_bsm_dismiss_plugin_notice', array( $this, 'dismiss_plugin_page_notice' ) );

			add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );

			add_action( 'current_screen', array( $this, 'maybe_set_screen_title' ) );
		}

		/**
		 * Set the stock management screen title if the inventory management feature is disabled.
		 *
		 * This is used to ensure that the $title global is set correctly to avoid a PHP notice
		 * for a null value when the inventory management feature is disabled.
		 *
		 * @global $title The admin page title.
		 *
		 * @since 2.3.1
		 *
		 * @param WP_Screen $current_screen The current screen object.
		 */
		public function maybe_set_screen_title( $current_screen ) {
			global $title;
			if ( $this->is_inventory_management_enabled() && ! empty( $title ) ) {
				return;
			}

			if ( 'product_page_woocommerce-bulk-stock-management' === $current_screen->id ) {
				$title = esc_html__( 'Stock Management', 'woocommerce-bulk-stock-management' );
			}
		}

		/**
		 * Maybe show a notice on the plugins page if the inventory management feature is disabled.
		 *
		 * When the plugin is activated, this checks to see if the inventory management feature is enabled.
		 * If it is not enabled, the plugin has no functionality and a notice is displayed to the user
		 * prompting them to enable the feature or deactivate the plugin.
		 *
		 * @since 2.3.1
		 */
		public function maybe_show_plugin_screen_notice() {
			if ( $this->is_inventory_management_enabled() ) {
				return;
			}

			$screen = get_current_screen();
			if ( ! $screen || 'plugins' !== $screen->base ) {
				return;
			}

			$dismissed = get_option( 'wc_bsm_dismissed_plugin_notice', 'undismissed' );
			if ( 'dismissed' === $dismissed ) {
				return;
			}

			$message = sprintf(
				// translators: %1$s link to inventory settings, %2$s closing link tag.
				__( 'To use Stock Management, please enable stock management in %1$sthe settings%2$s.', 'woocommerce-bulk-stock-management' ),
				'<a href="' . esc_url( admin_url( '/admin.php?page=wc-settings&tab=products&section=inventory' ) ) . '">',
				'</a>'
			);

			$message_p2 = __( 'If you do not wish to use stock management, you can deactivate the WooCommerce Bulk Stock Management plugin.', 'woocommerce-bulk-stock-management' );

			// Escape in case the translation contains unwanted HTML.
			echo wp_kses_post( "<div class='notice notice-error is-dismissible wc-bsm-plugin-page-notice'><p>{$message}</p><p>{$message_p2}</p></div>" );

			/*
			 * Register a source-less script for the dismiss functionality.
			 *
			 * The script is registered without a source URL as the code is added inline.
			 * `wp_register_script()` is used to the jQuery dependency is enqueued correctly in
			 * the event the plugin screen is modified not to require jQuery in the future.
			 */
			wp_register_script( 'wc-bulk-stock-management-dismiss-notice', false, array( 'jquery' ), WC_BULK_STOCK_MANAGEMENT_VERSION, true );
			$dismiss_nonce = wp_create_nonce( 'wc_bsm_dismiss_plugin_notice' );
			$dismiss_script = "jQuery(document).ready(function($) {
					$('.wc-bsm-plugin-page-notice').on('click', '.notice-dismiss', function() {
						$.post(ajaxurl, {
							action: 'wc_bsm_dismiss_plugin_notice',
							nonce: '{$dismiss_nonce}'
						});
					});
				});";
			wp_add_inline_script( 'wc-bulk-stock-management-dismiss-notice', $dismiss_script, 'after' );
			wp_enqueue_script( 'wc-bulk-stock-management-dismiss-notice' );
		}

		/**
		 * Handle AJAX request to dismiss the plugin page notice.
		 *
		 * Allows administrators to dismiss the notice that appears on the plugins
		 * page when the inventory management feature is disabled.
		 *
		 * @since 2.3.1
		 */
		public function dismiss_plugin_page_notice() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( esc_html__( 'You do not have permission to dismiss this notice.', 'woocommerce-bulk-stock-management' ) );
			}

			check_ajax_referer( 'wc_bsm_dismiss_plugin_notice', 'nonce' );

			// Store the notice as dismissed in user meta.
			update_option( 'wc_bsm_dismissed_plugin_notice', 'dismissed', false );

			wp_send_json_success();
		}

		/**
		 * Whether the WooCommerce inventory management feature is enabled.
		 *
		 * @since 2.3.1
		 *
		 * @return bool True if inventory management is enabled, false otherwise.
		 */
		public static function is_inventory_management_enabled() {
			return wc_string_to_bool( get_option( 'woocommerce_manage_stock', 'yes' ) );
		}

		/**
		 * Add screen ID to WC.
		 *
		 * @param array $screen_ids List of WooCommerce screens.
		 */
		public function add_screen_id( $screen_ids ) {
			$screen_ids[] = 'product_page_woocommerce-bulk-stock-management';

			return $screen_ids;
		}

		/**
		 * Handle localisation
		 */
		public function load_plugin_textdomain() {
			load_plugin_textdomain( 'woocommerce-bulk-stock-management', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
		}

		/**
		 * Enqueue styles
		 */
		public function admin_css() {
			wp_enqueue_style( 'woocommerce_stock_management_css', plugins_url( basename( dirname( __FILE__ ) ) ) . '/css/admin.css', array(), WC_BULK_STOCK_MANAGEMENT_VERSION );
		}

		/**
		 * Enqueue JS.
		 *
		 * @param string $hook Matching page hook.
		 */
		public function admin_enqueue_scripts( $hook ) {
			if ( 'product_page_woocommerce-bulk-stock-management' === $hook ) {
				$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
				wp_enqueue_script( 'woocommerce_stock_management_js', plugins_url( basename( dirname( __FILE__ ) ) ) . '/js/admin' . $suffix . '.js', array( 'jquery' ), WC_BULK_STOCK_MANAGEMENT_VERSION, true );
			}
		}

		/**
		 * Add menus to WP admin
		 */
		public function register_menu() {
			$page = add_submenu_page( 'edit.php?post_type=product', esc_html__( 'Stock Management', 'woocommerce-bulk-stock-management' ), esc_html__( 'Stock Management', 'woocommerce-bulk-stock-management' ), apply_filters( 'wc_bulk_stock_cap', 'edit_others_products' ), 'woocommerce-bulk-stock-management', array( $this, 'stock_management_page' ) );

			add_action( 'admin_print_styles-' . $page, array( $this, 'admin_css' ) );

			add_action( "load-$page", array( $this, 'add_screen_options' ) );
			add_action( "load-$page", array( $this, 'dispatch_request' ) );

			if ( ! $this->is_inventory_management_enabled() ) {
				/*
				 * Hide the menu item if the inventory management feature is disabled.
				 *
				 * The submenu page is registered and then deregistered to allow merchants who have
				 * the page bookmarked to still access the page but the link will not show in the admin menu.
				 */
				remove_submenu_page( 'edit.php?post_type=product', 'woocommerce-bulk-stock-management' );
				return;
			}
		}

		/**
		 * Adds screen options for this page
		 *
		 * @since 2.0.2
		 * @version 2.0.2
		 * @return bool
		 */
		public function add_screen_options() {
			$option = 'per_page';

			$args = array(
				'label'   => esc_html__( 'Products', 'woocommerce-product-vendors' ),
				'default' => apply_filters( 'wc_bulk_stock_default_items_per_page', 50 ),
				'option'  => 'wc_bulk_stock_products_per_page',
			);

			add_screen_option( $option, $args );

			return true;
		}

		/**
		 * Sets screen options for this page
		 *
		 * @since 2.0.2
		 * @version 2.0.2
		 *
		 * @param bool   $status Whether to save the screen option value.
		 * @param string $option The option name.
		 * @param int    $value  The number of rows to use.
		 * @return mixed
		 */
		public function set_screen_option( $status, $option, $value ) {
			if ( 'wc_bulk_stock_products_per_page' === $option ) {
				return $value;
			}

			return $status;
		}

		/**
		 * Output the stock management page
		 */
		public function stock_management_page() {
			if ( ! $this->is_inventory_management_enabled() ) {
				$error = sprintf(
					// translators: %1$s link to inventory settings, %2$s link to product listing, %3$s closing link tag.
					__( 'To use Stock Management, please enable stock management in %1$sthe settings%3$s or return to the %2$sproduct listing%3$s.', 'woocommerce-bulk-stock-management' ),
					'<a href="' . esc_url( admin_url( '/admin.php?page=wc-settings&tab=products&section=inventory' ) ) . '">',
					'<a href="' . esc_url( admin_url( '/edit.php?post_type=product' ) ) . '">',
					'</a>',
				);
				?>
				<div class="wrap">
					<h2><?php esc_html_e( 'Stock Management', 'woocommerce-bulk-stock-management' ); ?></h2>
					<?php echo wp_kses_post( '<div class="error"><p>' . $error . '</p></div>' ); ?>
				</div>
				<?php
				return;
			}

			$stock_list_table = $this->get_stock_list_table();
			$stock_list_table->prepare_items();

			$this->maybe_show_notice();
			?>
			<div class="wrap">
				<h2><?php esc_html_e( 'Stock Management', 'woocommerce-bulk-stock-management' ); ?> <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'print', 'stock_report' ), 'print-stock' ) ); ?>" class="add-new-h2"><?php esc_html_e( 'View stock report', 'woocommerce-bulk-stock-management' ); ?></a></h2>
				<form id="stock-management" method="get">
					<input type="hidden" name="post_type" value="product" />
					<input type="hidden" name="page" value="woocommerce-bulk-stock-management" />
					<?php $stock_list_table->display(); ?>
				</form>
			</div>
			<?php
		}

		/**
		 * Display notice if there's updated products.
		 */
		public function maybe_show_notice() {
			$updated_count = absint( Request::get_query_string_variable( 'updated', '0' ) );
			if ( $updated_count ) {
				/* translators: 1: number of product(s) */
				echo wp_kses_post( '<div class="updated notice is-dismissible"><p>' . sprintf( _n( '%s product was updated', '%s products were updated', $updated_count, 'woocommerce-bulk-stock-management' ), $updated_count ) . '</p></div>' );
			}
		}

		/**
		 * Dispatch request made into stock list table page.
		 */
		public function dispatch_request() {
			$stock_list_table = $this->get_stock_list_table();
			$action           = $stock_list_table->current_action();

			if ( $action ) {
				$this->dispatch_action( $action );
			} elseif ( ! empty( Request::get_request_variable( '_wp_http_referer' ) ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				wp_safe_redirect( sanitize_url( remove_query_arg( array( '_wp_http_referer', '_wpnonce' ), wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) );
				exit;
			}
		}

		/**
		 * Dispatch action on stock list table.
		 *
		 * @param string $action Action's name.
		 */
		public function dispatch_action( $action ) {
			check_admin_referer( 'bulk-products' );

			// Make sure bulk action is done via POST. The form wrapper of table
			// list is default to GET, but updated to POST, via JS, when bulk action
			// button is clicked or when user hits enter on stock quantity field.
			if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
				wp_die( esc_html__( 'Bulk action must be submitted via POST. Make sure JavaScript is enabled in your browser', 'woocommerce-bulk-stock-management' ) );
			}

			$stock_list_table = $this->get_stock_list_table();
			$pagenum          = $stock_list_table->get_pagenum();

			$sendback = remove_query_arg( array( 'updated' ), wp_get_referer() );
			if ( ! $sendback ) {
				$sendback = admin_url( 'edit.php?post_type=product&page=woocommerce-bulk-stock-management' );
			}
			$sendback = add_query_arg( 'paged', $pagenum, $sendback );

			$affected_rows = 0;
			if ( 'save' === $action ) {
				$quantities         = Request::get_post_variable( 'stock_quantity', array() );
				$current_quantities = Request::get_post_variable( 'current_stock_quantity', array() );

				foreach ( $quantities as $id => $qty ) {
					if ( '' === $qty ) {
						continue;
					}

					$id                 = absint( $id );
					$qty                = wc_stock_amount( $qty );
					$current_qty        = wc_stock_amount( get_post_meta( $id, '_stock', true ) );
					$posted_current_qty = wc_stock_amount( isset( $current_quantities[ $id ] ) ? $current_quantities[ $id ] : $current_qty );

					// Check the qty has not changed since showing the form.
					if ( $current_qty === $posted_current_qty ) {

						do_action( 'wc_bulk_stock_before_process_qty', $id );

						// Stock management ON and then update.
						update_post_meta( $id, '_manage_stock', 'yes' );
						wc_update_product_stock( $id, $qty );
						$affected_rows++;

						do_action( 'wc_bulk_stock_after_process_qty', $id );
					}
				}
			} else {
				$products = array_map( 'absint', Request::get_post_variable( 'product', array() ) );
				if ( $products ) {
					foreach ( $products as $id ) {
						$affected_rows++;
						do_action( 'wc_bulk_stock_before_process_action', $action, $id );

						if ( version_compare( WC_VERSION, '3.0.3', '<' ) ) {
							// we need to reset the transient in order to have WC update the latest products with statuses.
							wc_delete_product_transients( $id );
						}

						$product = wc_get_product( $id );

						switch ( $action ) {
							case 'in_stock':
								wc_update_product_stock_status( $id, 'instock' );
								break;
							case 'out_of_stock':
								wc_update_product_stock_status( $id, 'outofstock' );
								break;
							case 'allow_backorders':
								$product->set_manage_stock( true );
								$product->set_backorders( 'yes' );
								break;
							case 'allow_backorders_notify':
								$product->set_manage_stock( true );
								$product->set_backorders( 'notify' );
								break;
							case 'do_not_allow_backorders':
								$product->set_manage_stock( true );
								$product->set_backorders( 'no' );
								break;
							case 'manage_stock':
								$product->set_manage_stock( 'yes' );
								break;
							case 'do_not_manage_stock':
								$product->set_manage_stock( 'no' );
								update_post_meta( $id, '_stock', '' );
								break;
							default:
								$affected_rows--;
								break;
						}

						$product->save();
						do_action( 'wc_bulk_stock_after_process_action', $action, $id );
					}
				} // End if.
			} // End if.

			if ( $affected_rows > 0 ) {
				$sendback = add_query_arg( array( 'updated' => $affected_rows ), $sendback );
			}
			$sendback = remove_query_arg( array( 'action', 'action2' ), $sendback );

			wp_safe_redirect( $sendback );
			exit;
		}

		/**
		 * Output the stock report table
		 */
		public function print_stock_report() {
			$print = Request::get_query_string_variable( 'print' );
			if ( 'stock_report' === $print ) {
				check_admin_referer( 'print-stock' );
				include apply_filters( 'wc_stock_report_template', plugin_dir_path( __FILE__ ) . 'templates/stock-report.php' );
				die();
			}
		}

		/**
		 * Get stock list table object.
		 *
		 * @return WC_Stock_Management_List_Table Stock list table object
		 */
		protected function get_stock_list_table() {
			if ( ! $this->stock_list_table ) {
				require_once 'includes/class-wc-stock-management-list-table.php';
				$this->stock_list_table = new WC_Stock_Management_List_Table();
			}

			return $this->stock_list_table;
		}
	}

	new WC_Bulk_Stock_Management();
}

/**
 * WooCommerce Deactivated Notice.
 */
function wc_bulk_stock_management_woocommerce_deactivated() {
	/* translators: %s: WooCommerce link */
	echo '<div class="error"><p>' . sprintf( esc_html__( 'WooCommerce Bulk Stock Management requires %s to be installed and active.', 'woocommerce-bulk-stock-management' ), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>' ) . '</p></div>';
}

/**
 * Deactivation hook for WooCommerce Bulk Stock Management.
 *
 * This removes the option for dismissing the notice when inventory management
 * is disabled. This is so the notice will be shown again if the plugin is reactivated.
 */
function wc_bulk_stock_management_deactivate() {
	delete_option( 'wc_bsm_dismissed_plugin_notice' );
}
