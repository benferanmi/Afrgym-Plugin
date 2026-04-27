<?php
/**
 * Gym Email Report Service
 * Generates and sends detailed reports to super_admins
 */
class Gym_Email_Report_Service
{
    /**
     * Send bulk email report to all super_admins
     */
    public function send_bulk_report($job_id, $job_meta)
    {
        // Get all super_admins from BOTH gym tables
        $super_admins = $this->get_all_super_admins();

        if (empty($super_admins)) {
            error_log("Gym Email Report: No super_admins found for report {$job_id}");
            return false;
        }

        // Generate report HTML
        $report_html = $this->generate_bulk_report_html($job_id, $job_meta);
        $report_subject = '[Bulk Email Report] ' . $job_meta['total_users'] . ' emails processed - ' . date('Y-m-d H:i:s');

        // Send to each super_admin
        $sent_count = 0;
        foreach ($super_admins as $admin) {
            $result = $this->send_report_email(
                $admin->email,
                $admin->first_name . ' ' . $admin->last_name,
                $report_subject,
                $report_html,
                $job_meta
            );

            if ($result) {
                $sent_count++;
            }
        }

        error_log("Gym Email Report: Report {$job_id} sent to {$sent_count} super_admins");
        return true;
    }

    /**
     * Get all super_admins from both gym tables
     */
    private function get_all_super_admins()
    {
        global $wpdb;

        $gym_one_table = $wpdb->prefix . 'gym_admins';
        $gym_two_table = $wpdb->prefix . 'gym_admins_two';

        // Get super_admins from Gym One
        $gym_one_admins = $wpdb->get_results(
            "SELECT id, username, email, first_name, last_name, gym_identifier 
             FROM $gym_one_table 
             WHERE role = 'super_admin' AND status = 'active' 
             ORDER BY created_at"
        );

        // Get super_admins from Gym Two
        $gym_two_admins = $wpdb->get_results(
            "SELECT id, username, email, first_name, last_name, gym_identifier 
             FROM $gym_two_table 
             WHERE role = 'super_admin' AND status = 'active' 
             ORDER BY created_at"
        );

        // Merge and remove duplicates by email
        $all_admins = array_merge($gym_one_admins ?: array(), $gym_two_admins ?: array());
        
        // Remove duplicate emails
        $seen_emails = array();
        $unique_admins = array();
        foreach ($all_admins as $admin) {
            if (!in_array($admin->email, $seen_emails)) {
                $unique_admins[] = $admin;
                $seen_emails[] = $admin->email;
            }
        }

        return $unique_admins;
    }

    /**
     * Generate HTML report for bulk email job
     */
    private function generate_bulk_report_html($job_id, $job_meta)
    {
        $total = $job_meta['total_users'];
        $sent = $job_meta['results']['sent'];
        $failed = $job_meta['results']['failed'];
        $failed_users = $job_meta['results']['failed_users'] ?? array();
        $success_rate = $total > 0 ? round(($sent / $total) * 100, 2) : 0;

        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
                .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 5px; margin-bottom: 30px; }
                .header h1 { margin: 0; font-size: 24px; }
                .header p { margin: 5px 0 0 0; opacity: 0.9; }
                .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px; }
                .stat-box { background: #f8f9fa; padding: 15px; border-radius: 5px; border-left: 4px solid #667eea; }
                .stat-label { font-size: 12px; color: #666; text-transform: uppercase; margin-bottom: 5px; }
                .stat-value { font-size: 28px; font-weight: bold; color: #333; }
                .stat-box.success { border-left-color: #28a745; }
                .stat-box.success .stat-value { color: #28a745; }
                .stat-box.failed { border-left-color: #dc3545; }
                .stat-box.failed .stat-value { color: #dc3545; }
                .progress-bar { background: #e9ecef; height: 20px; border-radius: 10px; overflow: hidden; margin: 20px 0; }
                .progress-fill { background: linear-gradient(90deg, #28a745, #20c997); height: 100%; width: ' . $success_rate . '%; }
                .section { margin-bottom: 30px; }
                .section-title { font-size: 16px; font-weight: bold; color: #333; border-bottom: 2px solid #667eea; padding-bottom: 10px; margin-bottom: 15px; }
                table { width: 100%; border-collapse: collapse; margin-top: 15px; }
                table th { background: #f8f9fa; padding: 12px; text-align: left; font-weight: bold; border-bottom: 1px solid #ddd; }
                table td { padding: 12px; border-bottom: 1px solid #ddd; }
                table tr:hover { background: #f8f9fa; }
                .error-row { color: #dc3545; }
                .footer { border-top: 1px solid #ddd; padding-top: 20px; margin-top: 20px; color: #666; font-size: 12px; }
                .job-id { background: #f8f9fa; padding: 10px; border-radius: 3px; font-family: monospace; word-break: break-all; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>📧 Bulk Email Campaign Report</h1>
                    <p>Job ID: <code class="job-id">' . esc_html($job_id) . '</code></p>
                </div>

                <div class="stats">
                    <div class="stat-box">
                        <div class="stat-label">Total Emails</div>
                        <div class="stat-value">' . esc_html($total) . '</div>
                    </div>
                    <div class="stat-box success">
                        <div class="stat-label">Successfully Sent</div>
                        <div class="stat-value">' . esc_html($sent) . '</div>
                    </div>
                    <div class="stat-box failed">
                        <div class="stat-label">Failed</div>
                        <div class="stat-value">' . esc_html($failed) . '</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-label">Success Rate</div>
                        <div class="stat-value">' . esc_html($success_rate) . '%</div>
                    </div>
                </div>

                <div class="progress-bar">
                    <div class="progress-fill"></div>
                </div>

                <div class="section">
                    <div class="section-title">Campaign Details</div>
                    <table>
                        <tr>
                            <td><strong>Started:</strong></td>
                            <td>' . esc_html($job_meta['started_at']) . '</td>
                        </tr>
                        <tr>
                            <td><strong>Completed:</strong></td>
                            <td>' . esc_html($job_meta['completed_at'] ?? 'Processing...') . '</td>
                        </tr>
                        <tr>
                            <td><strong>Subject Line:</strong></td>
                            <td>' . esc_html($job_meta['subject']) . '</td>
                        </tr>
                    </table>
                </div>
        ';

        // Add failed emails table if any failed
        if (!empty($failed_users)) {
            $html .= '
                <div class="section">
                    <div class="section-title">❌ Failed Email Details</div>
                    <p>The following ' . count($failed_users) . ' email(s) could not be sent:</p>
                    <table>
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Email Address</th>
                                <th>Error Reason</th>
                                <th>Retries</th>
                            </tr>
                        </thead>
                        <tbody>
            ';

            foreach ($failed_users as $user) {
                $html .= '
                            <tr class="error-row">
                                <td>' . esc_html($user['display_name'] ?? $user['username'] ?? 'N/A') . '</td>
                                <td>' . esc_html($user['email']) . '</td>
                                <td><small>' . esc_html($user['error']) . '</small></td>
                                <td>' . (isset($user['retry_count']) ? esc_html($user['retry_count']) . '/3' : '0/3') . '</td>
                            </tr>
                ';
            }

            $html .= '
                        </tbody>
                    </table>
                </div>
            ';
        }

        $html .= '
                <div class="section">
                    <div class="section-title">📊 Summary</div>
                    <p>This bulk email campaign was processed successfully. Here\'s what happened:</p>
                    <ul>
                        <li><strong>' . esc_html($sent) . '</strong> emails were delivered successfully</li>
                        <li><strong>' . esc_html($failed) . '</strong> emails failed to send</li>
                        <li>Failed emails were automatically retried up to 3 times</li>
                        <li>All results have been logged in the email system</li>
                    </ul>
                </div>

                <div class="footer">
                    <p><strong>' . esc_html(get_bloginfo('name')) . '</strong></p>
                    <p>Report generated: ' . esc_html(date('Y-m-d H:i:s')) . '</p>
                    <p>This is an automated email. Do not reply to this message.</p>
                </div>
            </div>
        </body>
        </html>
            ';

        return $html;
    }

    /**
     * Send individual report email
     */
    private function send_report_email($to_email, $admin_name, $subject, $html_content, $job_meta)
    {
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . Gym_Admin::get_setting('email_from_name', get_bloginfo('name')) . ' <' . Gym_Admin::get_setting('email_from_address', get_option('admin_email')) . '>'
        );

        // Personalize the email
        $personalized_content = str_replace(
            '<!-- INSERT ADMIN NAME -->',
            '<p>Hi ' . esc_html($admin_name) . ',</p><p>Your bulk email campaign has completed. See the summary below:</p>',
            $html_content
        );

        $sent = wp_mail($to_email, $subject, $personalized_content, $headers);

        if ($sent) {
            error_log("Gym Email Report: Report email sent to {$to_email}");
        } else {
            error_log("Gym Email Report: Failed to send report email to {$to_email}");
        }

        return $sent;
    }
}