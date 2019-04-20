<?php
/*
Plugin Name: LLT WC Vendors Split Orders
Plugin URI: 
Description: WC Vendors plugin to split orders
Author: LLT
Author URI: 
Version: 1.0.0

Copyright: © 2019 LLT
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

/**
 * Check if WooCommerce is active
 */
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	
	if ( !class_exists( 'WCV_SplitOrders' ) ) {
		
		/**
		 * Localisation
		 **/
        load_plugin_textdomain( 'wcv_splitorders', false, dirname( plugin_basename( __FILE__ ) ) . '/' );
		require_once 'inc/class_llt_sorders_manager.php';

		class WCV_SplitOrders {
            
            public function __construct() {

                
                // called only after woocommerce has finished loading
                add_action( 'woocommerce_init', array( &$this, 'woocommerce_loaded' ) );
				
				// called after all plugins have loaded
				add_action( 'plugins_loaded', array( &$this, 'plugins_loaded' ) );
				

	            add_filter( 'woocommerce_email_classes', array(&$this, 'add_suborder_woocommerce_email') );
    
				// take care of anything else that needs to be done immediately upon plugin instantiation, here in the constructor
			}
			
			/**
			 * Take care of anything that needs woocommerce to be loaded.  
			 * For instance, if you need access to the $woocommerce global
			 */
			public function woocommerce_loaded() {
              
            
            }
			
			/**
			 * Take care of anything that needs all plugins to be loaded
			 */
			public function plugins_loaded() {
				$sOrdersM = new LLT_SOrders_Manager();
				$sOrdersM->init();
            }

			function add_suborder_woocommerce_email( $emails ) {

				// include our custom email class
				require_once( 'inc/class_wc_suborder_email.php' );

				// add the email class to the list of email classes that WooCommerce loads
				$emails['WC_Suborder_Email'] = new WC_Suborder_Email();

				return $emails;

			}
			
		}

		// finally instantiate our plugin class and add it to the set of globals
		$GLOBALS['wcv_split_order'] = new WCV_SplitOrders();
	}
}# llt-wcv-split-orders
