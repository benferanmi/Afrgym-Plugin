<?php
/**
 * Statistics API endpoints for gym dashboard analytics - COMPLETE GYM ISOLATION
 */
class Gym_Stats_Endpoints
{
    private $stats_service;

    public function __construct()
    {
        $this->stats_service = new Gym_Stats_Service();
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes()
    {
        // Main dashboard statistics (automatically filtered by logged-in gym)
        register_rest_route('gym-admin/v1', '/stats/dashboard', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_dashboard_stats'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Gym One specific dashboard
        register_rest_route('gym-admin/v1', '/stats/dashboard/gym-one', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_gym_one_stats'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Gym Two specific dashboard
        register_rest_route('gym-admin/v1', '/stats/dashboard/gym-two', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_gym_two_stats'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Combined stats for both gyms (Super Admin only)
        register_rest_route('gym-admin/v1', '/stats/combined', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_combined_stats'),
            'permission_callback' => array($this, 'check_super_admin_permission')
        ));

        // Daily statistics for specific date
        register_rest_route('gym-admin/v1', '/stats/daily', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_daily_stats'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'date' => array(
                    'type' => 'string',
                    'format' => 'date',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description' => 'Date in YYYY-MM-DD format (default: today)'
                )
            )
        ));

        // Monthly statistics
        register_rest_route('gym-admin/v1', '/stats/monthly', array(
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

        // Date range statistics
        register_rest_route('gym-admin/v1', '/stats/range', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_range_stats'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'start_date' => array(
                    'required' => true,
                    'type' => 'string',
                    'format' => 'date',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description' => 'Start date in YYYY-MM-DD format'
                ),
                'end_date' => array(
                    'required' => true,
                    'type' => 'string',
                    'format' => 'date',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description' => 'End date in YYYY-MM-DD format'
                )
            )
        ));

        // Membership breakdown statistics
        register_rest_route('gym-admin/v1', '/stats/memberships', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_membership_stats'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Growth trends (last 6 months)
        register_rest_route('gym-admin/v1', '/stats/growth', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_growth_trends'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Recent activities feed
        register_rest_route('gym-admin/v1', '/stats/activities', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_recent_activities'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'limit' => array(
                    'default' => 10,
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                    'sanitize_callback' => 'absint'
                )
            )
        ));

        // Summary overview (quick stats for widgets)
        register_rest_route('gym-admin/v1', '/stats/summary', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_summary_stats'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Pause-specific statistics
        register_rest_route('gym-admin/v1', '/stats/pauses', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_pause_stats'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Admin activity statistics
        register_rest_route('gym-admin/v1', '/stats/admin-activity', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_admin_activity_stats'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Expiring memberships statistics
        register_rest_route('gym-admin/v1', '/stats/expiring', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_expiring_stats'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'days' => array(
                    'default' => 7,
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 90,
                    'sanitize_callback' => 'absint'
                )
            )
        ));
    }

    /**
     * Get comprehensive dashboard statistics (automatically filtered by current gym)
     */
    public function get_dashboard_stats($request)
    {
        try {
            $gym_identifier = $this->get_current_gym_identifier();
            $stats = $this->stats_service->get_dashboard_stats($gym_identifier);

            return rest_ensure_response(array(
                'success' => true,
                'gym_identifier' => $gym_identifier,
                'gym_name' => Gym_Admin::get_current_gym_name(),
                'data' => $stats,
                'timestamp' => current_time('mysql'),
                'timezone' => wp_timezone_string(),
                'note' => 'All statistics are filtered for ' . Gym_Admin::get_current_gym_name()
            ));

        } catch (Exception $e) {
            error_log('Dashboard stats error: ' . $e->getMessage());
            return new WP_Error('stats_error', 'Failed to retrieve dashboard statistics.', array('status' => 500));
        }
    }

    /**
     * Get Gym One specific stats
     */
    public function get_gym_one_stats($request)
    {
        try {
            $stats = $this->stats_service->get_dashboard_stats('afrgym_one');

            return rest_ensure_response(array(
                'success' => true,
                'gym_identifier' => 'afrgym_one',
                'gym_name' => 'Afrgym One',
                'data' => $stats,
                'timestamp' => current_time('mysql')
            ));

        } catch (Exception $e) {
            error_log('Gym One stats error: ' . $e->getMessage());
            return new WP_Error('stats_error', 'Failed to retrieve Gym One statistics.', array('status' => 500));
        }
    }

    /**
     * Get Gym Two specific stats
     */
    public function get_gym_two_stats($request)
    {
        try {
            $stats = $this->stats_service->get_dashboard_stats('afrgym_two');

            return rest_ensure_response(array(
                'success' => true,
                'gym_identifier' => 'afrgym_two',
                'gym_name' => 'Afrgym Two',
                'data' => $stats,
                'timestamp' => current_time('mysql')
            ));

        } catch (Exception $e) {
            error_log('Gym Two stats error: ' . $e->getMessage());
            return new WP_Error('stats_error', 'Failed to retrieve Gym Two statistics.', array('status' => 500));
        }
    }

    /**
     * Get combined statistics for both gyms (Super Admin only)
     */
    public function get_combined_stats($request)
    {
        try {
            $combined_stats = $this->stats_service->get_combined_stats();

            return rest_ensure_response(array(
                'success' => true,
                'message' => 'Combined statistics for both gyms',
                'data' => $combined_stats,
                'timestamp' => current_time('mysql')
            ));

        } catch (Exception $e) {
            error_log('Combined stats error: ' . $e->getMessage());
            return new WP_Error('stats_error', 'Failed to retrieve combined statistics.', array('status' => 500));
        }
    }

    /**
     * Get daily statistics
     */
    public function get_daily_stats($request)
    {
        $date = $request->get_param('date') ?: current_time('Y-m-d');

        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
            return new WP_Error('invalid_date', 'Invalid date format. Use YYYY-MM-DD.', array('status' => 400));
        }

        try {
            $gym_identifier = $this->get_current_gym_identifier();
            $stats = $this->stats_service->get_daily_stats($date, $gym_identifier);

            return rest_ensure_response(array(
                'success' => true,
                'gym_identifier' => $gym_identifier,
                'gym_name' => Gym_Admin::get_current_gym_name(),
                'data' => $stats,
                'timestamp' => current_time('mysql')
            ));

        } catch (Exception $e) {
            error_log('Daily stats error: ' . $e->getMessage());
            return new WP_Error('stats_error', 'Failed to retrieve daily statistics.', array('status' => 500));
        }
    }

    /**
     * Get monthly statistics
     */
    public function get_monthly_stats($request)
    {
        $month = $request->get_param('month') ?: current_time('Y-m');

        // Validate month format
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return new WP_Error('invalid_month', 'Invalid month format. Use YYYY-MM.', array('status' => 400));
        }

        try {
            $gym_identifier = $this->get_current_gym_identifier();
            $stats = $this->stats_service->get_monthly_stats($month, $gym_identifier);

            return rest_ensure_response(array(
                'success' => true,
                'gym_identifier' => $gym_identifier,
                'gym_name' => Gym_Admin::get_current_gym_name(),
                'data' => $stats,
                'timestamp' => current_time('mysql')
            ));

        } catch (Exception $e) {
            error_log('Monthly stats error: ' . $e->getMessage());
            return new WP_Error('stats_error', 'Failed to retrieve monthly statistics.', array('status' => 500));
        }
    }

    /**
     * Get date range statistics
     */
    public function get_range_stats($request)
    {
        $start_date = $request->get_param('start_date');
        $end_date = $request->get_param('end_date');

        // Validate date formats
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !strtotime($start_date)) {
            return new WP_Error('invalid_start_date', 'Invalid start date format. Use YYYY-MM-DD.', array('status' => 400));
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date) || !strtotime($end_date)) {
            return new WP_Error('invalid_end_date', 'Invalid end date format. Use YYYY-MM-DD.', array('status' => 400));
        }

        // Validate date range
        if (strtotime($end_date) < strtotime($start_date)) {
            return new WP_Error('invalid_range', 'End date must be after start date.', array('status' => 400));
        }

        // Limit to maximum 1 year range
        $days_diff = (strtotime($end_date) - strtotime($start_date)) / 86400;
        if ($days_diff > 365) {
            return new WP_Error('range_too_large', 'Date range cannot exceed 365 days.', array('status' => 400));
        }

        try {
            $gym_identifier = $this->get_current_gym_identifier();
            $stats = $this->stats_service->get_date_range_stats($start_date, $end_date, $gym_identifier);

            return rest_ensure_response(array(
                'success' => true,
                'gym_identifier' => $gym_identifier,
                'gym_name' => Gym_Admin::get_current_gym_name(),
                'data' => $stats,
                'timestamp' => current_time('mysql')
            ));

        } catch (Exception $e) {
            error_log('Range stats error: ' . $e->getMessage());
            return new WP_Error('stats_error', 'Failed to retrieve range statistics.', array('status' => 500));
        }
    }

    /**
     * Get membership breakdown statistics
     */
    public function get_membership_stats($request)
    {
        try {
            $gym_identifier = $this->get_current_gym_identifier();
            $stats = $this->stats_service->get_membership_breakdown($gym_identifier);

            return rest_ensure_response(array(
                'success' => true,
                'gym_identifier' => $gym_identifier,
                'gym_name' => Gym_Admin::get_current_gym_name(),
                'data' => $stats,
                'timestamp' => current_time('mysql')
            ));

        } catch (Exception $e) {
            error_log('Membership stats error: ' . $e->getMessage());
            return new WP_Error('stats_error', 'Failed to retrieve membership statistics.', array('status' => 500));
        }
    }

    /**
     * Get growth trends
     */
    public function get_growth_trends($request)
    {
        try {
            $gym_identifier = $this->get_current_gym_identifier();
            $trends = $this->stats_service->get_growth_trends($gym_identifier);

            return rest_ensure_response(array(
                'success' => true,
                'gym_identifier' => $gym_identifier,
                'gym_name' => Gym_Admin::get_current_gym_name(),
                'data' => $trends,
                'timestamp' => current_time('mysql')
            ));

        } catch (Exception $e) {
            error_log('Growth trends error: ' . $e->getMessage());
            return new WP_Error('stats_error', 'Failed to retrieve growth trends.', array('status' => 500));
        }
    }

    /**
     * Get recent activities
     */
    public function get_recent_activities($request)
    {
        $limit = $request->get_param('limit');

        try {
            $gym_identifier = $this->get_current_gym_identifier();
            $activities = $this->stats_service->get_recent_activities($limit, $gym_identifier);

            return rest_ensure_response(array(
                'success' => true,
                'gym_identifier' => $gym_identifier,
                'gym_name' => Gym_Admin::get_current_gym_name(),
                'data' => $activities,
                'count' => count($activities),
                'timestamp' => current_time('mysql')
            ));

        } catch (Exception $e) {
            error_log('Recent activities error: ' . $e->getMessage());
            return new WP_Error('stats_error', 'Failed to retrieve recent activities.', array('status' => 500));
        }
    }

    /**
     * Get summary statistics (for widgets)
     */
    public function get_summary_stats($request)
    {
        try {
            $gym_identifier = $this->get_current_gym_identifier();
            $stats = $this->stats_service->get_summary_stats($gym_identifier);

            return rest_ensure_response(array(
                'success' => true,
                'gym_identifier' => $gym_identifier,
                'gym_name' => Gym_Admin::get_current_gym_name(),
                'data' => $stats,
                'timestamp' => current_time('mysql')
            ));

        } catch (Exception $e) {
            error_log('Summary stats error: ' . $e->getMessage());
            return new WP_Error('stats_error', 'Failed to retrieve summary statistics.', array('status' => 500));
        }
    }

    /**
     * Get pause statistics
     */
    public function get_pause_stats($request)
    {
        try {
            $gym_identifier = $this->get_current_gym_identifier();
            $stats = $this->stats_service->get_pause_statistics($gym_identifier);

            return rest_ensure_response(array(
                'success' => true,
                'gym_identifier' => $gym_identifier,
                'gym_name' => Gym_Admin::get_current_gym_name(),
                'data' => $stats,
                'timestamp' => current_time('mysql')
            ));

        } catch (Exception $e) {
            error_log('Pause stats error: ' . $e->getMessage());
            return new WP_Error('stats_error', 'Failed to retrieve pause statistics.', array('status' => 500));
        }
    }

    /**
     * Get admin activity statistics
     */
    public function get_admin_activity_stats($request)
    {
        try {
            $gym_identifier = $this->get_current_gym_identifier();
            $stats = $this->stats_service->get_admin_activity_stats($gym_identifier);

            return rest_ensure_response(array(
                'success' => true,
                'gym_identifier' => $gym_identifier,
                'gym_name' => Gym_Admin::get_current_gym_name(),
                'data' => $stats,
                'timestamp' => current_time('mysql')
            ));

        } catch (Exception $e) {
            error_log('Admin activity stats error: ' . $e->getMessage());
            return new WP_Error('stats_error', 'Failed to retrieve admin activity statistics.', array('status' => 500));
        }
    }

    /**
     * Get expiring memberships statistics
     */
    public function get_expiring_stats($request)
    {
        $days = $request->get_param('days');

        try {
            $gym_identifier = $this->get_current_gym_identifier();
            $stats = $this->stats_service->get_expiring_memberships_summary($gym_identifier);

            // If specific days requested, get custom list
            if ($days != 7) {
                $membership_service = new Gym_Membership_Service();
                $expiring_custom = $membership_service->get_expiring_memberships($days);

                // Filter by gym user IDs
                global $wpdb;
                $notes_table = $wpdb->prefix . 'gym_user_notes';

                $user_ids_query = "
                    SELECT DISTINCT n1.user_id
                    FROM $notes_table n1
                    INNER JOIN (
                        SELECT user_id, MIN(id) as first_note_id
                        FROM $notes_table
                        GROUP BY user_id
                    ) n2 ON n1.id = n2.first_note_id
                    WHERE n1.gym_identifier = %s
                ";

                $gym_user_ids = $wpdb->get_col($wpdb->prepare($user_ids_query, $gym_identifier));

                // Filter expiring list to only gym users
                $filtered_expiring = array_filter($expiring_custom, function ($member) use ($gym_user_ids) {
                    return in_array($member['user_id'], $gym_user_ids);
                });

                $stats['expiring_in_' . $days . '_days'] = count($filtered_expiring);
                $stats['custom_days_list'] = array_slice($filtered_expiring, 0, 10);
            }

            return rest_ensure_response(array(
                'success' => true,
                'gym_identifier' => $gym_identifier,
                'gym_name' => Gym_Admin::get_current_gym_name(),
                'data' => $stats,
                'days_checked' => $days,
                'timestamp' => current_time('mysql')
            ));

        } catch (Exception $e) {
            error_log('Expiring stats error: ' . $e->getMessage());
            return new WP_Error('stats_error', 'Failed to retrieve expiring statistics.', array('status' => 500));
        }
    }

    /**
     * Helper: Get current gym identifier from logged in admin
     */
    private function get_current_gym_identifier()
    {
        $current_admin = Gym_Admin::get_current_gym_admin();

        if ($current_admin && isset($current_admin->gym_identifier)) {
            return $current_admin->gym_identifier;
        }

        // Fallback to gym_one if not found
        return 'afrgym_one';
    }

    /**
     * Check permissions for stats endpoints
     */
    public function check_permission($request)
    {
        return Gym_Admin::check_api_permission($request, 'manage_options');
    }

    /**
     * Check super admin permissions for combined stats
     */
    public function check_super_admin_permission($request)
    {
        // First check basic permission
        if (!Gym_Admin::check_api_permission($request, 'manage_options')) {
            return false;
        }

        // Then check if admin has super_admin role
        $current_admin = Gym_Admin::get_current_gym_admin();

        if (!$current_admin) {
            return false;
        }

        // Only super_admin role can access combined stats
        return isset($current_admin->role) && $current_admin->role === 'super_admin';
    }
}