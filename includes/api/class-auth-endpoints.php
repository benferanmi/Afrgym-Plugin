<?php
/**
 * Authentication API endpoints with DUAL GYM SYSTEM
 */
class Gym_Auth_Endpoints
{

    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes()
    {
        // === GYM ONE LOGIN ===
        register_rest_route('gym-admin/v1', '/auth/login/gym-one', array(
            'methods' => 'POST',
            'callback' => array($this, 'login_gym_one'),
            'permission_callback' => '__return_true',
            'args' => array(
                'username' => array('required' => true, 'type' => 'string'),
                'password' => array('required' => true, 'type' => 'string')
            )
        ));

        // === GYM TWO LOGIN ===
        register_rest_route('gym-admin/v1', '/auth/login/gym-two', array(
            'methods' => 'POST',
            'callback' => array($this, 'login_gym_two'),
            'permission_callback' => '__return_true',
            'args' => array(
                'username' => array('required' => true, 'type' => 'string'),
                'password' => array('required' => true, 'type' => 'string')
            )
        ));

        // LEGACY: Keep old endpoint for backward compatibility (defaults to Gym One)
        register_rest_route('gym-admin/v1', '/auth/login', array(
            'methods' => 'POST',
            'callback' => array($this, 'login_gym_one'),
            'permission_callback' => '__return_true'
        ));

        register_rest_route('gym-admin/v1', '/auth/logout', array(
            'methods' => 'POST',
            'callback' => array($this, 'logout'),
            'permission_callback' => array($this, 'check_auth_permission')
        ));

        register_rest_route('gym-admin/v1', '/auth/validate', array(
            'methods' => 'GET',
            'callback' => array($this, 'validate_token'),
            'permission_callback' => array($this, 'check_auth_permission')
        ));

        // === CREATE ADMIN FOR SPECIFIC GYM ===
        register_rest_route('gym-admin/v1', '/auth/create-admin', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_admin'),
            'permission_callback' => array($this, 'check_super_admin_permission'),
            'args' => array(
                'gym_type' => array(
                    'required' => true,
                    'type' => 'string',
                    'enum' => array('gym_one', 'gym_two'),
                    'description' => 'Which gym to create admin for: gym_one or gym_two'
                ),
                'username' => array('required' => true, 'type' => 'string'),
                'email' => array('required' => true, 'type' => 'string'),
                'password' => array('required' => true, 'type' => 'string'),
                'first_name' => array('required' => true, 'type' => 'string'),
                'last_name' => array('required' => true, 'type' => 'string'),
                'role' => array('required' => false, 'type' => 'string', 'default' => 'admin', 'enum' => array('admin', 'super_admin'))
            )
        ));

        register_rest_route('gym-admin/v1', '/auth/change-password', array(
            'methods' => 'POST',
            'callback' => array($this, 'change_password'),
            'permission_callback' => array($this, 'check_auth_permission'),
            'args' => array(
                'current_password' => array('required' => true, 'type' => 'string'),
                'new_password' => array('required' => true, 'type' => 'string')
            )
        ));
    }

    /**
     * Login for Gym One
     */
    public function login_gym_one($request)
    {
        return $this->handle_login($request, 'gym_one');
    }

    /**
     * Login for Gym Two
     */
    public function login_gym_two($request)
    {
        return $this->handle_login($request, 'gym_two');
    }

    /**
     * Unified login handler for both gyms
     */
    private function handle_login($request, $gym_type)
    {
        global $wpdb;

        $username = $request->get_param('username');
        $password = $request->get_param('password');
        $ip_address = $this->get_client_ip();
        $user_agent = $request->get_header('user-agent');

        // Determine which table to query
        $table_name = ($gym_type === 'gym_two') ?
            $wpdb->prefix . 'gym_admins_two' :
            $wpdb->prefix . 'gym_admins';

        // Get admin from appropriate gym table
        $admin = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE (username = %s OR email = %s) AND status = 'active'",
            $username,
            $username
        ));

        if (!$admin) {
            return new WP_Error('invalid_credentials', 'Invalid username or password.', array('status' => 401));
        }

        // Check if account is locked
        if ($admin->locked_until && current_time('mysql') < $admin->locked_until) {
            return new WP_Error('account_locked', 'Account is temporarily locked due to too many failed login attempts.', array('status' => 423));
        }

        // Verify password
        if (!wp_check_password($password, $admin->password)) {
            $this->handle_failed_login($admin->id, $gym_type);
            return new WP_Error('invalid_credentials', 'Invalid username or password.', array('status' => 401));
        }

        // Reset failed attempts
        $this->reset_failed_attempts($admin->id, $gym_type);

        // Generate JWT token with gym type
        $token = Gym_Admin::generate_jwt_token($admin->id, $gym_type);

        // Store session with gym type
        $this->create_admin_session($admin->id, $gym_type, $token, $ip_address, $user_agent);

        // Update last login
        $wpdb->update($table_name, array('last_login' => current_time('mysql')), array('id' => $admin->id));

        return rest_ensure_response(array(
            'success' => true,
            'token' => $token,
            'gym_type' => $gym_type,
            'gym_name' => $gym_type === 'gym_two' ? 'Afrgym Two' : 'Afrgym One',
            'admin' => array(
                'id' => $admin->id,
                'username' => $admin->username,
                'email' => $admin->email,
                'first_name' => $admin->first_name,
                'last_name' => $admin->last_name,
                'role' => $admin->role,
                'gym_identifier' => $admin->gym_identifier,
                'full_name' => $admin->first_name . ' ' . $admin->last_name
            ),
            'expires_in' => $this->get_jwt_expiry() * 3600
        ));
    }

    public function logout($request)
    {
        $auth_header = $request->get_header('authorization');

        if ($auth_header && preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
            $token = $matches[1];
            $this->invalidate_session($token);
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Logged out successfully'
        ));
    }

    public function validate_token($request)
    {
        $admin_data = $this->authenticate_request($request);

        if (is_wp_error($admin_data)) {
            return new WP_Error('invalid_token', 'Invalid or expired token.', array('status' => 401));
        }

        return rest_ensure_response(array(
            'valid' => true,
            'gym_type' => $admin_data['gym_type'],
            'gym_name' => $admin_data['gym_name'],
            'admin' => array(
                'id' => $admin_data['admin']->id,
                'username' => $admin_data['admin']->username,
                'email' => $admin_data['admin']->email,
                'first_name' => $admin_data['admin']->first_name,
                'last_name' => $admin_data['admin']->last_name,
                'role' => $admin_data['admin']->role,
                'gym_identifier' => $admin_data['admin']->gym_identifier,
                'full_name' => $admin_data['admin']->first_name . ' ' . $admin_data['admin']->last_name
            )
        ));
    }

    /**
     * Create admin for specific gym
     */
    public function create_admin($request)
    {
        global $wpdb;

        $gym_type = $request->get_param('gym_type');
        $username = $request->get_param('username');
        $email = $request->get_param('email');
        $password = $request->get_param('password');
        $first_name = $request->get_param('first_name');
        $last_name = $request->get_param('last_name');
        $role = $request->get_param('role');

        // Validate
        if (!is_email($email)) {
            return new WP_Error('invalid_email', 'Invalid email format.', array('status' => 400));
        }

        if (strlen($password) < 8) {
            return new WP_Error('weak_password', 'Password must be at least 8 characters long.', array('status' => 400));
        }

        // Determine table and gym identifier
        $table_name = ($gym_type === 'gym_two') ?
            $wpdb->prefix . 'gym_admins_two' :
            $wpdb->prefix . 'gym_admins';

        $gym_identifier = ($gym_type === 'gym_two') ? 'afrgym_two' : 'afrgym_one';

        // Check if username/email exists in BOTH tables
        $gym_one_table = $wpdb->prefix . 'gym_admins';
        $gym_two_table = $wpdb->prefix . 'gym_admins_two';

        $exists_one = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $gym_one_table WHERE username = %s OR email = %s",
            $username,
            $email
        ));

        $exists_two = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $gym_two_table WHERE username = %s OR email = %s",
            $username,
            $email
        ));

        if ($exists_one > 0 || $exists_two > 0) {
            return new WP_Error('admin_exists', 'Admin with this username or email already exists.', array('status' => 409));
        }

        // Create admin
        $admin_data = array(
            'username' => $username,
            'email' => $email,
            'password' => wp_hash_password($password),
            'first_name' => $first_name,
            'last_name' => $last_name,
            'role' => $role,
            'gym_identifier' => $gym_identifier,
            'status' => 'active'
        );

        $result = $wpdb->insert($table_name, $admin_data);

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to create admin.', array('status' => 500));
        }

        $new_admin_id = $wpdb->insert_id;

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Admin created successfully',
            'gym_type' => $gym_type,
            'gym_name' => $gym_type === 'gym_two' ? 'Afrgym Two' : 'Afrgym One',
            'admin' => array(
                'id' => $new_admin_id,
                'username' => $username,
                'email' => $email,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'role' => $role,
                'gym_identifier' => $gym_identifier
            )
        ));
    }

    public function change_password($request)
    {
        global $wpdb;

        $current_password = $request->get_param('current_password');
        $new_password = $request->get_param('new_password');

        $admin_data = $this->get_current_admin_with_gym();
        $admin = $admin_data['admin'];
        $gym_type = $admin_data['gym_type'];

        if (!wp_check_password($current_password, $admin->password)) {
            return new WP_Error('incorrect_password', 'Current password is incorrect.', array('status' => 400));
        }

        if (strlen($new_password) < 8) {
            return new WP_Error('weak_password', 'New password must be at least 8 characters long.', array('status' => 400));
        }

        $table_name = ($gym_type === 'gym_two') ?
            $wpdb->prefix . 'gym_admins_two' :
            $wpdb->prefix . 'gym_admins';

        $result = $wpdb->update(
            $table_name,
            array('password' => wp_hash_password($new_password)),
            array('id' => $admin->id)
        );

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to update password.', array('status' => 500));
        }

        $this->invalidate_all_admin_sessions($admin->id, $gym_type);

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Password changed successfully. Please login again.'
        ));
    }

    public function check_auth_permission($request)
    {
        $admin_data = $this->authenticate_request($request);
        return is_wp_error($admin_data) ? $admin_data : true;
    }

    public function check_super_admin_permission($request)
    {
        $admin_data = $this->authenticate_request($request);

        if (is_wp_error($admin_data)) {
            return $admin_data;
        }

        if ($admin_data['admin']->role !== 'super_admin') {
            return new WP_Error('insufficient_permissions', 'Super admin access required.', array('status' => 403));
        }

        return true;
    }

    /**
     * Authenticate request and return admin with gym type
     */
    private function authenticate_request($request)
    {
        $auth_header = $request->get_header('authorization');

        if (!$auth_header) {
            return new WP_Error('missing_auth', 'Authorization header missing.', array('status' => 401));
        }

        if (!preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
            return new WP_Error('invalid_auth_format', 'Invalid authorization format.', array('status' => 401));
        }

        $token = $matches[1];
        $payload = Gym_Admin::verify_jwt_token($token);

        if (!$payload) {
            return new WP_Error('invalid_token', 'Invalid or expired token.', array('status' => 401));
        }

        $gym_type = $payload['gym_type'] ?? 'gym_one';
        $admin = $this->get_gym_admin_by_id($payload['user_id'], $gym_type);

        if (!$admin) {
            return new WP_Error('invalid_user', 'Invalid user or insufficient permissions.', array('status' => 403));
        }

        $gym_name = ($gym_type === 'gym_two') ? 'Afrgym Two' : 'Afrgym One';

        return array(
            'admin' => $admin,
            'gym_type' => $gym_type,
            'gym_name' => $gym_name
        );
    }

    /**
     * Get gym admin by ID from appropriate table
     */
    private function get_gym_admin_by_id($admin_id, $gym_type)
    {
        global $wpdb;

        $table_name = ($gym_type === 'gym_two') ?
            $wpdb->prefix . 'gym_admins_two' :
            $wpdb->prefix . 'gym_admins';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d AND status = 'active'",
            $admin_id
        ));
    }

    /**
     * Get current admin with gym info
     */
    private function get_current_admin_with_gym()
    {
        return Gym_Admin::get_current_gym_admin_full();
    }

    private function create_admin_session($admin_id, $gym_type, $token, $ip_address, $user_agent)
    {
        global $wpdb;

        $sessions_table = $wpdb->prefix . 'gym_admin_sessions';

        $session_data = array(
            'admin_id' => $admin_id,
            'gym_type' => $gym_type,
            'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', time() + ($this->get_jwt_expiry() * 3600)),
            'ip_address' => $ip_address,
            'user_agent' => $user_agent,
            'is_active' => 1
        );

        $wpdb->insert($sessions_table, $session_data);
        return $wpdb->insert_id;
    }

    private function invalidate_session($token)
    {
        global $wpdb;

        $sessions_table = $wpdb->prefix . 'gym_admin_sessions';
        $token_hash = hash('sha256', $token);

        $wpdb->update(
            $sessions_table,
            array('is_active' => 0),
            array('token_hash' => $token_hash)
        );
    }

    private function invalidate_all_admin_sessions($admin_id, $gym_type)
    {
        global $wpdb;

        $sessions_table = $wpdb->prefix . 'gym_admin_sessions';

        $wpdb->update(
            $sessions_table,
            array('is_active' => 0),
            array(
                'admin_id' => $admin_id,
                'gym_type' => $gym_type
            )
        );
    }

    private function handle_failed_login($admin_id, $gym_type)
    {
        global $wpdb;

        $table_name = ($gym_type === 'gym_two') ?
            $wpdb->prefix . 'gym_admins_two' :
            $wpdb->prefix . 'gym_admins';

        $max_attempts = $this->get_max_login_attempts();
        $lockout_duration = $this->get_lockout_duration();

        $wpdb->query($wpdb->prepare(
            "UPDATE $table_name SET failed_login_attempts = failed_login_attempts + 1 WHERE id = %d",
            $admin_id
        ));

        $failed_attempts = $wpdb->get_var($wpdb->prepare(
            "SELECT failed_login_attempts FROM $table_name WHERE id = %d",
            $admin_id
        ));

        if ($failed_attempts >= $max_attempts) {
            $locked_until = date('Y-m-d H:i:s', time() + ($lockout_duration * 60));
            $wpdb->update(
                $table_name,
                array('locked_until' => $locked_until),
                array('id' => $admin_id)
            );
        }
    }

    private function reset_failed_attempts($admin_id, $gym_type)
    {
        global $wpdb;

        $table_name = ($gym_type === 'gym_two') ?
            $wpdb->prefix . 'gym_admins_two' :
            $wpdb->prefix . 'gym_admins';

        $wpdb->update(
            $table_name,
            array(
                'failed_login_attempts' => 0,
                'locked_until' => null
            ),
            array('id' => $admin_id)
        );
    }

    private function get_client_ip()
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        }
    }

    private function get_jwt_expiry()
    {
        global $wpdb;

        $settings_table = $wpdb->prefix . 'gym_settings';
        $expiry = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM $settings_table WHERE setting_name = %s",
            'jwt_expiry_hours'
        ));

        return $expiry ? intval($expiry) : 24;
    }

    private function get_max_login_attempts()
    {
        global $wpdb;

        $settings_table = $wpdb->prefix . 'gym_settings';
        $attempts = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM $settings_table WHERE setting_name = %s",
            'max_login_attempts'
        ));

        return $attempts ? intval($attempts) : 5;
    }

    private function get_lockout_duration()
    {
        global $wpdb;

        $settings_table = $wpdb->prefix . 'gym_settings';
        $duration = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM $settings_table WHERE setting_name = %s",
            'lockout_duration_minutes'
        ));

        return $duration ? intval($duration) : 30;
    }
}