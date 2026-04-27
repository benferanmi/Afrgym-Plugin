<?php
/**
 * Email service for sending and managing gym emails - ENHANCED VERSION
 */
class Gym_Email_Service
{
    private $templates_dir;

    public function __construct()
    {
        $this->templates_dir = SIMPLE_GYM_ADMIN_PLUGIN_DIR . 'templates/';
        $this->resend_service = new Gym_Resend_Service();
    }

    public function get_email_templates()
    {
        return array(
            'welcome' => array(
                'name' => 'Welcome Email',
                'description' => 'Sent to new gym members',
                'subject' => 'Welcome to {{gym_name}}!',
                'variables' => array('user_name', 'first_name', 'last_name', 'membership_plan', 'expiry_date', 'gym_name', 'qr_code_image'),
                'category' => 'member_onboarding'
            ),
            'membership_expiry' => array(
                'name' => 'Membership Expiry Reminder',
                'description' => 'Sent when membership is about to expire',
                'subject' => 'Your {{membership_plan}} membership expires soon',
                'variables' => array('user_name', 'first_name', 'membership_plan', 'expiry_date', 'days_until_expiry', 'gym_name', 'renewal_url'),
                'category' => 'member_retention'
            ),
            'custom' => array(
                'name' => 'Custom Email',
                'description' => 'Custom email template with gym branding',
                'subject' => 'Message from {{gym_name}}',
                'variables' => array('user_name', 'first_name', 'gym_name', 'custom_message'),
                'category' => 'general'
            ),
            'payment_reminder' => array(
                'name' => 'Payment Reminder',
                'description' => 'Remind members about pending payments',
                'subject' => 'Payment Reminder - {{gym_name}}',
                'variables' => array('user_name', 'first_name', 'amount_due', 'due_date', 'gym_name', 'payment_url'),
                'category' => 'payments'
            ),
            'class_reminder' => array(
                'name' => 'Class Reminder',
                'description' => 'Remind members about upcoming classes',
                'subject' => 'Your {{class_name}} class is tomorrow!',
                'variables' => array('user_name', 'first_name', 'class_name', 'class_date', 'class_time', 'instructor_name', 'gym_name'),
                'category' => 'classes'
            ),
            'email_otp' => array(
                'name' => 'Email Verification OTP',
                'description' => 'One-time password for email verification',
                'subject' => 'Your verification code for {{gym_name}}',
                'variables' => array('user_name', 'first_name', 'otp_code', 'expiry_minutes', 'gym_name'),
                'category' => 'authentication'
            )
        );
    }

    /**
     * Get the default email wrapper template for custom emails
     */
    public function get_default_email_wrapper()
    {
        return '
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>{{subject}}</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
                .container { max-width: 600px; margin: 0 auto; background: white; padding: 0; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
                .header h1 { margin: 0; font-size: 28px; font-weight: bold; }
                .content { padding: 40px 30px; }
                .footer { background: #f8f9fa; padding: 20px 30px; text-align: center; border-top: 1px solid #e9ecef; }
                .footer p { margin: 0; color: #6c757d; font-size: 14px; }
                .button { display: inline-block; padding: 12px 30px; background: #667eea; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
                .highlight-box { background: #f8f9fa; border-left: 4px solid #667eea; padding: 20px; margin: 20px 0; }
                .qr-code { text-align: center; margin: 20px 0; }
                .qr-code img { max-width: 200px; border: 2px solid #e9ecef; border-radius: 8px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>{{gym_name}}</h1>
                </div>
                <div class="content">
                    {{content}}
                </div>
                <div class="footer">
                    <p>&copy; {{current_year}} {{gym_name}}. All rights reserved.</p>
                    <p>{{gym_address}} | {{gym_phone}} | {{gym_email}}</p>
                </div>
            </div>
        </body>
        </html>';
    }

    public function get_template_content($template_name)
    {
        $template_file = $this->templates_dir . $template_name . '-email.html';

        if (file_exists($template_file)) {
            return file_get_contents($template_file);
        }

        return $this->get_default_template($template_name);
    }

    private function get_default_template($template_name)
    {
        switch ($template_name) {
            case 'welcome':
                return '
            <h2 style="color: #667eea; margin-bottom: 20px;">Welcome to Our Gym Family, {{first_name}}!</h2>
            <p>We\'re absolutely thrilled to welcome you to {{gym_name}}! You\'ve just joined a community dedicated to helping you achieve your fitness goals.</p>
            
            <div class="highlight-box">
                <h3 style="margin-top: 0; color: #333;">Your Membership Details</h3>
                <p><strong>Member Name:</strong> {{user_name}}</p>
                <p><strong>Membership Plan:</strong> {{membership_plan}}</p>
                <p><strong>Valid Until:</strong> {{expiry_date}}</p>
            </div>
            
            {{qr_code_image}}
            
            <p><strong>Getting Started:</strong></p>
            <ul>
                <li>Download our mobile app for class schedules and bookings</li>
                <li>Complete your fitness assessment with our trainers</li>
                <li>Join our community groups and challenges</li>
                <li>Don\'t forget to bring your membership QR code for easy check-ins</li>
            </ul>
            
            <p>Our team is here to support you every step of the way. If you have any questions, don\'t hesitate to reach out!</p>
            
            <p><strong>Welcome to your fitness journey!</strong><br>
            The {{gym_name}} Team</p>';

            case 'membership_expiry':
                return '
            <h2 style="color: #d9534f; margin-bottom: 20px;">Your Membership Expires Soon!</h2>
            <p>Hi {{first_name}},</p>
            <p>We hope you\'ve been enjoying your time at {{gym_name}}! We wanted to remind you that your membership will expire soon.</p>
            
            <div class="highlight-box">
                <h3 style="margin-top: 0; color: #d9534f;">Membership Details</h3>
                <p><strong>Current Plan:</strong> {{membership_plan}}</p>
                <p><strong>Expiry Date:</strong> {{expiry_date}}</p>
                <p><strong>Days Remaining:</strong> {{days_until_expiry}} days</p>
            </div>
            
            <p>Don\'t let your fitness journey stop here! Renew your membership today and continue enjoying:</p>
            <ul>
                <li>Unlimited access to all gym equipment</li>
                <li>Free group fitness classes</li>
                <li>Personal training consultations</li>
                <li>Member-only events and challenges</li>
            </ul>
          
            
            <p>Questions about renewal? Our team is here to help you choose the best plan for your goals.</p>
            
            <p>Thanks for being a valued member!<br>
            The {{gym_name}} Team</p>';

            case 'custom':
                return '
            <h2 style="color: #667eea; margin-bottom: 20px;">Message from {{gym_name}}</h2>
            <p>Hi {{first_name}},</p>
            <div style="margin: 20px 0;">
                {{custom_message}}
            </div>
            <p>Best regards,<br>
            <strong>The {{gym_name}} Team</strong></p>';

            case 'payment_reminder':
                return '
            <h2 style="color: #f39c12; margin-bottom: 20px;">Payment Reminder</h2>
            <p>Hi {{first_name}},</p>
            <p>We hope you\'re enjoying your membership at {{gym_name}}! This is a friendly reminder about your upcoming payment.</p>
            
            <div class="highlight-box">
                <h3 style="margin-top: 0; color: #f39c12;">Payment Details</h3>
                <p><strong>Amount Due:</strong> {{amount_due}}</p>
                <p><strong>Due Date:</strong> {{due_date}}</p>
            </div>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="{{payment_url}}" class="button">Make Payment</a>
            </div>
            
            <p>If you\'ve already made this payment, please disregard this message. If you have any questions, feel free to contact us.</p>
            
            <p>Thank you for your continued membership!<br>
            The {{gym_name}} Team</p>';

            case 'class_reminder':
                return '
            <h2 style="color: #28a745; margin-bottom: 20px;">Class Reminder: {{class_name}}</h2>
            <p>Hi {{first_name}},</p>
            <p>Just a friendly reminder that your {{class_name}} class is coming up!</p>
            
            <div class="highlight-box">
                <h3 style="margin-top: 0; color: #28a745;">Class Details</h3>
                <p><strong>Class:</strong> {{class_name}}</p>
                <p><strong>Date:</strong> {{class_date}}</p>
                <p><strong>Time:</strong> {{class_time}}</p>
                <p><strong>Instructor:</strong> {{instructor_name}}</p>
            </div>
            
            <p><strong>What to bring:</strong></p>
            <ul>
                <li>Towel</li>
                <li>Comfortable workout clothes</li>
                <li>Positive attitude!</li>
            </ul>
            
            <p>Can\'t make it? Please cancel at least 2 hours before class time to avoid charges.</p>
            
            <p>See you soon!<br>
            The {{gym_name}} Team</p>';

            case 'email_otp':
                return '
            <h2 style="color: #667eea; margin-bottom: 20px;">Verify Your Email Address</h2>
            <p>Hi {{first_name}},</p>
            <p>Thank you for joining {{gym_name}}! To complete your registration, please verify your email address using the code below:</p>
            
            <div class="highlight-box" style="text-align: center; padding: 30px; margin: 30px 0;">
                <p style="margin: 0 0 10px 0; color: #6c757d; font-size: 14px; text-transform: uppercase; letter-spacing: 1px;">Your Verification Code</p>
                <h1 style="margin: 0; font-size: 48px; letter-spacing: 8px; color: #667eea; font-weight: bold;">{{otp_code}}</h1>
                <p style="margin: 15px 0 0 0; color: #6c757d; font-size: 14px;">Valid for {{expiry_minutes}} minutes</p>
            </div>
            
            <p><strong>Important:</strong></p>
            <ul>
                <li>This code will expire in {{expiry_minutes}} minutes</li>
                <li>You have 5 attempts to enter the correct code</li>
                <li>If you didn\'t request this code, please ignore this email</li>
            </ul>
            
            <p>If you need a new code, you can request one through the verification page.</p>
            
            <p>Welcome to our gym family!<br>
            The {{gym_name}} Team</p>';

            default:
                return '{{custom_message}}';
        }
    }

    public function send_template_with_overrides($user_id, $template_name, $custom_subject = null, $custom_content = null, $custom_data = array())
    {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return new WP_Error('user_not_found', 'User not found.');
        }

        $template = $this->get_email_templates()[$template_name] ?? null;
        if (!$template) {
            return new WP_Error('template_not_found', 'Email template not found.');
        }

        // Use custom subject or template subject
        $subject = $custom_subject ?: $template['subject'];

        // Use custom content or template content, but always wrap in template
        $content = $custom_content ?: $this->get_template_content($template_name);

        // Wrap content in email template
        $wrapped_content = $this->get_default_email_wrapper();
        $wrapped_content = str_replace('{{content}}', $content, $wrapped_content);
        $wrapped_content = str_replace('{{subject}}', $subject, $wrapped_content);

        // Prepare variables
        $variables = $this->prepare_variables($user, $custom_data);

        // Replace variables in content and subject
        $processed_content = $this->replace_variables($wrapped_content, $variables);
        $processed_subject = $this->replace_variables($subject, $variables);

        return $this->send_processed_email($user, $processed_subject, $processed_content, $template_name);
    }

    public function send_bulk_template_with_overrides($user_ids, $template_name, $custom_subject = null, $custom_content = null, $custom_data = array())
    {
        $max_batch_size = (int) Gym_Admin::get_setting('max_bulk_emails_per_batch', 50);
        $user_ids = array_slice($user_ids, 0, $max_batch_size);

        $results = array(
            'sent' => 0,
            'failed' => 0,
            'errors' => array()
        );

        foreach ($user_ids as $user_id) {
            $result = $this->send_template_with_overrides($user_id, $template_name, $custom_subject, $custom_content, $custom_data);

            if (is_wp_error($result)) {
                $results['failed']++;
                $results['errors'][] = array(
                    'user_id' => $user_id,
                    'error' => $result->get_error_message()
                );
            } else {
                $results['sent']++;
            }

            // Small delay to prevent overwhelming the mail server
            usleep(100000); // 0.1 second
        }

        return $results;
    }

    public function send_email($user_id, $template_name, $custom_data = array())
    {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return new WP_Error('user_not_found', 'User not found.');
        }

        $template = $this->get_email_templates()[$template_name] ?? null;
        if (!$template) {
            return new WP_Error('template_not_found', 'Email template not found.');
        }

        // Get template content and wrap it
        $content = $this->get_template_content($template_name);
        $subject = $template['subject'];

        // Wrap content in email template
        $wrapped_content = $this->get_default_email_wrapper();
        $wrapped_content = str_replace('{{content}}', $content, $wrapped_content);
        $wrapped_content = str_replace('{{subject}}', $subject, $wrapped_content);

        // Prepare variables
        $variables = $this->prepare_variables($user, $custom_data);

        // Replace variables in content and subject
        $processed_content = $this->replace_variables($wrapped_content, $variables);
        $processed_subject = $this->replace_variables($subject, $variables);

        return $this->send_processed_email($user, $processed_subject, $processed_content, $template_name);
    }

    public function send_custom_email($user_id, $subject, $content, $custom_data = array())
    {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return new WP_Error('user_not_found', 'User not found.');
        }

        // Wrap custom content in email template
        $wrapped_content = $this->get_default_email_wrapper();
        $wrapped_content = str_replace('{{content}}', $content, $wrapped_content);
        $wrapped_content = str_replace('{{subject}}', $subject, $wrapped_content);

        // Prepare variables for replacement
        $variables = $this->prepare_variables($user, $custom_data);

        // Replace variables in custom content and subject
        $processed_content = $this->replace_variables($wrapped_content, $variables);
        $processed_subject = $this->replace_variables($subject, $variables);

        return $this->send_processed_email($user, $processed_subject, $processed_content, 'custom');
    }



    private function send_processed_email($user, $subject, $content, $template_name)
    {
        // Log email attempt
        $log_id = Gym_Admin::log_email($user->ID, $user->user_email, $subject, $template_name);

        // Check if Resend is configured
        $resend_key = Gym_Admin::get_setting('resend_api_key', '');

        $from_email = Gym_Admin::get_setting('email_from_address', get_option('admin_email'));

        // Generate text version
        $text_content = wp_strip_all_tags($content);

        // FIXED: Use Resend service instead of wp_mail
        if (!empty($resend_key)) {
            // Send via Resend
            $result = $this->resend_service->send_email(
                $user->user_email,
                $subject,
                $content,
                $text_content
            );

            if ($result['success']) {
                Gym_Admin::update_email_status($log_id, 'sent');

                return array(
                    'success' => true,
                    'message' => 'Email sent successfully via Resend',
                    'log_id' => $log_id,
                    'recipient' => $user->user_email,
                    'template' => $template_name,
                    'sent_via' => 'resend'
                );
            } else {
                $error_msg = $result['error'] ?? 'Unknown Resend error';
                Gym_Admin::update_email_status($log_id, 'failed', $error_msg);

                return new WP_Error(
                    'resend_failed',
                    "Failed to send via Resend: {$error_msg}"
                );
            }
        } else {
            // Fallback to wp_mail if Resend not configured
            error_log('Resend API key not configured, falling back to wp_mail');

            $headers = array(
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . Gym_Admin::get_setting('email_from_name', get_bloginfo('name')) . ' <' . $from_email . '>'
            );

            $sent = wp_mail($user->user_email, $subject, $content, $headers);

            if ($sent) {
                Gym_Admin::update_email_status($log_id, 'sent');

                return array(
                    'success' => true,
                    'message' => 'Email sent successfully via WordPress',
                    'log_id' => $log_id,
                    'recipient' => $user->user_email,
                    'template' => $template_name,
                    'sent_via' => 'wordpress'
                );
            } else {
                $error_message = 'WordPress wp_mail function failed';
                Gym_Admin::update_email_status($log_id, 'failed', $error_message);

                return new WP_Error('send_failed', $error_message);
            }
        }
    }

    /**
     * FIXED: Bulk emails now properly uses Resend service
     */
    public function send_bulk_emails($user_ids, $template_name, $custom_data = array())
    {
        $max_batch_size = (int) Gym_Admin::get_setting('max_bulk_emails_per_batch', 50);
        $user_ids = array_slice($user_ids, 0, $max_batch_size);

        $results = array(
            'sent' => 0,
            'failed' => 0,
            'errors' => array()
        );

        foreach ($user_ids as $user_id) {
            $result = $this->send_email($user_id, $template_name, $custom_data);

            if (is_wp_error($result)) {
                $results['failed']++;
                $results['errors'][] = array(
                    'user_id' => $user_id,
                    'error' => $result->get_error_message()
                );
            } else {
                $results['sent']++;
            }

            // Small delay to prevent overwhelming the mail server
            usleep(100000); // 0.1 second
        }

        return $results;
    }

    /**
     * FIXED: Custom bulk emails via Resend
     */
    public function send_bulk_custom_emails($user_ids, $subject, $content, $custom_data = array())
    {
        $max_batch_size = (int) Gym_Admin::get_setting('max_bulk_emails_per_batch', 50);
        $user_ids = array_slice($user_ids, 0, $max_batch_size);

        $results = array(
            'sent' => 0,
            'failed' => 0,
            'errors' => array()
        );

        foreach ($user_ids as $user_id) {
            $result = $this->send_custom_email($user_id, $subject, $content, $custom_data);

            if (is_wp_error($result)) {
                $results['failed']++;
                $results['errors'][] = array(
                    'user_id' => $user_id,
                    'error' => $result->get_error_message()
                );
            } else {
                $results['sent']++;
            }

            // Small delay to prevent overwhelming the mail server
            usleep(100000); // 0.1 second
        }

        return $results;
    }

    // ENHANCED: Better variable preparation with gym info
    private function prepare_variables($user, $custom_data = array())
    {
        $membership_service = new Gym_Membership_Service();
        $membership = $membership_service->get_user_membership($user->ID);

        $variables = array(
            'user_name' => $user->display_name,
            'user_email' => $user->user_email,
            'first_name' => get_user_meta($user->ID, 'first_name', true) ?: explode(' ', $user->display_name)[0],
            'last_name' => get_user_meta($user->ID, 'last_name', true) ?: (count(explode(' ', $user->display_name)) > 1 ? end(explode(' ', $user->display_name)) : ''),
            'membership_plan' => $membership['level_name'] ?? 'No Membership',
            'expiry_date' => $membership['expiry_date'] ? date('F j, Y', strtotime($membership['expiry_date'])) : 'N/A',
            'expiry_date_short' => $membership['expiry_date'] ? date('m/d/Y', strtotime($membership['expiry_date'])) : 'N/A',
            'days_until_expiry' => $membership['expiry_date'] ? max(0, ceil((strtotime($membership['expiry_date']) - time()) / 86400)) : 'N/A',
            'gym_name' => get_bloginfo('name'),
            'gym_url' => home_url(),
            'gym_email' => get_option('admin_email'),
            'gym_phone' => Gym_Admin::get_setting('gym_phone', ''),
            'gym_address' => Gym_Admin::get_setting('gym_address', ''),
            'renewal_url' => home_url('/membership-renewal/'),
            'payment_url' => home_url('/payment/'),
            'current_date' => date('F j, Y'),
            'current_date_short' => date('m/d/Y'),
            'current_year' => date('Y'),
            'qr_code_image' => $this->get_qr_code_image($user->ID)
        );

        // Merge with custom data (custom data takes precedence)
        return array_merge($variables, $custom_data);
    }

    private function get_qr_code_image($user_id)
    {
        // Try to get QR code from Ben's QR Code Manager plugin
        if (function_exists('ben_qr_get_user_qr_url')) {
            $qr_url = ben_qr_get_user_qr_url($user_id);
            if ($qr_url) {
                return '<div class="qr-code"><img src="' . esc_url($qr_url) . '" alt="Your QR Code"></div>';
            }
        }

        return '<p style="text-align: center; font-style: italic; color: #6c757d;">Your QR code will be provided at the gym reception.</p>';
    }

    public function replace_variables($content, $variables)
    {
        foreach ($variables as $key => $value) {
            $content = str_replace('{{' . $key . '}}', $value, $content);
        }

        // Remove any unreplaced variables
        $content = preg_replace('/\{\{[^}]+\}\}/', '', $content);

        return $content;
    }

    // ENHANCED: Better email preview with realistic data
    public function preview_email($template_name, $custom_data = array(), $user_id = null)
    {
        // Use a real user or sample user for preview
        if ($user_id) {
            $user = get_user_by('id', $user_id);
        } else {
            // Create sample data for preview
            $user = (object) array(
                'ID' => 1,
                'display_name' => 'John Doe',
                'user_email' => 'john@example.com'
            );
        }

        if (!$user) {
            return new WP_Error('user_not_found', 'User not found for preview.');
        }

        $template = $this->get_email_templates()[$template_name] ?? null;
        if (!$template) {
            return new WP_Error('template_not_found', 'Email template not found.');
        }

        // Get template content and wrap it
        $content = $this->get_template_content($template_name);
        $subject = $template['subject'];

        $wrapped_content = $this->get_default_email_wrapper();
        $wrapped_content = str_replace('{{content}}', $content, $wrapped_content);
        $wrapped_content = str_replace('{{subject}}', $subject, $wrapped_content);

        // Prepare variables (with sample data if no user provided)
        if (!$user_id) {
            $sample_data = array(
                'membership_plan' => 'Premium Membership',
                'expiry_date' => date('F j, Y', strtotime('+30 days')),
                'days_until_expiry' => '30',
                'amount_due' => '$49.99',
                'due_date' => date('F j, Y', strtotime('+7 days')),
                'class_name' => 'CrossFit Fundamentals',
                'class_date' => date('F j, Y', strtotime('+1 day')),
                'class_time' => '6:00 PM',
                'instructor_name' => 'Sarah Johnson'
            );
            $custom_data = array_merge($sample_data, $custom_data);
        }

        $variables = $this->prepare_variables($user, $custom_data);

        // Replace variables
        $preview_content = $this->replace_variables($wrapped_content, $variables);
        $preview_subject = $this->replace_variables($subject, $variables);

        return array(
            'subject' => $preview_subject,
            'content' => $preview_content,
            'variables_used' => array_keys($variables),
            'template_info' => $template,
            'wrapped' => true
        );
    }

    // Rest of the methods remain the same...
    public function get_email_logs($limit = 50, $offset = 0, $user_id = null)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'gym_email_logs';
        $where = '';
        $params = array();

        if ($user_id) {
            $where = 'WHERE user_id = %d';
            $params[] = $user_id;
        }

        $params[] = $limit;
        $params[] = $offset;

        $query = "SELECT l.*, u.display_name as user_name 
                  FROM $table l 
                  JOIN {$wpdb->users} u ON l.user_id = u.ID 
                  $where 
                  ORDER BY l.created_at DESC 
                  LIMIT %d OFFSET %d";

        return $wpdb->get_results($wpdb->prepare($query, $params));
    }

    public function get_users_by_membership($level_ids = array(), $status = 'active')
    {
        global $wpdb;

        if (empty($level_ids)) {
            return array();
        }

        $level_ids_placeholder = implode(',', array_fill(0, count($level_ids), '%d'));

        $query = "SELECT DISTINCT u.ID, u.display_name, u.user_email, mu.membership_id, ml.name as membership_name
              FROM {$wpdb->users} u
              JOIN {$wpdb->prefix}pmpro_memberships_users mu ON u.ID = mu.user_id
              JOIN {$wpdb->prefix}pmpro_membership_levels ml ON mu.membership_id = ml.id
              WHERE mu.membership_id IN ({$level_ids_placeholder})
              AND mu.status = %s";

        if ($status === 'active') {
            $query .= " AND (mu.enddate IS NULL OR mu.enddate = '0000-00-00 00:00:00' OR mu.enddate > NOW())";
        } elseif ($status === 'expired') {
            $query .= " AND mu.enddate IS NOT NULL AND mu.enddate != '0000-00-00 00:00:00' AND mu.enddate <= NOW()";
        }

        $query .= " ORDER BY u.display_name";

        $params = array_merge($level_ids, array($status === 'expired' ? 'active' : $status));

        return $wpdb->get_results($wpdb->prepare($query, $params));
    }

    public function get_global_variables()
    {
        return array(
            'user_name' => 'Full display name of the user',
            'user_email' => 'Email address of the user',
            'first_name' => 'First name of the user',
            'last_name' => 'Last name of the user',
            'membership_plan' => 'Current membership plan name',
            'expiry_date' => 'Membership expiry date (formatted)',
            'expiry_date_short' => 'Membership expiry date (short format)',
            'days_until_expiry' => 'Days until membership expires',
            'gym_name' => 'Name of the gym/site',
            'gym_url' => 'Website URL',
            'gym_email' => 'Gym contact email',
            'gym_phone' => 'Gym phone number',
            'gym_address' => 'Gym address',
            'renewal_url' => 'Membership renewal URL',
            'payment_url' => 'Payment processing URL',
            'qr_code_image' => 'QR code image HTML (if available)',
            'current_date' => 'Current date (formatted)',
            'current_date_short' => 'Current date (short format)',
            'current_year' => 'Current year',
            'custom_message' => 'Custom message content',
            'amount_due' => 'Payment amount due',
            'due_date' => 'Payment due date',
            'class_name' => 'Name of the class',
            'class_date' => 'Date of the class',
            'class_time' => 'Time of the class',
            'instructor_name' => 'Name of the instructor'
        );
    }

    public function schedule_expiry_notifications()
    {
        $days = (int) Gym_Admin::get_setting('membership_expiry_notice_days', 7);
        $membership_service = new Gym_Membership_Service();
        $expiring_members = $membership_service->get_expiring_memberships($days);

        $sent_count = 0;
        foreach ($expiring_members as $member) {
            $result = $this->send_email($member['user_id'], 'membership_expiry');
            if (!is_wp_error($result)) {
                $sent_count++;
            }
        }

        return $sent_count;
    }
}