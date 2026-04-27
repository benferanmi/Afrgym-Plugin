<?php
/**
 * Plugin Name: Simple Gym Admin
 * Description: Updating recipient stats endpoint and dashboard summary stats endpoint 
 * Version: 5.5.2
 * Updates: updating the expirey so that they can unpause for expired users 
 * Author: Opafunso Benjamin
 * Text Domain: simple-gym-admin
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SIMPLE_GYM_ADMIN_VERSION', '5.2.0');
define('SIMPLE_GYM_ADMIN_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SIMPLE_GYM_ADMIN_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Load required files immediately
 */
function simple_gym_admin_load_dependencies()
{
    require_once SIMPLE_GYM_ADMIN_PLUGIN_DIR . 'includes/class-gym-activator.php';
    require_once SIMPLE_GYM_ADMIN_PLUGIN_DIR . 'includes/class-gym-admin.php';

    // Services
    require_once SIMPLE_GYM_ADMIN_PLUGIN_DIR . 'includes/services/class-email-service.php';
    require_once SIMPLE_GYM_ADMIN_PLUGIN_DIR . 'includes/services/class-membership-service.php';
    require_once SIMPLE_GYM_ADMIN_PLUGIN_DIR . 'includes/services/class-qr-service.php';
    require_once SIMPLE_GYM_ADMIN_PLUGIN_DIR . 'includes/services/class-admin-service.php';
    require_once SIMPLE_GYM_ADMIN_PLUGIN_DIR . 'includes/services/class-stats-service.php';
    require_once SIMPLE_GYM_ADMIN_PLUGIN_DIR . 'includes/services/class-product-service.php';
    require_once SIMPLE_GYM_ADMIN_PLUGIN_DIR . 'includes/services/class-gym-resend-service.php';
    require_once SIMPLE_GYM_ADMIN_PLUGIN_DIR . 'includes/services/class-gym-email-report-service.php';

    // API Endpoints
    require_once SIMPLE_GYM_ADMIN_PLUGIN_DIR . 'includes/api/class-auth-endpoints.php';
    require_once SIMPLE_GYM_ADMIN_PLUGIN_DIR . 'includes/api/class-user-endpoints.php';
    require_once SIMPLE_GYM_ADMIN_PLUGIN_DIR . 'includes/api/class-membership-endpoints.php';
    require_once SIMPLE_GYM_ADMIN_PLUGIN_DIR . 'includes/api/class-email-endpoints.php';
    require_once SIMPLE_GYM_ADMIN_PLUGIN_DIR . 'includes/api/class-qr-endpoints.php';
    require_once SIMPLE_GYM_ADMIN_PLUGIN_DIR . 'includes/api/class-stats-endpoints.php';
    require_once SIMPLE_GYM_ADMIN_PLUGIN_DIR . 'includes/api/class-media-endpoints.php';
    require_once SIMPLE_GYM_ADMIN_PLUGIN_DIR . 'includes/api/class-otp-endpoints.php';
    require_once SIMPLE_GYM_ADMIN_PLUGIN_DIR . 'includes/api/class-product-endpoints.php';
    require_once SIMPLE_GYM_ADMIN_PLUGIN_DIR . 'includes/api/class-gym-bulk-job-endpoints.php';

}

// Load dependencies immediately
simple_gym_admin_load_dependencies();
function simple_gym_admin_activate()
{
    $activator = new Gym_Activator();
    $activator->activate();
    flush_rewrite_rules();
}

function simple_gym_admin_deactivate()
{
    flush_rewrite_rules();

    // Clear scheduled monthly product reports
    wp_clear_scheduled_hook('gym_admin_monthly_product_report');
}

class Simple_Gym_Admin
{
    public function __construct()
    {
        add_action('init', array($this, 'init'));

        // Schedule session cleanup
        add_action('wp', array($this, 'schedule_cleanup'));
        add_action('gym_admin_cleanup_sessions', array($this, 'cleanup_expired_sessions'));

        // Schedule monthly product reports
        add_action('wp', array($this, 'schedule_monthly_product_reports'));
        add_action('gym_admin_monthly_product_report', array($this, 'send_monthly_product_reports'));

        // Schedule bulk email batch processing
        add_action('wp_loaded', array($this, 'schedule_bulk_email_processing'));
        add_action('gym_process_bulk_batch', array($this, 'process_bulk_batch'));
    }

    public function init()
    {
        $this->init_api();
    }

    private function init_api()
    {
        // Only initialize API endpoints if we're not in activation
        if (!wp_installing()) {
            new Gym_Auth_Endpoints();
            new Gym_User_Endpoints();
            new Gym_Membership_Endpoints();
            new Gym_Email_Endpoints();
            new Gym_QR_Endpoints();
            new Gym_Media_Endpoints();
            new Gym_Stats_Endpoints();
            new Gym_OTP_Endpoints();
            new Gym_Product_Endpoints();
            new Gym_Bulk_Job_Endpoints();
        }
    }

    public function schedule_cleanup()
    {
        if (!wp_next_scheduled('gym_admin_cleanup_sessions')) {
            wp_schedule_event(time(), 'daily', 'gym_admin_cleanup_sessions');
        }
    }

    public function cleanup_expired_sessions()
    {
        Gym_Admin_Service::cleanup_expired_sessions();
    }

    public function schedule_monthly_product_reports()
    {
        if (!wp_next_scheduled('gym_admin_monthly_product_report')) {
            // Schedule for 1st day of each month at 9:00 AM
            $first_day_next_month = strtotime('first day of next month 09:00:00');
            wp_schedule_event($first_day_next_month, 'monthly', 'gym_admin_monthly_product_report');
        }
    }

    public function send_monthly_product_reports()
    {
        // Check if product reports are enabled
        $enabled = Gym_Admin::get_setting('product_monthly_report_enabled', '1');

        if ($enabled !== '1') {
            return;
        }

        $email_service = new Gym_Email_Service();

        // Get last month
        $last_month = date('Y-m', strtotime('last month'));

        // Send separate report for each gym
        $gyms = array(
            'afrgym_one' => array(
                'name' => 'Afrgym One',
                'email_setting' => 'product_monthly_report_email_gym_one'
            ),
            'afrgym_two' => array(
                'name' => 'Afrgym Two',
                'email_setting' => 'product_monthly_report_email_gym_two'
            )
        );

        foreach ($gyms as $gym_id => $gym_info) {
            // Create product service for this specific gym
            $product_service = new Gym_Product_Service($gym_id);
            $stats = $product_service->get_monthly_stats($last_month);

            // Only send if there were sales
            if ($stats['summary']['total_units_sold'] > 0) {
                $report_email = Gym_Admin::get_setting($gym_info['email_setting'], get_option('admin_email'));
                $this->send_product_report_email($gym_id, $gym_info['name'], $last_month, $stats, $report_email);
            }
        }
    }


    private function send_product_report_email($gym_id, $gym_name, $month, $stats, $report_email)
    {
        // Load email template
        $template_path = SIMPLE_GYM_ADMIN_PLUGIN_DIR . 'templates/monthly-product-report.html';

        if (!file_exists($template_path)) {
            error_log('Product report template not found: ' . $template_path);
            return;
        }

        $template = file_get_contents($template_path);

        // Prepare variables
        $month_name = date('F Y', strtotime($month . '-01'));

        // Build top products rows
        $top_products_html = '';
        $count = 0;
        foreach ($stats['products_breakdown'] as $product) {
            if ($count >= 5)
                break;

            $bg_color = ($count % 2 == 0) ? '#ffffff' : '#f8f9fa';
            $top_products_html .= sprintf(
                '<tr style="background-color: %s;">
                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; font-size: 14px;">%s</td>
                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; text-align: center; font-size: 14px; font-weight: 600;">%d</td>
                    <td style="padding: 12px; border-bottom: 1px solid #e0e0e0; text-align: right; font-size: 14px; color: #28a745; font-weight: 600;">₦%s</td>
                </tr>',
                $bg_color,
                esc_html($product['name']),
                $product['total_sold'],
                number_format($product['total_revenue'], 2)
            );
            $count++;
        }

        // Build weekly breakdown
        $weekly_breakdown_html = '';
        $weeks = $this->group_days_by_week($stats['daily_stats']);

        foreach ($weeks as $week_num => $week_data) {
            $weekly_breakdown_html .= sprintf(
                '<tr>
                    <td style="padding: 12px; background-color: #e3f2fd; border-radius: 6px; margin-bottom: 8px;">
                        <table width="100%%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="color: #1976d2; font-weight: 600; font-size: 14px;">Week %d</td>
                                <td align="right" style="color: #666; font-size: 13px;">%d units • ₦%s</td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr><td style="height: 8px;"></td></tr>',
                $week_num,
                $week_data['total_units'],
                number_format($week_data['total_revenue'], 2)
            );
        }

        // Check for low stock (using gym-specific service)
        $product_service = new Gym_Product_Service($gym_id);
        $low_stock_products = $product_service->get_low_stock(10);
        $low_stock_html = '';

        if (!empty($low_stock_products)) {
            $low_stock_list = '';
            foreach (array_slice($low_stock_products, 0, 5) as $product) {
                $low_stock_list .= sprintf(
                    '<tr>
                        <td style="padding: 8px 12px; border-bottom: 1px solid #ffecb3;">
                            <strong style="color: #f57c00;">%s</strong> - Only %d left
                        </td>
                    </tr>',
                    esc_html($product['name']),
                    $product['quantity_left']
                );
            }

            $low_stock_html = sprintf(
                '<tr>
                    <td style="padding: 0 30px 30px 30px;">
                        <div style="background-color: #fff3e0; border-left: 4px solid #ff9800; padding: 20px; border-radius: 6px;">
                            <h3 style="margin: 0 0 15px 0; color: #f57c00; font-size: 18px;">
                                ⚠️ Low Stock Alert
                            </h3>
                            <table width="100%%" cellpadding="0" cellspacing="0" border="0">
                                %s
                            </table>
                        </div>
                    </td>
                </tr>',
                $low_stock_list
            );
        }

        // Replace placeholders
        $replacements = array(
            '{{gym_name}}' => $gym_name,
            '{{month_name}}' => $month_name,
            '{{total_revenue}}' => number_format($stats['summary']['total_revenue'], 2),
            '{{total_units_sold}}' => number_format($stats['summary']['total_units_sold']),
            '{{transaction_count}}' => number_format($stats['summary']['transaction_count']),
            '{{avg_transaction}}' => number_format($stats['summary']['average_transaction_value'], 2),
            '{{top_products_rows}}' => $top_products_html,
            '{{weekly_breakdown_rows}}' => $weekly_breakdown_html,
            '{{low_stock_section}}' => $low_stock_html,
            '{{report_date}}' => date('F j, Y')
        );

        $email_content = str_replace(array_keys($replacements), array_values($replacements), $template);

        // Send email
        $subject = sprintf('[%s] Product Sales Report - %s', $gym_name, $month_name);

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $gym_name . ' <' . get_option('admin_email') . '>'
        );

        wp_mail($report_email, $subject, $email_content, $headers);

        error_log(sprintf('Product report sent for %s (%s)', $gym_name, $month));
    }


    private function group_days_by_week($daily_stats)
    {
        $weeks = array();

        foreach ($daily_stats as $day) {
            $week_num = (int) date('W', strtotime($day['date']));

            if (!isset($weeks[$week_num])) {
                $weeks[$week_num] = array(
                    'total_units' => 0,
                    'total_revenue' => 0
                );
            }

            $weeks[$week_num]['total_units'] += (int) $day['units_sold'];
            $weeks[$week_num]['total_revenue'] += (float) $day['revenue'];
        }

        return $weeks;
    }


    public function schedule_bulk_email_processing()
    {
        if (!wp_next_scheduled('gym_process_bulk_batch_hook')) {
            wp_schedule_event(time(), 'every_minute', 'gym_process_bulk_batch_hook');
        }
    }


    public function process_bulk_batch($job_id = null, $batch_number = 0)
    {
        // This is called by WordPress cron
        // The actual batch processing is handled by Resend service
    }
}

/**
 * Helper function to get current gym admin from token
 */
function get_current_gym_admin()
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'];

        if (preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
            $token = $matches[1];
            return null;
        }
    }

    return null;
}

/**
 * Helper function to check if user has specific gym admin permission
 */
function current_gym_admin_can($capability)
{
    $admin = get_current_gym_admin();

    if (!$admin) {
        return false;
    }

    $capabilities = array(
        'super_admin' => array('*'),
        'admin' => array(
            'manage_users',
            'manage_memberships',
            'send_emails',
            'manage_qr_codes',
            'view_reports',
            'record_product_sales'
        )
    );

    if ($admin->role === 'super_admin') {
        return true;
    }

    return isset($capabilities[$admin->role]) && in_array($capability, $capabilities[$admin->role]);
}

/**
 * Add admin notices for first-time setup
 */
function simple_gym_admin_setup_notice()
{
    global $wpdb;

    if (is_admin()) {
        $gym_admins_table = $wpdb->prefix . 'gym_admins';

        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$gym_admins_table'");

        if ($table_exists) {
            $admin_count = $wpdb->get_var("SELECT COUNT(*) FROM $gym_admins_table WHERE status = 'active'");

            if ($admin_count == 0) {
                echo '<div class="notice notice-warning is-dismissible">
                    <h3>Simple Gym Admin Setup Required</h3>
                    <p><strong>No gym administrators found!</strong> You need to create a gym admin account to use the API.</p>
                    <p><strong>Version 5.2.0 Update:</strong> Now with SEPARATE product management per gym!</p>
                    <p><strong>Options:</strong></p>
                    <ol>
                        <li><strong>Use SQL:</strong> Run the SQL script provided in the plugin documentation</li>
                        <li><strong>Use PHP:</strong> Add the temporary code to functions.php (see documentation)</li>
                        <li><strong>Contact Developer:</strong> For assistance with setup</li>
                    </ol>
                    <p><em>This notice will disappear once you create your first gym admin.</em></p>
                </div>';
            }
        }
    }
}

add_action('admin_notices', 'simple_gym_admin_setup_notice');

/**
 * Plugin cleanup on uninstall
 */
function simple_gym_admin_uninstall()
{
    global $wpdb;

    wp_clear_scheduled_hook('gym_admin_cleanup_sessions');
    wp_clear_scheduled_hook('gym_admin_monthly_product_report');
}

// Register hooks
register_activation_hook(__FILE__, 'simple_gym_admin_activate');
register_deactivation_hook(__FILE__, 'simple_gym_admin_deactivate');
register_uninstall_hook(__FILE__, 'simple_gym_admin_uninstall');

// Initialize the plugin
new Simple_Gym_Admin();

/**
 * Add REST API authentication hook
 */
add_action('rest_api_init', function () {
    add_action('rest_pre_serve_request', function ($served, $result, $request, $server) {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');

        if ($request->get_method() === 'OPTIONS') {
            exit(0);
        }

        return $served;
    }, 10, 4);
});

add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['every_minute'])) {
        $schedules['every_minute'] = array(
            'interval' => 60,
            'display' => 'Every Minute'
        );
    }
    return $schedules;
});

function log_gym_admin_activity($action, $admin_id, $details = array())
{
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'action' => $action,
            'admin_id' => $admin_id,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'details' => $details
        );

        error_log('Gym Admin Activity: ' . json_encode($log_entry));
    }
}