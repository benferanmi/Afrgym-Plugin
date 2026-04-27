<?php
/**
 * Resend Email Service - Async Batch Processing
 * Sends emails via Resend API with batch processing
 * Retries only failed emails, not entire batches
 */
class Gym_Resend_Service
{
    private $api_key;
    private $api_endpoint = 'https://api.resend.com/emails';
    private $from_email;
    private $batch_size = 50;
    private $batch_delay = 2; // seconds between batches

    public function __construct()
    {
        $this->api_key = Gym_Admin::get_setting('resend_api_key', '');
        $this->from_email = Gym_Admin::get_setting('email_from_address', get_option('admin_email'));
    }

    /**
     * Send email via Resend API
     * Returns array with success status and message
     */
    public function send_email($to_email, $subject, $html_content, $text_content = null)
    {
        if (empty($this->api_key)) {
            return array(
                'success' => false,
                'error' => 'Resend API key not configured',
                'sent_via' => 'resend'
            );
        }

        $body = array(
            'from' => $this->from_email,
            'to' => $to_email,
            'subject' => $subject,
            'html' => $html_content
        );

        if ($text_content) {
            $body['text'] = $text_content;
        }

        $response = wp_remote_post($this->api_endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($body),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
                'sent_via' => 'resend'
            );
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        if ($http_code === 200 || $http_code === 201) {
            return array(
                'success' => true,
                'message' => 'Email sent successfully',
                'resend_id' => $response_body['id'] ?? null,
                'sent_via' => 'resend'
            );
        } else {
            $error_message = $response_body['message'] ?? 'Unknown error from Resend';
            return array(
                'success' => false,
                'error' => $error_message,
                'http_code' => $http_code,
                'sent_via' => 'resend'
            );
        }
    }

    /**
     * Send bulk emails with async batch processing
     * Returns job_id immediately, processes in background
     */
    public function send_bulk_async($user_ids, $subject, $html_content, $text_content = null, $user_data_callback = null)
    {
        if (empty($user_ids)) {
            return new WP_Error('no_users', 'No users provided', array('status' => 400));
        }

        // Create unique job ID
        $job_id = 'bulk_' . uniqid() . '_' . time();

        // Store job metadata
        $job_meta = array(
            'job_id' => $job_id,
            'total_users' => count($user_ids),
            'status' => 'processing',
            'started_at' => current_time('mysql'),
            'user_ids' => $user_ids,
            'subject' => $subject,
            'html_content' => $html_content,
            'text_content' => $text_content,
            'user_data_callback' => $user_data_callback ? serialize($user_data_callback) : null
        );

        // Store in transient (expires in 7 days)
        set_transient('gym_bulk_job_' . $job_id, $job_meta, 7 * DAY_IN_SECONDS);

        // Start background processing
        $this->process_bulk_batch($job_id, 0);

        return array(
            'success' => true,
            'job_id' => $job_id,
            'total_users' => count($user_ids),
            'message' => 'Bulk email initiated. Processing ' . count($user_ids) . ' emails in batches of ' . $this->batch_size
        );
    }

    /**
     * Process bulk email batch
     * This can be called via WordPress scheduled action or immediately
     */
    public function process_bulk_batch($job_id, $batch_number = 0)
    {
        $job_meta = get_transient('gym_bulk_job_' . $job_id);

        if (!$job_meta) {
            error_log("Gym Resend: Job {$job_id} not found or expired");
            return;
        }

        $user_ids = $job_meta['user_ids'];
        $subject = $job_meta['subject'];
        $html_content = $job_meta['html_content'];
        $text_content = $job_meta['text_content'] ?? null;

        // Calculate offset for this batch
        $offset = $batch_number * $this->batch_size;
        $batch_users = array_slice($user_ids, $offset, $this->batch_size);

        if (empty($batch_users)) {
            // All batches processed - generate and send report
            $this->finalize_bulk_job($job_id);
            return;
        }

        // Initialize results if first batch
        if ($batch_number === 0) {
            $job_meta['results'] = array(
                'sent' => 0,
                'failed' => 0,
                'failed_users' => array()
            );
        } else {
            // Get existing results
            $job_meta = get_transient('gym_bulk_job_' . $job_id);
        }

        // Process this batch
        foreach ($batch_users as $user_id) {
            $user = get_user_by('id', $user_id);

            if (!$user) {
                $job_meta['results']['failed']++;
                $job_meta['results']['failed_users'][] = array(
                    'user_id' => $user_id,
                    'error' => 'User not found',
                    'email' => 'N/A'
                );
                continue;
            }

            // Log email attempt
            $log_id = Gym_Admin::log_email($user_id, $user->user_email, $subject, 'bulk_batch', 'pending');

            // Send email via Resend
            $result = $this->send_email($user->user_email, $subject, $html_content, $text_content);

            if ($result['success']) {
                $job_meta['results']['sent']++;
                Gym_Admin::update_email_status($log_id, 'sent');
            } else {
                $job_meta['results']['failed']++;
                $job_meta['results']['failed_users'][] = array(
                    'user_id' => $user_id,
                    'email' => $user->user_email,
                    'username' => $user->user_login,
                    'display_name' => $user->display_name,
                    'error' => $result['error'] ?? 'Unknown error',
                    'retry_count' => 0
                );
                Gym_Admin::update_email_status($log_id, 'failed', $result['error'] ?? 'Unknown error');
            }

            // Small delay to avoid rate limiting
            usleep(100000); // 0.1 seconds
        }

        // Update job metadata
        set_transient('gym_bulk_job_' . $job_id, $job_meta, 7 * DAY_IN_SECONDS);

        // Schedule next batch with delay
        wp_schedule_single_event(
            time() + $this->batch_delay,
            'gym_process_bulk_batch',
            array($job_id, $batch_number + 1)
        );
    }

    /**
     * Retry only failed emails (not entire batch)
     * Called after all initial batches complete
     */
    public function retry_failed_emails($job_id)
    {
        $job_meta = get_transient('gym_bulk_job_' . $job_id);

        if (!$job_meta || empty($job_meta['results']['failed_users'])) {
            error_log("Gym Resend: No failed emails to retry for job {$job_id}");
            return;
        }

        $failed_users = $job_meta['results']['failed_users'];
        $subject = $job_meta['subject'];
        $html_content = $job_meta['html_content'];
        $text_content = $job_meta['text_content'] ?? null;

        $retry_count = 0;
        $newly_failed = array();

        foreach ($failed_users as $failed_user) {
            // Skip if already retried 3 times
            if ($failed_user['retry_count'] >= 3) {
                $newly_failed[] = $failed_user;
                continue;
            }

            $user_id = $failed_user['user_id'];
            $email = $failed_user['email'];

            // Log retry attempt
            $log_id = Gym_Admin::log_email($user_id, $email, $subject, 'bulk_batch_retry', 'pending');

            // Send email via Resend
            $result = $this->send_email($email, $subject, $html_content, $text_content);

            if ($result['success']) {
                // Success on retry
                $job_meta['results']['sent']++;
                $job_meta['results']['failed']--;
                Gym_Admin::update_email_status($log_id, 'sent');
                $retry_count++;
            } else {
                // Still failed - add to newly_failed with incremented retry count
                $failed_user['retry_count']++;
                $failed_user['error'] = $result['error'] ?? 'Unknown error';
                $newly_failed[] = $failed_user;
                Gym_Admin::update_email_status($log_id, 'failed', $result['error'] ?? 'Unknown error');
            }

            usleep(100000); // 0.1 seconds
        }

        // Update results with new failed list
        $job_meta['results']['failed_users'] = $newly_failed;
        set_transient('gym_bulk_job_' . $job_id, $job_meta, 7 * DAY_IN_SECONDS);

        error_log("Gym Resend: Retry complete for job {$job_id}. Retried: {$retry_count}, Still failed: " . count($newly_failed));
    }

    /**
     * Finalize bulk job - send admin report and clean up
     */
    private function finalize_bulk_job($job_id)
    {
        $job_meta = get_transient('gym_bulk_job_' . $job_id);

        if (!$job_meta) {
            error_log("Gym Resend: Job {$job_id} metadata not found during finalization");
            return;
        }

        // Retry failed emails once (only those that failed, not the whole batch)
        if (!empty($job_meta['results']['failed_users'])) {
            $this->retry_failed_emails($job_id);
            // Refresh metadata after retries
            $job_meta = get_transient('gym_bulk_job_' . $job_id);
        }

        // Mark job as complete
        $job_meta['status'] = 'completed';
        $job_meta['completed_at'] = current_time('mysql');
        set_transient('gym_bulk_job_' . $job_id, $job_meta, 7 * DAY_IN_SECONDS);

        // Send report to all super_admins
        $report_service = new Gym_Email_Report_Service();
        $report_service->send_bulk_report($job_id, $job_meta);

        error_log("Gym Resend: Job {$job_id} completed. Sent: {$job_meta['results']['sent']}, Failed: {$job_meta['results']['failed']}");
    }

    /**
     * Get job status
     */
    public function get_job_status($job_id)
    {
        $job_meta = get_transient('gym_bulk_job_' . $job_id);

        if (!$job_meta) {
            return new WP_Error('job_not_found', 'Job not found or expired', array('status' => 404));
        }

        return array(
            'job_id' => $job_id,
            'status' => $job_meta['status'],
            'total_users' => $job_meta['total_users'],
            'sent' => $job_meta['results']['sent'] ?? 0,
            'failed' => $job_meta['results']['failed'] ?? 0,
            'started_at' => $job_meta['started_at'],
            'completed_at' => $job_meta['completed_at'] ?? null,
            'pending_failed' => count($job_meta['results']['failed_users'] ?? array())
        );
    }

    /**
     * Test Resend connection
     */
    public function test_connection()
    {
        if (empty($this->api_key)) {
            return array(
                'success' => false,
                'message' => 'Resend API key not configured'
            );
        }

        // Send test email to admin
        $test_email = Gym_Admin::get_setting('email_from_address', get_option('admin_email'));
        $result = $this->send_email(
            $test_email,
            '[TEST] Resend Email Service',
            '<p>This is a test email from Resend integration.</p>',
            'This is a test email from Resend integration.'
        );

        return $result;
    }
}