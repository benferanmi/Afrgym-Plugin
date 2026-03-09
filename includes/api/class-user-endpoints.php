<?php
/**
 * User management API endpoints - UPDATED with Phone Number Support
 */
class Gym_User_Endpoints
{

    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes()
    {
        // List users with search and pagination
        register_rest_route('gym-admin/v1', '/users', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_users'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'page' => array(
                    'default' => 1,
                    'type' => 'integer'
                ),
                'per_page' => array(
                    'default' => 20,
                    'type' => 'integer'
                ),
                'search' => array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'orderby' => array(
                    'default' => 'registered',
                    'type' => 'string'
                )
            )
        ));

        // Get single user
        register_rest_route('gym-admin/v1', '/users/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_user'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Create new user
        register_rest_route('gym-admin/v1', '/users', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_user'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'username' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_user'
                ),
                'email' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_email'
                ),
                'first_name' => array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'last_name' => array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'password' => array(
                    'type' => 'string'
                ),
                // Phone number parameter
                'phone' => array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description' => 'User phone number'
                ),
                // Profile picture parameter
                'profile_picture_url' => array(
                    'type' => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                    'description' => 'Cloudinary URL for user profile picture'
                ),
                // Membership parameters
                'level_id' => array(
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                    'description' => 'Membership level ID to assign to the user'
                ),
                'start_date' => array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description' => 'Membership start date (YYYY-MM-DD format)'
                ),
                'end_date' => array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description' => 'Membership end date (YYYY-MM-DD format)'
                ),
                'send_verification_email' => array(
                    'type' => 'boolean',
                    'default' => false,
                    'sanitize_callback' => 'rest_sanitize_boolean',
                    'description' => 'Send OTP email verification after user creation'
                ),
                'email_verification_token' => array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description' => 'Email verification token from OTP verification'
                ),
            )
        ));

        // Get users created by Gym One admins
        register_rest_route('gym-admin/v1', '/users/gym-one', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_gym_one_users'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'page' => array(
                    'default' => 1,
                    'type' => 'integer'
                ),
                'per_page' => array(
                    'default' => 20,
                    'type' => 'integer'
                ),
                'search' => array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'orderby' => array(
                    'default' => 'registered',
                    'type' => 'string'
                )
            )
        ));

        // Get users created by Gym Two admins
        register_rest_route('gym-admin/v1', '/users/gym-two', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_gym_two_users'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'page' => array(
                    'default' => 1,
                    'type' => 'integer'
                ),
                'per_page' => array(
                    'default' => 20,
                    'type' => 'integer'
                ),
                'search' => array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'orderby' => array(
                    'default' => 'registered',
                    'type' => 'string'
                )
            )
        ));

        // Update user
        register_rest_route('gym-admin/v1', '/users/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_user'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Delete user
        register_rest_route('gym-admin/v1', '/users/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_user'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Alternative delete endpoint using POST (firewall-friendly)
        register_rest_route('gym-admin/v1', '/users/(?P<id>\d+)/delete', array(
            'methods' => 'POST',
            'callback' => array($this, 'delete_user_post'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Get user visit information
        register_rest_route('gym-admin/v1', '/users/(?P<id>\d+)/visits', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_user_visits'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Update user visit allowance
        register_rest_route('gym-admin/v1', '/users/(?P<id>\d+)/visits', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_user_visits'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'visit_allowance' => array(
                    'type' => 'integer',
                    'minimum' => 0,
                    'sanitize_callback' => 'absint'
                ),
                'reset_log' => array(
                    'type' => 'boolean',
                    'sanitize_callback' => 'rest_sanitize_boolean'
                )
            )
        ));

        // Record check-in for user
        register_rest_route('gym-admin/v1', '/checkin/(?P<id>\d+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'checkin_user'),
            'permission_callback' => array($this, 'check_permission')
        ));
        // Get recipient statistics (gym-aware)
        register_rest_route('gym-admin/v1', '/users/recipient-stats', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_recipient_stats'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route('gym-admin/v1', '/users/export/csv', array(
            'methods' => 'GET',
            'callback' => array($this, 'export_users_csv'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'search' => array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'membership_status' => array(
                    'type' => 'string',
                    'enum' => array('all', 'active', 'inactive', 'expired')
                )
            )
        ));

        register_rest_route('gym-admin/v1', '/users/export/pdf', array(
            'methods' => 'GET',
            'callback' => array($this, 'export_users_pdf'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'search' => array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'membership_status' => array(
                    'type' => 'string',
                    'enum' => array('all', 'active', 'inactive', 'expired')
                )
            )
        ));
    }

    public function get_users($request)
    {
        $page = $request->get_param('page');
        $per_page = min($request->get_param('per_page'), 100);
        $search = $request->get_param('search');
        $orderby = $request->get_param('orderby');

        $args = array(
            'number' => $per_page,
            'offset' => ($page - 1) * $per_page,
            'orderby' => $orderby,
            'order' => 'DESC',
            'role__not_in' => array('administrator', 'subadmin')
        );

        // Enhanced search to include phone numbers
        if (!empty($search)) {
            // First try standard search
            $args['search'] = '*' . $search . '*';
            $args['search_columns'] = array('user_login', 'user_email', 'display_name');

            $users = get_users($args);

            // If no results from standard search, try phone number search
            if (empty($users)) {
                unset($args['search']);
                unset($args['search_columns']);

                // Search by phone number in user meta
                $args['meta_query'] = array(
                    array(
                        'key' => 'phone',
                        'value' => $search,
                        'compare' => 'LIKE'
                    )
                );

                $users = get_users($args);
            }

            // Get total count for pagination - ALSO exclude admins from count
            $count_args = array(
                'search' => '*' . $search . '*',
                'role__not_in' => array('administrator', 'subadmin')
            );
            $total_users = count(get_users($count_args));

            // If no standard results, get phone search count
            if ($total_users == 0) {
                $count_args = array(
                    'role__not_in' => array('administrator', 'subadmin'),
                    'meta_query' => array(
                        array(
                            'key' => 'phone',
                            'value' => $search,
                            'compare' => 'LIKE'
                        )
                    )
                );
                $total_users = count(get_users($count_args));
            }
        } else {
            $users = get_users($args);
            // Get total count excluding admins
            $total_users = count(get_users(array('role__not_in' => array('administrator', 'subadmin'))));
        }

        $formatted_users = array();
        foreach ($users as $user) {
            $formatted_users[] = $this->format_user_data($user);
        }

        return rest_ensure_response(array(
            'users' => $formatted_users,
            'total' => $total_users,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total_users / $per_page)
        ));
    }

    public function get_user($request)
    {
        $user_id = $request->get_param('id');
        $user = get_user_by('id', $user_id);

        if (!$user) {
            return new WP_Error('user_not_found', 'User not found.', array('status' => 404));
        }

        $user_data = $this->format_user_data($user);
        $user_data['notes'] = Gym_Admin::get_user_notes($user_id);

        return rest_ensure_response($user_data);
    }

    public function create_user($request)
    {
        $username = $request->get_param('username');
        $email = $request->get_param('email');
        $first_name = $request->get_param('first_name');
        $last_name = $request->get_param('last_name');
        $password = $request->get_param('password') ?: wp_generate_password();
        $phone = $request->get_param('phone');
        $profile_picture_url = $request->get_param('profile_picture_url');

        // Get verification token instead of send_verification_email
        $verification_token = $request->get_param('email_verification_token');

        // Membership parameters
        $level_id = $request->get_param('level_id');
        $start_date = $request->get_param('start_date');
        $end_date = $request->get_param('end_date');

        // Validate required fields
        if (username_exists($username)) {
            return new WP_Error('username_exists', 'Username already exists.', array('status' => 400));
        }

        if (email_exists($email)) {
            return new WP_Error('email_exists', 'Email already exists.', array('status' => 400));
        }

        // Validate phone number format if provided
        if (!empty($phone) && !$this->is_valid_phone($phone)) {
            return new WP_Error('invalid_phone', 'Invalid phone number format.', array('status' => 400));
        }

        // Check if phone number already exists
        if (!empty($phone) && $this->phone_exists($phone)) {
            return new WP_Error('phone_exists', 'Phone number already exists.', array('status' => 400));
        }

        // Validate profile picture URL if provided
        if (!empty($profile_picture_url) && !filter_var($profile_picture_url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_profile_picture_url', 'Invalid profile picture URL format.', array('status' => 400));
        }

        // Validate membership level if provided
        if ($level_id) {
            $membership_service = new Gym_Membership_Service();
            $available_levels = $membership_service->get_all_membership_levels();

            $level_exists = false;
            foreach ($available_levels as $level) {
                if ($level['id'] == $level_id) {
                    $level_exists = true;
                    break;
                }
            }

            if (!$level_exists) {
                return new WP_Error('invalid_membership_level', 'Invalid membership level ID.', array('status' => 400));
            }

            // Enhanced date validation
            if ($start_date && !$this->is_valid_date($start_date)) {
                return new WP_Error('invalid_start_date', 'Invalid start date format. Use YYYY-MM-DD.', array('status' => 400));
            }

            if ($end_date && !$this->is_valid_date($end_date)) {
                return new WP_Error('invalid_end_date', 'Invalid end date format. Use YYYY-MM-DD.', array('status' => 400));
            }

            // Validate that end_date is after start_date
            if ($start_date && $end_date && strtotime($end_date) <= strtotime($start_date)) {
                return new WP_Error('invalid_date_range', 'End date must be after start date.', array('status' => 400));
            }
        }

        // Create WordPress user
        $user_data = array(
            'user_login' => $username,
            'user_email' => $email,
            'user_pass' => $password,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'display_name' => trim($first_name . ' ' . $last_name)
        );

        $user_id = wp_insert_user($user_data);

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        if (!empty($phone)) {
            update_user_meta($user_id, 'phone', $this->format_phone($phone));
            Gym_Admin::add_user_note($user_id, 'Phone number added via API: ' . $this->format_phone($phone));
        }

        if (!empty($profile_picture_url)) {
            update_user_meta($user_id, 'profile_picture_url', $profile_picture_url);
            Gym_Admin::add_user_note($user_id, 'Profile picture added via API');
        }

        // Log user creation
        Gym_Admin::add_user_note($user_id, 'User created via API');

        // Handle email verification token
        $email_verified = false;

        if (!empty($verification_token)) {
            // Validate the verification token
            $is_valid = Gym_OTP_Endpoints::validate_verification_token($email, $verification_token);

            if ($is_valid) {
                // Mark email as verified
                update_user_meta($user_id, 'email_verified', true);
                update_user_meta($user_id, 'email_verified_at', current_time('mysql'));
                $email_verified = true;

                // Clear the verification token transient
                $token_key = 'gym_verified_' . md5($email);
                delete_transient($token_key);

                Gym_Admin::add_user_note($user_id, 'Email verified during registration via OTP token');
            } else {
                // Invalid or expired token - user created but email not verified
                Gym_Admin::add_user_note($user_id, 'User created but email verification token was invalid or expired');
            }
        }

        // Track membership assignment status
        $membership_assigned = false;
        $membership_error = null;

        // Assign membership if level_id is provided
        if ($level_id) {
            $membership_service = new Gym_Membership_Service();

            // Pass dates to membership service - it will handle defaults
            $membership_result = $membership_service->assign_membership(
                $user_id,
                $level_id,
                $start_date,
                $end_date
            );

            if (is_wp_error($membership_result)) {
                $membership_error = $membership_result->get_error_message();
                Gym_Admin::add_user_note($user_id, 'Membership assignment failed: ' . $membership_error);
            } else {
                $membership_assigned = true;
                // Log successful membership assignment with dates
                $log_message = 'Membership assigned via API: Level ID ' . $level_id;
                if ($start_date)
                    $log_message .= ', Start: ' . $start_date;
                if ($end_date)
                    $log_message .= ', End: ' . $end_date;

                Gym_Admin::add_user_note($user_id, $log_message);
            }
        }

        $qr_generated = false;
        $qr_error = null;
        $qr_result = null;

        $auto_generate_qr = Gym_Admin::get_setting('auto_generate_qr_new_users', '1') === '1';

        if ($auto_generate_qr) {
            $qr_service = new Gym_QR_Service();
            $qr_result = $qr_service->generate_qr_for_user($user_id);

            if (is_wp_error($qr_result)) {
                $qr_error = $qr_result->get_error_message();
                Gym_Admin::add_user_note($user_id, 'QR code auto-generation failed: ' . $qr_error);
            } else {
                $qr_generated = true;
                Gym_Admin::add_user_note($user_id, 'QR code auto-generated: ' . $qr_result['unique_id']);
            }
        }

        $updated_user = get_user_by('id', $user_id);

        $message_parts = array('User created successfully');
        if (!empty($phone)) {
            $message_parts[] = 'with phone number';
        }
        if (!empty($profile_picture_url)) {
            $message_parts[] = 'with profile picture';
        }
        if ($membership_assigned) {
            $message_parts[] = 'with membership assigned';
        } elseif ($membership_error) {
            $message_parts[] = 'but membership assignment failed';
        }
        if ($qr_generated) {
            $message_parts[] = 'and QR code generated';
        } elseif ($qr_error && $auto_generate_qr) {
            $message_parts[] = 'but QR code generation failed';
        }
        if ($email_verified) {
            $message_parts[] = 'with verified email';
        }

        $response_data = array(
            'success' => true,
            'user' => $this->format_user_data($updated_user),
            'message' => implode(' ', $message_parts),
            'phone' => array(
                'added' => !empty($phone),
                'number' => !empty($phone) ? $this->format_phone($phone) : null
            ),
            'profile_picture' => array(
                'added' => !empty($profile_picture_url),
                'url' => $profile_picture_url
            ),
            'qr_code' => array(
                'auto_generation_enabled' => $auto_generate_qr,
                'generated' => $qr_generated
            ),
            'membership' => array(
                'assigned' => $membership_assigned,
                'level_id' => $level_id
            ),
            'email_verification' => array(
                'token_provided' => !empty($verification_token),
                'email_verified' => $email_verified
            )
        );

        // Add QR details if generated successfully
        if ($qr_generated && $qr_result) {
            $response_data['qr_code']['unique_id'] = $qr_result['unique_id'];
            $response_data['qr_code']['email_sent'] = $qr_result['email_sent'];
            $response_data['qr_code']['generated_by'] = $qr_result['generated_by'];
        }

        if ($membership_error || $qr_error) {
            $response_data['errors'] = array();
            if ($membership_error) {
                $response_data['errors']['membership'] = $membership_error;
            }
            if ($qr_error) {
                $response_data['errors']['qr_code'] = $qr_error;
            }
        }

        return rest_ensure_response($response_data);
    }

    /**
     * Helper method to validate date format
     */
    private function is_valid_date($date_string)
    {
        if (empty($date_string)) {
            return false;
        }

        $date = DateTime::createFromFormat('Y-m-d', $date_string);
        return $date && $date->format('Y-m-d') === $date_string;
    }

    /**
     * Helper method to validate phone number format
     */
    private function is_valid_phone($phone)
    {
        if (empty($phone)) {
            return false;
        }

        // Remove all non-numeric characters for validation
        $clean_phone = preg_replace('/[^0-9]/', '', $phone);

        // Basic validation: must be between 10-15 digits
        return strlen($clean_phone) >= 10 && strlen($clean_phone) <= 15;
    }

    /**
     * Helper method to check if phone number already exists
     */
    private function phone_exists($phone)
    {
        $formatted_phone = $this->format_phone($phone);

        $users = get_users(array(
            'meta_query' => array(
                array(
                    'key' => 'phone',
                    'value' => $formatted_phone,
                    'compare' => '='
                )
            ),
            'number' => 1
        ));

        return !empty($users);
    }

    /**
     * Helper method to format phone number consistently
     */
    private function format_phone($phone)
    {
        if (empty($phone)) {
            return '';
        }

        // Remove all non-numeric characters
        $clean_phone = preg_replace('/[^0-9]/', '', $phone);

        // Store as clean number - formatting can be done on display
        return $clean_phone;
    }

    public function update_user($request)
    {
        $user_id = $request->get_param('id');
        $user = get_user_by('id', $user_id);

        if (!$user) {
            return new WP_Error('user_not_found', 'User not found.', array('status' => 404));
        }

        $params = $request->get_json_params();
        $user_data = array('ID' => $user_id);
        $updated_fields = array();

        // Update basic user information
        if (isset($params['email'])) {
            $email = sanitize_email($params['email']);
            if (!is_email($email)) {
                return new WP_Error('invalid_email', 'Invalid email format.', array('status' => 400));
            }
            if (email_exists($email) && $email !== $user->user_email) {
                return new WP_Error('email_exists', 'Email already exists.', array('status' => 400));
            }
            $user_data['user_email'] = $email;
            $updated_fields[] = 'email';
        }

        if (isset($params['first_name'])) {
            $user_data['first_name'] = sanitize_text_field($params['first_name']);
            $updated_fields[] = 'first_name';
        }

        if (isset($params['last_name'])) {
            $user_data['last_name'] = sanitize_text_field($params['last_name']);
            $updated_fields[] = 'last_name';
        }

        if (isset($params['display_name'])) {
            $user_data['display_name'] = sanitize_text_field($params['display_name']);
            $updated_fields[] = 'display_name';
        }

        if (isset($params['password']) && !empty($params['password'])) {
            $user_data['user_pass'] = $params['password'];
            $updated_fields[] = 'password';
        }

        // Handle phone number updates
        if (isset($params['phone'])) {
            $phone = $params['phone'];
            $current_phone = get_user_meta($user_id, 'phone', true);

            if (empty($phone)) {
                // Remove phone number
                delete_user_meta($user_id, 'phone');
                $updated_fields[] = 'phone (removed)';
                Gym_Admin::add_user_note($user_id, 'Phone number removed via API');
            } else {
                // Validate phone number
                if (!$this->is_valid_phone($phone)) {
                    return new WP_Error('invalid_phone', 'Invalid phone number format.', array('status' => 400));
                }

                $formatted_phone = $this->format_phone($phone);

                // Check if phone already exists (excluding current user)
                if ($formatted_phone !== $current_phone && $this->phone_exists($phone)) {
                    return new WP_Error('phone_exists', 'Phone number already exists.', array('status' => 400));
                }

                update_user_meta($user_id, 'phone', $formatted_phone);
                $updated_fields[] = 'phone';
                Gym_Admin::add_user_note($user_id, 'Phone number updated via API: ' . $formatted_phone);
            }
        }

        // Handle profile picture URL updates
        if (isset($params['profile_picture_url'])) {
            $profile_picture_url = $params['profile_picture_url'];

            if (empty($profile_picture_url)) {
                delete_user_meta($user_id, 'profile_picture_url');
                $updated_fields[] = 'profile picture (removed)';
                Gym_Admin::add_user_note($user_id, 'Profile picture removed via API');
            } else {
                if (!filter_var($profile_picture_url, FILTER_VALIDATE_URL)) {
                    return new WP_Error('invalid_profile_picture_url', 'Invalid profile picture URL format.', array('status' => 400));
                }

                update_user_meta($user_id, 'profile_picture_url', esc_url_raw($profile_picture_url));
                $updated_fields[] = 'profile picture';
                Gym_Admin::add_user_note($user_id, 'Profile picture updated via API');
            }
        }

        // Handle visit check-in
        if (isset($params['checkin_today']) && $params['checkin_today']) {
            $membership_service = new Gym_Membership_Service();
            $checkin_result = $membership_service->record_visit_checkin($user_id);

            if (is_wp_error($checkin_result)) {
                return new WP_Error('checkin_failed', 'Check-in failed: ' . $checkin_result->get_error_message(), array('status' => 400));
            } else {
                $updated_fields[] = 'visit check-in';
            }
        }

        // Update user data if any basic fields changed
        if (count($user_data) > 1) { // More than just ID
            $result = wp_update_user($user_data);
            if (is_wp_error($result)) {
                return $result;
            }
        }

        // Handle membership updates
        $membership_updated = false;
        $membership_error = null;

        if (isset($params['level_id'])) {
            $level_id = $params['level_id'];
            $start_date = $params['start_date'] ?? null;
            $end_date = $params['end_date'] ?? null;

            if ($level_id === null || $level_id === '' || $level_id === 'none') {
                $level_id = 0; // Cancel membership
            } else {
                $level_id = absint($level_id);
            }

            if ($level_id > 0) {
                if ($start_date && !$this->is_valid_date($start_date)) {
                    return new WP_Error('invalid_start_date', 'Invalid start date format. Use YYYY-MM-DD.', array('status' => 400));
                }

                if ($end_date && !$this->is_valid_date($end_date)) {
                    return new WP_Error('invalid_end_date', 'Invalid end date format. Use YYYY-MM-DD.', array('status' => 400));
                }

                if ($start_date && $end_date && strtotime($end_date) <= strtotime($start_date)) {
                    return new WP_Error('invalid_date_range', 'End date must be after start date.', array('status' => 400));
                }
            }

            if ($level_id === 0) {
                // Cancel membership
                $membership_service = new Gym_Membership_Service();
                $cancel_result = $membership_service->cancel_membership($user_id);

                if (is_wp_error($cancel_result)) {
                    $membership_error = $cancel_result->get_error_message();
                    return new WP_Error(
                        'membership_cancellation_failed',
                        'Failed to cancel membership: ' . $membership_error,
                        array('status' => 400)
                    );
                } else {
                    $membership_updated = true;
                    $updated_fields[] = 'membership (cancelled)';
                }
            } else {
                // Update membership
                $membership_service = new Gym_Membership_Service();

                $available_levels = $membership_service->get_all_membership_levels();
                $level_exists = false;
                $level_name = '';
                foreach ($available_levels as $level) {
                    if (intval($level['id']) === $level_id) {
                        $level_exists = true;
                        $level_name = $level['name'];
                        break;
                    }
                }

                if (!$level_exists) {
                    return new WP_Error('invalid_membership_level', 'Invalid membership level ID: ' . $level_id, array('status' => 400));
                }

                if (empty($start_date)) {
                    $start_date = current_time('Y-m-d');
                }

                $membership_result = $membership_service->update_membership($user_id, $level_id, $start_date, $end_date);

                if (is_wp_error($membership_result)) {
                    $membership_error = $membership_result->get_error_message();
                    return new WP_Error(
                        'membership_update_failed',
                        'Failed to update membership: ' . $membership_error,
                        array('status' => 400)
                    );
                } else {
                    $membership_updated = true;
                    $updated_fields[] = 'membership';

                    $update_type = $membership_result['update_type'] ?? 'unknown';
                    $log_message = "Membership updated via API: $level_name (ID: $level_id) - Type: $update_type";
                    if ($start_date)
                        $log_message .= ", Start: $start_date";
                    if ($end_date)
                        $log_message .= ", End: $end_date";

                    Gym_Admin::add_user_note($user_id, $log_message);
                }
            }
        }

        // Add admin note if provided
        if (isset($params['note']) && !empty(trim($params['note']))) {
            Gym_Admin::add_user_note($user_id, sanitize_textarea_field($params['note']));
            $updated_fields[] = 'admin note';
        }

        // Log the update if any fields were changed
        if (!empty($updated_fields)) {
            $log_message = 'User updated via API: ' . implode(', ', $updated_fields);
            Gym_Admin::add_user_note($user_id, $log_message);
        }

        // Force complete cache clearance
        clean_user_cache($user_id);
        wp_cache_delete($user_id, 'users');
        wp_cache_delete($user_id, 'user_meta');
        wp_cache_delete("pmpro_membership_level_for_user_" . $user_id, 'pmpro');

        if (function_exists('pmpro_delete_user_membership_level_cache')) {
            pmpro_delete_user_membership_level_cache($user_id);
        }

        // Add delay to ensure database consistency
        usleep(250000); // 0.25 second delay

        // Get fresh user object
        $updated_user = get_user_by('id', $user_id);

        // Build response
        $response_data = array(
            'success' => true,
            'user' => $this->format_user_data($updated_user),
            'updated_fields' => $updated_fields,
            'membership_updated' => $membership_updated,
            'phone_updated' => in_array('phone', $updated_fields) || in_array('phone (removed)', $updated_fields),
            'profile_picture_updated' => in_array('profile picture', $updated_fields) || in_array('profile picture (removed)', $updated_fields),
            'message' => $membership_updated ? 'User and membership updated successfully' :
                (!empty($updated_fields) ? 'User updated successfully' : 'No changes were made')
        );

        return rest_ensure_response($response_data);
    }

    public function delete_user($request)
    {
        // Load WordPress admin functions if not available
        if (!function_exists('wp_delete_user')) {
            require_once(ABSPATH . 'wp-admin/includes/user.php');
        }

        $user_id = $request->get_param('id');
        $user = get_user_by('id', $user_id);

        if (!$user) {
            return new WP_Error('user_not_found', 'User not found.', array('status' => 404));
        }

        // Don't allow deletion of current user or other admins
        if ($user_id == get_current_user_id()) {
            return new WP_Error('cannot_delete_self', 'Cannot delete current user.', array('status' => 400));
        }

        if (user_can($user_id, 'administrator')) {
            return new WP_Error('cannot_delete_admin', 'Cannot delete administrator users.', array('status' => 400));
        }

        $deleted = wp_delete_user($user_id);

        if (!$deleted) {
            return new WP_Error('delete_failed', 'Failed to delete user.', array('status' => 500));
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'User deleted successfully'
        ));
    }

    public function delete_user_post($request)
    {
        // Same logic as delete_user but via POST
        return $this->delete_user($request);
    }

    /**
     * Get user visit information endpoint
     */
    public function get_user_visits($request)
    {
        $user_id = $request->get_param('id');
        $user = get_user_by('id', $user_id);

        if (!$user) {
            return new WP_Error('user_not_found', 'User not found.', array('status' => 404));
        }

        $membership_service = new Gym_Membership_Service();
        $membership = $membership_service->get_user_membership($user_id);

        if (!$membership['is_visit_based']) {
            return new WP_Error('not_visit_based', 'User does not have a visit-based membership plan.', array('status' => 400));
        }

        $visit_info = $membership_service->get_user_visit_info($user_id, $membership['start_date']);

        return rest_ensure_response(array(
            'success' => true,
            'user_id' => $user_id,
            'membership_level' => $membership['level_name'],
            'visit_info' => $visit_info
        ));
    }
    /**
     * Get users created by Gym One admins
     */
    public function get_gym_one_users($request)
    {
        return $this->get_users_by_gym($request, 'afrgym_one');
    }

    /**
     * Get users created by Gym Two admins
     */
    public function get_gym_two_users($request)
    {
        return $this->get_users_by_gym($request, 'afrgym_two');
    }

    /**
     * Get users filtered by gym identifier
     */
    private function get_users_by_gym($request, $gym_identifier)
    {
        global $wpdb;

        $page = $request->get_param('page');
        $per_page = min($request->get_param('per_page'), 100);
        $search = $request->get_param('search');
        $orderby = $request->get_param('orderby');

        // Get user IDs created by specific gym from notes table
        $notes_table = $wpdb->prefix . 'gym_user_notes';

        // Subquery to get first note per user (creation note)
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

        $user_ids = $wpdb->get_col($wpdb->prepare($user_ids_query, $gym_identifier));

        // If no users found for this gym, return empty result
        if (empty($user_ids)) {
            return rest_ensure_response(array(
                'users' => array(),
                'total' => 0,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => 0,
                'gym_identifier' => $gym_identifier,
                'gym_name' => $gym_identifier === 'afrgym_two' ? 'Afrgym Two' : 'Afrgym One'
            ));
        }

        // Build user query args
        $args = array(
            'include' => $user_ids,
            'number' => $per_page,
            'offset' => ($page - 1) * $per_page,
            'orderby' => $orderby,
            'order' => 'DESC',
            'role__not_in' => array('administrator', 'subadmin')
        );

        // Enhanced search to include phone numbers
        if (!empty($search)) {
            // First try standard search
            $args['search'] = '*' . $search . '*';
            $args['search_columns'] = array('user_login', 'user_email', 'display_name');

            $users = get_users($args);

            // If no results from standard search, try phone number search
            if (empty($users)) {
                unset($args['search']);
                unset($args['search_columns']);

                // Search by phone number in user meta
                $args['meta_query'] = array(
                    array(
                        'key' => 'phone',
                        'value' => $search,
                        'compare' => 'LIKE'
                    )
                );

                $users = get_users($args);
            }
        } else {
            $users = get_users($args);
        }

        // Get total count for this gym
        $total_users = count($user_ids);

        // Format users
        $formatted_users = array();
        foreach ($users as $user) {
            $formatted_users[] = $this->format_user_data($user);
        }

        $gym_name = $gym_identifier === 'afrgym_two' ? 'Afrgym Two' : 'Afrgym One';

        return rest_ensure_response(array(
            'users' => $formatted_users,
            'total' => $total_users,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total_users / $per_page),
            'gym_identifier' => $gym_identifier,
            'gym_name' => $gym_name,
            'message' => "Showing users created by $gym_name admins"
        ));
    }

    /**
     * Update user visit allowance or reset log
     */
    public function update_user_visits($request)
    {
        $user_id = $request->get_param('id');
        $params = $request->get_json_params();

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return new WP_Error('user_not_found', 'User not found.', array('status' => 404));
        }

        $membership_service = new Gym_Membership_Service();
        $membership = $membership_service->get_user_membership($user_id);

        if (!$membership['is_visit_based']) {
            return new WP_Error('not_visit_based', 'User does not have a visit-based membership plan.', array('status' => 400));
        }

        $updated_fields = array();

        // Update visit allowance
        if (isset($params['visit_allowance'])) {
            $new_allowance = (int) $params['visit_allowance'];
            $result = $membership_service->update_visit_allowance($user_id, $new_allowance);

            if (is_wp_error($result)) {
                return $result;
            }

            $updated_fields[] = 'visit allowance';
        }

        // Reset visit log if requested
        if (isset($params['reset_log']) && $params['reset_log']) {
            $result = $membership_service->reset_visit_log($user_id);

            if (is_wp_error($result)) {
                return $result;
            }

            $updated_fields[] = 'visit log reset';
        }

        if (empty($updated_fields)) {
            return new WP_Error('no_changes', 'No valid changes provided.', array('status' => 400));
        }

        // Get updated visit info
        $updated_visit_info = $membership_service->get_user_visit_info($user_id, $membership['start_date']);

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Visit information updated: ' . implode(', ', $updated_fields),
            'user_id' => $user_id,
            'updated_fields' => $updated_fields,
            'visit_info' => $updated_visit_info
        ));
    }

    /**
     * Record check-in for user
     */
    public function checkin_user($request)
    {
        $user_id = $request->get_param('id');
        $user = get_user_by('id', $user_id);

        if (!$user) {
            return new WP_Error('user_not_found', 'User not found.', array('status' => 404));
        }

        $membership_service = new Gym_Membership_Service();
        $checkin_result = $membership_service->record_visit_checkin($user_id);

        if (is_wp_error($checkin_result)) {
            return $checkin_result;
        }

        return rest_ensure_response($checkin_result);
    }

    private function format_user_data($user)
    {
        $membership_service = new Gym_Membership_Service();

        wp_cache_delete($user->ID, 'pmpro_membership_levels_for_user');
        wp_cache_delete($user->ID, 'user_meta');

        $membership = $membership_service->get_user_membership($user->ID);

        $qr_service = new Gym_QR_Service();

        wp_cache_delete($user->ID . '_unique_id', 'user_meta');
        wp_cache_delete($user->ID . '_qr_code_url', 'user_meta');

        $qr_data = $qr_service->get_user_qr_code($user->ID);

        $qr_info = array(
            'has_qr_code' => false,
            'unique_id' => null,
            'qr_code_url' => null,
            'generated_by' => null
        );

        if (!is_wp_error($qr_data) && $qr_data['has_qr_code']) {
            $qr_info = array(
                'has_qr_code' => true,
                'unique_id' => $qr_data['unique_id'],
                'qr_code_url' => $qr_data['qr_code_url'],
                'generated_by' => $qr_data['generated_by']
            );
        }

        clean_user_cache($user->ID);
        $first_name = get_user_meta($user->ID, 'first_name', true);
        $last_name = get_user_meta($user->ID, 'last_name', true);

        $profile_picture_url = get_user_meta($user->ID, 'profile_picture_url', true);

        $phone = get_user_meta($user->ID, 'phone', true);

        $formatted_data = array(
            'id' => $user->ID,
            'username' => $user->user_login,
            'email' => $user->user_email,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'display_name' => $user->display_name,
            'registered' => $user->user_registered,
            'phone' => !empty($phone) ? $this->display_phone($phone) : null,
            'membership' => $membership,
            'avatar_url' => get_avatar_url($user->ID),
            'profile_picture_url' => !empty($profile_picture_url) ? $profile_picture_url : null,
            'qr_code' => $qr_info,
            'email_verified' => (bool) get_user_meta($user->ID, 'email_verified', true)
        );

        return $formatted_data;
    }

    /**
     * Helper method to format phone number for display (Nigerian format)
     */
    private function display_phone($phone)
    {
        if (empty($phone)) {
            return '';
        }

        // Clean phone number - remove all non-numeric characters
        $clean_phone = preg_replace('/[^0-9]/', '', $phone);

        // Nigerian phone number formatting
        if (strlen($clean_phone) == 14 && substr($clean_phone, 0, 3) == '234') {
            // International format with +234: +234-803-123-4567
            return '+234-' . substr($clean_phone, 3, 3) . '-' . substr($clean_phone, 6, 3) . '-' . substr($clean_phone, 9, 4);
        } elseif (strlen($clean_phone) == 11 && substr($clean_phone, 0, 1) == '0') {
            // National format starting with 0: 0803-123-4567
            return substr($clean_phone, 0, 4) . '-' . substr($clean_phone, 4, 3) . '-' . substr($clean_phone, 7, 4);
        } elseif (strlen($clean_phone) == 10) {
            // Without leading 0: 803-123-4567
            return substr($clean_phone, 0, 3) . '-' . substr($clean_phone, 3, 3) . '-' . substr($clean_phone, 6, 4);
        } elseif (strlen($clean_phone) == 13 && substr($clean_phone, 0, 3) == '234') {
            // International without + sign: 234-803-123-4567
            return '234-' . substr($clean_phone, 3, 3) . '-' . substr($clean_phone, 6, 3) . '-' . substr($clean_phone, 9, 4);
        } else {
            // Fallback - return clean digits with basic spacing for readability
            if (strlen($clean_phone) > 4) {
                return chunk_split($clean_phone, 4, '-');
            } else {
                return $clean_phone;
            }
        }
    }

    public function check_permission($request)
    {
        // Use the updated method that accepts $request parameter
        return Gym_Admin::check_api_permission($request, 'manage_options');
    }

    public static function validate_verification_token($email, $token)
    {
        return Gym_OTP_Endpoints::validate_verification_token($email, $token);
    }
    /**
     * Get recipient statistics for email sending (gym-aware)
     * FIXED: Added expired users category + ensured consistency with dashboard
     */
    public function get_recipient_stats($request)
    {
        global $wpdb;

        // Get current gym identifier from JWT token
        $current_admin = Gym_Admin::get_current_gym_admin();
        $gym_identifier = $current_admin ? $current_admin->gym_identifier : 'afrgym_one';

        // Get user IDs created by this gym from notes table
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

        // If no users for this gym, return zeros
        if (empty($gym_user_ids)) {
            return rest_ensure_response(array(
                'success' => true,
                'gym_identifier' => $gym_identifier,
                'gym_name' => $gym_identifier === 'afrgym_two' ? 'Afrgym Two' : 'Afrgym One',
                'stats' => array(
                    'all' => 0,
                    'active' => 0,
                    'inactive' => 0,
                    'expired' => 0,
                    'expiring_7days' => 0,
                    'expiring_30days' => 0,
                    'paused' => 0,
                    'by_membership' => array()
                )
            ));
        }

        // FIXED: Filter to only existing users in wp_users table
        $user_ids_string_temp = implode(',', array_map('intval', $gym_user_ids));

        $existing_user_ids = $wpdb->get_col("
        SELECT DISTINCT ID
        FROM {$wpdb->users}
        WHERE ID IN ($user_ids_string_temp)
    ");

        if (empty($existing_user_ids)) {
            return rest_ensure_response(array(
                'success' => true,
                'gym_identifier' => $gym_identifier,
                'gym_name' => $gym_identifier === 'afrgym_two' ? 'Afrgym Two' : 'Afrgym One',
                'stats' => array(
                    'all' => 0,
                    'active' => 0,
                    'inactive' => 0,
                    'expired' => 0,
                    'expiring_7days' => 0,
                    'expiring_30days' => 0,
                    'paused' => 0,
                    'by_membership' => array()
                )
            ));
        }

        $user_ids_string = implode(',', array_map('intval', $existing_user_ids));

        // Get all users count
        $all_count = count($existing_user_ids);

        // Get membership data for gym users
        $pmpro_members_table = $wpdb->prefix . 'pmpro_memberships_users';

        // Active members (not paused, has active membership with valid end date)
        $active_count = $wpdb->get_var("
        SELECT COUNT(DISTINCT mu.user_id)
        FROM $pmpro_members_table mu
        LEFT JOIN {$wpdb->usermeta} pause ON mu.user_id = pause.user_id AND pause.meta_key = 'membership_is_paused'
        WHERE mu.user_id IN ($user_ids_string)
        AND mu.status = 'active'
        AND (pause.meta_value IS NULL OR pause.meta_value = '0')
        AND (mu.enddate IS NULL OR mu.enddate = '0000-00-00 00:00:00' OR mu.enddate >= NOW())
    ");

        // FIXED: Added expired members count
        $expired_count = $wpdb->get_var("
        SELECT COUNT(DISTINCT mu.user_id)
        FROM $pmpro_members_table mu
        WHERE mu.user_id IN ($user_ids_string)
        AND mu.status = 'active'
        AND mu.enddate IS NOT NULL
        AND mu.enddate != '0000-00-00 00:00:00'
        AND mu.enddate < NOW()
    ");

        // Inactive members - users with no active membership
        $inactive_count = $wpdb->get_var("
        SELECT COUNT(DISTINCT u.ID)
        FROM {$wpdb->users} u
        LEFT JOIN $pmpro_members_table mu ON u.ID = mu.user_id AND mu.status = 'active'
        WHERE u.ID IN ($user_ids_string)
        AND mu.user_id IS NULL
    ");

        // Paused memberships
        $paused_count = $wpdb->get_var("
        SELECT COUNT(DISTINCT user_id)
        FROM {$wpdb->usermeta}
        WHERE user_id IN ($user_ids_string)
        AND meta_key = 'membership_is_paused'
        AND meta_value = '1'
    ");

        // Expiring in 7 days
        $today = current_time('Y-m-d');
        $seven_days = date('Y-m-d', strtotime('+7 days'));

        $expiring_7_count = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(DISTINCT mu.user_id)
        FROM $pmpro_members_table mu
        LEFT JOIN {$wpdb->usermeta} pause ON mu.user_id = pause.user_id AND pause.meta_key = 'membership_is_paused'
        WHERE mu.user_id IN ($user_ids_string)
        AND mu.status = 'active'
        AND (pause.meta_value IS NULL OR pause.meta_value = '0')
        AND mu.enddate IS NOT NULL
        AND mu.enddate != '0000-00-00 00:00:00'
        AND mu.enddate BETWEEN %s AND %s
    ", $today, $seven_days));

        // Expiring in 30 days
        $thirty_days = date('Y-m-d', strtotime('+30 days'));

        $expiring_30_count = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(DISTINCT mu.user_id)
        FROM $pmpro_members_table mu
        LEFT JOIN {$wpdb->usermeta} pause ON mu.user_id = pause.user_id AND pause.meta_key = 'membership_is_paused'
        WHERE mu.user_id IN ($user_ids_string)
        AND mu.status = 'active'
        AND (pause.meta_value IS NULL OR pause.meta_value = '0')
        AND mu.enddate IS NOT NULL
        AND mu.enddate != '0000-00-00 00:00:00'
        AND mu.enddate BETWEEN %s AND %s
    ", $today, $thirty_days));

        // Get membership levels breakdown
        $membership_service = new Gym_Membership_Service();
        $all_levels = $membership_service->get_all_membership_levels();

        $by_membership = array();

        foreach ($all_levels as $level) {
            $level_count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT user_id)
            FROM $pmpro_members_table
            WHERE user_id IN ($user_ids_string)
            AND membership_id = %d
            AND status = 'active'
        ", $level['id']));

            if ($level_count > 0) {
                $by_membership[] = array(
                    'id' => (string) $level['id'],
                    'name' => $level['name'],
                    'count' => (int) $level_count
                );
            }
        }

        // Ensure all counts are non-null integers
        $all_count = (int) $all_count;
        $active_count = (int) ($active_count ?: 0);
        $expired_count = (int) ($expired_count ?: 0);
        $inactive_count = (int) ($inactive_count ?: 0);
        $paused_count = (int) ($paused_count ?: 0);
        $expiring_7_count = (int) ($expiring_7_count ?: 0);
        $expiring_30_count = (int) ($expiring_30_count ?: 0);

        return rest_ensure_response(array(
            'success' => true,
            'gym_identifier' => $gym_identifier,
            'gym_name' => $gym_identifier === 'afrgym_two' ? 'Afrgym Two' : 'Afrgym One',
            'stats' => array(
                'all' => $all_count,
                'active' => $active_count,
                'inactive' => $inactive_count,
                'expired' => $expired_count, // FIXED: Added expired category
                'expiring_7days' => $expiring_7_count,
                'expiring_30days' => $expiring_30_count,
                'paused' => $paused_count,
                'by_membership' => $by_membership
            )
        ));
    }

    /**
     * Export users to CSV - GYM FILTERED
     */
    public function export_users_csv($request)
    {
         // ✅ ADD CORS HEADERS FIRST
        // ✅ DYNAMIC CORS - Allow multiple origins
        $allowed_origins = array(
            'https://afrgym-backend.vercel.app',
            "https://afrgym-admin-two.vercel.app",
            'http://localhost:8080',
            'http://localhost:3000',
            'http://localhost:5173', // Vite default

        );

        $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

        if (in_array($origin, $allowed_origins)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
        }

        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');



        $search = $request->get_param('search');
        $membership_status = $request->get_param('membership_status') ?: 'all';

        // ✅ Get current gym identifier
        $current_admin = Gym_Admin::get_current_gym_admin();
        $gym_identifier = $current_admin ? $current_admin->gym_identifier : 'afrgym_one';

        // ✅ Get users filtered by gym
        $users = $this->get_filtered_users_for_export($search, $membership_status, $gym_identifier);

        if (empty($users)) {
            return new WP_Error('no_users', 'No users found to export.', array('status' => 404));
        }

        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="gym-users-' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Open output stream
        $output = fopen('php://output', 'w');

        // Add UTF-8 BOM for Excel compatibility
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Write CSV header
        fputcsv($output, array(
            'ID',
            'Username',
            'Full Name',
            'Email',
            'Phone',
            'Membership Plan',
            'Membership Status',
            'Start Date',
            'Expiry Date',
            'QR Code',
            'Email Verified',
            'Registration Date',
            'Profile Picture URL'
        ));

        // Write user data
        foreach ($users as $user) {
            $user_data = $this->format_user_data($user);

            fputcsv($output, array(
                $user_data['id'],
                $user_data['username'],
                $user_data['display_name'],
                $user_data['email'],
                $user_data['phone'] ?: 'N/A',
                $user_data['membership']['level_name'] ?: 'No Membership',
                $user_data['membership']['status'] ?: 'N/A',
                $user_data['membership']['start_date'] ?: 'N/A',
                $user_data['membership']['expiry_date'] ?: 'N/A',
                $user_data['qr_code']['unique_id'] ?: 'Not Generated',
                $user_data['email_verified'] ? 'Yes' : 'No',
                date('Y-m-d H:i', strtotime($user_data['registered'])),
                $user_data['profile_picture_url'] ?: 'N/A'
            ));
        }

        fclose($output);
        exit;
    }

    /**
     * Export users to PDF - GYM FILTERED
     */
    public function export_users_pdf($request)
    {
         // ✅ ADD CORS HEADERS FIRST
        // ✅ DYNAMIC CORS - Allow multiple origins
        $allowed_origins = array(
            'https://afrgym-backend.vercel.app',
            "https://afrgym-admin-two.vercel.app",
            'http://localhost:8080',
            'http://localhost:3000',
            'http://localhost:5173', // Vite default

        );

        $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

        if (in_array($origin, $allowed_origins)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
        }

        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');

        $search = $request->get_param('search');
        $membership_status = $request->get_param('membership_status') ?: 'all';

        // ✅ Get current gym identifier
        $current_admin = Gym_Admin::get_current_gym_admin();
        $gym_identifier = $current_admin ? $current_admin->gym_identifier : 'afrgym_one';

        // ✅ Get users filtered by gym
        $users = $this->get_filtered_users_for_export($search, $membership_status, $gym_identifier);

        if (empty($users)) {
            return new WP_Error('no_users', 'No users found to export.', array('status' => 404));
        }

        // Generate HTML for PDF
        $html = $this->generate_pdf_html($users);

        // Set headers for PDF download
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="gym-users-' . date('Y-m-d') . '.pdf"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo $html;
        exit;
    }

    /**
     * Get filtered users for export - WITH GYM FILTERING
     */
    private function get_filtered_users_for_export($search, $membership_status, $gym_identifier)
    {
        global $wpdb;

        // ✅ FIRST: Get user IDs created by this gym (same logic as get_users_by_gym)
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

        // If no users for this gym, return empty array
        if (empty($gym_user_ids)) {
            return array();
        }

        // ✅ Filter to only existing users in wp_users table
        $user_ids_string_temp = implode(',', array_map('intval', $gym_user_ids));

        $existing_user_ids = $wpdb->get_col("
        SELECT DISTINCT ID
        FROM {$wpdb->users}
        WHERE ID IN ($user_ids_string_temp)
    ");

        if (empty($existing_user_ids)) {
            return array();
        }

        // ✅ Build user query args with gym-filtered IDs
        $args = array(
            'include' => $existing_user_ids, // Only users from this gym
            'role__not_in' => array('administrator', 'subadmin'),
            'number' => -1 // Get all users
        );

        // Apply search filter
        if (!empty($search)) {
            $args['search'] = '*' . $search . '*';
            $args['search_columns'] = array('user_login', 'user_email', 'display_name');
        }

        // Get users
        $users = get_users($args);

        // Filter by membership status if needed
        if ($membership_status !== 'all') {
            $filtered_users = array();
            $membership_service = new Gym_Membership_Service();

            foreach ($users as $user) {
                $membership = $membership_service->get_user_membership($user->ID);

                $should_include = false;

                switch ($membership_status) {
                    case 'active':
                        $should_include = ($membership['status'] === 'active' && !$membership['is_expired']);
                        break;
                    case 'inactive':
                        $should_include = ($membership['level_id'] === 0);
                        break;
                    case 'expired':
                        $should_include = $membership['is_expired'];
                        break;
                }

                if ($should_include) {
                    $filtered_users[] = $user;
                }
            }

            return $filtered_users;
        }

        return $users;
    }



    /**
     * Generate HTML for PDF export
     */
    private function generate_pdf_html($users)
    {
        $gym_name = get_bloginfo('name');
        $current_date = date('F j, Y g:i A');
        $user_count = count($users);

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>

        <head>
            <meta charset="UTF-8">
            <title>Gym Users Report - <?php echo esc_html($gym_name); ?></title>
            <style>
                @page {
                    margin: 20mm;
                    size: A4 landscape;
                }

                body {
                    font-family: Arial, sans-serif;
                    font-size: 9pt;
                    line-height: 1.3;
                    color: #333;
                }

                .header {
                    text-align: center;
                    margin-bottom: 20px;
                    padding-bottom: 15px;
                    border-bottom: 3px solid #667eea;
                }

                .header h1 {
                    margin: 0;
                    color: #667eea;
                    font-size: 24pt;
                }

                .header p {
                    margin: 5px 0;
                    color: #666;
                }

                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 15px;
                    font-size: 8pt;
                }

                th {
                    background: #667eea;
                    color: white;
                    padding: 8px 5px;
                    text-align: left;
                    font-weight: bold;
                    border: 1px solid #5568d3;
                }

                td {
                    padding: 6px 5px;
                    border: 1px solid #ddd;
                }

                tr:nth-child(even) {
                    background-color: #f8f9fa;
                }

                .status-active {
                    color: #28a745;
                    font-weight: bold;
                }

                .status-expired {
                    color: #dc3545;
                    font-weight: bold;
                }

                .status-inactive {
                    color: #6c757d;
                }

                .footer {
                    margin-top: 20px;
                    padding-top: 15px;
                    border-top: 2px solid #e9ecef;
                    text-align: center;
                    font-size: 8pt;
                    color: #666;
                }

                .img-thumbnail {
                    width: 40px;
                    height: 40px;
                    object-fit: cover;
                    border-radius: 4px;
                    border: 1px solid #ddd;
                }
            </style>
        </head>

        <body>
            <div class="header">
                <h1><?php echo esc_html($gym_name); ?></h1>
                <p><strong>User Directory Report</strong></p>
                <p>Generated: <?php echo $current_date; ?> | Total Users: <?php echo $user_count; ?></p>
            </div>

            <table>
                <thead>
                    <tr>
                        <th style="width: 30px;">ID</th>
                        <th style="width: 80px;">Username</th>
                        <th style="width: 100px;">Full Name</th>
                        <th style="width: 110px;">Email</th>
                        <th style="width: 85px;">Phone</th>
                        <th style="width: 80px;">Membership</th>
                        <th style="width: 60px;">Status</th>
                        <th style="width: 75px;">Expiry Date</th>
                        <th style="width: 75px;">QR Code</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user):
                        $user_data = $this->format_user_data($user);
                        $membership = $user_data['membership'];

                        // Determine status class
                        $status_class = 'status-inactive';
                        if ($membership['status'] === 'active' && !$membership['is_expired']) {
                            $status_class = 'status-active';
                        } elseif ($membership['is_expired']) {
                            $status_class = 'status-expired';
                        }
                        ?>
                        <tr>
                            <td><?php echo esc_html($user_data['id']); ?></td>
                            <td><?php echo esc_html($user_data['username']); ?></td>
                            <td><strong><?php echo esc_html($user_data['display_name']); ?></strong></td>
                            <td><?php echo esc_html($user_data['email']); ?></td>
                            <td><?php echo esc_html($user_data['phone'] ?: 'N/A'); ?></td>
                            <td><?php echo esc_html($membership['level_name'] ?: 'None'); ?></td>
                            <td class="<?php echo $status_class; ?>">
                                <?php
                                if ($membership['is_expired']) {
                                    echo 'Expired';
                                } elseif ($membership['status'] === 'active') {
                                    echo 'Active';
                                } else {
                                    echo 'Inactive';
                                }
                                ?>
                            </td>
                            <td><?php echo $membership['expiry_date'] ? date('M j, Y', strtotime($membership['expiry_date'])) : 'N/A'; ?>
                            </td>
                            <td><?php echo esc_html($user_data['qr_code']['unique_id'] ?: 'Not Generated'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="footer">
                <p><strong><?php echo esc_html($gym_name); ?></strong> | User Directory Report</p>
                <p>This report contains confidential information. Handle with care.</p>
                <p style="margin-top: 10px;">Generated on <?php echo $current_date; ?></p>
            </div>
        </body>

        </html>
        <?php
        return ob_get_clean();
    }
}