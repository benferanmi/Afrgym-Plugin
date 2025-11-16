<?php
/**
 * Class for plugin activation and database setup - UPDATED with SEPARATE PRODUCT TABLES PER GYM
 */
class Gym_Activator
{

    public function activate()
    {
        $this->create_tables();
        $this->set_default_options();
        $this->create_default_admins();
        $this->create_capabilities();
    }

    private function create_tables()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // === GYM ONE ADMINS TABLE ===
        $gym_admins_table = $wpdb->prefix . 'gym_admins';
        $sql_gym_admins = "CREATE TABLE $gym_admins_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            username varchar(60) NOT NULL UNIQUE,
            email varchar(100) NOT NULL UNIQUE,
            password varchar(255) NOT NULL,
            first_name varchar(50) NOT NULL,
            last_name varchar(50) NOT NULL,
            role varchar(20) DEFAULT 'admin',
            gym_identifier varchar(20) DEFAULT 'afrgym_one',
            status varchar(20) DEFAULT 'active',
            last_login datetime DEFAULT NULL,
            failed_login_attempts int(3) DEFAULT 0,
            locked_until datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY username (username),
            KEY email (email),
            KEY status (status),
            KEY role (role),
            KEY gym_identifier (gym_identifier)
        ) $charset_collate;";

        // === GYM TWO ADMINS TABLE ===
        $gym_admins_two_table = $wpdb->prefix . 'gym_admins_two';
        $sql_gym_admins_two = "CREATE TABLE $gym_admins_two_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            username varchar(60) NOT NULL UNIQUE,
            email varchar(100) NOT NULL UNIQUE,
            password varchar(255) NOT NULL,
            first_name varchar(50) NOT NULL,
            last_name varchar(50) NOT NULL,
            role varchar(20) DEFAULT 'admin',
            gym_identifier varchar(20) DEFAULT 'afrgym_two',
            status varchar(20) DEFAULT 'active',
            last_login datetime DEFAULT NULL,
            failed_login_attempts int(3) DEFAULT 0,
            locked_until datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY username (username),
            KEY email (email),
            KEY status (status),
            KEY role (role),
            KEY gym_identifier (gym_identifier)
        ) $charset_collate;";

        // Admin sessions table for JWT token management (SUPPORTS BOTH GYMS)
        $admin_sessions_table = $wpdb->prefix . 'gym_admin_sessions';
        $sql_admin_sessions = "CREATE TABLE $admin_sessions_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            admin_id int(11) NOT NULL,
            gym_type varchar(20) NOT NULL,
            token_hash varchar(255) NOT NULL,
            expires_at datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            last_used_at datetime DEFAULT CURRENT_TIMESTAMP,
            ip_address varchar(45),
            user_agent text,
            is_active tinyint(1) DEFAULT 1,
            PRIMARY KEY (id),
            KEY admin_id (admin_id),
            KEY gym_type (gym_type),
            KEY token_hash (token_hash),
            KEY expires_at (expires_at),
            KEY is_active (is_active)
        ) $charset_collate;";

        // Membership pause history table
        $membership_pauses_table = $wpdb->prefix . 'gym_membership_pauses';
        $sql_membership_pauses = "CREATE TABLE $membership_pauses_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            user_id int(11) NOT NULL,
            admin_id int(11) DEFAULT NULL,
            gym_identifier varchar(20) DEFAULT NULL,
            action varchar(20) NOT NULL,
            pause_date datetime NOT NULL,
            unpause_date datetime DEFAULT NULL,
            days_paused int(11) DEFAULT 0,
            remaining_days int(11) DEFAULT 0,
            original_end_date datetime DEFAULT NULL,
            new_end_date datetime DEFAULT NULL,
            reason text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY admin_id (admin_id),
            KEY gym_identifier (gym_identifier),
            KEY action (action),
            KEY pause_date (pause_date)
        ) $charset_collate;";

        // Email logs table (TRACKS WHICH GYM SENT)
        $email_logs_table = $wpdb->prefix . 'gym_email_logs';
        $sql_email_logs = "CREATE TABLE $email_logs_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            user_id int(11) NOT NULL,
            admin_id int(11) DEFAULT NULL,
            gym_identifier varchar(20) DEFAULT NULL,
            recipient_email varchar(255) NOT NULL,
            subject varchar(255) NOT NULL,
            template_name varchar(100) NOT NULL,
            status varchar(20) DEFAULT 'pending',
            sent_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            error_message text,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY admin_id (admin_id),
            KEY gym_identifier (gym_identifier),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";

        // User notes table - UPDATED TO TRACK GYM SOURCE
        $user_notes_table = $wpdb->prefix . 'gym_user_notes';
        $sql_user_notes = "CREATE TABLE $user_notes_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            user_id int(11) NOT NULL,
            admin_id int(11) NOT NULL,
            gym_identifier varchar(20) DEFAULT NULL,
            admin_name varchar(100) DEFAULT NULL,
            note text NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY admin_id (admin_id),
            KEY gym_identifier (gym_identifier),
            KEY created_at (created_at)
        ) $charset_collate;";

        // Settings table
        $settings_table = $wpdb->prefix . 'gym_settings';
        $sql_settings = "CREATE TABLE $settings_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            setting_name varchar(255) NOT NULL UNIQUE,
            setting_value longtext,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY setting_name (setting_name)
        ) $charset_collate;";

        // ===================================================================
        // SEPARATE PRODUCT TABLES FOR GYM ONE
        // ===================================================================

        $products_one_table = $wpdb->prefix . 'gym_products_one';
        $sql_products_one = "CREATE TABLE $products_one_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            price decimal(10,2) NOT NULL DEFAULT 0.00,
            description text DEFAULT NULL,
            sku varchar(100) DEFAULT NULL UNIQUE,
            category varchar(100) DEFAULT NULL,
            quantity int(11) NOT NULL DEFAULT 0,
            total_sold int(11) NOT NULL DEFAULT 0,
            images longtext DEFAULT NULL,
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY name (name),
            KEY sku (sku),
            KEY category (category),
            KEY status (status)
        ) $charset_collate;";

        $product_sales_one_table = $wpdb->prefix . 'gym_product_sales_one';
        $sql_product_sales_one = "CREATE TABLE $product_sales_one_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            product_id int(11) NOT NULL,
            quantity int(11) NOT NULL,
            price_at_sale decimal(10,2) NOT NULL,
            total_amount decimal(10,2) NOT NULL,
            admin_id int(11) DEFAULT NULL,
            note text DEFAULT NULL,
            sale_date datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY admin_id (admin_id),
            KEY sale_date (sale_date),
            KEY created_at (created_at)
        ) $charset_collate;";

        $product_activity_one_table = $wpdb->prefix . 'gym_product_activity_one';
        $sql_product_activity_one = "CREATE TABLE $product_activity_one_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            product_id int(11) NOT NULL,
            admin_id int(11) DEFAULT NULL,
            action varchar(50) NOT NULL,
            description text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY admin_id (admin_id),
            KEY action (action),
            KEY created_at (created_at)
        ) $charset_collate;";

        // ===================================================================
        // SEPARATE PRODUCT TABLES FOR GYM TWO
        // ===================================================================

        $products_two_table = $wpdb->prefix . 'gym_products_two';
        $sql_products_two = "CREATE TABLE $products_two_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            price decimal(10,2) NOT NULL DEFAULT 0.00,
            description text DEFAULT NULL,
            sku varchar(100) DEFAULT NULL UNIQUE,
            category varchar(100) DEFAULT NULL,
            quantity int(11) NOT NULL DEFAULT 0,
            total_sold int(11) NOT NULL DEFAULT 0,
            images longtext DEFAULT NULL,
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY name (name),
            KEY sku (sku),
            KEY category (category),
            KEY status (status)
        ) $charset_collate;";

        $product_sales_two_table = $wpdb->prefix . 'gym_product_sales_two';
        $sql_product_sales_two = "CREATE TABLE $product_sales_two_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            product_id int(11) NOT NULL,
            quantity int(11) NOT NULL,
            price_at_sale decimal(10,2) NOT NULL,
            total_amount decimal(10,2) NOT NULL,
            admin_id int(11) DEFAULT NULL,
            note text DEFAULT NULL,
            sale_date datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY admin_id (admin_id),
            KEY sale_date (sale_date),
            KEY created_at (created_at)
        ) $charset_collate;";

        $product_activity_two_table = $wpdb->prefix . 'gym_product_activity_two';
        $sql_product_activity_two = "CREATE TABLE $product_activity_two_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            product_id int(11) NOT NULL,
            admin_id int(11) DEFAULT NULL,
            action varchar(50) NOT NULL,
            description text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY admin_id (admin_id),
            KEY action (action),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Create core tables
        dbDelta($sql_gym_admins);
        dbDelta($sql_gym_admins_two);
        dbDelta($sql_admin_sessions);
        dbDelta($sql_membership_pauses);
        dbDelta($sql_email_logs);
        dbDelta($sql_user_notes);
        dbDelta($sql_settings);

        // Create Gym One product tables
        dbDelta($sql_products_one);
        dbDelta($sql_product_sales_one);
        dbDelta($sql_product_activity_one);

        // Create Gym Two product tables
        dbDelta($sql_products_two);
        dbDelta($sql_product_sales_two);
        dbDelta($sql_product_activity_two);
    }

    private function create_default_admins()
    {
        global $wpdb;

        // === CREATE DEFAULT ADMIN FOR GYM ONE ===
        $gym_one_table = $wpdb->prefix . 'gym_admins';
        $existing_gym_one = $wpdb->get_var("SELECT COUNT(*) FROM $gym_one_table");

        if ($existing_gym_one == 0) {
            $default_gym_one_admin = array(
                'username' => 'gymone_admin',
                'email' => 'admin@gymone.local',
                'password' => wp_hash_password('GymOne2024!'),
                'first_name' => 'Afrgym',
                'last_name' => 'One Admin',
                'role' => 'super_admin',
                'gym_identifier' => 'afrgym_one',
                'status' => 'active'
            );

            $wpdb->insert($gym_one_table, $default_gym_one_admin);
            error_log('Simple Gym Admin: Gym One default admin created - Username: "gymone_admin" Password: "GymOne2024!" - CHANGE IMMEDIATELY');
        }

        // === CREATE DEFAULT ADMIN FOR GYM TWO ===
        $gym_two_table = $wpdb->prefix . 'gym_admins_two';
        $existing_gym_two = $wpdb->get_var("SELECT COUNT(*) FROM $gym_two_table");

        if ($existing_gym_two == 0) {
            $default_gym_two_admin = array(
                'username' => 'gymtwo_admin',
                'email' => 'admin@gymtwo.local',
                'password' => wp_hash_password('GymTwo2024!'),
                'first_name' => 'Afrgym',
                'last_name' => 'Two Admin',
                'role' => 'super_admin',
                'gym_identifier' => 'afrgym_two',
                'status' => 'active'
            );

            $wpdb->insert($gym_two_table, $default_gym_two_admin);
            error_log('Simple Gym Admin: Gym Two default admin created - Username: "gymtwo_admin" Password: "GymTwo2024!" - CHANGE IMMEDIATELY');
        }
    }

    private function set_default_options()
    {
        global $wpdb;

        $settings_table = $wpdb->prefix . 'gym_settings';

        $default_settings = array(
            'email_from_name' => get_bloginfo('name'),
            'email_from_address' => get_option('admin_email'),
            'membership_expiry_notice_days' => '7',
            'max_bulk_emails_per_batch' => '50',
            'api_rate_limit' => '1000',
            'max_login_attempts' => '5',
            'lockout_duration_minutes' => '30',
            'jwt_expiry_hours' => '24',
            'pause_email_notifications' => '1',
            'auto_unpause_expired_memberships' => '0',
            'max_pause_duration_days' => '365',
            'gym_one_name' => 'Afrgym One',
            'gym_two_name' => 'Afrgym Two',
            // Product settings for BOTH gyms
            'product_low_stock_threshold' => '10',
            'product_monthly_report_enabled' => '1',
            'product_monthly_report_email_gym_one' => get_option('admin_email'),
            'product_monthly_report_email_gym_two' => get_option('admin_email')
        );

        foreach ($default_settings as $name => $value) {
            $wpdb->replace($settings_table, array(
                'setting_name' => $name,
                'setting_value' => $value
            ));
        }
    }

    private function create_capabilities()
    {
        $admin_role = get_role('administrator');
        if ($admin_role) {
            $admin_role->add_cap('manage_gym_users');
            $admin_role->add_cap('manage_gym_memberships');
            $admin_role->add_cap('send_gym_emails');
            $admin_role->add_cap('pause_gym_memberships');
            $admin_role->add_cap('unpause_gym_memberships');
            $admin_role->add_cap('manage_gym_products');
        }
    }

    /**
     * Create admin for specific gym
     */
    public static function create_gym_admin_sql($gym_type, $username, $email, $password, $first_name, $last_name, $role = 'admin')
    {
        global $wpdb;

        // Determine which table to use
        $table_name = ($gym_type === 'gym_two') ?
            $wpdb->prefix . 'gym_admins_two' :
            $wpdb->prefix . 'gym_admins';

        $gym_identifier = ($gym_type === 'gym_two') ? 'afrgym_two' : 'afrgym_one';

        // Check if username or email already exists in BOTH tables
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
            return new WP_Error('admin_exists', 'Admin with this username or email already exists');
        }

        $admin_data = array(
            'username' => sanitize_user($username),
            'email' => sanitize_email($email),
            'password' => wp_hash_password($password),
            'first_name' => sanitize_text_field($first_name),
            'last_name' => sanitize_text_field($last_name),
            'role' => in_array($role, array('admin', 'super_admin')) ? $role : 'admin',
            'gym_identifier' => $gym_identifier,
            'status' => 'active'
        );

        $result = $wpdb->insert($table_name, $admin_data);

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to create admin: ' . $wpdb->last_error);
        }

        return $wpdb->insert_id;
    }
}