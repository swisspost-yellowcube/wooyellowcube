<?php
/**
 * Plugin Name: WooYellowCube
 * Plugin URI: http://www.yellowcube.ch
 * Description: WooCommerce synchronization with YellowCube
 * Version: 2.5.5
 * WC requires at least: 3.4.7
 * WC tested up to: 3.8.0
 *
 * for any support please contact yellowcube.ch
 */

use YellowCube\ART\Article;
use YellowCube\ART\ChangeFlag;
use YellowCube\ART\UnitsOfMeasure\ISO;
use YellowCube\ART\UnitsOfMeasure\EANType;
use YellowCube\WAB\AdditionalService\AdditionalShippingServices;
use YellowCube\WAB\AdditionalService\BasicShippingServices;
use YellowCube\WAB\AdditionalService\CODAccountNo;
use YellowCube\WAB\AdditionalService\CODAmount;
use YellowCube\WAB\AdditionalService\CODRefNo;
use YellowCube\WAB\AdditionalService\DeliveryDate;
use YellowCube\WAB\AdditionalService\DeliveryInstructions;
use YellowCube\WAB\AdditionalService\DeliveryLocation;
use YellowCube\WAB\AdditionalService\DeliveryPeriodeCode;
use YellowCube\WAB\AdditionalService\DeliveryTimeFrom;
use YellowCube\WAB\AdditionalService\DeliveryTimeJIT;
use YellowCube\WAB\AdditionalService\DeliveryTimeTo;
use YellowCube\WAB\AdditionalService\FloorNo;
use YellowCube\WAB\AdditionalService\FrightShippingFlag;
use YellowCube\WAB\AdditionalService\NotificationServiceCode;
use YellowCube\WAB\AdditionalService\NotificationType;
use YellowCube\WAB\Order;
use YellowCube\WAB\OrderHeader;
use YellowCube\WAB\Partner;
use YellowCube\WAB\Doc;
use YellowCube\Config;
use YellowCube\Service;
use YellowCube\WAB\Position;

class WooYellowCube
{
    /**
     * @var \YellowCube\Service
     */
    public $yellowcube;

    public $defaultLanguage = 'en';

    public $defaultWSDL = 'https://service-test.swisspost.ch/apache/yellowcube-test/?wsdl';

    public $lastRequest;

    public $logDebug;


  /**
     * __constructor
     */
    public function __construct()
    {
        include_once 'vendor/autoload.php';

        $this->logDebug = get_option('wooyellowcube_logsDebug');

        $this->actions();
        $this->columns();
        $this->crons();
        $this->languages();

    }

    /**
     * check if the shop manager has set the required settings
     */
    public function areSettingsReady()
    {
        if (!get_option('wooyellowcube_setter')) {
            return false;
        }

        if (!get_option('wooyellowcube_operatingMode')) {
            return false;
        }

        return true;
    }

    /**
     * Language repository
     */
    public function languages()
    {
        add_action('plugins_loaded', array(&$this, 'languages_textdomain'));
    }

    /**
     * Set the language textdomain
     */
    public function languages_textdomain()
    {
        load_plugin_textdomain('wooyellowcube', false, basename(dirname(__FILE__)) . '/languages/');
    }

    /**
     * Set the YellowCube connexion
     */
    public function yellowcube()
    {
        if ($this->yellowcube !== null) {
            return true;
        }

        switch ((int)get_option('wooyellowcube_yellowcubeSOAPUrl')) {
        case 1:
            $this->defaultWSDL = 'https://service-test.swisspost.ch/apache/yellowcube-test/?wsdl';
            break;
        case 2:
            $this->defaultWSDL = 'https://service-test.swisspost.ch/apache/yellowcube-int/?wsdl';
            break;
        case 3:
            $this->defaultWSDL = 'https://service.swisspost.ch/apache/yellowcube/?wsdl';
            break;
        }

        // YellowCube SOAP configuration
        $soap_config = new Config(get_option('wooyellowcube_setter'), $this->defaultWSDL, null, get_option('wooyellowcube_operatingMode'));

        // YellowCube API instanciation
        try {
          // YellowCube SOAP signature
          if (get_option('wooyellowcube_authentification')) {
            $soap_config->setCertificateFilePath(__DIR__ .'/'.get_option('wooyellowcube_authentificationFile'));
          }

          $logger = NULL;
          if ($this->logDebug) {
            include_once 'logger.php';
            $logger = new Logger($this);
          }
          $this->yellowcube = new Service($soap_config, NULL, $logger);
            return true;
        } catch (Exception $e) {
            $this->log_create(0, 'INIT-ERROR', 0, 0, __('SOAP WSDL not reachable', 'wooyellowcube'));
            return false;
        }
    }

    /**
     * Retrieve the WAR message
     *
     * Note that a test order without WAR retries to fetch hourly for 10 days.
     */
    public function retrieveWAR()
    {
        global $wpdb;

        if (!$this->yellowcube()) {
            // YellowCube setup failed, abort.
            return;
        }

        // 10 days in sec.
        $days = 10 * 60 * 60 * 24;
        // Get all orders that don't have status to 2 and dated more than 10 days.
        $orders = $wpdb->get_results('SELECT * FROM wooyellowcube_orders WHERE status != 2 AND created_at > '.(time() - $days));

        // Delete records if order was deleted. This doesn't scale.
        // @todo join to order table.
        foreach ($orders as $order) {
            $order_id = $order->id_order;
            $order_object = wc_get_order((int) $order_id);
            if (!$order_object) {
                // Article deleted, drop record.
                $wpdb->delete(
                    'wooyellowcube_orders',
                    array(
                        'id_order' => $order_id,
                    )
                );
            }
        }

        if (count($orders) > 0) {
            $replies = $this->yellowcube->getYCCustomerOrderReply();
            // @todo Use the effective shipping time instead of the fetch time.
            // @todo This can cause temporary off counts after a stuck cron.
            // @see getInventoryWithMetadata and ControlReference
            // $date = $reply->getTimestamp();
            $date = new DateTime('now');
            // @todo check time zone.

            // Process each reply.
            foreach ($replies as $reply) {
                $header = $reply->getCustomerOrderHeader();
                $order_id = $header->getCustomerOrderNo();

                $order_object = wc_get_order((int)$order_id);
                if (!$order_object) {
                    // We don't know this order. Skip.
                    continue;
                }

                // @todo Check reply status code.

                // Collect serial numbers for attachment.
                $serials = [];
                $list = $reply->getCustomerOrderList();
                foreach ($list as $item) {
                  if ($serial = $item->getSerialNumbers()) {
                    $serials[] = $item->getArticleNo() . ': ' . $serial;
                  }
                }

                $track = $header->getPostalShipmentNo();

                $wpdb->update(
                    'wooyellowcube_orders',
                    array(
                        'status' => 2,
                        'yc_shipping' => $track,
                        'shipped_at' => $date->getTimestamp(),
                    ),
                    array(
                        'id_order' => $order_id,
                    )
                );

                $this->log_create(1, 'WAR-SHIPMENT DELIVERED', 0, $order_id, 'Track & Trace received for order '.$order_id.' : '.$track);
                $order_object->update_status('completed', __('Your order has been shipped', 'wooyellowcube'), false);

                if (!empty($serials)) {
                  // @todo Move serials into line items.
                  $this->log_create(1, 'WAR-SHIPMENT SERIALS', 0, $order_id, "Serials received<br />\n" . implode("<br />\n", $serials));
                }

            }
        }
    }

    /**
     * Actions
     */
    private function actions()
    {
        // Add all the meta boxes in backend
        add_action('add_meta_boxes', array(&$this, 'meta_boxes'));

        // Ajax head
        add_action('admin_head', array(&$this, 'ajax_head'));

        // Execute ajax actions
        $this->ajax();

        // Add administration scripts
        add_action('admin_enqueue_scripts', array(&$this, 'scripts'));

        // Add administration styles
        add_action('admin_enqueue_scripts', array(&$this, 'styles'));

        // Add menus management
        add_action('admin_menu', array(&$this, 'menus'));

        // A new order is completed
        add_action('woocommerce_order_status_processing', array(&$this, 'order'), 10, 1);

        // Add the track & trace in order details
        add_action('woocommerce_order_details_after_order_table', array(&$this, 'meta_tracktrace'));

        // Email after completion
        add_action('woocommerce_email_before_order_table', array(&$this, 'email_completed'), 20);
    }

    /**
     * Set the Track & Trace informations in the reply email
     */
    public function email_completed($order)
    {
        global $wpdb;

        $order_id = $order->get_id();
        $getOrderFromYellowCube = $wpdb->get_row('SELECT * FROM wooyellowcube_orders WHERE id_order='.$order_id);

        if (!empty($getOrderFromYellowCube->yc_shipping)) {
            echo '<p>Track & Trace : http://www.post.ch/swisspost-tracking?p_language=en&formattedParcelCodes='.$getOrderFromYellowCube->yc_shipping.'</p>';
        }
    }

    /**
     * Plugin assets (scripts)
     */
    public function scripts()
    {
        // Add WooYellowCube JS file
        wp_enqueue_script('wooyellowcube-js', plugin_dir_url(__FILE__).'assets/js/wooyellowcube.js');
    }

    /**
     * Plugin assets (styles)
     */
    public function styles()
    {
        // Add WooYellowCube CSS file
        wp_register_style('wooyellowcube-css', plugin_dir_url(__FILE__).'assets/css/wooyellowcube.css', false, '1.0.0');
        wp_enqueue_style('wooyellowcube-css');
        wp_register_style('wooyellowcube-datatable', 'https://cdn.datatables.net/u/dt/dt-1.10.12/datatables.min.css', false, null);
        wp_enqueue_style('wooyellowcube-datatable');
    }

    /**
     * Meta boxes management
     */
    public function meta_boxes()
    {
        // Order meta box - Product meta box
        add_meta_box('wooyellowcube-order', 'YellowCube - Manage WAB (order)', array(&$this, 'meta_boxes_order'), 'shop_order');
        add_meta_box('wooyellowcube-product', 'YellowCube - Manage ART (product)', array($this, 'meta_boxes_product'), 'product');
    }

    /**
     * Metabox : Order
     */
    public function meta_boxes_order($post)
    {
        global $wpdb;
        include_once 'views/metabox-order.php';
    }

    /**
     * Metabox : Product
     */
    public function meta_boxes_product($post)
    {
        global $wpdb;
        include_once 'views/metabox-product.php';
    }

    /**
     * Metabox : Track & Trace
     */
    public function meta_tracktrace($order)
    {
        global $wpdb;
        // Get shipping postal no
        $shipping = $wpdb->get_row('SELECT * FROM wooyellowcube_orders WHERE id_order=\''.$order->get_id().'\'');

        // If we have it, display the track & trace
        if ($shipping && trim($shipping->yc_shipping) != '') {
            echo '<p><strong>'.__('Order track & trace', 'wooyellowcube').'</strong> : <a href="http://www.post.ch/swisspost-tracking?p_language=en&formattedParcelCodes='.$shipping->yc_shipping.'" target="_blank">'.$shipping->yc_shipping.'</a></p>';
        }
    }

    /**
     * Menu pages
     */
    public function menus()
    {
        add_menu_page('WooYellowCube', 'WooYellowCube', 'manage_woocommerce', 'wooyellowcube', array(&$this, 'menu_settings'), plugins_url('/assets/images/icon.png', __FILE__)); // Settings
        add_submenu_page('wooyellowcube', __('Shipping', 'wooyellowcube'), __('Shipping', 'wooyellowcube'), 'manage_woocommerce', 'wooyellowcube-shipping', array(&$this, 'menu_shipping')); // Stock
        add_submenu_page('wooyellowcube', __('Activities logs', 'wooyellowcube'), __('Activities logs', 'wooyellowcube'), 'manage_woocommerce', 'wooyellowcube-logs', array(&$this, 'menu_logs')); // Activities
        add_submenu_page('wooyellowcube', __('Stock', 'wooyellowcube'), __('Stock', 'wooyellowcube'), 'manage_woocommerce', 'wooyellowcube-stock', array(&$this, 'menu_stock')); // Stock
        add_options_page(__('Stock details', 'wooyellowcube'), __('Stock details', 'wooyellowcube'), 'manage_woocommerce', 'wooyellowcube-stock-view', array(&$this, 'menu_stock_view'));
        add_submenu_page('wooyellowcube', __('Need help ?', 'wooyellowcube'), __('Need help ?', 'wooyellowcube'), 'manage_woocommerce', 'wooyellowcube-help', array(&$this, 'menu_help')); // Help
    }

    /**
     * Menu : Settings
     */
    public function menu_settings()
    {
        global $wpdb;

        // Update form has been submitted
        if (isset($_POST['wooyellowcube-settings'])) {
            // Update all WordPress options
            $values_to_save = [
                'setter', 'receiver', 'depositorNo', 'partnerNo', 'plant',
                'operatingMode', 'authentification', 'authentificationFile',
                'yellowcubeSOAPUrl', 'lotmanagement', 'logs', 'logsDebug'
            ];

            foreach ($values_to_save as $value_key) {
                if (isset($_POST[$value_key])) {
                    update_option('wooyellowcube_' . $value_key, htmlspecialchars($_POST[$value_key]));
                }
            }

            // Remove the current stock information.
            $wpdb->query('DELETE FROM wooyellowcube_stock');
            $wpdb->query('DELETE FROM wooyellowcube_stock_lots');

            // Reset execution times so crons are triggered again.
            update_option('wooyellowcube_cron_response', 0, false);
            update_option('wooyellowcube_cron_daily', 0, false);
            update_option('wooyellowcube_cron_hourly', 0, false);
        }

        include_once 'views/menu-settings.php';
    }

    /**
     * Menu : Shipping
     */
    public function menu_shipping()
    {
        global $wpdb;

        // Get the form
        if (isset($_POST['submit_shipping'])) {
            $shipping_methods = array();
            $shipping_additionals = array();

            // Get all the shipping methods
            foreach ($_POST['yellowcube_shipping_id'] as $key => $method_id) {
                $shipping_methods[$method_id] = $_POST['yellowcube_shipping'][$key];
                $shipping_additionals[$method_id] = $_POST['yellowcube_additionals'][$key];
            }

            // Serialize array
            update_option('wooyellowcube_shipping', serialize($shipping_methods));
            update_option('wooyellowcube_shipping_additional', serialize($shipping_additionals));

            echo '<p class="alert alert-success">'.__('Shipping information has been updated', 'wooyellowcube').'</p>';
        }

        include_once 'views/menu-shipping.php';
    }

    /**
     * Menu : Stock
     */
    public function menu_stock()
    {
        global $wpdb;

        $status = false;

        if (isset($_POST['bulking_execute'])) {
            $option = htmlspecialchars($_POST['bulking_actions']);

            // Force to refresh inventory from YC.
            if ($option == 3) {
                $this->update_stock();
            }

            if (isset($_POST['products'])) {
                foreach ($_POST['products'] as $product_id) {

                    // Send ART to YC.
                    if ($option == 1) {
                        $this->YellowCube_ART($product_id, 'update');
                        $status = 1;
                    }

                    // Update WooCommerce Stock from YC.
                    if ($option == 2) {

                        // Get the stock row(s).
                        $stock_row = $wpdb->get_results('SELECT * FROM wooyellowcube_stock WHERE product_id="' . $product_id . '"');
                        if (count($stock_row) > 0) {
                            $quantity = 0;
                            $product_id = 0;

                            foreach ($stock_row as $row) {
                                $quantity = $quantity + $row->yellowcube_stock;
                                $product_id = $row->product_id;
                            }

                            // Deduct pending order count.
                            $quantity = $quantity - $this->get_product_order_pending_sum($row->product_id);

                            wc_update_product_stock($product_id, $quantity);
                        }
                    }
                }

                // Always force refresh inventory after stock update.
                if ($option == 2) {
                    $this->update_stock();
                    $status = 2;
                }
            }
        }

        include_once 'views/menu-stock.php';
    }

    public function menu_stock_view()
    {
        include_once 'views/menu-stock-view.php';
    }

    /**
     * Menu : Need help
     */
    public function menu_help()
    {
        global $wpdb;
        include_once 'views/menu-help.php';
    }

    /**
     * Menu : Logs
     */
    public function menu_logs()
    {
        global $wpdb;
        include_once 'views/menu-logs.php';
    }

    /**
     * Order from WooCommerce
     */
    public function order($order_id)
    {
        $this->YellowCube_WAB($order_id);
    }

    /**
     * Columns management
     */
    public function columns()
    {
        add_filter('manage_edit-product_columns', array(&$this, 'columns_products')); // Products column
        add_filter('manage_edit-shop_order_columns', array(&$this, 'columns_orders')); // Orders column
        add_action('manage_posts_custom_column', array(&$this, 'columns_content')); // Columns posts
    }

    /**
     * Columns : Products
     */
    public function columns_products($columns)
    {
        // Add YellowCube column to Products list
        $columns['yellowcube_products'] = 'YellowCube';
        return $columns;
    }

    /**
     * Columns : Orders
     */
    public function columns_orders($columns)
    {
        // Add YellowCube column to Products list
        $columns['yellowcube_orders'] = 'YellowCube';
        return $columns;
    }

    /**
     * Columns : Content
     */
    public function columns_content($column_name)
    {
        global $post, $wpdb;

        switch ($column_name) {
        // Products status
        case 'yellowcube_products':
            $product = $this->get_product_status($post->ID);

            // YellowCube entry has been found
            if ($product) {
                // Display status
                switch ($product->yc_response) {
                case 0: echo '<img class="yellowcube-product-status" src="'.plugin_dir_url(__FILE__).'assets/images/yc-error.png"  alt="'.__(' ', 'wooyellowcube').'" /> '.__('Error', 'wooyellowcube');
                    break;
                case 1: echo '<img class="yellowcube-product-status" src="'.plugin_dir_url(__FILE__).'assets/images/yc-pending.png" alt="'.__(' ', 'wooyellowcube').'" /> '.__('Submitted', 'wooyellowcube');
                    break;
                case 2: echo '<img class="yellowcube-product-status" src="'.plugin_dir_url(__FILE__).'assets/images/yc-success.png" alt="'.__(' ', 'wooyellowcube').'" /> '.__('Active', 'wooyellowcube');
                    break;
                }
            } else {
                //echo '<img src="'.plugin_dir_url(__FILE__).'assets/images/yc-unlink.png" alt="'.__('Unlink', 'wooyellowcube').'" /> '.__('Product sent to YellowCube', 'wooyellowcube');
                echo '-';
            }

            break;

        // Orders status
        case 'yellowcube_orders':
            $order = $this->get_order_status($post->ID);

            // YellowCube entry has been found
            if ($order) {
                // Display status
                switch ($order->yc_response) {
                case 0: echo '<img class="yellowcube-order-status" src="'.plugin_dir_url(__FILE__).'assets/images/yc-error.png" alt="'.__(' ', 'wooyellowcube').'" /> '.__('Error', 'wooyellowcube');
                    break;
                case 1: echo '<img class="yellowcube-order-status" src="'.plugin_dir_url(__FILE__).'assets/images/yc-pending.png" alt="'.__(' ', 'wooyellowcube').'" /> '.__('Submitted', 'wooyellowcube');
                    break;
                case 2: echo '<img class="yellowcube-order-status" src="'.plugin_dir_url(__FILE__).'assets/images/yc-success.png" alt="'.__(' ', 'wooyellowcube').'" /> '.__('Confirmed', 'wooyellowcube');
                    break;
                }
            } else {
                //echo '<img src="'.plugin_dir_url(__FILE__).'assets/images/yc-unlink.png" alt="'.__('Unlink', 'wooyellowcube').'" /> '.__('Order not sent to YellowCube', 'wooyellowcube');
                echo '-';
            }

            break;
        }
    }

    /**
     * Ajax calls
     */
    public function ajax()
    {
        // Product - send
        add_action('wp_ajax_product_send', array(&$this, 'ajax_product_send'));
        // Product - update
        add_action('wp_ajax_product_update', array(&$this, 'ajax_product_update'));
        // Product - remove
        add_action('wp_ajax_product_remove', array(&$this, 'ajax_product_deactivate'));

        // Order - send
        add_action('wp_ajax_order_send', array(&$this, 'ajax_order_send'));

        // @todo stubs only, remove?
        // Order - again
        add_action('wp_ajax_order_again', array(&$this, 'ajax_order_again'));
    }

    /**
     * Add ajax URL to header
     */
    public function ajax_head()
    {
        echo '<script type="text/javascript">var wooyellowcube_ajax = "'.admin_url('admin-ajax.php').'"</script>';
    }

    /**
     * Ajax - Product - Send
     */
    public function ajax_product_send()
    {
        // Get post ID
        $post_id = htmlspecialchars($_POST['post_id']);
        // Get lot management
        $lotmanagement = htmlspecialchars($_POST['lotmanagement']);
        // Get the information if the product is a variation
        $variation = isset($_POST['variation']) ? htmlspecialchars($_POST['variation']) : false;

        // Insert the product in YellowCube
        $this->YellowCube_ART($post_id, 'insert', null, $lotmanagement, $variation);

        exit();
    }

    /**
     * Ajax - Product - Update
     */
    public function ajax_product_update()
    {
        // Get post ID
        $post_id = htmlspecialchars($_POST['post_id']);
        // Get lot management
        $lotmanagement = htmlspecialchars($_POST['lotmanagement']);
        // Get the information if the product is a variation
        $variation = isset($_POST['variation']) ? htmlspecialchars($_POST['variation']) : false;

        // Update the product in YellowCube
        $this->YellowCube_ART($post_id, 'update', null, $lotmanagement, $variation);

        exit();
    }


    /**
     * Ajax - Product - Remove
     */
    public function ajax_product_deactivate()
    {
        // Get post ID
        $post_id = htmlspecialchars($_POST['post_id']);
        // Get the information if the product is a variation
        $variation = isset($_POST['variation']) ? htmlspecialchars($_POST['variation']) : false;

        // Delete the product in YellowCube
        $this->YellowCube_ART($post_id, 'deactivate', 0, $variation);

        exit();
    }

    /**
     * Ajax - Product - Refresh
     *
     * @todo stub, unused, remove?
     */
    public function ajax_product_refresh()
    {
        // Get post ID
        $post_id = htmlspecialchars($_POST['post_id']);
        echo 'ajax_product_refresh';
        exit();
    }

    /**
     * Ajax - Order - Send
     */
    public function ajax_order_send()
    {
        // Get post ID
        $post_id = htmlspecialchars($_POST['post_id']);

        // Insert the order in YellowCube
        $this->YellowCube_WAB($post_id, TRUE);

        exit();
    }

    /**
     * Ajax - Order - Again
     *
     * @todo stub, remove?
     */
    public function ajax_order_again()
    {
        // Get post ID
        $post_id = htmlspecialchars($_POST['post_id']);
        echo 'ajax_order_again';
        exit();
    }

    /**
     * Ajax - Order - Refresh
     *
     * @todo stub, unused, remove?
     */
    public function ajax_order_refresh()
    {
        // Get post ID
        $post_id = htmlspecialchars($_POST['post_id']);
        echo 'ajax_order_refresh';
        exit();
    }

    /**
     * Notice : Update the plugin
     */
    public function notice_update()
    {
        // require view
        include_once 'views/notice-update.php';
    }

    /**
     * ART request to YellowCube
     *
     * Note : $variation_id is optional
     */
    public function YellowCube_ART($product_id, $type, $variation_id = false, $lotmanagement = 0, $variation = false)
    {
        global $wpdb;

        if (!$this->yellowcube()) {
            // YellowCube setup failed, abort.
            return;
        }

        // Product object
        if ($variation == 'true') {
            $wc_product = new WC_Product_Variation((int)$product_id);
        } else {
            $wc_product = new WC_Product((int)$product_id);
        }

        if (!$wc_product) {
            return false;
        }

        // Skip products if virtual.
        if ($wc_product->get_virtual() == true) {
            $this->log_create(0, 'ART', 0, $product_id, 'Skipping virtual product id ' . $product_id);
            return false;
        }
        // Skip products with missing SKU.
        if ($wc_product->get_sku() == '') {
            $this->log_create(0, 'ART', 0, $product_id, 'SKU undefined, skipping product id ' . $product_id);
            return false;
        }

        if (wp_get_post_parent_id($product_id) == 0) {
            $wc_product_parent = new WC_Product((int)$product_id);
        } else {
            $wc_product_parent = new WC_Product((int)wp_get_post_parent_id($product_id));
        }

        $wc_product_parent_ID = $wc_product_parent->get_id();
        $attributes = str_replace(' ', '', $wc_product_parent->get_attribute('EAN'));
        $product_ean = '';

        if (strpos($attributes, '=') !== false) {
            // @todo Missing use case reference.
            $attributes_first_level = explode(',', $attributes);
            $temp_attributes = array();

            foreach ($attributes_first_level as $level) {
                $level = explode('=', $level);
                $product_identification = $level[0];
                $product_ean = $level[1];
                $temp_attributes[$product_identification] = $product_ean;
            }

            $product_ean = $temp_attributes[$product_id];
        } else {
            $product_ean = $attributes;
        }

        // YellowCube\Article
        $article = new Article;

        // Validate type
        switch ($type) {
          case 'update':
            $type = 'UPDATE';
            $article->setChangeFlag(ChangeFlag::UPDATE);
            break;
        case 'deactivate':
            $type = 'DEACTIVATE';
            $article->setChangeFlag(ChangeFlag::DEACTIVATE);
            break;
        case 'insert':
        default:
            $type = 'INSERT';
            $article->setChangeFlag(ChangeFlag::INSERT);
            break;
        }

        $article
            ->setPlantID(get_option('wooyellowcube_plant'))
            ->setDepositorNo(get_option('wooyellowcube_depositorNo'))
            ->setArticleNo($wc_product->get_sku())
            ->setNetWeight(round(wc_get_weight($wc_product->get_weight(), 'kg'), 3), ISO::KGM)
            ->setGrossWeight(round(wc_get_weight($wc_product->get_weight(), 'kg'), 3), ISO::KGM)
            ->setBatchMngtReq($lotmanagement)
            ->addArticleDescription(substr($wc_product->get_title(), 0, 39), 'de')
            ->addArticleDescription(substr($wc_product->get_title(), 0, 39), 'fr');

        // custom field : BaseUOM && AlternateUnitISO
        if (get_post_meta(get_the_ID(), 'yellowcube_BaseUOM', true) && get_post_meta(get_the_ID(), 'yellowcube_AlternateUnitISO', true)) {
            $metaBaseUOM = get_post_meta(get_the_ID(), 'yellowcube_BaseUOM', true);
            $metaBaseAlternateUnitISO = get_post_meta(get_the_ID(), 'yellowcube_AlternateUnitISO', true);
            $article->setBaseUOM($metaBaseUOM);
            $article->setAlternateUnitISO($metaBaseAlternateUnitISO);
        } else {
            // default value
            $article->setBaseUOM(ISO::PCE);
            $article->setAlternateUnitISO(ISO::PCE);
        }

        if (strlen($product_ean) == 8) {
            $article->setEAN($product_ean, EANType::UC);
        } else {
            $article->setEAN($product_ean, EANType::HE);
        }

        $volume = 1;

        // Set Length
        if ($length = $wc_product->get_length()) {
            $article->setLength(round(wc_get_dimension($length, 'cm'), 3), ISO::CMT);
            $volume = $volume * wc_get_dimension($length, 'cm');
        }

        // Set Width
        if ($width = $wc_product->get_width()) {
            $article->setWidth(round(wc_get_dimension($width, 'cm'), 3), ISO::CMT);
            $volume = $volume * wc_get_dimension($width, 'cm');
        }

        // Set Height
        if ($height = $wc_product->get_height()) {
            $article->setHeight(round(wc_get_dimension($height, 'cm'), 3), ISO::CMT);
            $volume = $volume * wc_get_dimension($height, 'cm');
        }

        // Set Volume
        $article->setVolume(round($volume, 3), ISO::CMQ);
        $response_status_code = 0;
        $response_status_text = '';
        $response_reference = 0;

        try {
            $response = $this->yellowcube->insertArticleMasterData($article);
            // Status PENDING.
            $response_status = 1;
            $response_status_code = $response->getStatusCode();
            $response_status_text = $response->getStatusText();
            $response_reference = $response->getReference();
            $this->log_create(1, 'ART-'.$type, $response_reference, $wc_product_parent_ID, $response_status_text);
        } catch (Exception $e) {
            // Status ERROR.
            $response_status = 0;
            $response_status_code = 0;
            $response_status_text = $e->getMessage();
            $this->log_create(0, 'ART-'.$type, 0, $wc_product_parent_ID, $e->getMessage());
        }

        // Insert a product to YellowCube
        if ($type == 'INSERT') {
            $wpdb->insert(
                'wooyellowcube_products',
                array(
                    'id_product' => $product_id,
                    'created_at' => time(),
                    'lotmanagement' => $lotmanagement,
                    'yc_response' => $response_status,
                    'yc_status_code' => $response_status_code,
                    'yc_status_text' => $response_status_text,
                    'yc_reference' => $response_reference
                )
            );
        }

        // Update a product to YellowCube
        if ($type == 'UPDATE') {
            $wpdb->update(
                'wooyellowcube_products',
                array(
                    'lotmanagement' => $lotmanagement,
                    'yc_response' => $response_status,
                    'yc_status_code' => $response_status_code,
                    'yc_status_text' => $response_status_text,
                    'yc_reference' => $response_reference
                ),
                array(
                    'id_product' => $product_id
                )
            );
        }

        // Deactivate a product to YellowCube
        if ($type == 'DEACTIVATE') {
            // Be sure that they is no error before deleting
            if ($response_status != 0) {
                $wpdb->delete(
                    'wooyellowcube_products',
                    array(
                        'id_product' => $product_id
                    )
                );
            }
        }
    }

    /**
     * Get wordpress locale language
     *
     * @release 3.4.1
     * @date    2017-05-08
     */
    public function getLocale()
    {
        // default locale is english
        $locale = 'en';
        $localeString = get_locale();

        if (!$localeString) {
            return $locale;
        }

        // decompose locale information
        $localeSegment = explode('_', $localeString);
        $localeSelector = $localeSegment[0];

        return ($localeSelector == '') ? $locale : $localeSelector;
    }

    /**
     * Get the order shipping constant for YellowCube
     *
     * @release 3.4.1
     * @date    2017-05-08
     */
    public function getShippingFromOrder($wcOrder)
    {

        // Default shipping info.
        $shipping = array();
        $shipping['main'] = new BasicShippingServices(BasicShippingServices::ECO);
        $shipping['additional'] = new AdditionalShippingServices('NONE');

        // Get shipping methods.
        $shipping_methods = $wcOrder->get_shipping_methods();

        // Get YC shipping methods available.
        $shipping_saved_methods = unserialize(get_option('wooyellowcube_shipping_methods'));

        // Get first shipping method identifier available in YC.
        foreach ($shipping_methods as $shippingMethod) {
            $shippingMethodIdentifier = $shippingMethod->get_instance_id();

            // Skip methods that are not configured for YC.
            if (!isset($shipping_saved_methods[$shippingMethodIdentifier])) {
              continue;
            }

            switch ($shipping_saved_methods[$shippingMethodIdentifier]['basic']) {
              case 'ECO':
                $shipping['main'] = new BasicShippingServices(BasicShippingServices::ECO);
                break;
              case 'PRI':
                $shipping['main'] = new BasicShippingServices(BasicShippingServices::PRI);
                break;
              case 'PICKUP':
                $shipping['main'] = new BasicShippingServices(BasicShippingServices::PICKUP);
                break;
            }

          $shipping['additional'] = new AdditionalShippingServices($shipping_saved_methods[$shippingMethodIdentifier]['additional']);

            break;
        }

        return $shipping;
    }

    public function isShippingAvailable($wcOrder)
    {
        // get shipping methods
        $shipping_methods = $wcOrder->get_shipping_methods();
        if (empty($shipping_methods)) {
          // No shipping methods available.
          return false;
        }

        // Get YC shipping methods available
        $shipping_saved_methods = unserialize(get_option('wooyellowcube_shipping_methods'));

        $shippingInstanceID = null;
        // loop on each shipping method available for this order
        foreach ($shipping_methods as $shippingMethod) {
            // @todo this does not properly support multiple shipping methods.
            // get shipping method informations
            $shippingMethodData = $shippingMethod->get_data();
            $shippingInstanceID = $shippingMethodData['instance_id'];
        }

        return ($shipping_saved_methods[$shippingInstanceID]['status'] == 0) ? false : true;
    }

    /**
     * Check if the order has already been sent to YellowCube with success
     *
     * @release 3.4.1
     * @date    2017-05-08
     *
     * @todo Unused, broken, remove?
     */
    public function alreadySuccessYellowCube($orderID)
    {
        global $wpdb;
        $orderCount = $wpdb->get_var('SELECT COUNT(id) FROM wooyellowcube_orders WHERE yc_response="2"');
        return ($orderCount > 0) ? true : false;
    }

    /**
     * Check if the order has already been sent to YellowCube
     *
     * @release 3.4.1
     * @date    2017-05-08
     */
    public function alreadySentYellowCube($orderID)
    {
        global $wpdb;
        $orderID = $wpdb->get_row('SELECT id, yc_response FROM wooyellowcube_orders WHERE id_order="'.$orderID.'"');

        if (!$orderID) {
            return false;
        } else {
            if ($orderID->yc_response == 1) {
                // Status PENDING.
                return true;
            } elseif ($orderID->yc_response == 2) {
                // Status SUCCESS.
                return true;
            } else {
                return false;
            }
        }
    }

    /**
     * Send an order to YellowCube (WAB Request)
     *
     * Use $force to force sending.
     *
     * @release 3.4.1
     * @date    2017-05-08
     */
    public function YellowCube_WAB($order_id, $force = FALSE)
    {
        global $wpdb, $woocommerce;

        // order informations
        $orderInformations = array();

        if (!$this->yellowcube()) {
            // YellowCube setup failed, abort.
            return;
        }

        // get the current order
        if ($wcOrder = wc_get_order($order_id)) {
            if ($force || $this->isShippingAvailable($wcOrder)) {
                if (!$this->alreadySentYellowCube($order_id)) {

                    // global informations
                    $orderInformations['global']['identifier'] = $wcOrder->get_order_number();
                    $orderInformations['global']['locale'] = $this->getLocale();
                    // partner informations
                    $orderInformations['partner']['yc_partnerNo'] = get_option('wooyellowcube_partnerNo');
                    $orderInformations['partner']['yc_partnerReference'] = substr($wcOrder->shipping_first_name, 0, 1).substr($wcOrder->shipping_last_name, 0, 1).$wcOrder->shipping_postcode;
                    $orderInformations['partner']['yc_name1'] = $wcOrder->shipping_first_name.' '.$wcOrder->shipping_last_name;
                    $orderInformations['partner']['yc_name2'] = $wcOrder->shipping_company;
                    $orderInformations['partner']['yc_name3'] = $wcOrder->shipping_address_2;
                    $orderInformations['partner']['yc_street'] = $wcOrder->shipping_address_1;
                    $orderInformations['partner']['yc_countryCode'] = $wcOrder->shipping_country;
                    $orderInformations['partner']['yc_zipCode'] = $wcOrder->shipping_postcode;
                    $orderInformations['partner']['yc_city'] = $wcOrder->shipping_city;
                    $orderInformations['partner']['yc_phoneNo'] = $wcOrder->billing_phone;
                    $orderInformations['partner']['yc_email'] = $wcOrder->billing_email;
                    // shipping informations
                    $shippingInformations = $this->getShippingFromOrder($wcOrder);
                    // create yellowcube order
                    $yellowcubeOrder = new Order();
                    $yellowcubeOrder->setOrderHeader(new OrderHeader(get_option('wooyellowcube_depositorNo'), $orderInformations['global']['identifier'], date('Ymd')));
                    // create yellowcube partner
                    $yellowcubePartner = new Partner();
                    $yellowcubePartner
                        ->setPartnerType('WE')
                        ->setPartnerNo(get_option('wooyellowcube_partnerNo'))
                        ->setPartnerReference($orderInformations['partner']['yc_partnerReference'])
                        ->setName1($orderInformations['partner']['yc_name1'])
                        ->setName2($orderInformations['partner']['yc_name2'])
                        ->setName3($orderInformations['partner']['yc_name3'])
                        ->setStreet($orderInformations['partner']['yc_street'])
                        ->setCountryCode($orderInformations['partner']['yc_countryCode'])
                        ->setZIPCode($orderInformations['partner']['yc_zipCode'])
                        ->setCity($orderInformations['partner']['yc_city'])
                        ->setPhoneNo($orderInformations['partner']['yc_phoneNo'])
                        ->setEmail($orderInformations['partner']['email'])
                        ->setLanguageCode($orderInformations['global']['locale']);
                    // set the partner to the order
                    $yellowcubeOrder->setPartnerAddress($yellowcubePartner);

                    // add shipping informations to the yellowcube order object
                    $yellowcubeOrder->addValueAddedService($shippingInformations['main']);

                    if (!empty($shippingInformations['additional'])) {
                        $yellowcubeOrder->addValueAddedService($shippingInformations['additional']);
                    }

                    // get order items
                    if ($orderItems = $wcOrder->get_items()) {
                        // count order items for position
                        $orderItemsCount = 0;

                        foreach ($orderItems as $key => $orderItem) {
                            $orderItemsCount++;

                            // item identifier
                            $itemIdentifier = 0;

                            // check if the product is a variation or not
                            $itemIdentifier = ($orderItem->get_variation_id() != 0) ? $orderItem->get_variation_id() : $orderItem->get_product_id();

                            // get the product object
                            $product = wc_get_product($itemIdentifier);
                            if (!$product) {
                              // Order obsolete, product likely deleted.
                              continue;
                            }

                            // Skip products if virtual or SKU missing.
                            if ($product->get_sku() != '' && $product->get_virtual() == false) {

                                // check if the product is in YellowCube
                                $productART = $wpdb->get_var('SELECT COUNT(id) FROM wooyellowcube_products WHERE id_product="'.$itemIdentifier.'"');

                                if ($productART > 0) {

                                    // create a position in YellowCube
                                    $yellowcubePosition = new Position();
                                    $yellowcubePosition
                                        ->setPosNo($orderItemsCount)
                                        ->setArticleNo($product->get_sku())
                                        ->setPlant(get_option('wooyellowcube_plant'))
                                        ->setQuantity($orderItem->get_quantity())
                                        ->setQuantityISO('PCE')
                                        ->setShortDescription(substr($product->get_name(), 0, 39));

                                    // add the position to the order
                                    $yellowcubeOrder->addOrderPosition($yellowcubePosition);
                                }
                            }
                        }
                    }

                    try {
                        $yellowcubeWABRequest = $this->yellowcube->createYCCustomerOrder($yellowcubeOrder);

                        if (!$this->alreadySentYellowCube($order_id)) {
                            // State unsubmitted or pending.
                            $num = $wpdb->replace(
                                'wooyellowcube_orders',
                                array(
                                    'id_order' => $order_id,
                                    'created_at' => time(),
                                    // Status PENDING.
                                    'yc_response' => 1,
                                    'yc_status_code' => $yellowcubeWABRequest->getStatusCode(),
                                    'yc_status_text' => $yellowcubeWABRequest->getStatusText(),
                                    'yc_reference' => $yellowcubeWABRequest->getReference()
                                )
                            );
                            $this->log_create(1, 'WAB-DELIVERY ORDER', $yellowcubeWABRequest->getReference(), $order_id, 'WAB Request has been sent');
                        } else {
                            // @todo Remove, this is never reached.
                            $orderIdentificationArchive = $this->alreadySentYellowCube($order_id);
                            $wpdb->update(
                                'wooyellowcube_orders',
                                array(
                                    'created_at' => time(),
                                    // Status PENDING.
                                    'yc_response' => 1,
                                    'yc_status_code' => $yellowcubeWABRequest->getStatusCode(),
                                    'yc_status_text' => $yellowcubeWABRequest->getStatusText(),
                                    'yc_reference' => $yellowcubeWABRequest->getReference()
                                ),
                                array(
                                    'id_order' => $order_id
                                )
                            );
                            $this->log_create(1, 'WAB-DELIVERY ORDER (UPDATE)', $yellowcubeWABRequest->getReference(), $orderIdentificationArchive, 'WAB Request has been sent');
                        }

                        // an error as occured
                    } catch (Exception $e) {
                        $wcOrder->update_status('failed', $e->getMessage());
                        $this->log_create(0, 'WAB-DELIVERY ORDER', 0, $order_id, $e->getMessage());
                    }
                }
            } else {
              // @todo Report in  UI: Order was skipped, no shipping needed.
            }
        }
    }

    /**
     * Get product status
     */
    public function get_product_status($product_id)
    {
        global $wpdb;
        return $wpdb->get_row('SELECT yc_response, yc_status_code FROM wooyellowcube_products WHERE id_product=\''.$product_id.'\' ');
    }

    /**
     * Get order status
     */
    public function get_order_status($order_id)
    {
        global $wpdb;
        return $wpdb->get_row('SELECT yc_response, yc_status_code FROM wooyellowcube_orders WHERE id_order=\''.$order_id.'\' ');
    }

  /**
   * Get quantity sum of pending orders from product.
   *
   * Completed and Cancelled orders are excluded.
   * Only count orders that are stock reduced.
   *
   * We can not skip completed orders as they could be pending in Inventory.
   *
   * Status notes
   * - wc-pending counters are not yet reduced
   * - wc-cancelled is final, counters are released
   * - wc-completed is final, maybe delta with delayed YC BAR
   * - wc-refunded is final, counters released
   * - wc-failed likely is temporary and needs action
   * Other states are checked and are likely transitional.
   *
   * @todo maybe check partial refund counters
   *
   * Assert: product_id is in stock.
   */
  public static function get_product_order_pending_sum($product_id)
  {
    global $wpdb;
    $inventory_timestamp = $wpdb->get_var('SELECT yellowcube_date FROM wooyellowcube_stock WHERE product_id="' . $product_id . '"');
    $lastsync = 0;


    // Check pending count on all non-final orders.
    $order_items = $wpdb->get_results('SELECT wp_woocommerce_order_items.order_id, SUM(order_item_qty.meta_value) count FROM wp_woocommerce_order_items
INNER JOIN wp_posts
  ON wp_posts.ID = wp_woocommerce_order_items.order_id
  AND wp_posts.post_status NOT IN ("wc-pending", "wc-cancelled", "wc-completed")
INNER JOIN wp_woocommerce_order_itemmeta AS order_item_prod
  ON order_item_prod.order_item_id = wp_woocommerce_order_items.order_item_id
  AND order_item_prod.meta_key = "_product_id"
  AND order_item_prod.meta_value = "'.$product_id.'"
INNER JOIN wp_woocommerce_order_itemmeta AS order_item_qty
  ON order_item_qty.order_item_id = wp_woocommerce_order_items.order_item_id
  AND order_item_qty.meta_key = "_qty"
LEFT JOIN wooyellowcube_orders
  ON wooyellowcube_orders.id_order = wp_woocommerce_order_items.order_id
  AND wooyellowcube_orders.status != 2
WHERE wp_woocommerce_order_items.order_item_type="line_item"
GROUP BY wp_woocommerce_order_items.order_id');

  $pending = 0;
  foreach ($order_items as $row) {
    $order = wc_get_order( $row->order_id );

    if ( $order ) {
      $stock_reduced  = $order->get_data_store()->get_stock_reduced( $row->order_id );
      if (!$stock_reduced) {
        // Skip counting as pending.
        continue;
      }
    }
    $pending += $row->count;
  }

    // YC delta is off due to shipping after BAR reception.
    // These orders are marked as completed in YC.
    // @todo Group in SQL and avoid loop.
    $yc_items = $wpdb->get_results('SELECT wp_woocommerce_order_items.order_id, SUM(order_item_qty.meta_value) count FROM wp_woocommerce_order_items
INNER JOIN wp_posts
  ON wp_posts.ID = wp_woocommerce_order_items.order_id
INNER JOIN wp_woocommerce_order_itemmeta AS order_item_prod
  ON order_item_prod.order_item_id = wp_woocommerce_order_items.order_item_id
  AND order_item_prod.meta_key = "_product_id"
  AND order_item_prod.meta_value = "'.$product_id.'"
INNER JOIN wp_woocommerce_order_itemmeta AS order_item_qty
  ON order_item_qty.order_item_id = wp_woocommerce_order_items.order_item_id
  AND order_item_qty.meta_key = "_qty"
INNER JOIN wooyellowcube_orders
  ON wooyellowcube_orders.id_order = wp_woocommerce_order_items.order_id
  AND wooyellowcube_orders.status = 2
  AND wooyellowcube_orders.shipped_at>'.$inventory_timestamp.'
WHERE wp_woocommerce_order_items.order_item_type="line_item"
GROUP BY wp_woocommerce_order_items.order_id');

    foreach ($yc_items as $row) {
      $pending += $row->count;
    }

    return $pending;
  }


  /**
     * Get product object by SKU
     *
     * @todo Unused, remove?
     */
    public function get_product_by_sku($sku)
    {
        global $wpdb;
        $product_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_sku' AND meta_value='%s' LIMIT 1", $sku));

        if ($product_id) {
            return new WC_Product($product_id);
        }

        return false;
    }

    /**
     * Create a lock for cron.
     */
    public function cron_lock_get($name) {
      $upload_dir = wp_upload_dir();
      $lockfile = $upload_dir['basedir'] . '/yellowcube.'.$name.'.lock';
      $fp = fopen($lockfile, 'w');
      if (!flock($fp, LOCK_EX | LOCK_NB)) {
        // Could not get the lock.
        return;
      }
      return $fp;
    }

    /**
     * Release lock for cron.
     */
    public function cron_lock_release($handle) {
      flock($handle, LOCK_UN);
      fclose($handle);
    }

    /**
     * Run all crons based on schedule.
     *
     * The recommended setup is a real system cron and set DISABLE_WP_CRON.
     */
    public function crons() {
      if (!$this->areSettingsReady()) {
        return;
      }
      // Skip cron on ajax requests to avoid parallel hits.
      if (defined( 'DOING_AJAX' ) && DOING_AJAX) {
        return;
      }
      // Skip cron if disabled. Requires recommended separate setup.
      $fakecron = true;
      if (defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON) {
        $fakecron = false;
      }

      try {
        $this->crons_responses($fakecron);
        $this->crons_daily($fakecron);
        $this->crons_hourly($fakecron);
      } catch (Exception $e) {
        // Silently fail.
      }
    }

    /**
     * CRON - RESPONSES ORDER | PRODUCT - Per Minute
     *
     * Tasks
     * - Update order status to accepted | refused
     * - Update product status to accepted | refused
     */
    public function crons_responses($fakecron)
    {
        global $wpdb;
        $cron_response_limit = 60; // 60 seconds
        $get_key = 'cron_response';
        $option_key = 'wooyellowcube_cron_response';
        $time = time();
        $this->lastRequest = get_option($option_key);

        if (($fakecron && (($time - $this->lastRequest) > $cron_response_limit))
          || (isset($_GET[$get_key]) != '')) {
            // Avoid parallel runs.
            if (!$lock = $this->cron_lock_get($option_key)) {
              return;
            }

            if ($this->logDebug) {
              $this->log_create(0, 'CRON-RESPONSES', 0, 0, $this->lastRequest . ' ' . $time . ' ' . ($time - $this->lastRequest));
            }

            // Update last execution date first, avoid re-run on error.
            update_option($option_key, $time, false);

            // Get PENDING results from previous requests on products.
            $products_execution = $wpdb->get_results('SELECT * FROM wooyellowcube_products WHERE yc_response = 1');
            // Get PENDING results from previous requests on orders.
            $orders_execution = $wpdb->get_results('SELECT * FROM wooyellowcube_orders WHERE yc_response = 1');

            // Connect to YellowCube if we have some requests entries.
            if (is_array($products_execution) || is_array($orders_execution)) {
                if (!$this->yellowcube()) {
                    // YellowCube setup failed, abort.
                    return;
                }
            }

            // Products execution
            if ($products_execution) {
                foreach ($products_execution as $execution) {
                    try {
                        $response = $this->yellowcube->getInsertArticleMasterDataStatus($execution->yc_reference);

                        if ($response->getStatusCode() == 100) {
                            // Update the record
                            $wpdb->update(
                                'wooyellowcube_products',
                                array(
                                    'yc_response' => 2,
                                    'yc_status_text' => $response->getStatusText()
                                ),
                                array(
                                    'id_product' => $execution->id_product
                                )
                            );
                            $this->log_create(1, 'ART-ACCEPTED', $response->getReference(), $execution->id_product, $response->getStatusText());
                        }
                        // @todo Other error codes?
                    } catch (Exception $e) {

                        $wpdb->update(
                            'wooyellowcube_products',
                            array(
                                'yc_response' => 0,
                                'yc_status_text' => $e->getMessage()
                            ),
                            array(
                                'id_product' => $execution->id_product
                            )
                        );
                        $this->log_create(0, 'ART-REFUSED', $execution->yc_reference, $execution->id_product, $e->getMessage());
                    }
                }
            }

            // Orders execution
            if ($orders_execution) {
                foreach ($orders_execution as $execution) {
                    try {
                        $response = $this->yellowcube->getYCCustomerOrderStatus($execution->yc_reference);

                        if ($response->isPending()) {
                          // Skip, recheck every minute.
                          continue;
                        }
                        // Update the order only when we got 100 StatusCode.
                        if ($response->getStatusCode() == 100) {

                            // Update the record
                            $wpdb->update(
                                'wooyellowcube_orders',
                                array(
                                    // Status SUCCESS.
                                    'yc_response' => 2,
                                    'yc_status_text' => $response->getStatusText(),
                                ),
                                array(
                                    'id_order' => $execution->id_order
                                )
                            );

                            $this->log_create(1, 'WAB-ACCEPTED', 0, $execution->id_order, $response->getStatusText());
                        }
                        else {
                          // @todo Support error responses. We still retry.
                        }
                    } catch (Exception $e) {
                        $wpdb->update(
                            'wooyellowcube_orders',
                            array(
                                // Status ERROR.
                                'yc_response' => 0,
                                'yc_status_text' => $e->getMessage()
                            ),
                            array(
                                'id_order' => $execution->id_order
                            )
                        );

                        wc_get_order($execution->id_order)->update_status('failed', $e->getMessage());
                        $this->log_create(0, 'WAB-REFUSED', 0, $execution->id_order, $e->getMessage());
                    }
                }
            }

            // Release lock.
            $this->cron_lock_release($lock);
        }
    }

    /**
     * CRON - UPDATE STOCK | LOG CLEAN - Daily
     *
     * Tasks
     * - Update stock repository
     * - Clean logs
     */
    public function crons_daily($fakecron)
    {
        global $wpdb;
        $cron_response_limit = 60*60*24; // 24 hours
        $get_key = 'cron_daily';
        $option_key = 'wooyellowcube_cron_daily';
        $time = time();
        $current_day = date('Ymd');
        $this->lastRequest = get_option($option_key);

        // Execute CRON
        if (($fakecron && ($current_day != $this->lastRequest))
          || (isset($_GET[$get_key]) != '')) {
            // Avoid parallel runs.
            if (!$lock = $this->cron_lock_get($option_key)) {
              return;
            }

            if ($this->logDebug) {
              $this->log_create(0, 'CRON-DAILY', 0, 0, $this->lastRequest . ' ' . $current_day);
            }

            // Update last execution date first, avoid re-run on error.
            update_option($option_key, $current_day, false);


            $this->update_stock();


            // Cleanup logs.
            if (get_option('wooyellowcube_logs') > 1) {
                $date_gap = get_option('wooyellowcube_logs') * 60 * 60 * 24;
                $wpdb->query("DELETE FROM wooyellowcube_logs WHERE created_at < ".(time() - $date_gap));
            }

            // Release lock.
            $this->cron_lock_release($lock);
        }
    }

    /**
     * Find product identification metas from SKU
     *
     * @release 3.4.1
     * @date    2017-05-08
     */
    public function retrieveProductBySKU($productSKU)
    {
        global $wpdb;

        /* product SKU is invalid */
        $productSKU = trim($productSKU);
        if ($productSKU == '') {
            return false;
        }

        /* find the product ID by SKU in database */
        $productMetas = $wpdb->get_row("SELECT * FROM ".$wpdb->prefix."postmeta WHERE meta_key='_sku' AND meta_value='".$productSKU."'");

        /* no product founded */
        if ($productMetas === null) {
            return false;
        }

        return $productMetas;
    }

    /**
     * Update the stock inventory from YellowCube
     *
     * @todo We should always check OrderReplies before.
     *
     * @release 3.4.1
     * @date    2017-05-08
     */
    public function update_stock()
    {
        global $wpdb;
        // number of products inserted during the BAR request
        $countInsertedArticle = 0;

        if (!$this->yellowcube()) {
            // YellowCube setup failed, abort.
            return;
        }

        try {
            // get yellowcube inventory
            $inventory = $this->yellowcube->getInventoryWithMetadata();
            // @todo check time zone.
            $date = DateTime::createFromFormat('YmdHis', $inventory->getTimestamp());

            // remove the current stock information.
            $wpdb->query('DELETE FROM wooyellowcube_stock');
            $wpdb->query('DELETE FROM wooyellowcube_stock_lots');

            // loop on each article
            foreach ($inventory->getArticles() as $article) {

                // select only YAFS products (see with YellowCube technical operators)
                if ($article->getStorageLocation() == 'YAFS') {

                    // get product informations
                    $articleSKU = $article->getArticleNo();
                    $articleInformations = $this->retrieveProductBySKU($articleSKU);
                    $articleID = 0;
                    if ($articleInformations) {
                      // get product ID
                      $articleID = intval($articleInformations->post_id);
                      //$product = wc_get_product($articleID);
                    }

                    // get product object (WooCommerce)
                    if (TRUE) {

                        // insert the stock information in database
                        $wpdb->insert(
                            'wooyellowcube_stock',
                            array(
                                'product_id' => $articleID,
                                'product_name' => $article->getArticleDescription(),
                                //'woocommerce_stock' => $product->get_stock_quantity(),
                                'yellowcube_stock' => (string)$article->getQuantityUOM(),
                                'yellowcube_date' => $date->getTimestamp(),
                                'yellowcube_articleno' => $article->getArticleNo(),
                                'yellowcube_lot' => $article->getLot() ?: '',
                                'yellowcube_bestbeforedate' => $article->getBestBeforeDate()
                            )
                        );

                        // insert the stock information for lots in database
                        $wpdb->insert(
                            'wooyellowcube_stock_lots',
                            array(
                                'id_product' => $articleID,
                                'product_lot' => $article->getLot() ?: '',
                                'product_quantity' => (string)$article->getQuantityUOM(),
                                'product_expiration' => $article->getBestBeforeDate() ?: ''
                            )
                        );

                        // update the number of inserted article
                        $countInsertedArticle++;
                    }
                }
            }

            // logging
            $this->log_create(1, 'BAR', 0, 0, 'Stock inventory updated on '.date('d/m/Y H:i:s').' - Product updates : '.$countInsertedArticle);
        } catch (Exception $e) {
            // logging error
            $this->log_create(0, 'BAR', 0, 0, $e->getMessage());
        }
    }

    /**
     * CRON - WAR - Hourly
     *
     * NEW interval 30mins, following standard intervals.
     *
     * The interval was originally 60mins = hourly.
     */

    public function crons_hourly($fakecron)
    {
        global $wpdb;
        // Cron hourly execution.
        $cron_limit_time = 30 * 60;
        $get_key = 'cron_hourly';
        $option_key = 'wooyellowcube_cron_hourly';
        $time = time();
        $this->lastRequest = get_option($option_key);

      // Need to execute the cron
        if (($fakecron && (($time - $this->lastRequest) > $cron_limit_time))
          || (isset($_GET[$get_key]) != '')) {
            // Avoid parallel runs.
            if (!$lock = $this->cron_lock_get($option_key)) {
              return;
            }

            if ($this->logDebug) {
              $this->log_create(0, 'CRON-HOURLY', 0, 0, $this->lastRequest . ' ' . $time . ' ' . ($time - $this->lastRequest));
            }

            // Update last execution date first, avoid re-run on error.
            update_option($option_key, $time, false);

            $this->retrieveWAR();

            // Release lock.
            $this->cron_lock_release($lock);
        }
    }

    /**
     * Logging linked with WooYellowCube WordPress back-office
     *
     * @release 3.4.1
     * @date    2017-05-08
     */
    public function log_create($response, $type, $reference, $object, $message)
    {
        global $wpdb;
        // insert the row in database (log database)
        $wpdb->insert('wooyellowcube_logs', array('id' => '', 'created_at' => time(), 'type' => $type, 'response' => $response, 'reference' => $reference, 'object' => $object, 'message' => $message));
    }
}


/**
 * Plugin initialization from init action
 *
 * @since 2.3.4
 */
function wooyellowcube_init()
{
    // instanciate WooYellowCube class
    // @todo maybe set global instance here?
    $wooyellowcube = new WooYellowCube();
}

/**
 * Check for update needs
 *
 * @todo Make sure this is not executed when installing.
 * @todo Maybe smaller delta with direct ALTER?
 */
function wooyellowcube_update()
{
  global $wpdb;
  $current_version = get_option('wooyellowcube_update', 1);

  if ($current_version < 2) {
    include_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset_collate = $wpdb->get_charset_collate();

    $wpdb->insert('wooyellowcube_logs', array(
      'created_at' => time(), 'type' => 'UPDATE',
      'message' => '1 Schema wooyellowcube_orders add column shipped_at'));

    dbDelta(
      "CREATE TABLE `wooyellowcube_orders` (
        `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `id_order` int(11) NOT NULL UNIQUE,
        `created_at` int(11) NOT NULL,
        `shipped_at` int(11) NOT NULL,
        `status` tinyint(4) NOT NULL,
        `pdf_file` varchar(250) NOT NULL,
        `yc_response` int(11) NOT NULL,
        `yc_status_code` int(11) NOT NULL,
        `yc_status_text` mediumtext NOT NULL,
        `yc_reference` int(11) NOT NULL,
        `yc_shipping` varchar(250) NOT NULL
    ) ENGINE=InnoDB $charset_collate;"
    );

    // Mark schema update as completed.
    update_option('wooyellowcube_update', 2);
  }
}

/**
 * Install callback for the WooYellowcube plugin.
 *
 * Creates necessary database tables.
 *
 * @since 2.5.1
 */
function wooyellowcube_install()
{
    include_once ABSPATH . 'wp-admin/includes/upgrade.php';
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    dbDelta(
        "CREATE TABLE `wooyellowcube_logs` (
        `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `created_at` int(11) NOT NULL,
        `type` varchar(250) NOT NULL,
        `response` int(11) DEFAULT NULL,
        `reference` int(11) DEFAULT NULL,
        `object` int(11) DEFAULT NULL,
        `message` mediumtext
    ) ENGINE=InnoDB $charset_collate;"
    );

    dbDelta(
        "CREATE TABLE `wooyellowcube_orders` (
        `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `id_order` int(11) NOT NULL UNIQUE,
        `created_at` int(11) NOT NULL,
        `shipped_at` int(11) NOT NULL,
        `status` tinyint(4) NOT NULL,
        `pdf_file` varchar(250) NOT NULL,
        `yc_response` int(11) NOT NULL,
        `yc_status_code` int(11) NOT NULL,
        `yc_status_text` mediumtext NOT NULL,
        `yc_reference` int(11) NOT NULL,
        `yc_shipping` varchar(250) NOT NULL
    ) ENGINE=InnoDB $charset_collate;"
    );

    dbDelta(
        "CREATE TABLE `wooyellowcube_orders_lots` (
        `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `id_order` int(11) NOT NULL,
        `product_no` varchar(250) NOT NULL,
        `product_lot` varchar(250) NOT NULL,
        `product_quantity` int(11) NOT NULL
    ) ENGINE=InnoDB $charset_collate;"
    );

    dbDelta(
        "CREATE TABLE `wooyellowcube_products` (
        `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `id_product` int(11) NOT NULL UNIQUE,
        `id_variation` int(11) NOT NULL,
        `created_at` int(11) NOT NULL,
        // @todo unused.
        `status` tinyint(4) NOT NULL,
        `lotmanagement` tinyint(1) NOT NULL,
        `yc_response` int(11) NOT NULL,
        `yc_status_code` int(11) NOT NULL,
        `yc_status_text` mediumtext NOT NULL,
        `yc_reference` int(11) NOT NULL
    ) ENGINE=InnoDB $charset_collate"
    );

    dbDelta(
        "CREATE TABLE `wooyellowcube_stock` (
        `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `product_id` int(11) DEFAULT NULL,
        `product_name` varchar(250) NOT NULL,
        `woocommerce_stock` int(11) DEFAULT NULL,
        `yellowcube_stock` int(11) NOT NULL,
        `yellowcube_date` int(11) DEFAULT NULL,
        `yellowcube_articleno` varchar(250) DEFAULT NULL,
        `yellowcube_lot` varchar(250) DEFAULT NULL,
        `yellowcube_bestbeforedate` int(11) DEFAULT NULL
    ) ENGINE=InnoDB $charset_collate"
    );

    dbDelta(
        "CREATE TABLE `wooyellowcube_stock_lots` (
        `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `id_product` int(11) NOT NULL,
        `product_lot` varchar(250) NOT NULL,
        `product_quantity` int(11) NOT NULL,
        `product_expiration` int(11) NOT NULL
    ) ENGINE=InnoDB AUTO_INCREMENT=33 $charset_collate"
    );

    // Skip update 2.
    update_option('wooyellowcube_update', 2);

}

add_action('init', 'wooyellowcube_init');
register_activation_hook(__FILE__, 'wooyellowcube_install');
add_action('admin_init', 'wooyellowcube_update');
