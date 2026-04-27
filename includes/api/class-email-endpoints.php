<?php
/**
 * Email management API endpoints - ENHANCED VERSION
 */
class Gym_Email_Endpoints
{
    private $email_service;

    public function __construct()
    {
        $this->email_service = new Gym_Email_Service();
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes()
    {
        // Send single email - ENHANCED VERSION
        register_rest_route('gym-admin/v1', '/emails/send', array(
            'methods' => 'POST',
            'callback' => array($this, 'send_email'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'user_id' => array(
                    'required' => true,
                    'type' => 'integer'
                ),
                'template' => array(
                    'required' => false,
                    'type' => 'string',
                    'description' => 'Template key (welcome, membership_expiry, custom, etc.)'
                ),
                'custom_subject' => array(
                    'required' => false,
                    'type' => 'string'
                ),
                'custom_content' => array(
                    'required' => false,
                    'type' => 'string'
                ),
                'custom_data' => array(
                    'type' => 'object',
                    'default' => array()
                )
            )
        ));

        // Send bulk emails - ENHANCED VERSION
        register_rest_route('gym-admin/v1', '/emails/bulk', array(
            'methods' => 'POST',
            'callback' => array($this, 'send_bulk_emails'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'user_ids' => array(
                    'required' => true,
                    'type' => 'array'
                ),
                'template' => array(
                    'required' => false,
                    'type' => 'string',
                    'description' => 'Template key (welcome, membership_expiry, custom, etc.)'
                ),
                'custom_subject' => array(
                    'required' => false,
                    'type' => 'string'
                ),
                'custom_content' => array(
                    'required' => false,
                    'type' => 'string'
                ),
                'custom_data' => array(
                    'type' => 'object',
                    'default' => array()
                )
            )
        ));

        // Get email templates with categories
        register_rest_route('gym-admin/v1', '/emails/templates', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_templates'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Get email logs with enhanced filtering
        register_rest_route('gym-admin/v1', '/emails/logs', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_email_logs'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'limit' => array(
                    'default' => 50,
                    'type' => 'integer'
                ),
                'offset' => array(
                    'default' => 0,
                    'type' => 'integer'
                ),
                'user_id' => array(
                    'type' => 'integer'
                ),
                'template' => array(
                    'type' => 'string'
                ),
                'status' => array(
                    'type' => 'string',
                    'enum' => array('sent', 'failed', 'pending')
                ),
                'date_from' => array(
                    'type' => 'string',
                    'description' => 'Filter logs from date (Y-m-d format)'
                ),
                'date_to' => array(
                    'type' => 'string',
                    'description' => 'Filter logs to date (Y-m-d format)'
                )
            )
        ));

        // Enhanced preview endpoint
        register_rest_route('gym-admin/v1', '/emails/preview', array(
            'methods' => 'POST',
            'callback' => array($this, 'preview_email'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'template' => array(
                    'required' => true,
                    'type' => 'string'
                ),
                'user_id' => array(
                    'type' => 'integer',
                    'description' => 'User ID for realistic preview (optional)'
                ),
                'custom_subject' => array(
                    'type' => 'string'
                ),
                'custom_content' => array(
                    'type' => 'string'
                ),
                'custom_data' => array(
                    'type' => 'object',
                    'default' => array()
                )
            )
        ));

        // Get email statistics
        register_rest_route('gym-admin/v1', '/emails/stats', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_email_stats'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'period' => array(
                    'default' => '30',
                    'type' => 'integer',
                    'description' => 'Period in days (7, 30, 90)'
                )
            )
        ));

        // Send expiry notifications
        register_rest_route('gym-admin/v1', '/emails/expiry-notifications', array(
            'methods' => 'POST',
            'callback' => array($this, 'send_expiry_notifications'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Get template variables
        register_rest_route('gym-admin/v1', '/emails/variables', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_template_variables'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'template' => array(
                    'type' => 'string',
                    'description' => 'Specific template to get variables for'
                )
            )
        ));

        // Get users by membership
        register_rest_route('gym-admin/v1', '/emails/users-by-membership', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_users_by_membership'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'level_ids' => array(
                    'required' => true,
                    'type' => 'array',
                    'description' => 'Array of membership level IDs'
                ),
                'status' => array(
                    'default' => 'active',
                    'type' => 'string',
                    'enum' => array('active', 'expired', 'all')
                )
            )
        ));

        // Test email endpoint
        register_rest_route('gym-admin/v1', '/emails/test', array(
            'methods' => 'POST',
            'callback' => array($this, 'send_test_email'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'template' => array(
                    'required' => true,
                    'type' => 'string'
                ),
                'test_email' => array(
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Email address to send test to'
                )
            )
        ));

        register_rest_route('gym-admin/v1', '/emails/bulk-by-category', array(
            'methods' => 'POST',
            'callback' => array($this, 'send_bulk_emails_by_category'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'recipient_type' => array(
                    'required' => true,
                    'type' => 'string',
                    'enum' => array('all', 'active', 'inactive', 'expired', 'expiring_7days', 'expiring_30days', 'paused'),
                    'description' => 'Category of recipients to send to'
                ),
                'membership_level_ids' => array(
                    'type' => 'array',
                    'description' => 'Array of membership level IDs (for membership-based sending)'
                ),
                'template' => array(
                    'required' => false,
                    'type' => 'string',
                    'description' => 'Template key (welcome, membership_expiry, custom, etc.)'
                ),
                'custom_subject' => array(
                    'required' => false,
                    'type' => 'string'
                ),
                'custom_content' => array(
                    'required' => false,
                    'type' => 'string'
                ),
                'custom_data' => array(
                    'type' => 'object',
                    'default' => array()
                )
            )
        ));
    }

    /**
     * Custom email content sanitization for HTML emails
     */
    private function sanitize_email_content($content)
    {
        // For email content, we need to be less restrictive than web content
        $allowed_tags = array(
            'h1' => array('style' => array(), 'class' => array()),
            'h2' => array('style' => array(), 'class' => array()),
            'h3' => array('style' => array(), 'class' => array()),
            'h4' => array('style' => array(), 'class' => array()),
            'h5' => array('style' => array(), 'class' => array()),
            'h6' => array('style' => array(), 'class' => array()),
            'p' => array('style' => array(), 'class' => array()),
            'br' => array(),
            'strong' => array('style' => array()),
            'b' => array('style' => array()),
            'em' => array('style' => array()),
            'i' => array('style' => array()),
            'u' => array('style' => array()),
            'ul' => array('style' => array()),
            'ol' => array('style' => array()),
            'li' => array('style' => array()),
            'a' => array(
                'href' => array(),
                'title' => array(),
                'target' => array(),
                'style' => array()
            ),
            'img' => array(
                'src' => array(),
                'alt' => array(),
                'width' => array(),
                'height' => array(),
                'style' => array()
            ),
            'div' => array('style' => array(), 'class' => array()),
            'span' => array('style' => array(), 'class' => array()),
            'table' => array('style' => array(), 'class' => array(), 'width' => array()),
            'tr' => array('style' => array()),
            'td' => array('style' => array(), 'colspan' => array(), 'rowspan' => array()),
            'th' => array('style' => array(), 'colspan' => array(), 'rowspan' => array()),
            'thead' => array('style' => array()),
            'tbody' => array('style' => array()),
            'tfoot' => array('style' => array())
        );

        return wp_kses($content, $allowed_tags);
    }

    // ENHANCED: Updated send_email method with better mode determination
    public function send_email($request)
    {
        $user_id = $request->get_param('user_id');
        $template = $request->get_param('template');
        $custom_data = $request->get_param('custom_data') ?: array();
        $custom_subject = $request->get_param('custom_subject');
        $custom_content = $request->get_param('custom_content');

        // Validate user exists
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return new WP_Error('user_not_found', 'User not found.', array('status' => 404));
        }

        // Sanitize custom data
        if (is_array($custom_data)) {
            array_walk_recursive($custom_data, function (&$value) {
                $value = sanitize_text_field($value);
            });
        }

        // Determine email mode based on provided parameters
        $email_mode = $this->determine_email_mode($template, $custom_subject, $custom_content);

        switch ($email_mode) {
            case 'template_only':
                // Use template as-is
                $result = $this->email_service->send_email($user_id, $template, $custom_data);
                break;

            case 'custom_with_template':
                // Use template but override subject and/or content
                $result = $this->email_service->send_template_with_overrides(
                    $user_id,
                    $template,
                    $custom_subject ? sanitize_text_field($custom_subject) : null,
                    $custom_content ? $this->sanitize_email_content($custom_content) : null,
                    $custom_data
                );
                break;

            case 'custom_only':
                // Pure custom email (no template) - use default template wrapper
                if (empty($custom_subject)) {
                    return new WP_Error('missing_subject', 'Subject is required for custom emails.', array('status' => 400));
                }
                if (empty($custom_content)) {
                    return new WP_Error('missing_content', 'Content is required for custom emails.', array('status' => 400));
                }

                $result = $this->email_service->send_custom_email(
                    $user_id,
                    sanitize_text_field($custom_subject),
                    $this->sanitize_email_content($custom_content),
                    $custom_data
                );
                break;

            default:
                return new WP_Error('invalid_email_params', 'Invalid email parameters provided.', array('status' => 400));
        }

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    // ENHANCED: Updated bulk email method
    public function send_bulk_emails($request)
    {
        $user_ids = $request->get_param('user_ids');
        $template = $request->get_param('template');
        $custom_data = $request->get_param('custom_data') ?: array();
        $custom_subject = $request->get_param('custom_subject');
        $custom_content = $request->get_param('custom_content');

        // Validate user_ids array
        if (!is_array($user_ids) || empty($user_ids)) {
            return new WP_Error('invalid_user_ids', 'User IDs must be a non-empty array.', array('status' => 400));
        }

        // Check if Resend is configured
        $resend_key = Gym_Admin::get_setting('resend_api_key', '');
        if (empty($resend_key)) {
            return new WP_Error('resend_not_configured', 'Resend API key not configured. Contact system administrator.', array('status' => 500));
        }

        // Sanitize custom data
        if (is_array($custom_data)) {
            array_walk_recursive($custom_data, function (&$value) {
                $value = sanitize_text_field($value);
            });
        }

        // Prepare email content
        $email_mode = $this->determine_email_mode($template, $custom_subject, $custom_content);

        // Generate HTML and text content based on mode
        $html_content = '';
        $text_content = '';

        switch ($email_mode) {
            case 'template_only':
                $content = $this->email_service->get_template_content($template);
                $subject = $this->email_service->get_email_templates()[$template]['subject'] ?? 'Email';
                break;

            case 'custom_with_template':
                $content = $custom_content ?: $this->email_service->get_template_content($template);
                $subject = $custom_subject ?: $this->email_service->get_email_templates()[$template]['subject'];
                break;

            case 'custom_only':
                if (empty($custom_subject) || empty($custom_content)) {
                    return new WP_Error('missing_custom_data', 'Both subject and content required for custom emails.', array('status' => 400));
                }
                $content = $custom_content;
                $subject = $custom_subject;
                break;

            default:
                return new WP_Error('invalid_email_params', 'Invalid email parameters.', array('status' => 400));
        }

        // Wrap content in email template
        $wrapped_content = $this->email_service->get_default_email_wrapper();
        $wrapped_content = str_replace('{{content}}', $content, $wrapped_content);
        $wrapped_content = str_replace('{{subject}}', $subject, $wrapped_content);

        // Replace variables
        $html_content = $this->email_service->replace_variables($wrapped_content, $custom_data);
        $text_content = wp_strip_all_tags($html_content);

        // Initialize Resend service
        $resend_service = new Gym_Resend_Service();

        // Start async batch processing
        $result = $resend_service->send_bulk_async(
            $user_ids,
            $subject,
            $html_content,
            $text_content
        );

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array(
            'success' => true,
            'job_id' => $result['job_id'],
            'message' => $result['message'],
            'total_users' => $result['total_users'],
            'status' => 'initiated',
            'note' => 'Emails are being sent in batches. A detailed report will be sent to all super_admins when complete.'
        ));
    }

    /**
     * Determine email mode based on provided parameters
     */
    private function determine_email_mode($template, $custom_subject, $custom_content)
    {
        $has_template = !empty($template);
        $has_custom_subject = !empty($custom_subject);
        $has_custom_content = !empty($custom_content);

        if ($has_template && !$has_custom_subject && !$has_custom_content) {
            return 'template_only';
        }

        if ($has_template && ($has_custom_subject || $has_custom_content)) {
            return 'custom_with_template';
        }

        if (!$has_template && ($has_custom_subject || $has_custom_content)) {
            return 'custom_only';
        }

        return 'invalid';
    }

    // ENHANCED: Get templates with categories and enhanced info
    public function get_templates($request)
    {
        $templates = $this->email_service->get_email_templates();

        $templates_with_content = array();
        foreach ($templates as $key => $template) {
            $template['content_preview'] = wp_trim_words(
                strip_tags($this->email_service->get_template_content($key)),
                20,
                '...'
            );
            $template['key'] = $key;
            $templates_with_content[$key] = $template;
        }

        return rest_ensure_response(array(
            'templates' => $templates_with_content,
            'categories' => $this->get_template_categories($templates_with_content)
        ));
    }

    // Get template categories for better organization
    private function get_template_categories($templates)
    {
        $categories = array();
        foreach ($templates as $template) {
            $category = $template['category'] ?? 'general';
            if (!isset($categories[$category])) {
                $categories[$category] = array(
                    'name' => ucfirst(str_replace('_', ' ', $category)),
                    'templates' => array()
                );
            }
            $categories[$category]['templates'][] = $template['key'];
        }
        return $categories;
    }

    // ENHANCED: Get email logs with better filtering
    public function get_email_logs($request)
    {
        $limit = min($request->get_param('limit'), 100);
        $offset = $request->get_param('offset');
        $user_id = $request->get_param('user_id');
        $template = $request->get_param('template');
        $status = $request->get_param('status');
        $date_from = $request->get_param('date_from');
        $date_to = $request->get_param('date_to');

        global $wpdb;
        $table = $wpdb->prefix . 'gym_email_logs';

        $where_conditions = array();
        $params = array();

        if ($user_id) {
            $where_conditions[] = 'l.user_id = %d';
            $params[] = $user_id;
        }

        if ($template) {
            $where_conditions[] = 'l.template_name = %s';
            $params[] = $template;
        }

        if ($status) {
            $where_conditions[] = 'l.status = %s';
            $params[] = $status;
        }

        if ($date_from) {
            $where_conditions[] = 'DATE(l.created_at) >= %s';
            $params[] = $date_from;
        }

        if ($date_to) {
            $where_conditions[] = 'DATE(l.created_at) <= %s';
            $params[] = $date_to;
        }

        $where_clause = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);

        $params[] = $limit;
        $params[] = $offset;

        $query = "SELECT l.*, u.display_name as user_name 
                  FROM $table l 
                  LEFT JOIN {$wpdb->users} u ON l.user_id = u.ID 
                  $where_clause 
                  ORDER BY l.created_at DESC 
                  LIMIT %d OFFSET %d";

        $logs = $wpdb->get_results($wpdb->prepare($query, $params));

        $formatted_logs = array();
        foreach ($logs as $log) {
            $formatted_logs[] = array(
                'id' => $log->id,
                'user_id' => $log->user_id,
                'user_name' => $log->user_name ?: 'Unknown User',
                'recipient_email' => $log->recipient_email,
                'subject' => $log->subject,
                'template_name' => $log->template_name,
                'status' => $log->status,
                'sent_at' => $log->sent_at,
                'created_at' => $log->created_at,
                'error_message' => $log->error_message
            );
        }

        // Get total count for pagination
        $count_query = str_replace('SELECT l.*, u.display_name as user_name', 'SELECT COUNT(*)', $query);
        $count_query = str_replace('LIMIT %d OFFSET %d', '', $count_query);
        $total_count = $wpdb->get_var($wpdb->prepare($count_query, array_slice($params, 0, -2)));

        return rest_ensure_response(array(
            'logs' => $formatted_logs,
            'count' => count($formatted_logs),
            'total_count' => (int) $total_count,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $total_count
        ));
    }

    // ENHANCED: Preview email with custom overrides
    public function preview_email($request)
    {
        $template = $request->get_param('template');
        $user_id = $request->get_param('user_id');
        $custom_data = $request->get_param('custom_data') ?: array();
        $custom_subject = $request->get_param('custom_subject');
        $custom_content = $request->get_param('custom_content');

        // If custom overrides are provided, use preview with overrides
        if ($custom_subject || $custom_content) {
            $preview = $this->email_service->preview_template_with_overrides(
                $template,
                $custom_subject,
                $custom_content,
                $custom_data,
                $user_id
            );
        } else {
            $preview = $this->email_service->preview_email($template, $custom_data, $user_id);
        }

        if (is_wp_error($preview)) {
            return $preview;
        }

        return rest_ensure_response($preview);
    }

    // Get email statistics
    public function get_email_stats($request)
    {
        $period = $request->get_param('period');
        global $wpdb;

        $table = $wpdb->prefix . 'gym_email_logs';
        $date_from = date('Y-m-d', strtotime("-{$period} days"));

        $stats = array();

        // Total emails in period
        $stats['total_emails'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE DATE(created_at) >= %s",
            $date_from
        ));

        // Emails by status
        $status_stats = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) as count FROM $table 
             WHERE DATE(created_at) >= %s 
             GROUP BY status",
            $date_from
        ));

        foreach ($status_stats as $stat) {
            $stats['by_status'][$stat->status] = (int) $stat->count;
        }

        // Emails by template
        $template_stats = $wpdb->get_results($wpdb->prepare(
            "SELECT template_name, COUNT(*) as count FROM $table 
             WHERE DATE(created_at) >= %s AND template_name IS NOT NULL
             GROUP BY template_name 
             ORDER BY count DESC LIMIT 5",
            $date_from
        ));

        foreach ($template_stats as $stat) {
            $stats['by_template'][$stat->template_name] = (int) $stat->count;
        }

        // Daily email count for charts
        $daily_stats = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) as date, COUNT(*) as count 
             FROM $table 
             WHERE DATE(created_at) >= %s 
             GROUP BY DATE(created_at) 
             ORDER BY date ASC",
            $date_from
        ));

        foreach ($daily_stats as $stat) {
            $stats['daily_count'][] = array(
                'date' => $stat->date,
                'count' => (int) $stat->count
            );
        }

        // Calculate delivery rate
        $total = $stats['total_emails'];
        $sent = $stats['by_status']['sent'] ?? 0;
        $stats['delivery_rate'] = $total > 0 ? round(($sent / $total) * 100, 2) : 0;

        $stats['period_days'] = $period;
        $stats['date_from'] = $date_from;

        return rest_ensure_response($stats);
    }

    // Send test email
    public function send_test_email($request)
    {
        $template = $request->get_param('template');
        $test_email = sanitize_email($request->get_param('test_email'));

        if (!is_email($test_email)) {
            return new WP_Error('invalid_email', 'Please provide a valid email address.', array('status' => 400));
        }

        // Create a temporary user object for testing
        $test_user = (object) array(
            'ID' => 999999,
            'display_name' => 'Test User',
            'user_email' => $test_email
        );

        // Get template
        $templates = $this->email_service->get_email_templates();
        if (!isset($templates[$template])) {
            return new WP_Error('invalid_template', 'Invalid email template.', array('status' => 400));
        }

        // Prepare test content
        $content = $this->email_service->get_template_content($template);
        $subject = '[TEST] ' . $templates[$template]['subject'];

        // Use sample data for testing
        $test_data = array(
            'first_name' => 'Test',
            'user_name' => 'Test User',
            'membership_plan' => 'Premium Test Plan',
            'expiry_date' => date('F j, Y', strtotime('+30 days')),
            'days_until_expiry' => '30',
            'custom_message' => 'This is a test email to verify the template design and functionality.'
        );

        // Wrap and process template
        $wrapped_content = $this->email_service->get_default_email_wrapper();
        $wrapped_content = str_replace('{{content}}', $content, $wrapped_content);
        $wrapped_content = str_replace('{{subject}}', $subject, $wrapped_content);

        $variables = $this->email_service->prepare_test_variables($test_user, $test_data);
        $processed_content = $this->email_service->replace_variables($wrapped_content, $variables);
        $processed_subject = $this->email_service->replace_variables($subject, $variables);

        // Send test email
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . Gym_Admin::get_setting('email_from_name', get_bloginfo('name')) . ' <' . Gym_Admin::get_setting('email_from_address', get_option('admin_email')) . '>'
        );

        $sent = wp_mail($test_email, $processed_subject, $processed_content, $headers);

        if ($sent) {
            return rest_ensure_response(array(
                'success' => true,
                'message' => "Test email sent successfully to {$test_email}",
                'template' => $template
            ));
        } else {
            return new WP_Error('send_failed', 'Failed to send test email.', array('status' => 500));
        }
    }

    // Rest of the existing methods...
    public function get_template_variables($request)
    {
        $template = $request->get_param('template');

        if ($template) {
            $templates = $this->email_service->get_email_templates();
            if (!isset($templates[$template])) {
                return new WP_Error('invalid_template', 'Invalid email template.', array('status' => 400));
            }

            return rest_ensure_response(array(
                'variables' => $templates[$template]['variables'],
                'template_info' => $templates[$template]
            ));
        }

        return rest_ensure_response(array(
            'global_variables' => $this->email_service->get_global_variables(),
            'templates' => $this->email_service->get_email_templates()
        ));
    }

    public function get_users_by_membership($request)
    {
        $level_ids = $request->get_param('level_ids');
        $status = $request->get_param('status');

        if (!is_array($level_ids) || empty($level_ids)) {
            return new WP_Error('invalid_level_ids', 'Level IDs must be a non-empty array.', array('status' => 400));
        }

        $users = $this->email_service->get_users_by_membership($level_ids, $status);

        return rest_ensure_response(array(
            'users' => $users,
            'count' => count($users),
            'level_ids' => $level_ids,
            'status' => $status
        ));
    }

    public function send_expiry_notifications($request)
    {
        $sent_count = $this->email_service->schedule_expiry_notifications();

        return rest_ensure_response(array(
            'success' => true,
            'notifications_sent' => $sent_count,
            'message' => "Sent {$sent_count} expiry notification emails"
        ));
    }

    public function check_permission($request)
    {
        return Gym_Admin::check_api_permission($request, 'manage_options');
    }

    /**
     * NEW METHOD: Send bulk emails by recipient category (gym-aware)
     * This eliminates the need to send explicit user IDs from frontend
     */
    public function send_bulk_emails_by_category($request)
    {
        global $wpdb;

        $recipient_type = $request->get_param('recipient_type');
        $membership_level_ids = $request->get_param('membership_level_ids') ?: array();
        $template = $request->get_param('template');
        $custom_data = $request->get_param('custom_data') ?: array();
        $custom_subject = $request->get_param('custom_subject');
        $custom_content = $request->get_param('custom_content');

        // Get current gym identifier from JWT token
        $current_admin = Gym_Admin::get_current_gym_admin();
        $gym_identifier = $current_admin ? $current_admin->gym_identifier : 'afrgym_one';

        // Get gym user IDs from notes table (users created by this gym)
        $notes_table = $wpdb->prefix . 'gym_user_notes';

        $gym_user_ids_query = "
        SELECT DISTINCT n1.user_id
        FROM $notes_table n1
        INNER JOIN (
            SELECT user_id, MIN(id) as first_note_id
            FROM $notes_table
            GROUP BY user_id
        ) n2 ON n1.id = n2.first_note_id
        WHERE n1.gym_identifier = %s
    ";

        $gym_user_ids = $wpdb->get_col($wpdb->prepare($gym_user_ids_query, $gym_identifier));

        if (empty($gym_user_ids)) {
            return new WP_Error('no_users', 'No users found for this gym.', array('status' => 404));
        }

        // Filter to only existing users
        $user_ids_string = implode(',', array_map('intval', $gym_user_ids));

        $existing_user_ids = $wpdb->get_col("
        SELECT DISTINCT ID
        FROM {$wpdb->users}
        WHERE ID IN ($user_ids_string)
    ");

        if (empty($existing_user_ids)) {
            return new WP_Error('no_users', 'No valid users found for this gym.', array('status' => 404));
        }

        $user_ids_string = implode(',', array_map('intval', $existing_user_ids));

        // Build query based on recipient type
        $pmpro_members_table = $wpdb->prefix . 'pmpro_memberships_users';
        $recipient_user_ids = array();

        switch ($recipient_type) {
            case 'all':
                // All gym users
                $recipient_user_ids = $existing_user_ids;
                break;

            case 'active':
                // Active members (not paused, valid membership)
                $recipient_user_ids = $wpdb->get_col("
                SELECT DISTINCT mu.user_id
                FROM $pmpro_members_table mu
                LEFT JOIN {$wpdb->usermeta} pause ON mu.user_id = pause.user_id AND pause.meta_key = 'membership_is_paused'
                WHERE mu.user_id IN ($user_ids_string)
                AND mu.status = 'active'
                AND (pause.meta_value IS NULL OR pause.meta_value = '0')
                AND (mu.enddate IS NULL OR mu.enddate = '0000-00-00 00:00:00' OR mu.enddate >= NOW())
            ");
                break;

            case 'inactive':
                // Users with no active membership
                $recipient_user_ids = $wpdb->get_col("
                SELECT DISTINCT u.ID
                FROM {$wpdb->users} u
                LEFT JOIN $pmpro_members_table mu ON u.ID = mu.user_id AND mu.status = 'active'
                WHERE u.ID IN ($user_ids_string)
                AND mu.user_id IS NULL
            ");
                break;

            case 'expired':
                // Members with expired memberships
                $recipient_user_ids = $wpdb->get_col("
                SELECT DISTINCT mu.user_id
                FROM $pmpro_members_table mu
                WHERE mu.user_id IN ($user_ids_string)
                AND mu.status = 'active'
                AND mu.enddate IS NOT NULL
                AND mu.enddate != '0000-00-00 00:00:00'
                AND mu.enddate < NOW()
            ");
                break;

            case 'expiring_7days':
                // Expiring in next 7 days
                $today = current_time('Y-m-d');
                $seven_days = date('Y-m-d', strtotime('+7 days'));

                $recipient_user_ids = $wpdb->get_col($wpdb->prepare("
                SELECT DISTINCT mu.user_id
                FROM $pmpro_members_table mu
                LEFT JOIN {$wpdb->usermeta} pause ON mu.user_id = pause.user_id AND pause.meta_key = 'membership_is_paused'
                WHERE mu.user_id IN ($user_ids_string)
                AND mu.status = 'active'
                AND (pause.meta_value IS NULL OR pause.meta_value = '0')
                AND mu.enddate IS NOT NULL
                AND mu.enddate != '0000-00-00 00:00:00'
                AND mu.enddate BETWEEN %s AND %s
            ", $today, $seven_days));
                break;

            case 'expiring_30days':
                // Expiring in next 30 days
                $today = current_time('Y-m-d');
                $thirty_days = date('Y-m-d', strtotime('+30 days'));

                $recipient_user_ids = $wpdb->get_col($wpdb->prepare("
                SELECT DISTINCT mu.user_id
                FROM $pmpro_members_table mu
                LEFT JOIN {$wpdb->usermeta} pause ON mu.user_id = pause.user_id AND pause.meta_key = 'membership_is_paused'
                WHERE mu.user_id IN ($user_ids_string)
                AND mu.status = 'active'
                AND (pause.meta_value IS NULL OR pause.meta_value = '0')
                AND mu.enddate IS NOT NULL
                AND mu.enddate != '0000-00-00 00:00:00'
                AND mu.enddate BETWEEN %s AND %s
            ", $today, $thirty_days));
                break;

            case 'paused':
                // Paused memberships
                $recipient_user_ids = $wpdb->get_col("
                SELECT DISTINCT user_id
                FROM {$wpdb->usermeta}
                WHERE user_id IN ($user_ids_string)
                AND meta_key = 'membership_is_paused'
                AND meta_value = '1'
            ");
                break;

            case 'membership':
                // Specific membership levels
                if (empty($membership_level_ids)) {
                    return new WP_Error('missing_membership_ids', 'Membership level IDs required for membership-based sending.', array('status' => 400));
                }

                $level_ids_placeholder = implode(',', array_fill(0, count($membership_level_ids), '%d'));

                $query = "
                SELECT DISTINCT mu.user_id
                FROM $pmpro_members_table mu
                WHERE mu.user_id IN ($user_ids_string)
                AND mu.membership_id IN ($level_ids_placeholder)
                AND mu.status = 'active'
            ";

                $recipient_user_ids = $wpdb->get_col($wpdb->prepare($query, $membership_level_ids));
                break;

            default:
                return new WP_Error('invalid_recipient_type', 'Invalid recipient type.', array('status' => 400));
        }

        if (empty($recipient_user_ids)) {
            return new WP_Error('no_recipients', "No recipients found for category: {$recipient_type}", array('status' => 404));
        }

        // Limit to max bulk size
        $max_bulk = (int) Gym_Admin::get_setting('max_bulk_emails_per_request', 100);
        if (count($recipient_user_ids) > $max_bulk) {
            return new WP_Error('too_many_recipients', "Maximum {$max_bulk} recipients allowed per request. Found: " . count($recipient_user_ids), array('status' => 400));
        }

        // Sanitize custom data
        if (is_array($custom_data)) {
            array_walk_recursive($custom_data, function (&$value) {
                $value = sanitize_text_field($value);
            });
        }

        // Determine email mode
        $email_mode = $this->determine_email_mode($template, $custom_subject, $custom_content);

        // Send emails using existing bulk methods
        switch ($email_mode) {
            case 'template_only':
                $results = $this->email_service->send_bulk_emails($recipient_user_ids, $template, $custom_data);
                break;

            case 'custom_with_template':
                $results = $this->email_service->send_bulk_template_with_overrides(
                    $recipient_user_ids,
                    $template,
                    $custom_subject ? sanitize_text_field($custom_subject) : null,
                    $custom_content ? $this->sanitize_email_content($custom_content) : null,
                    $custom_data
                );
                break;

            case 'custom_only':
                if (empty($custom_subject) || empty($custom_content)) {
                    return new WP_Error('missing_custom_data', 'Both subject and content are required for custom bulk emails.', array('status' => 400));
                }

                $results = $this->email_service->send_bulk_custom_emails(
                    $recipient_user_ids,
                    sanitize_text_field($custom_subject),
                    $this->sanitize_email_content($custom_content),
                    $custom_data
                );
                break;

            default:
                return new WP_Error('invalid_email_params', 'Invalid email parameters provided.', array('status' => 400));
        }

        return rest_ensure_response(array(
            'success' => true,
            'results' => $results,
            'recipient_type' => $recipient_type,
            'gym_identifier' => $gym_identifier,
            'gym_name' => $gym_identifier === 'afrgym_two' ? 'Afrgym Two' : 'Afrgym One',
            'total_attempted' => count($recipient_user_ids),
            'sent' => $results['sent'],
            'failed' => $results['failed'],
            'message' => "Bulk email sent to {$results['sent']} recipients in category: {$recipient_type}"
        ));
    }
}