<?php
/**
 * Bulk Job Management API Endpoints
 * Handles job status checking, history, and retry operations
 */
class Gym_Bulk_Job_Endpoints
{
    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes()
    {
        // Get bulk jobs history with pagination
        register_rest_route('gym-admin/v1', '/bulk-jobs', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_bulk_jobs'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'page' => array(
                    'default' => 1,
                    'type' => 'integer',
                    'description' => 'Page number for pagination'
                ),
                'limit' => array(
                    'default' => 10,
                    'type' => 'integer',
                    'description' => 'Items per page'
                ),
                'status' => array(
                    'type' => 'string',
                    'enum' => array('processing', 'completed', 'failed', 'all'),
                    'description' => 'Filter by job status'
                )
            )
        ));

        // Get specific job status
        register_rest_route('gym-admin/v1', '/bulk-jobs/(?P<job_id>[^/]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_job_status'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'job_id' => array(
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Bulk job ID'
                )
            )
        ));

        // Retry failed emails for a job
        register_rest_route('gym-admin/v1', '/bulk-jobs/(?P<job_id>[^/]+)/retry', array(
            'methods' => 'POST',
            'callback' => array($this, 'retry_failed_emails'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'job_id' => array(
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Bulk job ID'
                )
            )
        ));

        // Clear/archive completed jobs
        register_rest_route('gym-admin/v1', '/bulk-jobs/(?P<job_id>[^/]+)/clear', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'clear_job'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'job_id' => array(
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Bulk job ID'
                )
            )
        ));
    }

    /**
     * Get bulk jobs history with pagination
     */
    public function get_bulk_jobs($request)
    {
        $page = max(1, $request->get_param('page') ?? 1);
        $limit = min(50, $request->get_param('limit') ?? 10);
        $status = $request->get_param('status') ?? 'all';

        $offset = ($page - 1) * $limit;

        try {
            $jobs = $this->fetch_jobs_from_storage($offset, $limit, $status);
            $total = $this->count_jobs_in_storage($status);

            return rest_ensure_response(array(
                'success' => true,
                'jobs' => $jobs,
                'total' => $total,
                'page' => $page,
                'per_page' => $limit,
                'has_more' => ($offset + $limit) < $total
            ));
        } catch (Exception $e) {
            return new WP_Error(
                'fetch_jobs_failed',
                'Failed to fetch bulk jobs: ' . $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    /**
     * Get specific job status
     */
    public function get_job_status($request)
    {
        $job_id = $request->get_param('job_id');

        if (empty($job_id)) {
            return new WP_Error('missing_job_id', 'Job ID is required', array('status' => 400));
        }

        try {
            // Try to get from transient (live jobs)
            $job_meta = get_transient('gym_bulk_job_' . $job_id);

            if ($job_meta) {
                $job = $this->format_job($job_id, $job_meta);
                return rest_ensure_response(array(
                    'success' => true,
                    'job' => $job
                ));
            }

            // Try to get from database (archived jobs)
            $job = $this->get_job_from_database($job_id);

            if ($job) {
                return rest_ensure_response(array(
                    'success' => true,
                    'job' => $job
                ));
            }

            return new WP_Error(
                'job_not_found',
                'Bulk job not found or expired',
                array('status' => 404)
            );
        } catch (Exception $e) {
            return new WP_Error(
                'fetch_job_failed',
                'Failed to fetch job status: ' . $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    /**
     * Retry failed emails for a job
     */
    public function retry_failed_emails($request)
    {
        $job_id = $request->get_param('job_id');

        if (empty($job_id)) {
            return new WP_Error('missing_job_id', 'Job ID is required', array('status' => 400));
        }

        try {
            // Get job metadata
            $job_meta = get_transient('gym_bulk_job_' . $job_id);

            if (!$job_meta) {
                return new WP_Error(
                    'job_not_found',
                    'Job not found or expired',
                    array('status' => 404)
                );
            }

            // Check if there are failed emails to retry
            if (empty($job_meta['results']['failed_users'])) {
                return new WP_Error(
                    'no_failed_emails',
                    'No failed emails to retry for this job',
                    array('status' => 400)
                );
            }

            // Use Resend service to retry
            $resend_service = new Gym_Resend_Service();
            $resend_service->retry_failed_emails($job_id);

            // Get updated job metadata
            $updated_job_meta = get_transient('gym_bulk_job_' . $job_id);
            $job = $this->format_job($job_id, $updated_job_meta);

            error_log("Retried failed emails for job {$job_id}");

            return rest_ensure_response(array(
                'success' => true,
                'message' => 'Retrying failed emails...',
                'job' => $job,
                'retried_count' => count($job_meta['results']['failed_users'])
            ));
        } catch (Exception $e) {
            return new WP_Error(
                'retry_failed',
                'Failed to retry emails: ' . $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    /**
     * Clear/archive a completed job
     */
    public function clear_job($request)
    {
        $job_id = $request->get_param('job_id');

        if (empty($job_id)) {
            return new WP_Error('missing_job_id', 'Job ID is required', array('status' => 400));
        }

        try {
            // Get job from transient before deleting
            $job_meta = get_transient('gym_bulk_job_' . $job_id);

            if ($job_meta) {
                // Archive to database if needed
                $this->archive_job_to_database($job_id, $job_meta);

                // Clear from transient
                delete_transient('gym_bulk_job_' . $job_id);

                error_log("Cleared job {$job_id}");

                return rest_ensure_response(array(
                    'success' => true,
                    'message' => 'Job cleared successfully'
                ));
            }

            return new WP_Error(
                'job_not_found',
                'Job not found',
                array('status' => 404)
            );
        } catch (Exception $e) {
            return new WP_Error(
                'clear_failed',
                'Failed to clear job: ' . $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    /**
     * Format job data for API response
     */
    private function format_job($job_id, $job_meta)
    {
        return array(
            'job_id' => $job_id,
            'status' => $job_meta['status'] ?? 'processing',
            'total_users' => $job_meta['total_users'] ?? 0,
            'sent' => $job_meta['results']['sent'] ?? 0,
            'failed' => $job_meta['results']['failed'] ?? 0,
            'started_at' => $job_meta['started_at'] ?? current_time('mysql'),
            'completed_at' => $job_meta['completed_at'] ?? null,
            'current_batch' => isset($job_meta['current_batch']) ? $job_meta['current_batch'] : null,
            'total_batches' => isset($job_meta['total_batches']) ? $job_meta['total_batches'] : null,
            'failed_users' => $job_meta['results']['failed_users'] ?? array()
        );
    }

    /**
     * Fetch jobs from transient storage (live jobs)
     */
    private function fetch_jobs_from_storage($offset = 0, $limit = 10, $status = 'all')
    {
        global $wpdb;

        // Get all job transient keys
        $transient_prefix = '_transient_gym_bulk_job_';
        $like = $wpdb->esc_like($transient_prefix) . '%';

        $query = $wpdb->prepare(
            "SELECT option_name, option_value 
             FROM {$wpdb->options} 
             WHERE option_name LIKE %s 
             ORDER BY option_id DESC",
            $like
        );

        $results = $wpdb->get_results($query);

        $jobs = array();

        if ($results) {
            foreach ($results as $row) {
                $job_id = str_replace($transient_prefix, '', $row->option_name);
                $job_meta = maybe_unserialize($row->option_value);

                if (is_array($job_meta)) {
                    // Filter by status if specified
                    $job_status = $job_meta['status'] ?? 'processing';
                    if ($status !== 'all' && $job_status !== $status) {
                        continue;
                    }

                    $jobs[] = $this->format_job($job_id, $job_meta);
                }
            }
        }

        // Apply offset and limit
        return array_slice($jobs, $offset, $limit);
    }

    /**
     * Count jobs in storage
     */
    private function count_jobs_in_storage($status = 'all')
    {
        global $wpdb;

        $transient_prefix = '_transient_gym_bulk_job_';
        $like = $wpdb->esc_like($transient_prefix) . '%';

        $query = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} 
             WHERE option_name LIKE %s",
            $like
        );

        $total = $wpdb->get_var($query);

        // If filtering by status, we need to count manually
        if ($status !== 'all') {
            $all_results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_value FROM {$wpdb->options} 
                     WHERE option_name LIKE %s",
                    $like
                )
            );

            $count = 0;
            foreach ($all_results as $row) {
                $job_meta = maybe_unserialize($row->option_value);
                if (is_array($job_meta) && ($job_meta['status'] ?? 'processing') === $status) {
                    $count++;
                }
            }

            return $count;
        }

        return intval($total);
    }

    /**
     * Get job from database (archived jobs)
     */
    private function get_job_from_database($job_id)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'gym_bulk_jobs'; // Assuming this table exists

        // If table doesn't exist, return null
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return null;
        }

        $job = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE job_id = %s", $job_id),
            ARRAY_A
        );

        if ($job) {
            // Deserialize failed_users if needed
            if (isset($job['failed_users'])) {
                $job['failed_users'] = maybe_unserialize($job['failed_users']);
            }

            return $job;
        }

        return null;
    }

    /**
     * Archive job to database before deleting
     */
    private function archive_job_to_database($job_id, $job_meta)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'gym_bulk_jobs';

        // Create table if it doesn't exist
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            $this->create_bulk_jobs_table();
        }

        $wpdb->insert(
            $table,
            array(
                'job_id' => $job_id,
                'status' => $job_meta['status'] ?? 'processing',
                'total_users' => $job_meta['total_users'] ?? 0,
                'sent' => $job_meta['results']['sent'] ?? 0,
                'failed' => $job_meta['results']['failed'] ?? 0,
                'started_at' => $job_meta['started_at'] ?? current_time('mysql'),
                'completed_at' => $job_meta['completed_at'] ?? null,
                'failed_users' => maybe_serialize($job_meta['results']['failed_users'] ?? array()),
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s')
        );
    }

    /**
     * Create bulk_jobs table if it doesn't exist
     */
    private function create_bulk_jobs_table()
    {
        global $wpdb;

        $table = $wpdb->prefix . 'gym_bulk_jobs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            job_id VARCHAR(100) UNIQUE NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'processing',
            total_users INT UNSIGNED NOT NULL DEFAULT 0,
            sent INT UNSIGNED NOT NULL DEFAULT 0,
            failed INT UNSIGNED NOT NULL DEFAULT 0,
            started_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            failed_users LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            KEY job_id (job_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        error_log("Bulk jobs table created");
    }

    /**
     * Check API permission
     */
    public function check_permission($request)
    {
        return Gym_Admin::check_api_permission($request, 'manage_options');
    }
}