<?php
global $wpdb, $post;

$yellowcube_product = $wpdb->get_row('SELECT * FROM wooyellowcube_products WHERE id_product=\''.$post->ID.'\' AND id_variation=\'0\'');
?>
<div class="wooyellowcube-overflow">

    <!-- left -->
    <div class="wooyellowcube-middle-left">

        <input type="hidden" name="wooyellowcube-product" id="wooyellowcube-product-id" value="<?php echo $post->ID?>" />

        <?php if($yellowcube_product): ?>
        <div>
            <h3><?php _e('Last YellowCube status: ', 'wooyellowcube')?></h3>
            <?php
            switch($yellowcube_product->yc_response){
                case 0: echo '<div class="yellowcube-error"><u>Error</u>'.$yellowcube_product->yc_status_text.'</div>'; break;
                case 1: echo '<div class="yellowcube-pending"><u>Pending</u>'.$yellowcube_product->yc_status_text.'</div>'; break;
                case 2: echo '<div class="yellowcube-success"><u>Success</u>'.$yellowcube_product->yc_status_text.'</div>'; break;
            }
            ?>

            <?php if($yellowcube_product->yc_response == 0): ?>
            <p><small>There is an error sent from YellowCube. Refer to the error message above or contact YellowCube to get help.</small></p>
            <?php endif; ?>

        </div>
        <?php else: ?>
        <div>
            <h3><?php _e('This product has not been sent to YellowCube', 'wooyellowcube'); ?></h3>
            <p><?php _e('<strong><u>Important:</u> Save product changes before sending to YellowCube</strong>', 'wooyellowcube'); ?></p>
            <hr />
            <h3><?php _e('Lot management', 'wooyellowcube'); ?></h3>
            <p><label for="lotmanagement"><strong><?php _e('Do you want to enable lot management for this product ?'); ?></strong></label></p>
            <p>
                <select name="lotmanagement" id="lotmanagement">
                    <option value="0"><?php _e('Lot management is deactivated', 'wooyellowcube');?></option>
                    <option value="1"><?php _e('Lot management is activated', 'wooyellowcube');?></option>
                </select>
            </p>
            <p><small><i><u>Important:</u> YellowCube has to be informed that you product use lot management</i></small></p>
            <p><a href="#" onclick="return false;" class="button-primary" id="wooyellowcube-product-send"><?php _e('Send product to YellowCube', 'wooyellowcube'); ?></a></p>
        </div>
        <?php endif; ?>

        <?php if($yellowcube_product): ?>
        <br /><hr />
        <div>
            <h3><?php _e('Resend product information to YellowCube', 'wooyellowcube'); ?></h3>
            <p><label for="lotmanagement"><?php _e('<strong>Is your product using lot management?</strong>', 'wooyellowcube'); ?></label></p>
            <p>
                <select name="lotmanagement" id="lotmanagement">
                    <option value="0" <?php if($yellowcube_product->lotmanagement == '0')  echo 'selected="selected"'; ?>><?php _e('Lot management is deactivated', 'wooyellowcube'); ?></option>
                    <option value="1" <?php if($yellowcube_product->lotmanagement == '1') echo 'selected="selected"'; ?>><?php _e('Lot management is activated', 'wooyellowcube'); ?></option>
                </select>
            </p>
            <p><small><i><u>Important:</u> YellowCube has to be informed that you product use lot management</i></small></p>
            <p><a href="#" onclick="return false;" class="button-primary" id="wooyellowcube-product-update"><?php _e('Update product to YellowCube', 'wooyellowcube'); ?></a></p>
        </div>
        <br />
        <hr />
        <div>
          <h3><?php _e('Unlink the liaison with YellowCube', 'wooyellowcube'); ?></h3>
          <p><?php _e('Your product will be deactivated in YellowCube', 'wooyellowcube'); ?></p>
          <p><a href="#" onclick="return false;" class="button" id="wooyellowcube-product-remove"><?php _e('Remove the link with YellowCube', 'wooyellowcube'); ?></a></p>
        </div>
        <?php endif; ?>

        <?php
        $product_variable = new WC_Product_Variable($post);
        $variations = $product_variable->get_available_variations();

        if(count($variations)){
        ?>
        <h3><?php _e('Manage variations ART', 'wooyellowcube'); ?></h3>
        <p><?php _e('<strong>Information :</strong> Your product with variations need to be save before', 'wooyellowcube'); ?></p>

        <table class="wp-list-table widefat fixed striped pages">
            <thead>
                <tr>
                    <th width="30%"><strong><?php _e('Variation SKU', 'wooyellowcube'); ?></strong></th>
                    <th width="60%"><strong><?php _e('Actions', 'wooyellowcube'); ?></strong></th>
                    <th width="10%"><strong><?php _e('Status', 'wooyellowcube'); ?></strong></th>
                </tr>
            </thead>
        <tbody>
        <?php
        foreach($variations as $variation){

            $variation_id = $variation['variation_id'];

            // Get information from YellowCube
            $yellowcube_variation = $wpdb->get_row('SELECT * FROM wooyellowcube_products WHERE id_product=\''.$variation_id.'\'');

            // Check that the SKU is not similar to the parent SKU - disable the button in this case
            $button_disable = false;
            $parent_product = new WC_Product((int)$post->ID);

            if($parent_product->get_sku() == $variation['sku']){
              $button_disable = true;
            }elseif($variation['sku'] == ''){
              $button_disable = true;
            }

          ?>
          <tr>
            <td><?php echo $variation['sku']?></td>
            <td>
              <?php if($yellowcube_variation): ?>
                <button onclick="return false;" class="button <?php if(!$button_disable): ?>wooyellowcube-product-variation-update<?php endif; ?>" <?php if($button_disable) echo 'disabled="disabled"'; ?>><?php _e('Update', 'wooyellowcube'); ?></button>
                <input type="hidden" class="wooyellowcube-product-variation-id" value="<?php echo $variation_id?>" />

                <a href="#" onclick="return false;" class="button <?php if(!$button_disable): ?>wooyellowcube-product-variation-deactivate<?php endif; ?>" <?php if($button_disable) echo 'disabled="disabled"'; ?>><?php _e('Deactivate', 'wooyellowcube');?></a>
                <input type="hidden" class="wooyellowcube-product-variation-id" value="<?php echo $variation_id?>" />
              <?php else: ?>
                <a href="#" onclick="return false;" class="button <?php if(!$button_disable): ?>wooyellowcube-product-variation-send<?php endif; ?>" <?php if($button_disable) echo 'disabled="disabled"'; ?>><?php _e('Insert', 'wooyellowcube'); ?></a>
                <input type="hidden" class="wooyellowcube-product-variation-id" value="<?php echo $variation_id?>"  />
              <?php endif; ?>
            </td>
            <td>
              <?php if($yellowcube_variation):

              switch($yellowcube_variation->yc_response){
                case 0: echo '<img src="'.str_replace('views/', '', plugin_dir_url(__FILE__)).'assets/images/yc-error.png" alt="'.__('Error', 'wooyellowcube').'" />'; break;
                case 1: echo '<img src="'.str_replace('views/', '', plugin_dir_url(__FILE__)).'assets/images/yc-pending.png" alt="'.__('Pending', 'wooyellowcube').'" />'; break;
                case 2: echo '<img src="'.str_replace('views/', '', plugin_dir_url(__FILE__)).'assets/images/yc-success.png" alt="'.__('Success', 'wooyellowcube').'" />'; break;
              }
              ?>
              <?php else: ?>
                <img src="<?php echo str_replace('views/', '', plugin_dir_url(__FILE__)); ?>assets/images/yc-unlink.png" alt="<?php _e('Not linked', 'wooyellowcube');?>" />
              <?php endif; ?>
            </td>
          </tr>
          <?php
          }
        ?>
          </tbody>
        </table>
        <?php } ?>
    </div>

    <!-- right -->
    <div class="wooyellowcube-middle-right">
        <h3>YellowCube last activites for this product</h3>
        <div class="wooyellowcube-activities">
            <?php
            $productLogs = $wpdb->get_results('SELECT * FROM wooyellowcube_logs WHERE object=\''.get_the_ID().'\' ORDER BY created_at DESC');

            if(count($productLogs) == 0){
                echo '<p>There is no previous logs</p>';
            }else{
                foreach($productLogs as $log){
                    echo '<div class="wooyellowcube-activity">';
                        echo '<div class="wooyellowcube-activity-status">'.$log->type.'</div>';
                        echo '<div class="wooyellowcube-activity-msg"><span class="date">'.date('d/m/Y H:i:s', $log->created_at).'</span><br />'.$log->message.'</div>';
                    echo '</div>';
                }
            }
            ?>
        </div>
    </div>

</div>
