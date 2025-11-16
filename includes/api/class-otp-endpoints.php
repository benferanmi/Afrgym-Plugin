<?php
/**
 * OTP (One-Time Password) Email Verification API Endpoints
 * EMAIL-FIRST FLOW: Verify email BEFORE user creation
 */
class Gym_OTP_Endpoints
{
    private $email_service;

    public function __construct()
    {
        $this->email_service = new Gym_Email_Service();
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes()
    {
        // Send OTP to email (NO user_id needed)
        register_rest_route('gym-admin/v1', '/auth/send-otp', array(
            'methods' => 'POST',
            'callback' => array($this, 'send_otp'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'email' => array(
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Email address to send OTP to',
                    'sanitize_callback' => 'sanitize_email',
                    'validate_callback' => 'is_email'
                )
            )
        ));

        // Verify OTP code (returns verification token)
        register_rest_route('gym-admin/v1', '/auth/verify-otp', array(
            'methods' => 'POST',
            'callback' => array($this, 'verify_otp'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'email' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_email'
                ),
                'otp_code' => array(
                    'required' => true,
                    'type' => 'string',
                    'description' => '6-digit OTP code'
                )
            )
        ));

        // Resend OTP (if expired or not received)
        register_rest_route('gym-admin/v1', '/auth/resend-otp', array(
            'methods' => 'POST',
            'callback' => array($this, 'resend_otp'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'email' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_email'
                )
            )
        ));

        // Check email verification status (for existing users)
        register_rest_route('gym-admin/v1', '/users/(?P<id>\d+)/email-status', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_email_status'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Admin can manually verify user email (bypass OTP)
        register_rest_route('gym-admin/v1', '/users/(?P<id>\d+)/verify-email', array(
            'methods' => 'POST',
            'callback' => array($this, 'manual_verify_email'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'verified' => array(
                    'required' => true,
                    'type' => 'boolean',
                    'description' => 'Set email verification status'
                )
            )
        ));
    }

    /**
     * Send OTP to email (NO user_id required)
     */
    public function send_otp($request)
    {
        $email = $request->get_param('email');

        if (!is_email($email)) {
            return new WP_Error('invalid_email', 'Invalid email address.', array('status' => 400));
        }

        // Check if email already exists in WordPress
        if (email_exists($email)) {
            return new WP_Error('email_exists', 'This email is already registered. Please use a different email.', array('status' => 400));
        }

        // Check rate limiting (max 3 OTPs per hour per email)
        $rate_limit_result = $this->check_rate_limit($email);
        if (is_wp_error($rate_limit_result)) {
            return $rate_limit_result;
        }

        // Generate 6-digit OTP
        $otp_code = $this->generate_otp_code();
        $expires_at = time() + (10 * 60); // 10 minutes

        // Store OTP in WordPress transients (temporary storage)
        $transient_key = 'gym_otp_' . md5($email);
        $otp_data = array(
            'code' => $otp_code,
            'email' => $email,
            'expires_at' => $expires_at,
            'attempts' => 0,
            'created_at' => time()
        );
        set_transient($transient_key, $otp_data, 15 * 60); // Store for 15 minutes

        // Update rate limit tracking
        $this->update_rate_limit_tracking($email);

        // Send OTP email
        $email_result = $this->send_otp_email($email, $otp_code);

        if (is_wp_error($email_result)) {
            return new WP_Error('email_send_failed', 'Failed to send OTP email: ' . $email_result->get_error_message(), array('status' => 500));
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'OTP sent successfully to ' . $this->mask_email($email),
            'email' => $this->mask_email($email),
            'expires_in_seconds' => 600
        ));
    }

    /**
     * Verify OTP code (returns verification token)
     */
    public function verify_otp($request)
    {
        $email = $request->get_param('email');
        $otp_code = sanitize_text_field($request->get_param('otp_code'));

        if (!is_email($email)) {
            return new WP_Error('invalid_email', 'Invalid email address.', array('status' => 400));
        }

        // Get stored OTP data from transient
        $transient_key = 'gym_otp_' . md5($email);
        $otp_data = get_transient($transient_key);

        if (!$otp_data) {
            return new WP_Error('no_otp', 'No OTP found or OTP has expired. Please request a new OTP.', array('status' => 400));
        }

        // Check if OTP has expired
        if (time() > $otp_data['expires_at']) {
            delete_transient($transient_key);
            return new WP_Error('otp_expired', 'OTP has expired. Please request a new one.', array('status' => 400));
        }

        // Check attempt limit (max 5 attempts)
        if ($otp_data['attempts'] >= 5) {
            delete_transient($transient_key);
            return new WP_Error('too_many_attempts', 'Too many failed attempts. Please request a new OTP.', array('status' => 429));
        }

        // Verify OTP code
        if ($otp_code !== $otp_data['code']) {
            // Increment failed attempts
            $otp_data['attempts']++;
            set_transient($transient_key, $otp_data, 15 * 60);

            $remaining_attempts = 5 - $otp_data['attempts'];
            return new WP_Error('invalid_otp', "Invalid OTP code. {$remaining_attempts} attempts remaining.", array('status' => 400));
        }

        // OTP is valid - Generate verification token
        $verification_token = $this->generate_verification_token($email);

        // Store verification token (valid for 30 minutes)
        $token_key = 'gym_verified_' . md5($email);
        set_transient($token_key, array(
            'email' => $email,
            'verified_at' => time()
        ), 30 * 60); // 30 minutes

        // Clear OTP data
        delete_transient($transient_key);

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Email verified successfully',
            'email' => $email,
            'verification_token' => $verification_token,
            'token_expires_in_seconds' => 1800 // 30 minutes
        ));
    }

    /**
     * Resend OTP (handles rate limiting)
     */
    public function resend_otp($request)
    {
        $email = $request->get_param('email');

        if (!is_email($email)) {
            return new WP_Error('invalid_email', 'Invalid email address.', array('status' => 400));
        }

        // Use the same send_otp method (includes rate limiting)
        return $this->send_otp($request);
    }

    /**
     * Get email verification status (for existing users)
     */
    public function get_email_status($request)
    {
        $user_id = $request->get_param('id');
        $user = get_user_by('id', $user_id);

        if (!$user) {
            return new WP_Error('user_not_found', 'User not found.', array('status' => 404));
        }

        $is_verified = get_user_meta($user_id, 'email_verified', true);
        $verified_at = get_user_meta($user_id, 'email_verified_at', true);

        return rest_ensure_response(array(
            'user_id' => $user_id,
            'email' => $user->user_email,
            'is_verified' => (bool) $is_verified,
            'verified_at' => $verified_at ?: null
        ));
    }

    /**
     * Admin can manually verify/unverify user email (bypass OTP)
     */
    public function manual_verify_email($request)
    {
        $user_id = $request->get_param('id');
        $verified = $request->get_param('verified');

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return new WP_Error('user_not_found', 'User not found.', array('status' => 404));
        }

        if ($verified) {
            update_user_meta($user_id, 'email_verified', true);
            update_user_meta($user_id, 'email_verified_at', current_time('mysql'));

            Gym_Admin::add_user_note($user_id, 'Email manually verified by admin');
            $message = 'Email verified successfully by admin';
        } else {
            delete_user_meta($user_id, 'email_verified');
            delete_user_meta($user_id, 'email_verified_at');

            Gym_Admin::add_user_note($user_id, 'Email verification removed by admin');
            $message = 'Email verification removed';
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => $message,
            'user_id' => $user_id,
            'email' => $user->user_email,
            'verified' => $verified
        ));
    }

    /**
     * Validate verification token when creating user
     */
    public static function validate_verification_token($email, $token)
    {
        $token_key = 'gym_verified_' . md5($email);
        $token_data = get_transient($token_key);

        if (!$token_data) {
            return false;
        }

        // Verify token matches
        $expected_token = self::generate_verification_token_static($email, $token_data['verified_at']);

        return $token === $expected_token;
    }

    /**
     * Generate 6-digit OTP code
     */
    private function generate_otp_code()
    {
        return str_pad(wp_rand(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Generate verification token
     */
    private function generate_verification_token($email)
    {
        $timestamp = time();
        return self::generate_verification_token_static($email, $timestamp);
    }

    /**
     * Static method for generating verification token
     */
    private static function generate_verification_token_static($email, $timestamp)
    {
        $secret = defined('AUTH_KEY') ? AUTH_KEY : 'gym-verification-secret';
        return hash_hmac('sha256', $email . '|' . $timestamp, $secret);
    }

    /**
     * Check rate limiting (max 3 OTPs per hour per email)
     */
    private function check_rate_limit($email)
    {
        $rate_key = 'gym_otp_rate_' . md5($email);
        $rate_data = get_transient($rate_key);

        if (!$rate_data) {
            // No rate limit data, allow request
            return true;
        }

        $sent_count = $rate_data['count'];
        $last_reset = $rate_data['reset_at'];

        // Check if limit exceeded
        if ($sent_count >= 3) {
            $time_until_reset = ($last_reset + 3600) - time();
            $minutes_remaining = ceil($time_until_reset / 60);

            return new WP_Error(
                'rate_limit_exceeded',
                "Too many OTP requests. Please try again in {$minutes_remaining} minutes.",
                array('status' => 429)
            );
        }

        return true;
    }

    /**
     * Update rate limit tracking
     */
    private function update_rate_limit_tracking($email)
    {
        $rate_key = 'gym_otp_rate_' . md5($email);
        $rate_data = get_transient($rate_key);

        if (!$rate_data) {
            // First request in this hour
            $rate_data = array(
                'count' => 1,
                'reset_at' => time()
            );
        } else {
            $rate_data['count']++;
        }

        set_transient($rate_key, $rate_data, 3600); // 1 hour
    }

    /**
     * Send OTP email
     */
    private function send_otp_email($email, $otp_code)
    {
        $subject = 'Verify Your Email - Gym Registration';

        $message = $this->get_otp_email_template($otp_code);

        $headers = array('Content-Type: text/html; charset=UTF-8');

        $sent = wp_mail($email, $subject, $message, $headers);

        if (!$sent) {
            return new WP_Error('email_failed', 'Failed to send email');
        }

        return true;
    }

    /**
     * Get OTP email template
     */
    private function get_otp_email_template($otp_code)
    {
        $gym_name = get_bloginfo('name');

        return "
        <html>
        <body style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <div style='background: #667eea; color: white; padding: 20px; text-align: center;'>
                <h1 style='margin: 0;'>Verify Your Email Address</h1>
            </div>
            
            <div style='padding: 30px; background: #f9f9f9;'>
                <p style='font-size: 16px;'>Hi there,</p>
                
                <p style='font-size: 16px;'>Thank you for joining <strong>{$gym_name}</strong>! To complete your registration, please verify your email address using the code below:</p>
                
                <div style='text-align: center; padding: 30px; background: white; border-radius: 8px; margin: 20px 0;'>
                    <h1 style='font-size: 48px; letter-spacing: 8px; color: #667eea; margin: 0;'>{$otp_code}</h1>
                    <p style='color: #666; margin-top: 10px;'>Valid for 10 minutes</p>
                </div>
                
                <div style='background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 20px 0;'>
                    <p style='margin: 0; font-weight: bold;'>Important:</p>
                    <ul style='margin: 10px 0; padding-left: 20px;'>
                        <li>This code will expire in 10 minutes</li>
                        <li>You have 5 attempts to enter the correct code</li>
                        <li>If you didn't request this code, please ignore this email</li>
                    </ul>
                </div>
                
                <p style='font-size: 16px;'>Welcome to our gym family!</p>
                
                <p style='font-size: 14px; color: #666; margin-top: 30px;'>
                    If you have any questions, please contact us.
                </p>
            </div>
            
            <div style='text-align: center; padding: 20px; color: #666; font-size: 12px;'>
                <p>&copy; {$gym_name}. All rights reserved.</p>
            </div>
        </body>
        </html>
        ";
    }

    /**
     * Mask email for security (show partial email)
     */
    private function mask_email($email)
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return $email;
        }

        $name = $parts[0];
        $domain = $parts[1];

        $name_length = strlen($name);
        $visible_chars = min(3, floor($name_length / 2));
        $masked_name = substr($name, 0, $visible_chars) . str_repeat('*', $name_length - $visible_chars);

        return $masked_name . '@' . $domain;
    }

    public function check_permission($request)
    {
        return Gym_Admin::check_api_permission($request, 'manage_options');
    }
}