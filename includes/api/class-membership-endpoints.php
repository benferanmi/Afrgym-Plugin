<?php
/**
 * Membership management API endpoints - Updated with pricing functionality
 * BUG FIX APPLIED: Fix 7 — enddate gate added to visit-based stats queries
 */
class Gym_Membership_Endpoints
{

    private $membership_service;

    public function __construct()
    {
        $this->membership_service = new Gym_Membership_Service();
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes()
    {
        // List all memberships
        register_rest_route('gym-admin/v1', '/memberships', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_memberships'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Assign membership to user
        register_rest_route('gym-admin/v1', '/users/(?P<id>\d+)/membership', array(
            'methods' => 'POST',
            'callback' => array($this, 'assign_membership'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'level_id' => array(
                    'required' => true,
                    'type' => 'integer'
                ),
                'start_date' => array(
                    'type' => 'string'
                ),
                'end_date' => array(
                    'type' => 'string'
                )
            )
        ));

        // Update user membership
        register_rest_route('gym-admin/v1', '/users/(?P<id>\d+)/membership', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_membership'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Update membership level pricing
        register_rest_route('gym-admin/v1', '/memberships/(?P<level_id>\d+)/pricing', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_membership_pricing'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'initial_payment' => array(
                    'type' => 'number',
                    'minimum' => 0
                ),
                'billing_amount' => array(
                    'type' => 'number',
                    'minimum' => 0
                ),
                'trial_amount' => array(
                    'type' => 'number',
                    'minimum' => 0
                ),
                'cycle_number' => array(
                    'type' => 'integer',
                    'minimum' => 0
                ),
                'cycle_period' => array(
                    'type' => 'string',
                    'enum' => array('', 'Day', 'Week', 'Month', 'Year')
                ),
                'billing_limit' => array(
                    'type' => 'integer',
                    'minimum' => 0
                ),
                'trial_limit' => array(
                    'type' => 'integer',
                    'minimum' => 0
                )
            )
        ));

        // Get expiring memberships
        register_rest_route('gym-admin/v1', '/memberships/expiring', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_expiring_memberships'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'days' => array(
                    'default' => 7,
                    'type' => 'integer'
                )
            )
        ));

        // Get membership statistics
        register_rest_route('gym-admin/v1', '/memberships/stats', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_membership_stats'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Cancel user membership
        register_rest_route('gym-admin/v1', '/users/(?P<id>\d+)/membership/cancel', array(
            'methods' => 'POST',
            'callback' => array($this, 'cancel_membership'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Pause user membership
        register_rest_route('gym-admin/v1', '/memberships/(?P<user_id>\d+)/pause', array(
            'methods' => 'POST',
            'callback' => array($this, 'pause_membership'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'reason' => array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field'
                )
            )
        ));

        // Unpause user membership
        register_rest_route('gym-admin/v1', '/memberships/(?P<user_id>\d+)/unpause', array(
            'methods' => 'POST',
            'callback' => array($this, 'unpause_membership'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Get membership pause status
        register_rest_route('gym-admin/v1', '/memberships/(?P<user_id>\d+)/pause-status', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_pause_status'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Get visit-based membership statistics
        register_rest_route('gym-admin/v1', '/memberships/visit-stats', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_visit_based_stats'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Get users with low visit counts
        register_rest_route('gym-admin/v1', '/memberships/low-visits', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_low_visit_users'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'threshold' => array(
                    'default' => 3,
                    'type' => 'integer',
                    'minimum' => 1
                )
            )
        ));
    }

    public function get_memberships($request)
    {
        $levels = $this->membership_service->get_all_membership_levels();
        $stats = $this->membership_service->get_membership_statistics();

        return rest_ensure_response(array(
            'membership_levels' => $levels,
            'statistics' => $stats
        ));
    }

    public function assign_membership($request)
    {
        $user_id = $request->get_param('id');
        $level_id = $request->get_param('level_id');
        $start_date = $request->get_param('start_date');
        $end_date = $request->get_param('end_date');

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return new WP_Error('user_not_found', 'User not found.', array('status' => 404));
        }

        // Validate dates if provided
        if ($start_date && !strtotime($start_date)) {
            return new WP_Error('invalid_start_date', 'Invalid start date format.', array('status' => 400));
        }

        if ($end_date && !strtotime($end_date)) {
            return new WP_Error('invalid_end_date', 'Invalid end date format.', array('status' => 400));
        }

        $result = $this->membership_service->assign_membership($user_id, $level_id, $start_date, $end_date);

        if (is_wp_error($result)) {
            return $result;
        }

        // 🆕 ADD: Track which gym assigned the membership
        $gym_admin = Gym_Admin::get_current_gym_admin_full();
        if ($gym_admin) {
            $gym_name = $gym_admin['gym_name'];
            $admin_name = $gym_admin['admin']->first_name . ' ' . $gym_admin['admin']->last_name;
            $note = "Membership assigned by {$admin_name} ({$gym_name})";
            Gym_Admin::add_user_note($user_id, $note);
        }

        return rest_ensure_response($result);
    }

    /**
     * FIXED: Update existing membership - handles expired, active, and no membership cases
     */
    public function update_membership($user_id, $level_id, $start_date = null, $end_date = null)
    {
        if (!function_exists('pmpro_changeMembershipLevel')) {
            return new WP_Error('pmpro_not_active', 'Paid Memberships Pro is not active.');
        }

        // Get current membership first
        $current_membership = $this->get_user_membership($user_id);

        // FIXED: Allow updates for ANY membership status (active, expired, or no membership)
        // No need to restrict based on active status

        // Validate the new level
        $level = pmpro_getLevel($level_id);
        if (!$level) {
            return new WP_Error('invalid_level', 'Invalid membership level.');
        }

        // Debug logging
        error_log("=== MEMBERSHIP UPDATE DEBUG ===");
        error_log("User ID: $user_id");
        error_log("Current Level ID: " . ($current_membership['level_id'] ?? 'none'));
        error_log("Current Status: " . ($current_membership['status'] ?? 'none'));
        error_log("Current Is Active: " . ($current_membership['is_active'] ? 'true' : 'false'));
        error_log("New Level ID: $level_id");
        error_log("Start Date: $start_date");
        error_log("End Date: $end_date");

        global $wpdb;
        $table = $wpdb->prefix . 'pmpro_memberships_users';

        // Start transaction for atomic operation
        $wpdb->query('START TRANSACTION');

        try {
            // Prepare the dates for new membership
            $formatted_start_date = null;
            $formatted_end_date = null;

            if ($start_date) {
                $formatted_start_date = date('Y-m-d H:i:s', strtotime($start_date));
            } else {
                $formatted_start_date = current_time('mysql');
            }

            if ($end_date) {
                $formatted_end_date = date('Y-m-d H:i:s', strtotime($end_date));
            } else {
                // Calculate expiry based on level settings if no end_date provided
                if (!empty($level->expiration_number) && !empty($level->expiration_period)) {
                    $expiry_timestamp = strtotime("+{$level->expiration_number} {$level->expiration_period}", strtotime($formatted_start_date));
                    $formatted_end_date = date('Y-m-d H:i:s', $expiry_timestamp);
                }
            }

            // FIXED: Determine update strategy based on current membership status
            $has_existing_membership = ($current_membership['status'] !== 'no_membership');
            $is_same_level = $has_existing_membership && ($current_membership['level_id'] == $level_id);

            error_log("Has existing membership: " . ($has_existing_membership ? 'YES' : 'NO'));
            error_log("Is same level update: " . ($is_same_level ? 'YES' : 'NO'));

            if ($is_same_level && $current_membership['is_active']) {
                // SAME LEVEL UPDATE for ACTIVE membership: Just update the dates
                error_log("Performing SAME LEVEL update on ACTIVE membership - updating dates only");

                $update_data = array(
                    'startdate' => $formatted_start_date,
                    'enddate' => $formatted_end_date,
                    'status' => 'active', // Ensure it stays active
                    'modified' => current_time('mysql')
                );

                $update_result = $wpdb->update(
                    $table,
                    $update_data,
                    array(
                        'user_id' => $user_id,
                        'membership_id' => $level_id,
                        'status' => 'active'
                    ),
                    array('%s', '%s', '%s', '%s'),
                    array('%d', '%d', '%s')
                );

                if ($update_result === false) {
                    throw new Exception("Failed to update same-level membership dates: " . $wpdb->last_error);
                }

                error_log("Same level update successful - updated $update_result row(s)");

            } else {
                // DIFFERENT LEVEL UPDATE or EXPIRED/NO MEMBERSHIP: Cancel current and create new
                if ($is_same_level) {
                    error_log("Performing SAME LEVEL update on EXPIRED membership - creating new active record");
                } else {
                    error_log("Performing DIFFERENT LEVEL update - cancel current and create new");
                }

                // Step 1: Cancel/expire ALL current active memberships for this user
                if ($has_existing_membership) {
                    $cancel_result = $wpdb->update(
                        $table,
                        array(
                            'status' => 'cancelled',
                            'enddate' => current_time('mysql')
                        ),
                        array(
                            'user_id' => $user_id,
                            'status' => 'active'
                        ),
                        array('%s', '%s'),
                        array('%d', '%s')
                    );

                    // Don't fail if no active records to cancel (user might have expired membership)
                    error_log("Cancelled/expired existing memberships for user {$user_id} - affected rows: " . ($cancel_result === false ? 'error' : $cancel_result));
                }

                // Step 2: Create new membership record
                $insert_data = array(
                    'user_id' => $user_id,
                    'membership_id' => $level_id,
                    'code_id' => 0,
                    'initial_payment' => $level->initial_payment ?? 0,
                    'billing_amount' => $level->billing_amount ?? 0,
                    'cycle_number' => $level->cycle_number ?? 0,
                    'cycle_period' => $level->cycle_period ?? '',
                    'billing_limit' => $level->billing_limit ?? 0,
                    'trial_amount' => $level->trial_amount ?? 0,
                    'trial_limit' => $level->trial_limit ?? 0,
                    'startdate' => $formatted_start_date,
                    'enddate' => $formatted_end_date,
                    'status' => 'active',
                    'modified' => current_time('mysql')
                );

                $insert_result = $wpdb->insert($table, $insert_data);

                if ($insert_result === false) {
                    throw new Exception("Failed to insert new membership for user {$user_id}: " . $wpdb->last_error);
                }

                error_log("Created new membership {$level_id} for user {$user_id}");
            }

            // Step 3: Update WordPress user meta (PMPro uses this for caching)
            update_user_meta($user_id, 'pmpro_membership_level_ID', $level_id);

            // Commit transaction
            $wpdb->query('COMMIT');

        } catch (Exception $e) {
            // Rollback on any error
            $wpdb->query('ROLLBACK');
            error_log("Transaction failed: " . $e->getMessage());
            return new WP_Error('update_failed', $e->getMessage());
        }

        // Clear any PMPro caches
        if (function_exists('pmpro_delete_user_membership_level_cache')) {
            pmpro_delete_user_membership_level_cache($user_id);
        }

        // Clear WordPress object cache
        wp_cache_delete($user_id, 'users');
        wp_cache_delete($user_id, 'user_meta');
        wp_cache_delete("pmpro_membership_level_for_user_" . $user_id, 'pmpro');

        // Determine update type for logging
        $update_type = 'new_membership';
        if ($has_existing_membership) {
            if ($is_same_level) {
                $update_type = $current_membership['is_active'] ? 'same_level_date_update' : 'same_level_reactivation';
            } else {
                $update_type = 'level_change';
            }
        }

        // Log the membership change
        $log_message = '';
        if ($update_type === 'same_level_date_update') {
            $log_message = "Membership dates updated for {$level->name}";
        } elseif ($update_type === 'same_level_reactivation') {
            $log_message = "Membership reactivated for {$level->name}";
        } elseif ($update_type === 'level_change') {
            $log_message = "Membership updated from {$current_membership['level_name']} to {$level->name}";
        } else {
            $log_message = "New membership assigned: {$level->name}";
        }

        if ($formatted_end_date) {
            $log_message .= " (expires: " . date('Y-m-d', strtotime($formatted_end_date)) . ")";
        }

        Gym_Admin::add_user_note($user_id, $log_message);

        error_log("Successfully updated membership for user {$user_id} to level {$level_id}");

        // Small delay to ensure database consistency
        usleep(100000); // 0.1 second delay

        // Get fresh membership data directly from database
        $fresh_membership = $this->get_user_membership_fresh($user_id);

        // Verify the update actually worked
        if ($fresh_membership['level_id'] != $level_id) {
            error_log("Verification failed: Expected level {$level_id}, got {$fresh_membership['level_id']}");
            return new WP_Error('update_verification_failed', 'Membership update could not be verified.');
        }

        error_log("Successfully updated and verified membership for user {$user_id} to level {$level_id}");

        // Return the updated membership
        return array(
            'success' => true,
            'message' => 'Membership updated successfully',
            'membership' => $fresh_membership,
            'update_type' => $update_type
        );
    }

    /**
     * NEW METHOD: Update membership level pricing
     */
    public function update_membership_pricing($request)
    {
        $level_id = $request->get_param('level_id');
        $params = $request->get_json_params();

        // Log the pricing update request
        error_log('Update membership pricing called for level: ' . $level_id);
        error_log('Pricing params received: ' . print_r($params, true));

        // Validate that at least one pricing field is provided
        $pricing_fields = array(
            'initial_payment',
            'billing_amount',
            'trial_amount',
            'cycle_number',
            'cycle_period',
            'billing_limit',
            'trial_limit'
        );

        $has_pricing_data = false;
        foreach ($pricing_fields as $field) {
            if (isset($params[$field])) {
                $has_pricing_data = true;
                break;
            }
        }

        if (!$has_pricing_data) {
            return new WP_Error('no_pricing_data', 'At least one pricing field must be provided.', array('status' => 400));
        }

        // Call the membership service pricing update method
        $result = $this->membership_service->update_membership_price($level_id, $params);

        if (is_wp_error($result)) {
            error_log('Update membership pricing error: ' . $result->get_error_message());
            return $result;
        }

        error_log('Pricing update successful for level: ' . $level_id);

        return rest_ensure_response($result);
    }

    public function get_expiring_memberships($request)
    {
        $days = $request->get_param('days');

        if ($days < 1 || $days > 365) {
            return new WP_Error('invalid_days', 'Days must be between 1 and 365.', array('status' => 400));
        }

        $expiring_members = $this->membership_service->get_expiring_memberships($days);

        return rest_ensure_response(array(
            'expiring_members' => $expiring_members,
            'count' => count($expiring_members),
            'days_ahead' => $days
        ));
    }

    public function get_membership_stats($request)
    {
        $stats = $this->membership_service->get_membership_statistics();

        return rest_ensure_response($stats);
    }

    public function cancel_membership($request)
    {
        $user_id = $request->get_param('id');

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return new WP_Error('user_not_found', 'User not found.', array('status' => 404));
        }

        $current_membership = $this->membership_service->get_user_membership($user_id);
        if (!$current_membership['is_active']) {
            return new WP_Error('no_active_membership', 'User has no active membership to cancel.', array('status' => 400));
        }

        $result = $this->membership_service->cancel_membership($user_id);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public function pause_membership($request)
    {
        $user_id = $request->get_param('user_id');
        $reason = $request->get_param('reason');

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return new WP_Error('user_not_found', 'User not found.', array('status' => 404));
        }

        // Check if user has active membership
        $current_membership = $this->membership_service->get_user_membership($user_id);
        if (!$current_membership['is_active']) {
            return new WP_Error('no_active_membership', 'User has no active membership to pause.', array('status' => 400));
        }

        // Check if membership is already paused
        $pause_status = $this->membership_service->get_membership_pause_status($user_id);
        if ($pause_status['is_paused']) {
            return new WP_Error('already_paused', 'Membership is already paused.', array('status' => 400));
        }

        $result = $this->membership_service->pause_membership($user_id, $reason);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    // Unpause membership endpoint
    public function unpause_membership($request)
    {
        $user_id = $request->get_param('user_id');

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return new WP_Error('user_not_found', 'User not found.', array('status' => 404));
        }

        // Check if membership is actually paused
        $pause_status = $this->membership_service->get_membership_pause_status($user_id);
        if (!$pause_status['is_paused']) {
            return new WP_Error('not_paused', 'Membership is not currently paused.', array('status' => 400));
        }

        $result = $this->membership_service->unpause_membership($user_id);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    // Get pause status endpoint
    public function get_pause_status($request)
    {
        $user_id = $request->get_param('user_id');

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return new WP_Error('user_not_found', 'User not found.', array('status' => 404));
        }

        $pause_status = $this->membership_service->get_membership_pause_status($user_id);

        return rest_ensure_response($pause_status);
    }

    /**
     * Get visit-based membership statistics
     *
     * FIX 7: Added enddate gate to the DB query — expired members with stale
     * status='active' rows were being counted as active visit users.
     */
    public function get_visit_based_stats($request)
    {
        global $wpdb;

        // FIX 7: Added enddate gate so expired rows with status='active' are excluded.
        // Also selecting enddate so we can pass it to get_user_visit_info().
        $visit_based_users = $wpdb->get_results(
            "SELECT DISTINCT mu.user_id, ml.name as level_name, ml.id as level_id, mu.enddate
             FROM {$wpdb->prefix}pmpro_memberships_users mu
             JOIN {$wpdb->prefix}pmpro_membership_levels ml ON mu.membership_id = ml.id
             WHERE mu.status = 'active' AND ml.id IN (12, 13)
             AND (mu.enddate IS NULL OR mu.enddate = '0000-00-00 00:00:00' OR mu.enddate > NOW())"
        );

        $total_visit_users = count($visit_based_users);
        $active_this_cycle = 0;
        $exhausted_visits = 0;
        $total_visits_used = 0;
        $total_visits_remaining = 0;

        foreach ($visit_based_users as $user_data) {
            // FIX 7 (continued): Pass expiry_date to get_user_visit_info() so the
            // service-layer expiry guard (Fix 3) also applies here.
            $expiry_date_val = (!empty($user_data->enddate) && $user_data->enddate !== '0000-00-00 00:00:00')
                ? $user_data->enddate
                : null;
            $visit_info = $this->membership_service->get_user_visit_info($user_data->user_id, null, $expiry_date_val);

            if ($visit_info['is_current_cycle']) {
                $active_this_cycle++;
                $total_visits_used += $visit_info['used_visits'];
                $total_visits_remaining += $visit_info['remaining_visits'];

                if ($visit_info['remaining_visits'] <= 0) {
                    $exhausted_visits++;
                }
            }
        }

        return rest_ensure_response(array(
            'success' => true,
            'visit_based_stats' => array(
                'total_visit_based_users' => $total_visit_users,
                'active_in_current_cycle' => $active_this_cycle,
                'users_with_exhausted_visits' => $exhausted_visits,
                'total_visits_used' => $total_visits_used,
                'total_visits_remaining' => $total_visits_remaining,
                'average_visits_used' => $active_this_cycle > 0 ? round($total_visits_used / $active_this_cycle, 2) : 0,
                'average_visits_remaining' => $active_this_cycle > 0 ? round($total_visits_remaining / $active_this_cycle, 2) : 0
            )
        ));
    }

    /**
     * Get users with low visit counts
     *
     * FIX 7: Added enddate gate to the DB query — same issue as get_visit_based_stats().
     */
    public function get_low_visit_users($request)
    {
        $threshold = $request->get_param('threshold');

        global $wpdb;

        // FIX 7: Added enddate gate so expired rows with status='active' are excluded.
        // Also selecting enddate so we can pass it to get_user_visit_info().
        $visit_based_users = $wpdb->get_results(
            "SELECT mu.user_id, ml.name as level_name, ml.id as level_id, u.display_name, u.user_email, mu.enddate
             FROM {$wpdb->prefix}pmpro_memberships_users mu
             JOIN {$wpdb->prefix}pmpro_membership_levels ml ON mu.membership_id = ml.id
             JOIN {$wpdb->users} u ON mu.user_id = u.ID
             WHERE mu.status = 'active' AND ml.id IN (12, 13)
             AND (mu.enddate IS NULL OR mu.enddate = '0000-00-00 00:00:00' OR mu.enddate > NOW())"
        );

        $low_visit_users = array();

        foreach ($visit_based_users as $user_data) {
            // FIX 7 (continued): Pass expiry_date to get_user_visit_info() so the
            // service-layer expiry guard (Fix 3) also applies here.
            $expiry_date_val = (!empty($user_data->enddate) && $user_data->enddate !== '0000-00-00 00:00:00')
                ? $user_data->enddate
                : null;
            $visit_info = $this->membership_service->get_user_visit_info($user_data->user_id, null, $expiry_date_val);

            if ($visit_info['is_current_cycle'] && $visit_info['remaining_visits'] <= $threshold) {
                $low_visit_users[] = array(
                    'user_id' => $user_data->user_id,
                    'display_name' => $user_data->display_name,
                    'email' => $user_data->user_email,
                    'membership_level' => $user_data->level_name,
                    'remaining_visits' => $visit_info['remaining_visits'],
                    'used_visits' => $visit_info['used_visits'],
                    'total_visits' => $visit_info['total_visits'],
                    'next_reset_date' => $visit_info['next_reset_date'],
                    'is_exhausted' => $visit_info['remaining_visits'] <= 0
                );
            }
        }

        // Sort by remaining visits (lowest first)
        usort($low_visit_users, function ($a, $b) {
            return $a['remaining_visits'] - $b['remaining_visits'];
        });

        return rest_ensure_response(array(
            'success' => true,
            'threshold' => $threshold,
            'count' => count($low_visit_users),
            'low_visit_users' => $low_visit_users
        ));
    }



    public function check_permission($request)
    {
        // Use the updated method that accepts $request parameter
        return Gym_Admin::check_api_permission($request, 'manage_options');
    }
}