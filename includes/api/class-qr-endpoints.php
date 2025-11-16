<?php
/**
 * Enhanced QR Code API endpoints with Visit Tracking and Avatar Support
 * UPDATED VERSION - Added visit info and avatar support to QR responses
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

        // ENHANCED: Lookup user by QR code with visit tracking and avatar support
        register_rest_route('gym-admin/v1', '/qr/lookup', array(
            'methods' => 'GET',
            'callback' => array($this, 'lookup_user_by_qr'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'unique_id' => array(
                    'required' => true,
                    'type' => 'string',
                    'validate_callback' => function ($param) {
                        return !empty($param) && strlen($param) <= 20;
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

        // QR lookup with check-in action
        register_rest_route('gym-admin/v1', '/qr/lookup-checkin', array(
            'methods' => 'POST',
            'callback' => array($this, 'lookup_and_checkin'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'unique_id' => array(
                    'required' => true,
                    'type' => 'string',
                    'validate_callback' => function ($param) {
                        return !empty($param) && strlen($param) <= 20;
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
     * ENHANCED: Lookup user by QR code with visit tracking and avatar support
     */
    public function lookup_user_by_qr($request)
    {
        $unique_id = $request->get_param('unique_id');
        $include_visit_info = $request->get_param('include_visit_info');

        if (empty($unique_id)) {
            return new WP_Error(
                'missing_unique_id',
                'QR code unique_id parameter is required.',
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
     * Lookup user and perform check-in in one action
     */
    public function lookup_and_checkin($request)
    {
        $unique_id = $request->get_param('unique_id');

        if (empty($unique_id)) {
            return new WP_Error(
                'missing_unique_id',
                'QR code unique_id parameter is required.',
                array('status' => 400)
            );
        }

        // First, lookup the user
        $lookup_result = $this->qr_service->lookup_user_by_code($unique_id);

        if (is_wp_error($lookup_result)) {
            return $lookup_result;
        }

        if (!$lookup_result['user_found']) {
            return rest_ensure_response(array(
                'success' => false,
                'user_found' => false,
                'message' => 'No user found with this QR code'
            ));
        }

        $user_id = $lookup_result['user']['id'];
        $user = get_user_by('id', $user_id);

        // Get fresh membership data
        $membership_service = new Gym_Membership_Service();
        $membership = $membership_service->get_user_membership($user_id);

        // Build initial response
        $response = array(
            'success' => true,
            'user_found' => true,
            'user' => array(
                'id' => $user_id,
                'name' => $lookup_result['user']['name'],
                'email' => $lookup_result['user']['email'],
                'avatar_url' => get_avatar_url($user_id),
                'profile_picture_url' => get_user_meta($user_id, 'profile_picture_url', true) ?: null,
                'unique_id' => $unique_id,
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
            $updated_visit_info = $membership_service->get_user_visit_info($user_id, $membership['start_date']);
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

/**
 * Enhancement to existing user endpoints for QR code search
 * This would be added to the existing Gym_User_Endpoints class
 */
class Gym_QR_User_Search_Enhancement
{
    private $qr_service;

    public function __construct()
    {
        $this->qr_service = new Gym_QR_Service();
    }

    /**
     * Enhanced user search that includes QR code search
     * This method can be called from the existing user endpoints
     */
    public function search_users_with_qr($search_params)
    {
        $search = isset($search_params['search']) ? sanitize_text_field($search_params['search']) : '';
        $qr_code = isset($search_params['qr_code']) ? sanitize_text_field($search_params['qr_code']) : '';
        $limit = isset($search_params['per_page']) ? absint($search_params['per_page']) : 20;
        $offset = isset($search_params['offset']) ? absint($search_params['offset']) : 0;

        // If searching by QR code specifically
        if (!empty($qr_code)) {
            return $this->qr_service->search_users_by_qr_code($qr_code, $limit, $offset);
        }

        // Check if regular search term looks like a QR code (alphanumeric, 6-8 chars)
        if (!empty($search) && preg_match('/^[A-Za-z0-9]{6,8}$/', $search)) {
            // Try QR search first
            $qr_result = $this->qr_service->search_users_by_qr_code($search, $limit, $offset);

            // If we found results, return them
            if (!empty($qr_result['users'])) {
                return $qr_result;
            }

            // Fall through to regular search if no QR results
        }

        // Regular user search logic would go here
        // Return empty result for now since this is just the QR enhancement
        return array(
            'users' => array(),
            'total' => 0,
            'message' => 'Regular user search would be handled by existing user endpoints'
        );
    }

    /**
     * ENHANCED: Add QR code and visit information to user data
     */
    public function enhance_user_data_with_qr($user_data)
    {
        if (!isset($user_data['id'])) {
            return $user_data;
        }

        $user_id = $user_data['id'];

        // Add QR code data
        $qr_data = $this->qr_service->get_user_qr_code($user_id);

        if (!is_wp_error($qr_data)) {
            $user_data['qr_code'] = array(
                'unique_id' => $qr_data['unique_id'],
                'qr_code_url' => $qr_data['qr_code_url'],
                'has_qr_code' => $qr_data['has_qr_code'],
                'generated_by' => $qr_data['generated_by']
            );
        }

        // Add avatar URLs
        $user_data['avatar_url'] = get_avatar_url($user_id);
        $profile_picture_url = get_user_meta($user_id, 'profile_picture_url', true);
        $user_data['profile_picture_url'] = !empty($profile_picture_url) ? $profile_picture_url : null;

        // Add visit information for visit-based memberships
        if (isset($user_data['membership']) && $user_data['membership']['is_visit_based']) {
            $membership_service = new Gym_Membership_Service();
            $visit_info = $membership_service->get_user_visit_info($user_id, $user_data['membership']['start_date']);
            $user_data['membership']['visit_info'] = $visit_info;
        }

        return $user_data;
    }
}