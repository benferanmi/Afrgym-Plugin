<?php
/**
 * Filename: includes/services/class-admin-service.php
 * Service class for gym admin management
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Gym_Admin_Service
{

    /**
     * Create a new gym admin
     */
    public static function create_admin($data)
    {
        global $wpdb;

        $gym_admins_table = $wpdb->prefix . 'gym_admins';

        // Validate required fields
        $required_fields = array('username', 'email', 'password', 'first_name', 'last_name');
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                return new WP_Error('missing_field', "Field '$field' is required.", array('status' => 400));
            }
        }

        // Validate email format
        if (!is_email($data['email'])) {
            return new WP_Error('invalid_email', 'Invalid email format.', array('status' => 400));
        }

        // Check password strength
        if (strlen($data['password']) < 8) {
            return new WP_Error('weak_password', 'Password must be at least 8 characters long.', array('status' => 400));
        }

        // Check if username or email already exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $gym_admins_table WHERE username = %s OR email = %s",
            $data['username'],
            $data['email']
        ));

        if ($existing > 0) {
            return new WP_Error('admin_exists', 'Admin with this username or email already exists.', array('status' => 409));
        }

        // Prepare admin data
        $admin_data = array(
            'username' => sanitize_user($data['username']),
            'email' => sanitize_email($data['email']),
            'password' => wp_hash_password($data['password']),
            'first_name' => sanitize_text_field($data['first_name']),
            'last_name' => sanitize_text_field($data['last_name']),
            'role' => isset($data['role']) && in_array($data['role'], array('admin', 'super_admin')) ? $data['role'] : 'admin',
            'status' => 'active'
        );

        $result = $wpdb->insert($gym_admins_table, $admin_data);

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to create admin: ' . $wpdb->last_error, array('status' => 500));
        }

        return $wpdb->insert_id;
    }

    /**
     * Get admin by ID
     */
    public static function get_admin($admin_id)
    {
        global $wpdb;

        $gym_admins_table = $wpdb->prefix . 'gym_admins';

        $admin = $wpdb->get_row($wpdb->prepare(
            "SELECT id, username, email, first_name, last_name, role, status, last_login, created_at FROM $gym_admins_table WHERE id = %d",
            $admin_id
        ));

        if (!$admin) {
            return new WP_Error('admin_not_found', 'Admin not found.', array('status' => 404));
        }

        return $admin;
    }

    /**
     * Get admin by username or email
     */
    public static function get_admin_by_login($login)
    {
        global $wpdb;

        $gym_admins_table = $wpdb->prefix . 'gym_admins';

        $admin = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $gym_admins_table WHERE (username = %s OR email = %s) AND status = 'active'",
            $login,
            $login
        ));

        return $admin;
    }

    /**
     * List all admins
     */
    public static function list_admins($args = array())
    {
        global $wpdb;

        $gym_admins_table = $wpdb->prefix . 'gym_admins';

        // Default parameters
        $defaults = array(
            'per_page' => 20,
            'page' => 1,
            'search' => '',
            'role' => '',
            'status' => 'active',
            'orderby' => 'created_at',
            'order' => 'DESC'
        );

        $args = wp_parse_args($args, $defaults);

        // Build query
        $where_conditions = array();
        $where_values = array();

        if (!empty($args['search'])) {
            $where_conditions[] = "(username LIKE %s OR email LIKE %s OR first_name LIKE %s OR last_name LIKE %s)";
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $where_values = array_merge($where_values, array($search_term, $search_term, $search_term, $search_term));
        }

        if (!empty($args['role'])) {
            $where_conditions[] = "role = %s";
            $where_values[] = $args['role'];
        }

        if (!empty($args['status'])) {
            $where_conditions[] = "status = %s";
            $where_values[] = $args['status'];
        }

        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

        // Order by
        $allowed_orderby = array('id', 'username', 'email', 'first_name', 'last_name', 'role', 'status', 'created_at', 'last_login');
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        // Pagination
        $offset = ($args['page'] - 1) * $args['per_page'];
        $limit = intval($args['per_page']);

        // Get total count
        $count_query = "SELECT COUNT(*) FROM $gym_admins_table $where_clause";
        if (!empty($where_values)) {
            $total = $wpdb->get_var($wpdb->prepare($count_query, $where_values));
        } else {
            $total = $wpdb->get_var($count_query);
        }

        // Get admins
        $query = "SELECT id, username, email, first_name, last_name, role, status, last_login, created_at 
                  FROM $gym_admins_table 
                  $where_clause 
                  ORDER BY $orderby $order 
                  LIMIT %d OFFSET %d";

        $query_values = array_merge($where_values, array($limit, $offset));
        $admins = $wpdb->get_results($wpdb->prepare($query, $query_values));

        return array(
            'admins' => $admins,
            'total' => intval($total),
            'pages' => ceil($total / $args['per_page']),
            'current_page' => $args['page'],
            'per_page' => $args['per_page']
        );
    }

    /**
     * Update admin
     */
    public static function update_admin($admin_id, $data)
    {
        global $wpdb;

        $gym_admins_table = $wpdb->prefix . 'gym_admins';

        // Check if admin exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $gym_admins_table WHERE id = %d",
            $admin_id
        ));

        if ($existing == 0) {
            return new WP_Error('admin_not_found', 'Admin not found.', array('status' => 404));
        }

        // Prepare update data
        $update_data = array();

        if (isset($data['username'])) {
            // Check if new username already exists (excluding current admin)
            $username_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $gym_admins_table WHERE username = %s AND id != %d",
                $data['username'],
                $admin_id
            ));

            if ($username_exists > 0) {
                return new WP_Error('username_exists', 'Username already exists.', array('status' => 409));
            }

            $update_data['username'] = sanitize_user($data['username']);
        }

        if (isset($data['email'])) {
            if (!is_email($data['email'])) {
                return new WP_Error('invalid_email', 'Invalid email format.', array('status' => 400));
            }

            // Check if new email already exists (excluding current admin)
            $email_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $gym_admins_table WHERE email = %s AND id != %d",
                $data['email'],
                $admin_id
            ));

            if ($email_exists > 0) {
                return new WP_Error('email_exists', 'Email already exists.', array('status' => 409));
            }

            $update_data['email'] = sanitize_email($data['email']);
        }

        if (isset($data['first_name'])) {
            $update_data['first_name'] = sanitize_text_field($data['first_name']);
        }

        if (isset($data['last_name'])) {
            $update_data['last_name'] = sanitize_text_field($data['last_name']);
        }

        if (isset($data['role']) && in_array($data['role'], array('admin', 'super_admin'))) {
            $update_data['role'] = $data['role'];
        }

        if (isset($data['status']) && in_array($data['status'], array('active', 'inactive'))) {
            $update_data['status'] = $data['status'];
        }

        if (empty($update_data)) {
            return new WP_Error('no_data', 'No valid data to update.', array('status' => 400));
        }

        $result = $wpdb->update($gym_admins_table, $update_data, array('id' => $admin_id));

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to update admin.', array('status' => 500));
        }

        return true;
    }

    /**
     * Delete admin (soft delete - set status to inactive)
     */
    public static function delete_admin($admin_id)
    {
        global $wpdb;

        $gym_admins_table = $wpdb->prefix . 'gym_admins';

        // Check if admin exists
        $admin = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $gym_admins_table WHERE id = %d",
            $admin_id
        ));

        if (!$admin) {
            return new WP_Error('admin_not_found', 'Admin not found.', array('status' => 404));
        }

        // Don't allow deleting the last super_admin
        if ($admin->role === 'super_admin') {
            $super_admin_count = $wpdb->get_var(
                "SELECT COUNT(*) FROM $gym_admins_table WHERE role = 'super_admin' AND status = 'active'"
            );

            if ($super_admin_count <= 1) {
                return new WP_Error('cannot_delete_last_super_admin', 'Cannot delete the last super admin.', array('status' => 403));
            }
        }

        // Soft delete - set status to inactive
        $result = $wpdb->update(
            $gym_admins_table,
            array('status' => 'inactive'),
            array('id' => $admin_id)
        );

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to delete admin.', array('status' => 500));
        }

        // Invalidate all sessions for this admin
        self::invalidate_all_admin_sessions($admin_id);

        return true;
    }

    /**
     * Change admin password
     */
    public static function change_admin_password($admin_id, $new_password)
    {
        global $wpdb;

        if (strlen($new_password) < 8) {
            return new WP_Error('weak_password', 'Password must be at least 8 characters long.', array('status' => 400));
        }

        $gym_admins_table = $wpdb->prefix . 'gym_admins';

        $result = $wpdb->update(
            $gym_admins_table,
            array('password' => wp_hash_password($new_password)),
            array('id' => $admin_id)
        );

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to update password.', array('status' => 500));
        }

        // Invalidate all sessions for this admin (force re-login)
        self::invalidate_all_admin_sessions($admin_id);

        return true;
    }

    /**
     * Get admin sessions
     */
    public static function get_admin_sessions($admin_id)
    {
        global $wpdb;

        $sessions_table = $wpdb->prefix . 'gym_admin_sessions';

        $sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT id, ip_address, user_agent, created_at, last_used_at, expires_at, is_active 
             FROM $sessions_table 
             WHERE admin_id = %d 
             ORDER BY last_used_at DESC",
            $admin_id
        ));

        return $sessions;
    }

    /**
     * Invalidate all sessions for an admin
     */
    public static function invalidate_all_admin_sessions($admin_id)
    {
        global $wpdb;

        $sessions_table = $wpdb->prefix . 'gym_admin_sessions';

        $wpdb->update(
            $sessions_table,
            array('is_active' => 0),
            array('admin_id' => $admin_id)
        );
    }

    /**
     * Clean up expired sessions
     */
    public static function cleanup_expired_sessions()
    {
        global $wpdb;

        $sessions_table = $wpdb->prefix . 'gym_admin_sessions';

        $wpdb->query(
            "UPDATE $sessions_table SET is_active = 0 WHERE expires_at < NOW()"
        );

        // Delete sessions older than 30 days
        $wpdb->query(
            "DELETE FROM $sessions_table WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
    }

    /**
     * Get admin statistics
     */
    public static function get_admin_stats()
    {
        global $wpdb;

        $gym_admins_table = $wpdb->prefix . 'gym_admins';
        $sessions_table = $wpdb->prefix . 'gym_admin_sessions';

        $stats = array();

        // Total admins
        $stats['total_admins'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM $gym_admins_table WHERE status = 'active'"
        );

        // Admins by role
        $stats['admins_by_role'] = $wpdb->get_results(
            "SELECT role, COUNT(*) as count FROM $gym_admins_table WHERE status = 'active' GROUP BY role"
        );

        // Active sessions
        $stats['active_sessions'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM $sessions_table WHERE is_active = 1 AND expires_at > NOW()"
        );

        // Recent logins (last 7 days)
        $stats['recent_logins'] = $wpdb->get_var(
            "SELECT COUNT(DISTINCT admin_id) FROM $gym_admins_table WHERE last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        return $stats;
    }
}