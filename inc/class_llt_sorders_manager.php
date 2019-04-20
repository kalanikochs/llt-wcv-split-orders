<?php

class LLT_SOrders_Manager {

	public function init()
	{
		add_filter( 'llt_wc_thankyou_order_received', array( $this, 'create_child_orders' ), 10, 1 );
	}

	public function create_child_orders( $main_order_id ) {

		$main_order      = wc_get_order( $main_order_id );
		$main_order_post = get_post( $main_order_id );
		$vendor_items    = array();

		$currentUser                = wp_get_current_user();
		$original_main_order_status = get_post_status( $main_order_id );
		$suborder_status            = $original_main_order_status;

		// Just create suborders if there is more than 1 item in order
		if ( $main_order->get_item_count() > 1 ) {

			// Get meta data from order
			$main_order_metadata = get_metadata( 'post', $main_order_id );

			// Gets fees and taxes
			$fees  = $main_order->get_fees();
			$taxes = $main_order->get_taxes();

			foreach ( $main_order->get_items() as $item_id => $item ) {
				$terms = wp_get_post_terms($item['product_id'], 'vendor');
				
				$mainVendor = '';
				if (!empty($terms)) {
					$vendor = array_shift($terms);
					$mainVendor = $vendor->name;
					$product_author = $vendor->slug;
					
				}
				$vendor_items[ $product_author ][ $item_id ] = $item;
			}

			$order_counter = 1;

			foreach ( $vendor_items as $vendor_id => $items ) {
				if ( ! empty( $items ) ) {

					$order_data = array(
						'post_type'     => 'shop_order',
						'post_title'    => $main_order_post->post_title,
						'post_status'   => $suborder_status,
						'ping_status'   => 'closed',
						'post_author'   => $currentUser->ID,
						'post_password' => $main_order_post->post_password,
						'meta_input'    => array(
							'llt_vso_is_suborder'      => true,
							'llt_vso_parentorder'      => $main_order_id,
							'llt_vso_suborder_sub_id'  => $order_counter,
							'llt_vso_suborder_fake_id' => $main_order->get_order_number() . '-' . $order_counter,
						),
					);

					// Create sub order
					$suborder_id = wp_insert_post( $order_data, true );

					$this->clone_order_postmetas( $main_order_metadata, $suborder_id );

					// Adds line item in suborder
					$this->add_line_items_in_suborder( $items, $suborder_id );

					// Adds fees in suborder
					$fee_value = $this->add_fees_in_suborder( $fees, $suborder_id, $main_order, $items );

					// Adds taxes in suborder
					$this->add_taxes_in_suborder( $taxes, $suborder_id );

					// Updates suborder price
					$this->update_suborder_price($suborder_id, $items, $fee_value);

					//Updates suborder number
					$this->update_suborder_number($suborder_id, $main_order, $order_counter );

					do_action('woocommerce_order_status_on-hold_to_processing_notification', $suborder_id);

					$order_counter += 1;
					$suborders[] = $suborder_id;
				}

			}

			wp_delete_post($main_order_id,true);
		}

		return $suborders;
	}

	public function clone_order_postmetas( $main_order_metadata, $suborder_id ) {
		foreach ( $main_order_metadata as $index => $meta_value ) {
			foreach ( $meta_value as $value ) {
				add_post_meta( $suborder_id, $index, $value );
			}
		}
	}

	public function add_line_items_in_suborder( $items, $suborder_id ) {
		//echo '<pre>';
		//var_dump($items);
		//echo '</pre>';
		//die();
		foreach ( $items as $item_id => $order_item ) {
			$item_name        = $order_item['name'];
			$item_type        = $order_item->get_type();
			$suborder_item_id = wc_add_order_item( $suborder_id, array(
				'order_item_name' => $item_name,
				'order_item_type' => $item_type,
			) );

			// Clone order item metas
			$this->clone_order_itemmetas( $item_id, $suborder_item_id );
		}

		return $suborder_item_id;
	}

	public function clone_order_itemmetas( $order_item_id, $target_order_id, $method = 'add' ) {
		$order_item_metas = wc_get_order_item_meta( $order_item_id, '' );
		foreach ( $order_item_metas as $index => $meta_value ) {
			foreach ( $meta_value as $value ) {
				if ( $method == 'add' ) {
					wc_add_order_item_meta( $target_order_id, $index, maybe_unserialize( $value ) );
				} else if ( $method == 'update' ) {
					wc_update_order_item_meta( $target_order_id, $index, maybe_unserialize( $value ) );
				}
			}
		}
	}

	public function add_fees_in_suborder( $fees, $suborder_id, $main_order, $items ) {
		$fee_value_count = 0;
		/* @var WC_Order_Item_Fee $fee */
		foreach ( $fees as $fee ) {
			$item_name           = $fee->get_name();
			$item_type           = $fee->get_type();
			$suborder_new_fee_id = wc_add_order_item( $suborder_id, array(
				'order_item_name' => $item_name,
				'order_item_type' => $item_type,
			) );
			$this->clone_order_itemmetas( $fee->get_id(), $suborder_new_fee_id );
			$fee_value       = ( $fee->get_total() / $main_order->get_item_count() ) * ( count( $items, 0 ) );
			$fee_value_count += $fee_value;
			wc_update_order_item_meta( $suborder_new_fee_id, '_line_total', $fee_value );
			wc_update_order_item_meta( $suborder_new_fee_id, '_line_tax', 0 );
			wc_update_order_item_meta( $suborder_new_fee_id, '_line_tax_data', 0 );
		}

		return $fee_value_count;
	}

	public function add_taxes_in_suborder( $taxes, $suborder_id ) {
		/* @var WC_Order_Item_Tax $tax */
		foreach ( $taxes as $tax ) {
			$item_name           = $tax->get_name();
			$item_type           = $tax->get_type();
			$suborder_new_tax_id = wc_add_order_item( $suborder_id, array(
				'order_item_name' => $item_name,
				'order_item_type' => $item_type,
			) );
			$this->clone_order_itemmetas( $tax->get_id(), $suborder_new_tax_id );
		}
	}

	public function update_suborder_price( $suborder_id, $items, $fee_value ) {
		$order_total = 0;
		$order_tax = 0;

		foreach ( $items as $item ) {
			$order_total += $item->get_total_tax() + $item->get_total();
			$order_tax   += $item->get_total_tax();
		}

		update_post_meta( $suborder_id, '_order_total', $order_total + $fee_value );
		update_post_meta( $suborder_id, '_order_tax', $order_tax );
	}

	public function update_suborder_number($suborder_id, $main_order, $order_counter){

		update_post_meta( $suborder_id, '_order_number', $main_order->get_order_number() . '-' . $order_counter );

		update_post_meta( $suborder_id, '_order_number_formatted', $main_order->get_order_number() . '-' . $order_counter );
	}
}
