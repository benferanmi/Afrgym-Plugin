<?php
/**
 * Core gym admin functionality - UPDATED for DUAL GYM SYSTEM
 */
class Gym_Admin
{
    // Store current gym admin for this request
    private static $current_gym_admin = null;
    private static $current_gym_type = null;

    public static function get_setting($name, $default = '')
    {
        global $wpdb;

        $table = $wpdb->prefix . 'gym_settings';
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM $table WHERE setting_name = %s",
            $name
        ));

        return $value !== null ? $value : $default;
    }

    public static function update_setting($name, $value)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'gym_settings';
        return $wpdb->replace($table, array(
            'setting_name' => $name,
            'setting_value' => $value
        ));
    }

    /**
     * UPDATED: Add user note with gym tracking
     */
    public static function add_user_note($user_id, $note, $admin_id = null)
    {
        global $wpdb;

        $gym_identifier = null;
        $admin_name = null;

        // Use current gym admin if available
        if (!$admin_id && self::$current_gym_admin) {
            $admin_id = self::$current_gym_admin->id;
            $gym_identifier = self::$current_gym_admin->gym_identifier;
            $admin_name = self::$current_gym_admin->first_name . ' ' . self::$current_gym_admin->last_name;
        } elseif ($admin_id && self::$current_gym_type) {
            // Get admin details if we have admin_id
            $table_name = (self::$current_gym_type === 'gym_two') ?
                $wpdb->prefix . 'gym_admins_two' :
                $wpdb->prefix . 'gym_admins';

            $admin = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE id = %d",
                $admin_id
            ));

            if ($admin) {
                $gym_identifier = $admin->gym_identifier;
                $admin_name = $admin->first_name . ' ' . $admin->last_name;
            }
        }

        $table = $wpdb->prefix . 'gym_user_notes';
        return $wpdb->insert($table, array(
            'user_id' => $user_id,
            'admin_id' => $admin_id,
            'gym_identifier' => $gym_identifier,
            'admin_name' => $admin_name,
            'note' => sanitize_textarea_field($note)
        ));
    }

    /**
     * UPDATED: Get user notes with gym information
     */
    public static function get_user_notes($user_id, $limit = 10)
    {
        global $wpdb;

        $notes_table = $wpdb->prefix . 'gym_user_notes';

        $notes = $wpdb->get_results($wpdb->prepare(
            "SELECT n.*, 
                    CASE 
                        WHEN n.gym_identifier = 'afrgym_one' THEN 'Afrgym One'
                        WHEN n.gym_identifier = 'afrgym_two' THEN 'Afrgym Two'
                        ELSE 'Unknown Gym'
                    END as gym_name
             FROM $notes_table n 
             WHERE n.user_id = %d 
             ORDER BY n.created_at DESC 
             LIMIT %d",
            $user_id,
            $limit
        ));

        return $notes;
    }

    /**
     * UPDATED: Log email with gym tracking
     */
    public static function log_email($user_id, $email, $subject, $template, $status = 'pending')
    {
        global $wpdb;

        $admin_id = null;
        $gym_identifier = null;

        if (self::$current_gym_admin) {
            $admin_id = self::$current_gym_admin->id;
            $gym_identifier = self::$current_gym_admin->gym_identifier;
        }

        $table = $wpdb->prefix . 'gym_email_logs';
        return $wpdb->insert($table, array(
            'user_id' => $user_id,
            'admin_id' => $admin_id,
            'gym_identifier' => $gym_identifier,
            'recipient_email' => $email,
            'subject' => $subject,
            'template_name' => $template,
            'status' => $status
        ));
    }

    public static function update_email_status($log_id, $status, $error_message = null)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'gym_email_logs';
        $data = array('status' => $status);

        if ($status === 'sent') {
            $data['sent_at'] = current_time('mysql');
        }

        if ($error_message) {
            $data['error_message'] = $error_message;
        }

        return $wpdb->update($table, $data, array('id' => $log_id));
    }

    /**
     * UPDATED: Generate JWT token with gym type
     */
    public static function generate_jwt_token($admin_id, $gym_type = 'gym_one')
    {
        global $wpdb;

        // Get gym admin details from appropriate table
        $table_name = ($gym_type === 'gym_two') ?
            $wpdb->prefix . 'gym_admins_two' :
            $wpdb->prefix . 'gym_admins';

        $admin = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d AND status = 'active'",
            $admin_id
        ));

        if (!$admin) {
            return false;
        }

        $header = json_encode(array('typ' => 'JWT', 'alg' => 'HS256'));

        // Payload includes gym_type for dual system
        $payload = json_encode(array(
            'user_id' => $admin_id,
            'admin_id' => $admin_id,
            'username' => $admin->username,
            'role' => $admin->role,
            'gym_type' => $gym_type,
            'gym_identifier' => $admin->gym_identifier,
            'iat' => time(),
            'exp' => time() + (24 * 60 * 60)
        ));

        $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));

        $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, self::get_jwt_secret(), true);
        $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        return $base64Header . "." . $base64Payload . "." . $base64Signature;
    }

    public static function verify_jwt_token($token)
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        list($header, $payload, $signature) = $parts;

        $valid_signature = hash_hmac('sha256', $header . "." . $payload, self::get_jwt_secret(), true);
        $valid_signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($valid_signature));

        if (!hash_equals($signature, $valid_signature)) {
            return false;
        }

        $payload_data = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $payload)), true);

        if (!$payload_data || $payload_data['exp'] < time()) {
            return false;
        }

        return $payload_data;
    }

    /**
     * UPDATED: Check API permission with dual gym system
     */
    public static function check_api_permission($request, $capability = 'manage_options')
    {
        $auth_header = $request->get_header('authorization');

        if (!$auth_header) {
            return new WP_Error('jwt_auth_no_auth_header', 'Authorization header not found.', array('status' => 401));
        }

        if (!preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
            return new WP_Error('jwt_auth_bad_auth_header', 'Authorization header malformed.', array('status' => 401));
        }

        $token = $matches[1];
        $payload = self::verify_jwt_token($token);

        if (!$payload) {
            return new WP_Error('jwt_auth_invalid_token', 'Token is invalid.', array('status' => 403));
        }

        // Get gym type from payload
        $gym_type = $payload['gym_type'] ?? 'gym_one';

        // Get admin from appropriate table
        $admin = self::get_gym_admin_by_id($payload['user_id'], $gym_type);

        if (!$admin) {
            return new WP_Error('jwt_auth_user_not_found', 'User not found.', array('status' => 403));
        }

        // Store current gym admin and type
        self::$current_gym_admin = $admin;
        self::$current_gym_type = $gym_type;

        // Check permissions
        if (!self::gym_admin_can($admin, $capability)) {
            return new WP_Error('jwt_auth_insufficient_permissions', 'You do not have permission to access this resource.', array('status' => 403));
        }

        // Update session last used time
        self::update_session_last_used($token);

        return true;
    }

    /**
     * Get gym admin by ID from appropriate table
     */
    private static function get_gym_admin_by_id($admin_id, $gym_type)
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

    private static function gym_admin_can($admin, $capability)
    {
        if ($admin->role === 'super_admin') {
            return true;
        }

        $capability_map = array(
            'manage_options' => true,
            'manage_gym_users' => true,
            'manage_gym_memberships' => true,
            'send_gym_emails' => true,
            'manage_gym_qr' => true,
            'view_gym_reports' => true
        );

        return isset($capability_map[$capability]) && $capability_map[$capability];
    }

    private static function update_session_last_used($token)
    {
        global $wpdb;

        $sessions_table = $wpdb->prefix . 'gym_admin_sessions';
        $token_hash = hash('sha256', $token);

        $wpdb->update(
            $sessions_table,
            array('last_used_at' => current_time('mysql')),
            array('token_hash' => $token_hash, 'is_active' => 1)
        );
    }

    /**
     * Get current gym admin
     */
    public static function get_current_gym_admin()
    {
        return self::$current_gym_admin;
    }

    /**
     * Get current gym admin with full info
     */
    public static function get_current_gym_admin_full()
    {
        if (!self::$current_gym_admin) {
            return null;
        }

        $gym_name = (self::$current_gym_type === 'gym_two') ? 'Afrgym Two' : 'Afrgym One';

        return array(
            'admin' => self::$current_gym_admin,
            'gym_type' => self::$current_gym_type,
            'gym_name' => $gym_name
        );
    }

    /**
     * Get current gym type
     */
    public static function get_current_gym_type()
    {
        return self::$current_gym_type;
    }

    /**
     * Get current gym name
     */
    public static function get_current_gym_name()
    {
        return (self::$current_gym_type === 'gym_two') ? 'Afrgym Two' : 'Afrgym One';
    }

    private static function get_jwt_secret()
    {
        $secret = get_option('gym_admin_jwt_secret');
        if (!$secret) {
            $secret = wp_generate_password(64, true, true);
            update_option('gym_admin_jwt_secret', $secret);
        }
        return $secret;
    }

    public static function check_simple_permission($capability = 'manage_options')
    {
        if (!current_user_can($capability)) {
            return new WP_Error('insufficient_permissions', 'You do not have permission to access this resource.', array('status' => 403));
        }
        return true;
    }

    public static function validate_email($email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function sanitize_user_data($data)
    {
        $sanitized = array();

        if (isset($data['first_name'])) {
            $sanitized['first_name'] = sanitize_text_field($data['first_name']);
        }
        if (isset($data['last_name'])) {
            $sanitized['last_name'] = sanitize_text_field($data['last_name']);
        }
        if (isset($data['email'])) {
            $sanitized['user_email'] = sanitize_email($data['email']);
        }
        if (isset($data['phone'])) {
            $sanitized['phone'] = sanitize_text_field($data['phone']);
        }

        return $sanitized;
    }
}