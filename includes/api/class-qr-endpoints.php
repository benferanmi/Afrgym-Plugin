<?php
/**
 * Enhanced QR Code API endpoints with Visit Tracking and Avatar Support
 * UPDATED VERSION - Added username lookup support to QR lookup endpoint
 * BUG FIX APPLIED: Fix 4 — defence-in-depth hard expiry check in lookup_and_checkin()
 */
class Gym_QR_Endpoints
{

    private $qr_service;

    public function __construct()
    {
        $this->qr_service = new Gym_QR_Service();
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes()
    {
        // Get user's QR code
        register_rest_route('gym-admin/v1', '/qr/user/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_user_qr_code'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'id' => array(
                    'required' => true,
                    'validate_callback' => function ($param) {
                        return is_numeric($param);
                    },
                    'sanitize_callback' => 'absint'
                )
            )
        ));

        // Generate/update QR code for user
        register_rest_route('gym-admin/v1', '/qr/generate/(?P<id>\d+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'generate_user_qr_code'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'id' => array(
                    'required' => true,
                    'validate_callback' => function ($param) {
                        return is_numeric($param);
                    },
                    'sanitize_callback' => 'absint'
                ),
                'force_regenerate' => array(
                    'required' => false,
                    'type' => 'boolean',
                    'default' => false,
                    'sanitize_callback' => 'rest_sanitize_boolean'
                )
            )
        ));

        // 🆕 ENHANCED: Lookup user by QR code, username, email, OR phone number with visit tracking
        register_rest_route('gym-admin/v1', '/qr/lookup', array(
            'methods' => 'GET',
            'callback' => array($this, 'lookup_user_by_qr'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'unique_id' => array(
                    'required' => true,
                    'type' => 'string',
                    'description' => 'QR code, username, email, or phone number to lookup',
                    'validate_callback' => function ($param) {
                        return !empty($param) && strlen($param) <= 100;
                    },
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'include_visit_info' => array(
                    'required' => false,
                    'type' => 'boolean',
                    'default' => true,
                    'sanitize_callback' => 'rest_sanitize_boolean'
                )
            )
        ));

        // 🆕 QR lookup with check-in action - now supports QR code, username, email, and phone
        register_rest_route('gym-admin/v1', '/qr/lookup-checkin', array(
            'methods' => 'POST',
            'callback' => array($this, 'lookup_and_checkin'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'unique_id' => array(
                    'required' => true,
                    'type' => 'string',
                    'description' => 'QR code, username, email, or phone number for check-in',
                    'validate_callback' => function ($param) {
                        return !empty($param) && strlen($param) <= 100;
                    },
                    'sanitize_callback' => 'sanitize_text_field'
                )
            )
        ));

        // QR statistics endpoint
        register_rest_route('gym-admin/v1', '/qr/statistics', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_qr_statistics'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Auto-generate missing QR codes
        register_rest_route('gym-admin/v1', '/qr/auto-generate', array(
            'methods' => 'POST',
            'callback' => array($this, 'auto_generate_qr_codes'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'batch_size' => array(
                    'required' => false,
                    'type' => 'integer',
                    'default' => 50,
                    'validate_callback' => function ($param) {
                        return is_numeric($param) && $param > 0 && $param <= 100;
                    },
                    'sanitize_callback' => 'absint'
                )
            )
        ));

        // Cleanup orphaned QR codes
        register_rest_route('gym-admin/v1', '/qr/cleanup', array(
            'methods' => 'POST',
            'callback' => array($this, 'cleanup_qr_codes'),
            'permission_callback' => array($this, 'check_permission')
        ));
    }

    /**
     * Get user's QR code data
     */
    public function get_user_qr_code($request)
    {
        $user_id = $request->get_param('id');

        $result = $this->qr_service->get_user_qr_code($user_id);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * Generate or update QR code for user
     */
    public function generate_user_qr_code($request)
    {
        $user_id = $request->get_param('id');
        $force_regenerate = $request->get_param('force_regenerate');

        $result = $this->qr_service->generate_qr_for_user($user_id, $force_regenerate);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * 🆕 ENHANCED: Lookup user by QR code, username, email, OR phone number
     * with visit tracking and avatar support
     * 
     * Usage examples:
     * - GET /qr/lookup?unique_id=ABC12345        (QR code lookup)
     * - GET /qr/lookup?unique_id=john_doe        (Username lookup)
     * - GET /qr/lookup?unique_id=john@email.com  (Email lookup)
     * - GET /qr/lookup?unique_id=08012345678     (Phone number lookup)
     */
    public function lookup_user_by_qr($request)
    {
        $unique_id = $request->get_param('unique_id');
        $include_visit_info = $request->get_param('include_visit_info');

        if (empty($unique_id)) {
            return new WP_Error(
                'missing_unique_id',
                'Search parameter (QR code, username, email, or phone) is required.',
                array('status' => 400)
            );
        }

        $result = $this->qr_service->lookup_user_by_code($unique_id);

        if (is_wp_error($result)) {
            return $result;
        }

        // If user not found, return original result
        if (!$result['user_found']) {
            return rest_ensure_response($result);
        }

        // ENHANCED: Add avatar URLs and visit information
        $user_id = $result['user']['id'];
        $user = get_user_by('id', $user_id);

        if ($user) {
            // Add avatar URLs
            $result['user']['avatar_url'] = get_avatar_url($user_id);
            $profile_picture_url = get_user_meta($user_id, 'profile_picture_url', true);
            $result['user']['profile_picture_url'] = !empty($profile_picture_url) ? $profile_picture_url : null;

            // Add visit information for visit-based memberships
            if ($include_visit_info && isset($result['user']['membership'])) {
                $membership_service = new Gym_Membership_Service();
                $membership = $membership_service->get_user_membership($user_id);

                // Update membership info in result
                $result['user']['membership'] = $membership;

                // If it's a visit-based membership, add specific visit status
                if ($membership['is_visit_based'] && isset($membership['visit_info'])) {
                    $result['visit_status'] = array(
                        'is_visit_based' => true,
                        'can_check_in' => $membership['is_active'] &&
                            $membership['visit_info']['remaining_visits'] > 0 &&
                            !in_array(current_time('Y-m-d'), $membership['visit_info']['visit_log']),
                        'remaining_visits' => $membership['visit_info']['remaining_visits'],
                        'used_visits' => $membership['visit_info']['used_visits'],
                        'total_visits' => $membership['visit_info']['total_visits'],
                        'already_checked_in_today' => in_array(current_time('Y-m-d'), $membership['visit_info']['visit_log']),
                        'next_reset_date' => $membership['visit_info']['next_reset_date']
                    );
                } else {
                    $result['visit_status'] = array(
                        'is_visit_based' => false,
                        'can_check_in' => false,
                        'message' => 'User has time-based membership'
                    );
                }
            }

            // Add membership status summary
            $result['access_status'] = array(
                'can_access' => $membership['is_active'] && !$membership['is_paused'],
                'is_active' => $membership['is_active'],
                'is_paused' => $membership['is_paused'],
                'status_message' => $this->get_access_status_message($membership)
            );
        }

        return rest_ensure_response($result);
    }

    /**
     * FIX 4: Defence-in-depth hard expiry check — queries the PMPro memberships
     * table directly (bypassing the service layer entirely) to confirm the
     * member's enddate has not passed.  Called at the very top of
     * lookup_and_checkin() before the service layer is involved.
     *
     * Returns true  → membership is not date-expired (safe to proceed).
     * Returns false → membership IS date-expired (deny access immediately).
     *
     * Note: null/zero enddates are treated as not-expired here because a
     * genuine lifetime membership has no enddate; the service layer's
     * is_lifetime_membership() helper handles the finer distinction.
     *
     * @param int $user_id
     * @return bool
     */
    private function hard_check_membership_not_expired($user_id)
    {
        global $wpdb;

        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT enddate FROM {$wpdb->prefix}pmpro_memberships_users
             WHERE user_id = %d AND status = 'active'
             ORDER BY id DESC LIMIT 1",
            $user_id
        ));

        // No active membership row at all — deny
        if (!$record) {
            return false;
        }

        // Null or zero enddate — no date expiry, let service layer decide
        if (empty($record->enddate) || $record->enddate === '0000-00-00 00:00:00') {
            return true;
        }

        // Check whether the enddate is still in the future
        return strtotime($record->enddate) > time();
    }

    /**
     * 🆕 Lookup user and perform check-in in one action
     * Now supports QR code, username, email, and phone number lookup
     * 
     * Usage examples:
     * - POST /qr/lookup-checkin with body: {"unique_id": "ABC12345"}        (QR code)
     * - POST /qr/lookup-checkin with body: {"unique_id": "john_doe"}        (Username)
     * - POST /qr/lookup-checkin with body: {"unique_id": "john@email.com"}  (Email)
     * - POST /qr/lookup-checkin with body: {"unique_id": "08012345678"}     (Phone)
     */
    public function lookup_and_checkin($request)
    {
        $unique_id = $request->get_param('unique_id');

        if (empty($unique_id)) {
            return new WP_Error(
                'missing_unique_id',
                'Search parameter (QR code, username, email, or phone) is required.',
                array('status' => 400)
            );
        }

        // First, lookup the user (now supports QR, username, email, and phone)
        $lookup_result = $this->qr_service->lookup_user_by_code($unique_id);

        if (is_wp_error($lookup_result)) {
            return $lookup_result;
        }

        if (!$lookup_result['user_found']) {
            return rest_ensure_response(array(
                'success' => false,
                'user_found' => false,
                'message' => 'No user found with this QR code, username, email, or phone number'
            ));
        }

        $user_id = $lookup_result['user']['id'];
        $user = get_user_by('id', $user_id);

        // FIX 4: Hard expiry check — this is the defence-in-depth gate at the
        // endpoint level.  It runs BEFORE the service layer so that even if the
        // service has stale cached data we still block access on date expiry.
        if (!$this->hard_check_membership_not_expired($user_id)) {
            return rest_ensure_response(array(
                'success'       => false,
                'user_found'    => true,
                'access_denied' => true,
                'message'       => 'Access denied: Membership has expired.',
                'reason'        => 'membership_expired',
                'lookup_method' => $lookup_result['lookup_method'],
                'search_term'   => $lookup_result['search_term'],
                'user'          => array(
                    'id'       => $user_id,
                    'username' => $lookup_result['user']['username'],
                    'name'     => $lookup_result['user']['name'],
                    'email'    => $lookup_result['user']['email'],
                ),
            ));
        }

        // Get fresh membership data
        $membership_service = new Gym_Membership_Service();
        $membership = $membership_service->get_user_membership($user_id);

        // Build initial response
        $response = array(
            'success' => true,
            'user_found' => true,
            'lookup_method' => $lookup_result['lookup_method'], // 'qr_code', 'username', 'email', or 'phone'
            'search_term' => $lookup_result['search_term'],
            'user' => array(
                'id' => $user_id,
                'username' => $lookup_result['user']['username'],
                'name' => $lookup_result['user']['name'],
                'email' => $lookup_result['user']['email'],
                'phone' => $lookup_result['user']['phone'],
                'avatar_url' => get_avatar_url($user_id),
                'profile_picture_url' => get_user_meta($user_id, 'profile_picture_url', true) ?: null,
                'unique_id' => $lookup_result['user']['unique_id'],
                'membership' => $membership
            ),
            'checkin_attempted' => false,
            'checkin_success' => false
        );

        // Check if user can access the gym
        if (!$membership['is_active']) {
            $response['access_denied'] = true;
            $response['message'] = 'Access denied: Membership is not active';
            $response['reason'] = 'inactive_membership';
            return rest_ensure_response($response);
        }

        if ($membership['is_paused']) {
            $response['access_denied'] = true;
            $response['message'] = 'Access denied: Membership is currently paused';
            $response['reason'] = 'paused_membership';
            return rest_ensure_response($response);
        }

        // For visit-based memberships, attempt check-in
        if ($membership['is_visit_based']) {
            $response['checkin_attempted'] = true;

            $checkin_result = $membership_service->record_visit_checkin($user_id);

            if (is_wp_error($checkin_result)) {
                $response['checkin_success'] = false;
                $response['checkin_error'] = $checkin_result->get_error_message();
                $response['message'] = 'Check-in failed: ' . $checkin_result->get_error_message();

                // Provide specific error context
                if ($checkin_result->get_error_code() === 'already_checked_in') {
                    $response['reason'] = 'already_checked_in_today';
                } elseif ($checkin_result->get_error_code() === 'no_visits_remaining') {
                    $response['reason'] = 'no_visits_remaining';
                    $response['access_denied'] = true;
                }

            } else {
                $response['checkin_success'] = true;
                $response['message'] = 'Check-in successful! Welcome to the gym.';
                $response['checkin_info'] = $checkin_result['visit_info'];
            }

            // Get updated visit info
            $updated_visit_info = $membership_service->get_user_visit_info($user_id, $membership['start_date'], $membership['expiry_date']);
            $response['visit_status'] = array(
                'remaining_visits' => $updated_visit_info['remaining_visits'],
                'used_visits' => $updated_visit_info['used_visits'],
                'total_visits' => $updated_visit_info['total_visits'],
                'next_reset_date' => $updated_visit_info['next_reset_date']
            );
        } else {
            // Time-based membership - just grant access
            $response['message'] = 'Access granted! Welcome to the gym.';
            $response['visit_status'] = array(
                'is_visit_based' => false,
                'message' => 'Time-based membership - no visit tracking'
            );
        }

        return rest_ensure_response($response);
    }

    /**
     * Get QR code statistics
     */
    public function get_qr_statistics($request)
    {
        $result = $this->qr_service->get_qr_statistics();

        return rest_ensure_response(array(
            'success' => true,
            'statistics' => $result
        ));
    }

    /**
     * Auto-generate QR codes for users without them
     */
    public function auto_generate_qr_codes($request)
    {
        $batch_size = $request->get_param('batch_size');

        $result = $this->qr_service->auto_generate_missing_qr_codes($batch_size);

        return rest_ensure_response(array(
            'success' => true,
            'message' => sprintf(
                'Processed %d users, generated %d new QR codes',
                $result['processed'],
                $result['generated']
            ),
            'results' => $result
        ));
    }

    /**
     * Cleanup orphaned QR codes
     */
    public function cleanup_qr_codes($request)
    {
        $result = $this->qr_service->cleanup_orphaned_qr_codes();

        return rest_ensure_response(array(
            'success' => true,
            'message' => sprintf('Cleaned up %d orphaned QR code records', $result['deleted_records']),
            'deleted_records' => $result['deleted_records']
        ));
    }

    /**
     * Generate access status message based on membership
     */
    private function get_access_status_message($membership)
    {
        if (!$membership['is_active']) {
            return 'Membership expired - Access denied';
        }

        if ($membership['is_paused']) {
            return 'Membership paused - Access denied';
        }

        if ($membership['is_visit_based']) {
            if (!isset($membership['visit_info'])) {
                return 'Visit-based membership - Status unknown';
            }

            $remaining = $membership['visit_info']['remaining_visits'];
            $today = current_time('Y-m-d');
            $already_checked_in = in_array($today, $membership['visit_info']['visit_log']);

            if ($remaining <= 0) {
                return 'No visits remaining - Access denied';
            }

            if ($already_checked_in) {
                return 'Already checked in today - ' . $remaining . ' visits remaining';
            }

            return 'Access granted - ' . $remaining . ' visits remaining';
        }

        return 'Access granted - Time-based membership';
    }

    /**
     * Check permissions for QR endpoints
     */
    public function check_permission($request)
    {
        return Gym_Admin::check_api_permission($request, 'manage_options');
    }
}