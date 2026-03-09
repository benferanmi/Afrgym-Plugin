<?php
/**
 * Product Service - SEPARATE PRODUCT MANAGEMENT PER GYM
 * Automatically routes to gym-specific tables based on logged-in admin
 */
class Gym_Product_Service
{
    private $products_table;
    private $sales_table;
    private $activity_table;
    private $gym_identifier;

    public function __construct($gym_identifier = null)
    {
        global $wpdb;

        // Get gym identifier from parameter or current admin
        if ($gym_identifier === null) {
            $current_admin = Gym_Admin::get_current_gym_admin();
            $this->gym_identifier = $current_admin ? $current_admin->gym_identifier : 'afrgym_one';
        } else {
            $this->gym_identifier = $gym_identifier;
        }

        // Set table names based on gym
        if ($this->gym_identifier === 'afrgym_two') {
            $this->products_table = $wpdb->prefix . 'gym_products_two';
            $this->sales_table = $wpdb->prefix . 'gym_product_sales_two';
            $this->activity_table = $wpdb->prefix . 'gym_product_activity_two';
        } else {
            $this->products_table = $wpdb->prefix . 'gym_products_one';
            $this->sales_table = $wpdb->prefix . 'gym_product_sales_one';
            $this->activity_table = $wpdb->prefix . 'gym_product_activity_one';
        }
    }

    /**
     * Get products with pagination and filtering
     */
    public function get_products($args = array())
    {
        global $wpdb;

        $defaults = array(
            'page' => 1,
            'per_page' => 20,
            'search' => '',
            'status' => 'all',
            'orderby' => 'created_at',
            'order' => 'DESC'
        );

        $args = wp_parse_args($args, $defaults);

        // Build WHERE clause
        $where_conditions = array();
        $params = array();

        if (!empty($args['search'])) {
            $where_conditions[] = '(name LIKE %s OR description LIKE %s OR sku LIKE %s OR category LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
        }

        if ($args['status'] !== 'all') {
            $where_conditions[] = 'status = %s';
            $params[] = $args['status'];
        }

        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

        // Validate orderby
        $valid_orderby = array('name', 'price', 'quantity', 'created_at', 'total_sold', 'status');
        $orderby = in_array($args['orderby'], $valid_orderby) ? $args['orderby'] : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        // Get total count
        $count_query = "SELECT COUNT(*) FROM {$this->products_table} $where_clause";
        $total = (int) $wpdb->get_var(
            empty($params) ? $count_query : $wpdb->prepare($count_query, $params)
        );

        // Calculate offset
        $offset = ($args['page'] - 1) * $args['per_page'];

        // Get products
        $query = "SELECT * FROM {$this->products_table} $where_clause ORDER BY $orderby $order LIMIT %d OFFSET %d";
        $params[] = $args['per_page'];
        $params[] = $offset;

        $products = $wpdb->get_results($wpdb->prepare($query, $params));

        // Format products
        $formatted_products = array();
        foreach ($products as $product) {
            $formatted_products[] = $this->format_product($product);
        }

        return array(
            'products' => $formatted_products,
            'total' => $total,
            'gym_identifier' => $this->gym_identifier
        );
    }

    /**
     * Get single product
     */
    public function get_product($product_id)
    {
        global $wpdb;

        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->products_table} WHERE id = %d",
            $product_id
        ));

        if (!$product) {
            return new WP_Error('product_not_found', 'Product not found.', array('status' => 404));
        }

        return $this->format_product($product);
    }

    /**
     * Add new product
     */
    public function add_product($product_data)
    {
        global $wpdb;

        // Validate required fields
        if (empty($product_data['name'])) {
            return new WP_Error('missing_name', 'Product name is required.', array('status' => 400));
        }

        if (!isset($product_data['price']) || $product_data['price'] < 0) {
            return new WP_Error('invalid_price', 'Valid price is required.', array('status' => 400));
        }

        if (!isset($product_data['quantity']) || $product_data['quantity'] < 0) {
            return new WP_Error('invalid_quantity', 'Valid quantity is required.', array('status' => 400));
        }

        // Check for duplicate SKU in THIS gym's table only
        if (!empty($product_data['sku'])) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->products_table} WHERE sku = %s",
                $product_data['sku']
            ));

            if ($existing > 0) {
                return new WP_Error('duplicate_sku', 'Product with this SKU already exists in your gym.', array('status' => 409));
            }
        }

        // Prepare data
        $insert_data = array(
            'name' => sanitize_text_field($product_data['name']),
            'price' => floatval($product_data['price']),
            'description' => wp_kses_post($product_data['description'] ?? ''),
            'quantity' => absint($product_data['quantity']),
            'images' => json_encode($product_data['images'] ?? array()),
            'sku' => sanitize_text_field($product_data['sku'] ?? ''),
            'category' => sanitize_text_field($product_data['category'] ?? ''),
            'status' => in_array($product_data['status'] ?? '', array('active', 'inactive')) ? $product_data['status'] : 'active',
            'total_sold' => 0
        );

        $result = $wpdb->insert($this->products_table, $insert_data);

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to add product: ' . $wpdb->last_error, array('status' => 500));
        }

        $product_id = $wpdb->insert_id;

        // Log activity
        $this->log_product_activity($product_id, 'created', "Product '{$insert_data['name']}' created");

        return $this->get_product($product_id);
    }

    /**
     * Update product
     */
    public function update_product($product_id, $product_data)
    {
        global $wpdb;

        // Check if product exists
        $existing = $this->get_product($product_id);
        if (is_wp_error($existing)) {
            return $existing;
        }

        // Check for duplicate SKU if SKU is being updated
        if (isset($product_data['sku']) && !empty($product_data['sku'])) {
            $duplicate = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->products_table} WHERE sku = %s AND id != %d",
                $product_data['sku'],
                $product_id
            ));

            if ($duplicate > 0) {
                return new WP_Error('duplicate_sku', 'Another product with this SKU already exists.', array('status' => 409));
            }
        }

        // Prepare update data
        $update_data = array();

        if (isset($product_data['name'])) {
            $update_data['name'] = sanitize_text_field($product_data['name']);
        }

        if (isset($product_data['price'])) {
            $update_data['price'] = floatval($product_data['price']);
        }

        if (isset($product_data['description'])) {
            $update_data['description'] = wp_kses_post($product_data['description']);
        }

        if (isset($product_data['quantity'])) {
            $update_data['quantity'] = absint($product_data['quantity']);
        }

        if (isset($product_data['images'])) {
            $update_data['images'] = json_encode($product_data['images']);
        }

        if (isset($product_data['sku'])) {
            $update_data['sku'] = sanitize_text_field($product_data['sku']);
        }

        if (isset($product_data['category'])) {
            $update_data['category'] = sanitize_text_field($product_data['category']);
        }

        if (isset($product_data['status'])) {
            $update_data['status'] = in_array($product_data['status'], array('active', 'inactive')) ? $product_data['status'] : 'active';
        }

        if (empty($update_data)) {
            return $existing;
        }

        $result = $wpdb->update($this->products_table, $update_data, array('id' => $product_id));

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to update product: ' . $wpdb->last_error, array('status' => 500));
        }

        // Log activity
        $changes = implode(', ', array_keys($update_data));
        $this->log_product_activity($product_id, 'updated', "Product updated: $changes");

        return $this->get_product($product_id);
    }

    /**
     * Delete product
     */
    public function delete_product($product_id)
    {
        global $wpdb;

        // Check if product exists
        $product = $this->get_product($product_id);
        if (is_wp_error($product)) {
            return $product;
        }

        // Delete product
        $result = $wpdb->delete($this->products_table, array('id' => $product_id));

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to delete product: ' . $wpdb->last_error, array('status' => 500));
        }

        // Delete associated sales records
        $wpdb->delete($this->sales_table, array('product_id' => $product_id));

        // Log activity
        $this->log_product_activity($product_id, 'deleted', "Product '{$product['name']}' deleted");

        return true;
    }

    /**
     * Record product sale - UPDATED WITH NOTIFICATIONS
     */
    public function record_sale($product_id, $quantity, $note = '')
    {
        global $wpdb;

        // Get product
        $product = $this->get_product($product_id);
        if (is_wp_error($product)) {
            return $product;
        }

        // Check if enough quantity available
        $quantity_left = $product['quantity'] - $product['total_sold'];
        if ($quantity > $quantity_left) {
            return new WP_Error('insufficient_stock', "Only {$quantity_left} units available.", array('status' => 400));
        }

        // Get current admin info
        $current_admin = Gym_Admin::get_current_gym_admin();
        $admin_id = $current_admin ? $current_admin->id : null;

        // Record sale
        $sale_data = array(
            'product_id' => $product_id,
            'quantity' => $quantity,
            'price_at_sale' => $product['price'],
            'total_amount' => $product['price'] * $quantity,
            'admin_id' => $admin_id,
            'note' => sanitize_textarea_field($note),
            'sale_date' => current_time('mysql')
        );

        $result = $wpdb->insert($this->sales_table, $sale_data);

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to record sale: ' . $wpdb->last_error, array('status' => 500));
        }

        // Update total_sold
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->products_table} SET total_sold = total_sold + %d WHERE id = %d",
            $quantity,
            $product_id
        ));

        // Log activity
        $admin_name = $current_admin ? $current_admin->first_name . ' ' . $current_admin->last_name : 'Unknown';
        $gym_name = $this->gym_identifier === 'afrgym_two' ? 'Afrgym Two' : 'Afrgym One';
        $this->log_product_activity(
            $product_id,
            'sale',
            "Sale recorded: {$quantity} units by {$admin_name} ({$gym_name})"
        );

        // Get updated product info
        $updated_product = $this->get_product($product_id);

        // Send notification email - NEW
        $this->send_sale_notification($updated_product, $sale_data, $current_admin, $note);

        return array(
            'sale_id' => $wpdb->insert_id,
            'product' => $updated_product,
            'quantity_sold' => $quantity,
            'total_amount' => $sale_data['total_amount'],
            'gym_identifier' => $this->gym_identifier
        );
    }

    /**
     * Send sale notification email to admins - NEW METHOD
     */
    private function send_sale_notification($product, $sale_data, $admin, $note)
    {
        // Check if notifications are enabled
        $notifications_enabled = Gym_Admin::get_setting('product_sale_notifications_enabled', '1');

        if ($notifications_enabled !== '1') {
            return;
        }

        // Get notification email based on gym
        $setting_key = ($this->gym_identifier === 'afrgym_two') ?
            'product_sale_notification_email_gym_two' :
            'product_sale_notification_email_gym_one';

        $notification_email = Gym_Admin::get_setting($setting_key, get_option('admin_email'));

        // Load email template
        $template_path = SIMPLE_GYM_ADMIN_PLUGIN_DIR . 'templates/product-sale-notification.html';

        if (!file_exists($template_path)) {
            error_log('Product sale notification template not found');
            return;
        }

        $template = file_get_contents($template_path);

        // Prepare variables
        $gym_name = ($this->gym_identifier === 'afrgym_two') ? 'Afrgym Two' : 'Afrgym One';
        $admin_name = $admin ? ($admin->first_name . ' ' . $admin->last_name) : 'Unknown Admin';

        $quantity_left = $product['quantity_left'];
        $low_stock_threshold = (int) Gym_Admin::get_setting('low_stock_threshold', 10);

        // Build note section if note exists
        $note_html = '';
        if (!empty($note)) {
            $note_html = '<div class="note-section">
            <h4 style="margin-top: 0;">📝 Sale Note:</h4>
            <p style="margin: 0;">' . esc_html($note) . '</p>
        </div>';
        }

        // Build low stock alert if applicable
        $low_stock_alert = '';
        if ($quantity_left <= $low_stock_threshold && $quantity_left > 0) {
            $low_stock_alert = '<div style="background: #fff3cd; border: 2px solid #ffc107; padding: 15px; border-radius: 5px; margin: 20px 0;">
            <h4 style="margin-top: 0; color: #f57c00;">⚠️ Low Stock Warning</h4>
            <p style="margin: 0;"><strong>' . esc_html($product['name']) . '</strong> is running low on stock. Only <strong>' . $quantity_left . ' units</strong> remaining. Consider restocking soon.</p>
        </div>';
        } elseif ($quantity_left === 0) {
            $low_stock_alert = '<div style="background: #ffebee; border: 2px solid #f44336; padding: 15px; border-radius: 5px; margin: 20px 0;">
            <h4 style="margin-top: 0; color: #d32f2f;">🚨 Out of Stock Alert</h4>
            <p style="margin: 0;"><strong>' . esc_html($product['name']) . '</strong> is now <strong>OUT OF STOCK</strong>. Immediate restocking required.</p>
        </div>';
        }

        // Replace placeholders
        $replacements = array(
            '{{gym_name}}' => $gym_name,
            '{{product_name}}' => esc_html($product['name']),
            '{{quantity}}' => number_format($sale_data['quantity']),
            '{{price_per_unit}}' => number_format($product['price'], 2),
            '{{admin_name}}' => esc_html($admin_name),
            '{{sale_date}}' => date('F j, Y g:i A', strtotime($sale_data['sale_date'])),
            '{{total_amount}}' => number_format($sale_data['total_amount'], 2),
            '{{sale_note}}' => $note_html,
            '{{quantity_left}}' => number_format($quantity_left),
            '{{total_sold}}' => number_format($product['total_sold']),
            '{{low_stock_alert}}' => $low_stock_alert,
            '{{notification_time}}' => date('F j, Y g:i A')
        );

        $email_content = str_replace(array_keys($replacements), array_values($replacements), $template);

        // Send email
        $subject = sprintf('[%s] Product Sale: %s', $gym_name, $product['name']);

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $gym_name . ' <' . get_option('admin_email') . '>'
        );

        wp_mail($notification_email, $subject, $email_content, $headers);
    }

    /**
     * Get monthly statistics
     */
    public function get_monthly_stats($month)
    {
        global $wpdb;

        // Parse month
        $start_date = $month . '-01';
        $end_date = date('Y-m-t', strtotime($start_date));

        // Total products sold this month
        $total_sold = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(quantity), 0) FROM {$this->sales_table} 
             WHERE DATE(sale_date) BETWEEN %s AND %s",
            $start_date,
            $end_date
        ));

        // Total revenue this month
        $total_revenue = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(total_amount), 0) FROM {$this->sales_table} 
             WHERE DATE(sale_date) BETWEEN %s AND %s",
            $start_date,
            $end_date
        ));

        // Number of transactions
        $transaction_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->sales_table} 
             WHERE DATE(sale_date) BETWEEN %s AND %s",
            $start_date,
            $end_date
        ));

        // Products sold by product
        $products_breakdown = $wpdb->get_results($wpdb->prepare(
            "SELECT p.id, p.name, p.price, 
                    COALESCE(SUM(s.quantity), 0) as total_sold,
                    COALESCE(SUM(s.total_amount), 0) as total_revenue
             FROM {$this->products_table} p
             LEFT JOIN {$this->sales_table} s ON p.id = s.product_id 
                AND DATE(s.sale_date) BETWEEN %s AND %s
             GROUP BY p.id
             HAVING total_sold > 0
             ORDER BY total_sold DESC",
            $start_date,
            $end_date
        ), ARRAY_A);

        // Daily breakdown
        $daily_stats = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(sale_date) as date, 
                    SUM(quantity) as units_sold,
                    SUM(total_amount) as revenue
             FROM {$this->sales_table} 
             WHERE DATE(sale_date) BETWEEN %s AND %s
             GROUP BY DATE(sale_date)
             ORDER BY date ASC",
            $start_date,
            $end_date
        ), ARRAY_A);

        return array(
            'month' => $month,
            'gym_identifier' => $this->gym_identifier,
            'summary' => array(
                'total_units_sold' => $total_sold,
                'total_revenue' => round($total_revenue, 2),
                'transaction_count' => $transaction_count,
                'average_transaction_value' => $transaction_count > 0 ? round($total_revenue / $transaction_count, 2) : 0
            ),
            'products_breakdown' => $products_breakdown,
            'daily_stats' => $daily_stats
        );
    }

    /**
     * Get weekly statistics
     */
    public function get_weekly_stats($date)
    {
        global $wpdb;

        // Get week boundaries (Monday to Sunday)
        $timestamp = strtotime($date);
        $week_start = date('Y-m-d', strtotime('monday this week', $timestamp));
        $week_end = date('Y-m-d', strtotime('sunday this week', $timestamp));

        // Total sold this week
        $total_sold = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(quantity), 0) FROM {$this->sales_table} 
             WHERE DATE(sale_date) BETWEEN %s AND %s",
            $week_start,
            $week_end
        ));

        // Total revenue this week
        $total_revenue = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(total_amount), 0) FROM {$this->sales_table} 
             WHERE DATE(sale_date) BETWEEN %s AND %s",
            $week_start,
            $week_end
        ));

        // Day by day breakdown
        $daily_breakdown = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(sale_date) as date, DAYNAME(sale_date) as day_name,
                    SUM(quantity) as units_sold,
                    SUM(total_amount) as revenue
             FROM {$this->sales_table} 
             WHERE DATE(sale_date) BETWEEN %s AND %s
             GROUP BY DATE(sale_date)
             ORDER BY date ASC",
            $week_start,
            $week_end
        ), ARRAY_A);

        return array(
            'week_start' => $week_start,
            'week_end' => $week_end,
            'gym_identifier' => $this->gym_identifier,
            'summary' => array(
                'total_units_sold' => $total_sold,
                'total_revenue' => round($total_revenue, 2)
            ),
            'daily_breakdown' => $daily_breakdown
        );
    }

    /**
     * Get product analytics for specified period
     */
    public function get_product_analytics($period)
    {
        global $wpdb;

        // Determine date range based on period
        $periods = array(
            'week' => 7,
            'month' => 30,
            '3months' => 90,
            '6months' => 180,
            'year' => 365
        );

        $days = $periods[$period] ?? 30;
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        $end_date = current_time('Y-m-d');

        // Total revenue and units
        $totals = $wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(SUM(quantity), 0) as total_units,
                    COALESCE(SUM(total_amount), 0) as total_revenue,
                    COUNT(DISTINCT product_id) as products_sold_count
             FROM {$this->sales_table} 
             WHERE DATE(sale_date) BETWEEN %s AND %s",
            $start_date,
            $end_date
        ), ARRAY_A);

        // Top 5 products by revenue
        $top_revenue = $wpdb->get_results($wpdb->prepare(
            "SELECT p.id, p.name, 
                    SUM(s.quantity) as units_sold,
                    SUM(s.total_amount) as revenue
             FROM {$this->products_table} p
             INNER JOIN {$this->sales_table} s ON p.id = s.product_id
             WHERE DATE(s.sale_date) BETWEEN %s AND %s
             GROUP BY p.id
             ORDER BY revenue DESC
             LIMIT 5",
            $start_date,
            $end_date
        ), ARRAY_A);

        // Top 5 products by units
        $top_units = $wpdb->get_results($wpdb->prepare(
            "SELECT p.id, p.name, 
                    SUM(s.quantity) as units_sold,
                    SUM(s.total_amount) as revenue
             FROM {$this->products_table} p
             INNER JOIN {$this->sales_table} s ON p.id = s.product_id
             WHERE DATE(s.sale_date) BETWEEN %s AND %s
             GROUP BY p.id
             ORDER BY units_sold DESC
             LIMIT 5",
            $start_date,
            $end_date
        ), ARRAY_A);

        return array(
            'period' => $period,
            'gym_identifier' => $this->gym_identifier,
            'date_range' => array(
                'start' => $start_date,
                'end' => $end_date
            ),
            'summary' => array(
                'total_units_sold' => (int) $totals['total_units'],
                'total_revenue' => round((float) $totals['total_revenue'], 2),
                'products_sold_count' => (int) $totals['products_sold_count']
            ),
            'top_by_revenue' => $top_revenue,
            'top_by_units' => $top_units
        );
    }

    /**
     * Get top selling products
     */
    public function get_top_selling($limit, $period)
    {
        global $wpdb;

        // Determine date range
        if ($period !== 'all') {
            $periods = array(
                'week' => 7,
                'month' => 30,
                '3months' => 90,
                '6months' => 180,
                'year' => 365
            );

            $days = $periods[$period] ?? 30;
            $date_filter = "AND DATE(s.sale_date) >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)";
        } else {
            $date_filter = '';
        }

        $query = $wpdb->prepare(
            "SELECT p.id, p.name, p.price, p.category,
                    COALESCE(SUM(s.quantity), 0) as units_sold,
                    COALESCE(SUM(s.total_amount), 0) as revenue,
                    COUNT(s.id) as transaction_count
             FROM {$this->products_table} p
             LEFT JOIN {$this->sales_table} s ON p.id = s.product_id {$date_filter}
             GROUP BY p.id
             HAVING units_sold > 0
             ORDER BY units_sold DESC
             LIMIT %d",
            $limit
        );

        return $wpdb->get_results($query, ARRAY_A);
    }

    /**
     * Get low stock products
     */
    public function get_low_stock($threshold)
    {
        global $wpdb;

        $products = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, sku, price, quantity, total_sold, 
                    (quantity - total_sold) as quantity_left, status
             FROM {$this->products_table}
             WHERE status = 'active' AND (quantity - total_sold) <= %d AND (quantity - total_sold) > 0
             ORDER BY quantity_left ASC",
            $threshold
        ), ARRAY_A);

        return $products;
    }

    /**
     * Get product categories
     */
    public function get_categories()
    {
        global $wpdb;

        $categories = $wpdb->get_col(
            "SELECT DISTINCT category FROM {$this->products_table} WHERE category != '' AND category IS NOT NULL ORDER BY category ASC"
        );

        return $categories;
    }

    /**
     * Format product object
     */
    private function format_product($product)
    {
        if (!$product) {
            return null;
        }

        $images = json_decode($product->images, true);
        if (!is_array($images)) {
            $images = array();
        }

        $quantity_left = (int) $product->quantity - (int) $product->total_sold;

        return array(
            'id' => (int) $product->id,
            'name' => $product->name,
            'price' => (float) $product->price,
            'description' => $product->description,
            'sku' => $product->sku,
            'category' => $product->category,
            'quantity' => (int) $product->quantity,
            'total_sold' => (int) $product->total_sold,
            'quantity_left' => $quantity_left,
            'images' => $images,
            'status' => $product->status,
            'gym_identifier' => $this->gym_identifier,
            'created_at' => $product->created_at,
            'updated_at' => $product->updated_at
        );
    }

    /**
     * Log product activity
     */
    private function log_product_activity($product_id, $action, $description)
    {
        global $wpdb;

        $current_admin = Gym_Admin::get_current_gym_admin();
        $admin_id = $current_admin ? $current_admin->id : null;

        $wpdb->insert($this->activity_table, array(
            'product_id' => $product_id,
            'admin_id' => $admin_id,
            'action' => $action,
            'description' => $description,
            'created_at' => current_time('mysql')
        ));
    }
}