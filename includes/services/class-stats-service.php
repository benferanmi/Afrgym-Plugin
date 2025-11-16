<?php
/**
 * Statistics service for comprehensive gym analytics - COMPLETE GYM ISOLATION
 * All stats filtered by gym_identifier - NO shared data between gyms
 */
class Gym_Stats_Service
{
    private $membership_service;

    public function __construct()
    {
        $this->membership_service = new Gym_Membership_Service();
    }

    /**
     * Get comprehensive dashboard statistics (COMPLETELY FILTERED BY GYM)
     */
    public function get_dashboard_stats($gym_identifier = null)
    {
        $current_date = current_time('Y-m-d');
        $current_month = current_time('Y-m');
        $previous_month = date('Y-m', strtotime('-1 month'));

        return array(
            'summary' => $this->get_summary_stats($gym_identifier),
            'daily_today' => $this->get_daily_stats($current_date, $gym_identifier),
            'current_month' => $this->get_monthly_stats($current_month, $gym_identifier),
            'previous_month' => $this->get_monthly_stats($previous_month, $gym_identifier),
            'membership_breakdown' => $this->get_membership_breakdown($gym_identifier),
            'recent_activities' => $this->get_recent_activities(10, $gym_identifier),
            'expiring_soon' => $this->get_expiring_memberships_summary($gym_identifier),
            'growth_trends' => $this->get_growth_trends($gym_identifier),
            'pause_statistics' => $this->get_pause_statistics($gym_identifier),
            'admin_activity' => $this->get_admin_activity_stats($gym_identifier)
        );
    }

    /**
     * Get users created by specific gym (CORE HELPER METHOD)
     */
    private function get_gym_user_ids($gym_identifier)
    {
        global $wpdb;

        if (!$gym_identifier) {
            return array();
        }

        $notes_table = $wpdb->prefix . 'gym_user_notes';

        // Get user IDs created by specific gym from first note
        $user_ids_query = "
            SELECT DISTINCT n1.user_id
            FROM $notes_table n1
            INNER JOIN (
                SELECT user_id, MIN(id) as first_note_id
                FROM $notes_table
                GROUP BY user_id
            ) n2 ON n1.id = n2.first_note_id
            WHERE n1.gym_identifier = %s
        ";

        $user_ids = $wpdb->get_col($wpdb->prepare($user_ids_query, $gym_identifier));

        return !empty($user_ids) ? $user_ids : array(0); // Return array(0) to prevent SQL errors
    }

    /**
     * Get summary statistics - COMPLETELY FILTERED BY GYM
     * FIXED: Now counts only existing users and matches recipient stats logic
     */
    public function get_summary_stats($gym_identifier = null)
    {
        global $wpdb;

        if (!$gym_identifier) {
            return array(
                'error' => 'Gym identifier is required',
                'total_users' => 0,
                'total_active_members' => 0,
                'total_expired_members' => 0,
                'total_paused_members' => 0,
                'users_with_qr_codes' => 0,
                'emails_sent_last_30_days' => 0
            );
        }

        // Get users created by this gym
        $gym_user_ids = $this->get_gym_user_ids($gym_identifier);

        // FIXED: Filter to only existing users in wp_users table
        if (empty($gym_user_ids) || $gym_user_ids[0] === 0) {
            $existing_user_ids = array(0);
            $total_users = 0;
        } else {
            $user_ids_string = implode(',', array_map('intval', $gym_user_ids));

            // Get only users that actually exist in wp_users table
            $existing_user_ids = $wpdb->get_col("
            SELECT DISTINCT ID
            FROM {$wpdb->users}
            WHERE ID IN ($user_ids_string)
        ");

            $total_users = count($existing_user_ids);

            if (empty($existing_user_ids)) {
                $existing_user_ids = array(0);
            }
        }

        $user_ids_string = implode(',', array_map('intval', $existing_user_ids));

        // Get membership statistics using the SAME logic as recipient stats
        $pmpro_members_table = $wpdb->prefix . 'pmpro_memberships_users';

        // Active members (not paused, has active membership with valid end date)
        $active_members = $wpdb->get_var("
        SELECT COUNT(DISTINCT mu.user_id)
        FROM $pmpro_members_table mu
        LEFT JOIN {$wpdb->usermeta} pause ON mu.user_id = pause.user_id AND pause.meta_key = 'membership_is_paused'
        WHERE mu.user_id IN ($user_ids_string)
        AND mu.status = 'active'
        AND (pause.meta_value IS NULL OR pause.meta_value = '0')
        AND (mu.enddate IS NULL OR mu.enddate = '0000-00-00 00:00:00' OR mu.enddate >= NOW())
    ");

        // Expired members (have active status but end date has passed)
        $expired_members = $wpdb->get_var("
        SELECT COUNT(DISTINCT mu.user_id)
        FROM $pmpro_members_table mu
        WHERE mu.user_id IN ($user_ids_string)
        AND mu.status = 'active'
        AND mu.enddate IS NOT NULL
        AND mu.enddate != '0000-00-00 00:00:00'
        AND mu.enddate < NOW()
    ");

        // Paused members
        $paused_members = $wpdb->get_var("
        SELECT COUNT(DISTINCT user_id)
        FROM {$wpdb->usermeta}
        WHERE user_id IN ($user_ids_string)
        AND meta_key = 'membership_is_paused'
        AND meta_value = '1'
    ");

        // Get QR code statistics for gym users only
        $users_with_qr = $wpdb->get_var("
        SELECT COUNT(DISTINCT user_id) 
        FROM {$wpdb->usermeta} 
        WHERE meta_key = 'unique_id' 
        AND meta_value != '' 
        AND user_id IN ($user_ids_string)
    ");

        // Get email statistics for this gym (last 30 days)
        $email_logs_table = $wpdb->prefix . 'gym_email_logs';
        $emails_sent_30d = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$email_logs_table} 
         WHERE status = 'sent' 
         AND gym_identifier = %s 
         AND sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            $gym_identifier
        ));

        // Get membership levels count (only levels with members from this gym)
        $levels_with_members = $wpdb->get_results("
        SELECT DISTINCT ml.id, ml.name
        FROM {$wpdb->prefix}pmpro_membership_levels ml
        INNER JOIN $pmpro_members_table mu ON ml.id = mu.membership_id
        WHERE mu.user_id IN ($user_ids_string)
        AND mu.status = 'active'
        ORDER BY ml.id
    ");

        $active_members = (int) ($active_members ?: 0);
        $expired_members = (int) ($expired_members ?: 0);
        $paused_members = (int) ($paused_members ?: 0);

        return array(
            'gym_identifier' => $gym_identifier,
            'gym_name' => $gym_identifier === 'afrgym_two' ? 'Afrgym Two' : 'Afrgym One',
            'total_users' => (int) $total_users,
            'total_active_members' => $active_members,
            'total_expired_members' => $expired_members,
            'total_paused_members' => $paused_members,
            'users_with_qr_codes' => (int) $users_with_qr,
            'emails_sent_last_30_days' => (int) $emails_sent_30d,
            'membership_levels_count' => count($levels_with_members),
            'overall_health_score' => $this->calculate_health_score(
                array(
                    'active_members' => $active_members,
                    'expired_members' => $expired_members,
                    'paused_members' => $paused_members
                ),
                $total_users
            )
        );
    }

    /**
     * Get membership statistics for specific user IDs
     */
    private function get_membership_stats_for_users($user_ids)
    {
        global $wpdb;

        if (empty($user_ids) || $user_ids[0] === 0) {
            return array(
                'active_members' => 0,
                'expired_members' => 0,
                'paused_members' => 0,
                'total_members' => 0,
                'by_level' => array()
            );
        }

        $user_ids_string = implode(',', $user_ids);

        // Get PMPro membership data for these users
        $pmpro_table = $wpdb->prefix . 'pmpro_memberships_users';

        $active_members = $wpdb->get_var("
            SELECT COUNT(DISTINCT user_id) 
            FROM $pmpro_table 
            WHERE user_id IN ($user_ids_string)
            AND status = 'active'
            AND (enddate IS NULL OR enddate = '0000-00-00 00:00:00' OR enddate >= NOW())
        ");

        $expired_members = $wpdb->get_var("
            SELECT COUNT(DISTINCT user_id) 
            FROM $pmpro_table 
            WHERE user_id IN ($user_ids_string)
            AND status = 'active'
            AND enddate IS NOT NULL 
            AND enddate != '0000-00-00 00:00:00' 
            AND enddate < NOW()
        ");

        // Get paused members
        $paused_members = $wpdb->get_var("
            SELECT COUNT(DISTINCT user_id) 
            FROM {$wpdb->usermeta} 
            WHERE meta_key = 'membership_is_paused' 
            AND meta_value = '1'
            AND user_id IN ($user_ids_string)
        ");

        // Get membership breakdown by level
        $by_level = $wpdb->get_results("
            SELECT 
                ml.id as level_id,
                ml.name as level_name,
                COUNT(DISTINCT mu.user_id) as member_count
            FROM {$wpdb->prefix}pmpro_membership_levels ml
            LEFT JOIN $pmpro_table mu ON ml.id = mu.membership_id 
                AND mu.user_id IN ($user_ids_string)
                AND mu.status = 'active'
            GROUP BY ml.id, ml.name
            HAVING member_count > 0
            ORDER BY member_count DESC
        ");

        $formatted_levels = array();
        foreach ($by_level as $level) {
            $formatted_levels[] = array(
                'level_id' => (int) $level->level_id,
                'level_name' => $level->level_name,
                'member_count' => (int) $level->member_count
            );
        }

        return array(
            'active_members' => (int) $active_members,
            'expired_members' => (int) $expired_members,
            'paused_members' => (int) $paused_members,
            'total_members' => (int) ($active_members + $expired_members),
            'by_level' => $formatted_levels
        );
    }

    /**
     * Get daily statistics - FILTERED BY GYM
     */
    public function get_daily_stats($date = null, $gym_identifier = null)
    {
        global $wpdb;

        if (!$date) {
            $date = current_time('Y-m-d');
        }

        if (!$gym_identifier) {
            return array('error' => 'Gym identifier is required');
        }

        $start_date = $date . ' 00:00:00';
        $end_date = $date . ' 23:59:59';
        $user_notes_table = $wpdb->prefix . 'gym_user_notes';

        // Get gym user IDs
        $gym_user_ids = $this->get_gym_user_ids($gym_identifier);

        // New user registrations by this gym
        $new_users = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM {$user_notes_table} 
             WHERE gym_identifier = %s
             AND note LIKE '%created via API%'
             AND created_at >= %s AND created_at <= %s",
            $gym_identifier,
            $start_date,
            $end_date
        ));

        // New membership assignments by this gym
        $new_memberships = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$user_notes_table} 
             WHERE gym_identifier = %s
             AND (note LIKE '%Membership assigned%' OR note LIKE '%New membership assigned%')
             AND created_at >= %s AND created_at <= %s",
            $gym_identifier,
            $start_date,
            $end_date
        ));

        // Membership updates by this gym
        $membership_updates = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$user_notes_table} 
             WHERE gym_identifier = %s
             AND (note LIKE '%Membership updated%' OR note LIKE '%Membership changed%')
             AND created_at >= %s AND created_at <= %s",
            $gym_identifier,
            $start_date,
            $end_date
        ));

        // QR codes generated by this gym
        $qr_generated = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$user_notes_table} 
             WHERE gym_identifier = %s
             AND note LIKE '%QR code%generated%'
             AND created_at >= %s AND created_at <= %s",
            $gym_identifier,
            $start_date,
            $end_date
        ));

        // Emails sent by this gym
        $email_logs_table = $wpdb->prefix . 'gym_email_logs';
        $emails_sent = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$email_logs_table} 
             WHERE gym_identifier = %s
             AND status = 'sent' AND sent_at >= %s AND sent_at <= %s",
            $gym_identifier,
            $start_date,
            $end_date
        ));

        // Membership pauses/unpauses by this gym
        $pauses_today = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$user_notes_table} 
             WHERE gym_identifier = %s
             AND note LIKE '%paused%'
             AND created_at >= %s AND created_at <= %s",
            $gym_identifier,
            $start_date,
            $end_date
        ));

        return array(
            'date' => $date,
            'gym_identifier' => $gym_identifier,
            'new_user_registrations' => (int) $new_users,
            'new_membership_assignments' => (int) $new_memberships,
            'membership_updates' => (int) $membership_updates,
            'qr_codes_generated' => (int) $qr_generated,
            'emails_sent' => (int) $emails_sent,
            'membership_pauses' => (int) $pauses_today,
            'total_activities' => (int) ($new_users + $new_memberships + $membership_updates + $qr_generated)
        );
    }

    /**
     * Get monthly statistics - FILTERED BY GYM
     */
    public function get_monthly_stats($month = null, $gym_identifier = null)
    {
        global $wpdb;

        if (!$month) {
            $month = current_time('Y-m');
        }

        if (!$gym_identifier) {
            return array('error' => 'Gym identifier is required');
        }

        $start_date = $month . '-01 00:00:00';
        $end_date = date('Y-m-t 23:59:59', strtotime($start_date));
        $user_notes_table = $wpdb->prefix . 'gym_user_notes';

        // Get gym user IDs
        $gym_user_ids = $this->get_gym_user_ids($gym_identifier);

        // New users created by this gym this month
        $new_users = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM {$user_notes_table} 
             WHERE gym_identifier = %s
             AND note LIKE '%created via API%'
             AND created_at >= %s AND created_at <= %s",
            $gym_identifier,
            $start_date,
            $end_date
        ));

        // Membership activities breakdown
        $new_memberships = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$user_notes_table} 
             WHERE gym_identifier = %s
             AND (note LIKE '%Membership assigned%' OR note LIKE '%New membership assigned%')
             AND created_at >= %s AND created_at <= %s",
            $gym_identifier,
            $start_date,
            $end_date
        ));

        $membership_updates = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$user_notes_table} 
             WHERE gym_identifier = %s
             AND (note LIKE '%Membership updated%' OR note LIKE '%Membership changed%')
             AND created_at >= %s AND created_at <= %s",
            $gym_identifier,
            $start_date,
            $end_date
        ));

        $membership_cancellations = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$user_notes_table} 
             WHERE gym_identifier = %s
             AND note LIKE '%Membership cancelled%'
             AND created_at >= %s AND created_at <= %s",
            $gym_identifier,
            $start_date,
            $end_date
        ));

        // Get membership level breakdown for this month
        $level_breakdown = $this->get_monthly_membership_breakdown($month, $gym_identifier);

        // Email statistics
        $email_logs_table = $wpdb->prefix . 'gym_email_logs';
        $emails_sent = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$email_logs_table} 
             WHERE gym_identifier = %s
             AND status = 'sent' AND sent_at >= %s AND sent_at <= %s",
            $gym_identifier,
            $start_date,
            $end_date
        ));

        // QR codes generated
        $qr_generated = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$user_notes_table} 
             WHERE gym_identifier = %s
             AND note LIKE '%QR code%generated%'
             AND created_at >= %s AND created_at <= %s",
            $gym_identifier,
            $start_date,
            $end_date
        ));

        return array(
            'month' => $month,
            'gym_identifier' => $gym_identifier,
            'period' => array(
                'start_date' => $start_date,
                'end_date' => $end_date
            ),
            'new_users' => (int) $new_users,
            'membership_activities' => array(
                'new_assignments' => (int) $new_memberships,
                'updates' => (int) $membership_updates,
                'cancellations' => (int) $membership_cancellations,
                'total' => (int) ($new_memberships + $membership_updates + $membership_cancellations)
            ),
            'membership_level_breakdown' => $level_breakdown,
            'emails_sent' => (int) $emails_sent,
            'qr_codes_generated' => (int) $qr_generated
        );
    }

    /**
     * Get membership breakdown by level - FILTERED BY GYM
     */
    public function get_membership_breakdown($gym_identifier = null)
    {
        if (!$gym_identifier) {
            return array(
                'error' => 'Gym identifier is required',
                'by_level' => array(),
                'summary' => array(
                    'total_members' => 0,
                    'active_members' => 0,
                    'expired_members' => 0,
                    'paused_members' => 0
                )
            );
        }

        // Get gym user IDs
        $gym_user_ids = $this->get_gym_user_ids($gym_identifier);
        $membership_stats = $this->get_membership_stats_for_users($gym_user_ids);

        // Add percentage calculations
        $total_members = $membership_stats['total_members'];

        foreach ($membership_stats['by_level'] as &$level) {
            $level['percentage'] = $total_members > 0 ? round(($level['member_count'] / $total_members) * 100, 2) : 0;
        }

        return array(
            'gym_identifier' => $gym_identifier,
            'by_level' => $membership_stats['by_level'],
            'summary' => array(
                'total_members' => $membership_stats['total_members'],
                'active_members' => $membership_stats['active_members'],
                'expired_members' => $membership_stats['expired_members'],
                'paused_members' => $membership_stats['paused_members']
            )
        );
    }

    /**
     * Get recent activities - FILTERED BY GYM
     */
    public function get_recent_activities($limit = 10, $gym_identifier = null)
    {
        global $wpdb;

        if (!$gym_identifier) {
            return array();
        }

        $user_notes_table = $wpdb->prefix . 'gym_user_notes';

        $activities = $wpdb->get_results($wpdb->prepare(
            "SELECT n.*, u.display_name as user_name, u.user_email,
                    n.admin_name,
                    CASE 
                        WHEN n.gym_identifier = 'afrgym_one' THEN 'Afrgym One'
                        WHEN n.gym_identifier = 'afrgym_two' THEN 'Afrgym Two'
                        ELSE 'Unknown Gym'
                    END as gym_name
             FROM {$user_notes_table} n
             LEFT JOIN {$wpdb->users} u ON n.user_id = u.ID
             WHERE n.gym_identifier = %s
             ORDER BY n.created_at DESC
             LIMIT %d",
            $gym_identifier,
            $limit
        ));

        $formatted_activities = array();
        foreach ($activities as $activity) {
            $formatted_activities[] = array(
                'id' => $activity->id,
                'user_id' => $activity->user_id,
                'user_name' => $activity->user_name ?: 'Unknown User',
                'user_email' => $activity->user_email,
                'admin_name' => $activity->admin_name ?: 'System',
                'gym_name' => $activity->gym_name,
                'gym_identifier' => $activity->gym_identifier,
                'activity' => $activity->note,
                'date' => $activity->created_at,
                'activity_type' => $this->classify_activity($activity->note)
            );
        }

        return $formatted_activities;
    }

    /**
     * Get expiring memberships summary - FILTERED BY GYM
     */
    public function get_expiring_memberships_summary($gym_identifier = null)
    {
        global $wpdb;

        if (!$gym_identifier) {
            return array(
                'gym_identifier' => null,
                'expiring_in_7_days' => 0,
                'expiring_in_30_days' => 0,
                'urgent_renewals' => array()
            );
        }

        // Get gym user IDs
        $gym_user_ids = $this->get_gym_user_ids($gym_identifier);

        if (empty($gym_user_ids) || $gym_user_ids[0] === 0) {
            return array(
                'gym_identifier' => $gym_identifier,
                'expiring_in_7_days' => 0,
                'expiring_in_30_days' => 0,
                'urgent_renewals' => array()
            );
        }

        $user_ids_string = implode(',', $gym_user_ids);
        $pmpro_table = $wpdb->prefix . 'pmpro_memberships_users';

        // Get expiring in 7 days
        $expiring_7 = $wpdb->get_results("
            SELECT mu.user_id, u.display_name, u.user_email, mu.enddate, ml.name as level_name
            FROM $pmpro_table mu
            LEFT JOIN {$wpdb->users} u ON mu.user_id = u.ID
            LEFT JOIN {$wpdb->prefix}pmpro_membership_levels ml ON mu.membership_id = ml.id
            WHERE mu.user_id IN ($user_ids_string)
            AND mu.status = 'active'
            AND mu.enddate IS NOT NULL
            AND mu.enddate != '0000-00-00 00:00:00'
            AND mu.enddate BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
            ORDER BY mu.enddate ASC
            LIMIT 10
        ");

        // Get expiring in 30 days
        $expiring_30_count = $wpdb->get_var("
            SELECT COUNT(DISTINCT user_id)
            FROM $pmpro_table
            WHERE user_id IN ($user_ids_string)
            AND status = 'active'
            AND enddate IS NOT NULL
            AND enddate != '0000-00-00 00:00:00'
            AND enddate BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)
        ");

        $urgent_renewals = array();
        foreach ($expiring_7 as $member) {
            $urgent_renewals[] = array(
                'user_id' => (int) $member->user_id,
                'name' => $member->display_name,
                'email' => $member->user_email,
                'level_name' => $member->level_name,
                'end_date' => $member->enddate,
                'days_remaining' => floor((strtotime($member->enddate) - time()) / 86400)
            );
        }

        return array(
            'gym_identifier' => $gym_identifier,
            'expiring_in_7_days' => count($expiring_7),
            'expiring_in_30_days' => (int) $expiring_30_count,
            'urgent_renewals' => $urgent_renewals,
            'needs_attention' => count($expiring_7) > 0
        );
    }

    /**
     * Get growth trends (last 6 months) - FILTERED BY GYM
     */
    public function get_growth_trends($gym_identifier = null)
    {
        if (!$gym_identifier) {
            return array();
        }

        $trends = array();

        for ($i = 5; $i >= 0; $i--) {
            $month = date('Y-m', strtotime("-{$i} months"));
            $monthly_stats = $this->get_monthly_stats($month, $gym_identifier);

            $trends[] = array(
                'month' => $month,
                'month_name' => date('M Y', strtotime($month . '-01')),
                'new_users' => $monthly_stats['new_users'],
                'new_memberships' => $monthly_stats['membership_activities']['new_assignments'],
                'total_activities' => $monthly_stats['membership_activities']['total']
            );
        }

        return $trends;
    }

    /**
     * Get pause statistics - FILTERED BY GYM
     */
    public function get_pause_statistics($gym_identifier = null)
    {
        global $wpdb;

        if (!$gym_identifier) {
            return array(
                'gym_identifier' => null,
                'currently_paused' => 0,
                'pauses_this_month' => 0,
                'unpauses_this_month' => 0
            );
        }

        // Get gym user IDs
        $gym_user_ids = $this->get_gym_user_ids($gym_identifier);

        if (empty($gym_user_ids) || $gym_user_ids[0] === 0) {
            return array(
                'gym_identifier' => $gym_identifier,
                'currently_paused' => 0,
                'pauses_this_month' => 0,
                'unpauses_this_month' => 0,
                'net_pauses_this_month' => 0
            );
        }

        $user_ids_string = implode(',', $gym_user_ids);

        // Currently paused members for this gym's users
        $currently_paused = $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->usermeta} 
            WHERE meta_key = 'membership_is_paused' 
            AND meta_value = '1'
            AND user_id IN ($user_ids_string)
        ");

        // Total pauses this month by this gym
        $user_notes_table = $wpdb->prefix . 'gym_user_notes';
        $start_of_month = current_time('Y-m-01 00:00:00');

        $pauses_this_month = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$user_notes_table} 
             WHERE gym_identifier = %s
             AND note LIKE '%paused%' 
             AND note NOT LIKE '%unpaused%'
             AND created_at >= %s",
            $gym_identifier,
            $start_of_month
        ));

        $unpauses_this_month = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$user_notes_table} 
             WHERE gym_identifier = %s
             AND note LIKE '%unpaused%' 
             AND created_at >= %s",
            $gym_identifier,
            $start_of_month
        ));

        return array(
            'gym_identifier' => $gym_identifier,
            'currently_paused' => (int) $currently_paused,
            'pauses_this_month' => (int) $pauses_this_month,
            'unpauses_this_month' => (int) $unpauses_this_month,
            'net_pauses_this_month' => (int) ($pauses_this_month - $unpauses_this_month)
        );
    }

    /**
     * Get admin activity statistics - FILTERED BY GYM
     */
    public function get_admin_activity_stats($gym_identifier = null)
    {
        global $wpdb;

        if (!$gym_identifier) {
            return array(
                'gym_identifier' => null,
                'total_active_admins' => 0,
                'current_active_sessions' => 0
            );
        }

        // Determine which admin table to query
        $gym_one_table = $wpdb->prefix . 'gym_admins';
        $gym_two_table = $wpdb->prefix . 'gym_admins_two';

        if ($gym_identifier === 'afrgym_two') {
            $gym_admins_table = $gym_two_table;
            $gym_type = 'gym_two';
        } else {
            $gym_admins_table = $gym_one_table;
            $gym_type = 'gym_one';
        }

        $sessions_table = $wpdb->prefix . 'gym_admin_sessions';
        $user_notes_table = $wpdb->prefix . 'gym_user_notes';

        // Active admins for this gym (logged in within 7 days)
        $active_admins = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$gym_admins_table} 
             WHERE last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY) 
             AND status = 'active'"
        );

        // Current active sessions for this gym
        $active_sessions = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$sessions_table} 
             WHERE gym_type = %s 
             AND is_active = 1 
             AND expires_at > NOW()",
            $gym_type
        ));

        // Most active admin this month for this gym
        $start_of_month = current_time('Y-m-01 00:00:00');

        $most_active_admin = $wpdb->get_row($wpdb->prepare(
            "SELECT n.admin_name, COUNT(n.id) as activity_count
             FROM {$user_notes_table} n
             WHERE n.gym_identifier = %s 
             AND n.created_at >= %s
             GROUP BY n.admin_name
             ORDER BY activity_count DESC
             LIMIT 1",
            $gym_identifier,
            $start_of_month
        ));

        return array(
            'gym_identifier' => $gym_identifier,
            'total_active_admins' => (int) $active_admins,
            'current_active_sessions' => (int) $active_sessions,
            'most_active_this_month' => $most_active_admin ? array(
                'name' => $most_active_admin->admin_name ?: 'Unknown',
                'activity_count' => (int) $most_active_admin->activity_count
            ) : null
        );
    }

    /**
     * Get specific date range statistics - FILTERED BY GYM
     */
    public function get_date_range_stats($start_date, $end_date, $gym_identifier = null)
    {
        global $wpdb;

        if (!$gym_identifier) {
            return array('error' => 'Gym identifier is required');
        }

        $start_datetime = $start_date . ' 00:00:00';
        $end_datetime = $end_date . ' 23:59:59';
        $user_notes_table = $wpdb->prefix . 'gym_user_notes';

        // New users in range by this gym
        $new_users = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM {$user_notes_table} 
             WHERE gym_identifier = %s
             AND note LIKE '%created via API%'
             AND created_at >= %s AND created_at <= %s",
            $gym_identifier,
            $start_datetime,
            $end_datetime
        ));

        // Activities in range
        $activities = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                SUM(CASE WHEN note LIKE '%Membership assigned%' THEN 1 ELSE 0 END) as new_memberships,
                SUM(CASE WHEN note LIKE '%Membership updated%' THEN 1 ELSE 0 END) as membership_updates,
                SUM(CASE WHEN note LIKE '%paused%' THEN 1 ELSE 0 END) as pauses,
                SUM(CASE WHEN note LIKE '%QR code%generated%' THEN 1 ELSE 0 END) as qr_generated,
                COUNT(*) as total_activities
             FROM {$user_notes_table}
             WHERE gym_identifier = %s
             AND created_at >= %s AND created_at <= %s",
            $gym_identifier,
            $start_datetime,
            $end_datetime
        ));

        $stats = $activities[0];

        return array(
            'period' => array(
                'start_date' => $start_date,
                'end_date' => $end_date,
                'days' => (strtotime($end_date) - strtotime($start_date)) / 86400 + 1
            ),
            'gym_identifier' => $gym_identifier,
            'new_users' => (int) $new_users,
            'new_memberships' => (int) $stats->new_memberships,
            'membership_updates' => (int) $stats->membership_updates,
            'membership_pauses' => (int) $stats->pauses,
            'qr_codes_generated' => (int) $stats->qr_generated,
            'total_activities' => (int) $stats->total_activities
        );
    }

    /**
     * Helper: Get monthly membership breakdown - FILTERED BY GYM
     */
    private function get_monthly_membership_breakdown($month, $gym_identifier = null)
    {
        global $wpdb;

        if (!$gym_identifier) {
            return array();
        }

        $start_date = $month . '-01 00:00:00';
        $end_date = date('Y-m-t 23:59:59', strtotime($start_date));
        $user_notes_table = $wpdb->prefix . 'gym_user_notes';

        // Get membership assignments by level for this month
        $level_activities = $wpdb->get_results($wpdb->prepare(
            "SELECT note, COUNT(*) as count
             FROM {$user_notes_table}
             WHERE gym_identifier = %s
             AND (note LIKE '%Membership assigned%' OR note LIKE '%Membership updated%')
             AND created_at >= %s AND created_at <= %s
             GROUP BY note",
            $gym_identifier,
            $start_date,
            $end_date
        ));

        // Parse the notes to extract membership levels
        $levels = array();
        foreach ($level_activities as $activity) {
            // Extract level name from notes
            if (preg_match('/Membership (?:assigned|updated).*?:\s*([^(]+)(?:\s*\(ID:\s*\d+\))?/', $activity->note, $matches)) {
                $level_name = trim($matches[1]);
                if (!isset($levels[$level_name])) {
                    $levels[$level_name] = 0;
                }
                $levels[$level_name] += $activity->count;
            }
        }

        return $levels;
    }

    /**
     * Helper: Calculate overall health score
     */
    private function calculate_health_score($membership_stats, $total_users)
    {
        if ($total_users == 0) {
            return 0;
        }

        $active_ratio = $membership_stats['active_members'] / $total_users;
        $paused_ratio = $membership_stats['paused_members'] / $total_users;

        // Health score based on active members (good) minus paused members (concerning)
        $score = ($active_ratio * 100) - ($paused_ratio * 20);

        return max(0, min(100, round($score)));
    }

    /**
     * Helper: Classify activity type
     */
    private function classify_activity($note)
    {
        if (strpos($note, 'created') !== false) {
            return 'user_creation';
        }
        if (strpos($note, 'Membership assigned') !== false) {
            return 'membership_assignment';
        }
        if (strpos($note, 'Membership updated') !== false) {
            return 'membership_update';
        }
        if (strpos($note, 'paused') !== false) {
            return 'membership_pause';
        }
        if (strpos($note, 'QR code') !== false) {
            return 'qr_generation';
        }
        if (strpos($note, 'Profile picture') !== false) {
            return 'profile_update';
        }

        return 'other';
    }

    /**
     * Get combined statistics for both gyms (Super Admin only)
     */
    public function get_combined_stats()
    {
        $gym_one_stats = $this->get_dashboard_stats('afrgym_one');
        $gym_two_stats = $this->get_dashboard_stats('afrgym_two');

        return array(
            'gym_one' => array(
                'gym_identifier' => 'afrgym_one',
                'gym_name' => 'Afrgym One',
                'stats' => $gym_one_stats
            ),
            'gym_two' => array(
                'gym_identifier' => 'afrgym_two',
                'gym_name' => 'Afrgym Two',
                'stats' => $gym_two_stats
            ),
            'combined_summary' => array(
                'total_users_both_gyms' => $gym_one_stats['summary']['total_users'] + $gym_two_stats['summary']['total_users'],
                'total_active_members' => $gym_one_stats['summary']['total_active_members'] + $gym_two_stats['summary']['total_active_members'],
                'total_emails_sent' => $gym_one_stats['summary']['emails_sent_last_30_days'] + $gym_two_stats['summary']['emails_sent_last_30_days'],
                'comparison' => array(
                    'gym_one_users' => $gym_one_stats['summary']['total_users'],
                    'gym_two_users' => $gym_two_stats['summary']['total_users'],
                    'larger_gym' => $gym_one_stats['summary']['total_users'] > $gym_two_stats['summary']['total_users'] ? 'Afrgym One' : 'Afrgym Two'
                )
            ),
            'timestamp' => current_time('mysql')
        );
    }
}