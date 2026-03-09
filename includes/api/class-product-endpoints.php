<?php
/**
 * Product Management API Endpoints - SEPARATE TABLES PER GYM
 * Automatically routes to gym-specific product tables
 */
class Gym_Product_Endpoints
{
    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes()
    {
        // Get all products (paginated with search)
        register_rest_route('gym-admin/v1', '/products', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_products'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'page' => array(
                    'default' => 1,
                    'type' => 'integer',
                    'minimum' => 1,
                    'sanitize_callback' => 'absint'
                ),
                'per_page' => array(
                    'default' => 20,
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                    'sanitize_callback' => 'absint'
                ),
                'search' => array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'status' => array(
                    'type' => 'string',
                    'enum' => array('active', 'inactive', 'all'),
                    'default' => 'all'
                ),
                'orderby' => array(
                    'type' => 'string',
                    'enum' => array('name', 'price', 'quantity', 'created_at', 'total_sold'),
                    'default' => 'created_at'
                ),
                'order' => array(
                    'type' => 'string',
                    'enum' => array('asc', 'desc'),
                    'default' => 'desc'
                )
            )
        ));

        // Get single product
        register_rest_route('gym-admin/v1', '/products/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_product'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint'
                )
            )
        ));

        // Add product (super_admin only)
        register_rest_route('gym-admin/v1', '/products', array(
            'methods' => 'POST',
            'callback' => array($this, 'add_product'),
            'permission_callback' => array($this, 'check_super_admin_permission'),
            'args' => array(
                'name' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function ($value) {
                        return !empty(trim($value));
                    }
                ),
                'price' => array(
                    'required' => true,
                    'type' => 'number',
                    'minimum' => 0,
                    'validate_callback' => function ($value) {
                        return is_numeric($value) && $value >= 0;
                    }
                ),
                'description' => array(
                    'type' => 'string',
                    'sanitize_callback' => 'wp_kses_post'
                ),
                'quantity' => array(
                    'required' => true,
                    'type' => 'integer',
                    'minimum' => 0,
                    'sanitize_callback' => 'absint'
                ),
                'images' => array(
                    'type' => 'array',
                    'items' => array('type' => 'string'),
                    'default' => array(),
                    'validate_callback' => array($this, 'validate_image_urls')
                ),
                'sku' => array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'category' => array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'status' => array(
                    'type' => 'string',
                    'enum' => array('active', 'inactive'),
                    'default' => 'active'
                )
            )
        ));

        // Update product details (super_admin only)
        register_rest_route('gym-admin/v1', '/products/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_product'),
            'permission_callback' => array($this, 'check_super_admin_permission'),
            'args' => array(
                'id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint'
                ),
                'name' => array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'price' => array(
                    'type' => 'number',
                    'minimum' => 0
                ),
                'description' => array(
                    'type' => 'string',
                    'sanitize_callback' => 'wp_kses_post'
                ),
                'quantity' => array(
                    'type' => 'integer',
                    'minimum' => 0,
                    'sanitize_callback' => 'absint'
                ),
                'images' => array(
                    'type' => 'array',
                    'items' => array('type' => 'string'),
                    'validate_callback' => array($this, 'validate_image_urls')
                ),
                'sku' => array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'category' => array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'status' => array(
                    'type' => 'string',
                    'enum' => array('active', 'inactive')
                )
            )
        ));

        // Delete product (super_admin only)
        register_rest_route('gym-admin/v1', '/products/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_product'),
            'permission_callback' => array($this, 'check_super_admin_permission'),
            'args' => array(
                'id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint'
                )
            )
        ));

        // Record sale (admin and super_admin can do this)
        register_rest_route('gym-admin/v1', '/products/(?P<id>\d+)/sale', array(
            'methods' => 'POST',
            'callback' => array($this, 'record_sale'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint'
                ),
                'quantity' => array(
                    'required' => true,
                    'type' => 'integer',
                    'minimum' => 1,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function ($value) {
                        return $value > 0;
                    }
                ),
                'note' => array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field'
                )
            )
        ));

        // Get monthly product statistics
        register_rest_route('gym-admin/v1', '/products/stats/monthly', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_monthly_stats'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'month' => array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description' => 'Month in YYYY-MM format (default: current month)'
                )
            )
        ));

        // Get weekly product statistics
        register_rest_route('gym-admin/v1', '/products/stats/weekly', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_weekly_stats'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'date' => array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description' => 'Date in YYYY-MM-DD format (default: today)'
                )
            )
        ));

        // Get product analytics
        register_rest_route('gym-admin/v1', '/products/stats/analytics', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_product_analytics'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'period' => array(
                    'type' => 'string',
                    'enum' => array('week', 'month', '3months', '6months', 'year'),
                    'default' => 'month'
                )
            )
        ));

        // Get top selling products
        register_rest_route('gym-admin/v1', '/products/stats/top-selling', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_top_selling'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'limit' => array(
                    'default' => 10,
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                    'sanitize_callback' => 'absint'
                ),
                'period' => array(
                    'type' => 'string',
                    'enum' => array('week', 'month', '3months', '6months', 'year', 'all'),
                    'default' => 'month'
                )
            )
        ));

        // Get low stock products
        register_rest_route('gym-admin/v1', '/products/low-stock', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_low_stock'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'threshold' => array(
                    'default' => 10,
                    'type' => 'integer',
                    'minimum' => 0,
                    'sanitize_callback' => 'absint'
                )
            )
        ));

        // Get product categories
        register_rest_route('gym-admin/v1', '/products/categories', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_categories'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Gym One specific products
        register_rest_route('gym-admin/v1', '/products/gym-one', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_gym_one_products'),
            'permission_callback' => array($this, 'check_permission'),
            // same args as get_products
        ));

        // Gym Two specific products
        register_rest_route('gym-admin/v1', '/products/gym-two', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_gym_two_products'),
            'permission_callback' => array($this, 'check_permission'),
            // same args as get_products
        ));

        // Monthly stats gym-one
        register_rest_route('gym-admin/v1', '/products/stats/monthly/gym-one', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_gym_one_monthly_stats'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Monthly stats gym-two
        register_rest_route('gym-admin/v1', '/products/stats/monthly/gym-two', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_gym_two_monthly_stats'),
            'permission_callback' => array($this, 'check_permission')
        ));


    }

    /**
     * Get product service instance for current gym
     */
    private function get_product_service()
    {
        return new Gym_Product_Service();
    }

    /**
     * Get all products with pagination and filtering
     */
    public function get_products($request)
    {
        $page = $request->get_param('page');
        $per_page = $request->get_param('per_page');
        $search = $request->get_param('search');
        $status = $request->get_param('status');
        $orderby = $request->get_param('orderby');
        $order = $request->get_param('order');

        $product_service = $this->get_product_service();
        $result = $product_service->get_products(array(
            'page' => $page,
            'per_page' => $per_page,
            'search' => $search,
            'status' => $status,
            'orderby' => $orderby,
            'order' => $order
        ));

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array(
            'success' => true,
            'data' => $result['products'],
            'pagination' => array(
                'page' => $page,
                'per_page' => $per_page,
                'total_items' => $result['total'],
                'total_pages' => ceil($result['total'] / $per_page)
            ),
            'gym_identifier' => $result['gym_identifier'],
            'gym_name' => Gym_Admin::get_current_gym_name(),
            'timestamp' => current_time('mysql')
        ));
    }

    /**
     * Get single product
     */
    public function get_product($request)
    {
        $product_id = $request->get_param('id');
        $product_service = $this->get_product_service();
        $product = $product_service->get_product($product_id);

        if (is_wp_error($product)) {
            return $product;
        }

        return rest_ensure_response(array(
            'success' => true,
            'data' => $product,
            'gym_identifier' => $product['gym_identifier'],
            'gym_name' => Gym_Admin::get_current_gym_name(),
            'timestamp' => current_time('mysql')
        ));
    }

    /**
     * Add new product (super_admin only)
     */
    public function add_product($request)
    {
        $product_data = array(
            'name' => $request->get_param('name'),
            'price' => floatval($request->get_param('price')),
            'description' => $request->get_param('description'),
            'quantity' => $request->get_param('quantity'),
            'images' => $request->get_param('images') ?: array(),
            'sku' => $request->get_param('sku'),
            'category' => $request->get_param('category'),
            'status' => $request->get_param('status')
        );

        $product_service = $this->get_product_service();
        $result = $product_service->add_product($product_data);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Product added successfully to ' . Gym_Admin::get_current_gym_name(),
            'data' => $result,
            'gym_identifier' => $result['gym_identifier'],
            'gym_name' => Gym_Admin::get_current_gym_name(),
            'timestamp' => current_time('mysql')
        ));
    }

    /**
     * Update product (super_admin only)
     */
    public function update_product($request)
    {
        $product_id = $request->get_param('id');

        $product_data = array();

        // Only update fields that are provided
        $updatable_fields = array('name', 'description', 'sku', 'category', 'status');
        foreach ($updatable_fields as $field) {
            if ($request->has_param($field)) {
                $product_data[$field] = $request->get_param($field);
            }
        }

        if ($request->has_param('price')) {
            $product_data['price'] = floatval($request->get_param('price'));
        }

        if ($request->has_param('quantity')) {
            $product_data['quantity'] = $request->get_param('quantity');
        }

        if ($request->has_param('images')) {
            $product_data['images'] = $request->get_param('images');
        }

        if (empty($product_data)) {
            return new WP_Error('no_update_data', 'No valid fields to update.', array('status' => 400));
        }

        $product_service = $this->get_product_service();
        $result = $product_service->update_product($product_id, $product_data);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Product updated successfully',
            'data' => $result,
            'gym_identifier' => $result['gym_identifier'],
            'gym_name' => Gym_Admin::get_current_gym_name(),
            'timestamp' => current_time('mysql')
        ));
    }

    /**
     * Delete product (super_admin only)
     */
    public function delete_product($request)
    {
        $product_id = $request->get_param('id');
        $product_service = $this->get_product_service();
        $result = $product_service->delete_product($product_id);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Product deleted successfully from ' . Gym_Admin::get_current_gym_name(),
            'gym_name' => Gym_Admin::get_current_gym_name(),
            'timestamp' => current_time('mysql')
        ));
    }

    /**
     * Record sale (admin can do this)
     */
    public function record_sale($request)
    {
        $product_id = $request->get_param('id');
        $quantity = $request->get_param('quantity');
        $note = $request->get_param('note');

        $product_service = $this->get_product_service();
        $result = $product_service->record_sale($product_id, $quantity, $note);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Sale recorded successfully',
            'data' => $result,
            'gym_identifier' => $result['gym_identifier'],
            'gym_name' => Gym_Admin::get_current_gym_name(),
            'timestamp' => current_time('mysql')
        ));
    }

    /**
     * Get monthly statistics
     */
    public function get_monthly_stats($request)
    {
        $month = $request->get_param('month') ?: current_time('Y-m');

        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return new WP_Error('invalid_month', 'Invalid month format. Use YYYY-MM.', array('status' => 400));
        }

        $product_service = $this->get_product_service();
        $stats = $product_service->get_monthly_stats($month);

        if (is_wp_error($stats)) {
            return $stats;
        }

        return rest_ensure_response(array(
            'success' => true,
            'gym_identifier' => $stats['gym_identifier'],
            'gym_name' => Gym_Admin::get_current_gym_name(),
            'data' => $stats,
            'timestamp' => current_time('mysql')
        ));
    }

    /**
     * Get weekly statistics
     */
    public function get_weekly_stats($request)
    {
        $date = $request->get_param('date') ?: current_time('Y-m-d');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return new WP_Error('invalid_date', 'Invalid date format. Use YYYY-MM-DD.', array('status' => 400));
        }

        $product_service = $this->get_product_service();
        $stats = $product_service->get_weekly_stats($date);

        if (is_wp_error($stats)) {
            return $stats;
        }

        return rest_ensure_response(array(
            'success' => true,
            'gym_identifier' => $stats['gym_identifier'],
            'gym_name' => Gym_Admin::get_current_gym_name(),
            'data' => $stats,
            'timestamp' => current_time('mysql')
        ));
    }

    /**
     * Get product analytics
     */
    public function get_product_analytics($request)
    {
        $period = $request->get_param('period');
        $product_service = $this->get_product_service();
        $analytics = $product_service->get_product_analytics($period);

        if (is_wp_error($analytics)) {
            return $analytics;
        }

        return rest_ensure_response(array(
            'success' => true,
            'gym_identifier' => $analytics['gym_identifier'],
            'gym_name' => Gym_Admin::get_current_gym_name(),
            'data' => $analytics,
            'period' => $period,
            'timestamp' => current_time('mysql')
        ));
    }

    /**
     * Get top selling products
     */
    public function get_top_selling($request)
    {
        $limit = $request->get_param('limit');
        $period = $request->get_param('period');

        $product_service = $this->get_product_service();
        $top_products = $product_service->get_top_selling($limit, $period);

        if (is_wp_error($top_products)) {
            return $top_products;
        }

        return rest_ensure_response(array(
            'success' => true,
            'gym_name' => Gym_Admin::get_current_gym_name(),
            'data' => $top_products,
            'limit' => $limit,
            'period' => $period,
            'timestamp' => current_time('mysql')
        ));
    }

    /**
     * Get low stock products
     */
    public function get_low_stock($request)
    {
        $threshold = $request->get_param('threshold');
        $product_service = $this->get_product_service();
        $products = $product_service->get_low_stock($threshold);

        if (is_wp_error($products)) {
            return $products;
        }

        return rest_ensure_response(array(
            'success' => true,
            'gym_name' => Gym_Admin::get_current_gym_name(),
            'data' => $products,
            'count' => count($products),
            'threshold' => $threshold,
            'timestamp' => current_time('mysql')
        ));
    }

    /**
     * Get product categories
     */
    public function get_categories($request)
    {
        $product_service = $this->get_product_service();
        $categories = $product_service->get_categories();

        return rest_ensure_response(array(
            'success' => true,
            'gym_name' => Gym_Admin::get_current_gym_name(),
            'data' => $categories,
            'count' => count($categories),
            'timestamp' => current_time('mysql')
        ));
    }

    /**
     * Validate image URLs
     */
    public function validate_image_urls($urls)
    {
        if (!is_array($urls)) {
            return false;
        }

        foreach ($urls as $url) {
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check basic permission (admin or super_admin)
     */
    public function check_permission($request)
    {
        return Gym_Admin::check_api_permission($request, 'manage_options');
    }

    /**
     * Check super admin permission
     */
    public function check_super_admin_permission($request)
    {
        if (!Gym_Admin::check_api_permission($request, 'manage_options')) {
            return false;
        }

        $current_admin = Gym_Admin::get_current_gym_admin();

        if (!$current_admin) {
            return new WP_Error('unauthorized', 'Authentication required.', array('status' => 401));
        }

        if ($current_admin->role !== 'super_admin') {
            return new WP_Error('forbidden', 'Super admin access required.', array('status' => 403));
        }

        return true;
    }
}