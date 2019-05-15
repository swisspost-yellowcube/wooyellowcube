<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/u/bs/dt-1.10.12/datatables.min.css"/>

<script type="text/javascript" src="https://cdn.datatables.net/u/bs/dt-1.10.12/datatables.min.js"></script>
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.8.4/moment.min.js"></script>
<script type="text/javascript" src="https://cdn.datatables.net/plug-ins/1.10.12/sorting/datetime-moment.js"></script>

<script type="text/javascript">
	jQuery(document).ready(function(){
		jQuery.fn.dataTable.moment('dd/mm/YY H:i');
		jQuery('.datatable').DataTable({
			'order': [[0, 'desc']],
			'displayLength': 15
		});
	});
</script>

<h1><?php _e('WooYellowCube Activity logs', 'wooyellowcube'); ?></h1>
<p><?php _e('If you have any question about errors displayed, please contact YellowCube to get more information (<a href="http://yellowcube.ch">www.yellowcube.ch</a> or by phone <strong>+41 58 386 48 08</strong>)', 'wooyellowcube'); ?></p>

<hr />

<?php
global $wpdb;

// Get last 20 activities from database
$yellowcube_activities = $wpdb->get_results('SELECT * FROM wooyellowcube_logs ORDER BY created_at DESC LIMIT 0, 600');

if(count($yellowcube_activities) == 0) : ?>
<p>There are currently no recent activities.</p>
<?php else: ?>
<div style="overflow-y: scroll; width: 100%; height: 890px">
<table class="wp-list-table widefat fixed striped datatable ">
  <thead>
    <tr>
      <th class="column-primary" width="10%"><?php _e('Date', 'wooyellowcube'); ?></th>
      <th width="10%"><?php _e('Reference', 'wooyellowcube'); ?></th>
      <th width="15%"><?php _e('Action', 'wooyellowcube'); ?></th>
      <th width="10%"><?php _e('Order / Product', 'wooyellowcube'); ?></th>
      <th><?php _e('Message', 'wooyellowcube'); ?></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach($yellowcube_activities as $activity): ?>
      <tr>
        <td><?php echo date('Y/m/d H:i', $activity->created_at); ?></td>
        <td><?php echo $activity->reference?:''; ?></td>
        <td><?php echo $activity->type; ?></td>
        <td>
        <?php
        if(strpos($activity->type, 'ART') === 0) {
          $product = wc_get_product((int) $activity->object);
          if($product) {
            $product_link = admin_url('post.php?post=' . $product->get_id() . '&action=edit');
        ?>
            <a href="<?php echo esc_url($product_link); ?>"><?php echo esc_html($product->get_sku()); ?></a>
        <?php
          } else {
            // Product has been deleted.
            echo "#" . $activity->object;
          }
        }
        elseif(strpos($activity->type, 'WAB') === 0) {
          $order = wc_get_order((int) $activity->object);
          if($order) {
            $order_link = admin_url('post.php?post=' . $order->get_id() . '&action=edit');
            ?>
              <a href="<?php echo esc_url($order_link); ?>">#<?php echo esc_html($order->get_id()); ?></a>
            <?php
          }
        }
        elseif($activity->object) {
        ?>
          <?php echo $activity->object; ?>
        <?php
        }
        ?>
                </td>
        <td>
            <?php echo $activity->message; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
