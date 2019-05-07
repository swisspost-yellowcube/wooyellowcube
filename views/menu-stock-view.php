<?php

global $wpdb;

$product_id = isset($_GET['id']) ? htmlspecialchars($_GET['id']) : 0;

$product = wc_get_product((int)$product_id);
if (!$product) {
    echo '<h1>Product '.$product_id.' inexisting</h1>';
    exit();
}


// Check if there are lots.
$yellowcube_products_lots = $wpdb->get_results('SELECT * FROM wooyellowcube_stock_lots WHERE id_product=\''.$product_id.'\'');


echo '<h1>'.$product->get_title().'</h1>';
echo '<p><a href="admin.php?page=wooyellowcube-stock">'.__('Go back to stock', 'wooyellowcube').'</a></p>';

if(count($yellowcube_products_lots)) {

    echo '<table class="wp-list-table widefat fixed striped pages">
		<tr>
			<th class=" column-primary"><strong>'.__('Lot', 'wooyellowcube').'</strong></th>
			<th><strong>'.__('Quantity', 'wooyellowcube').'</strong></th>
			<th><strong>'.__('Expiration date', 'wooyellowcube').'</strong></th>
		</tr>';

    foreach($yellowcube_products_lots as $lot){
        echo '<tr>
			<td>'.$lot->product_lot.'</td>
			<td>'.$lot->product_quantity.'</td>
			<td>'.$lot->product_expiration.'</td>
		</tr>';
    }

    echo '</table>';
}else{

    echo '<p>'.__('There are no lots on this product.', 'wooyellowcube').'</p>';

}
