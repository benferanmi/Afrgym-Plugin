<?php
/**
 * Membership service for integrating with Paid Memberships Pro
 * UPDATED VERSION - Added price update functionality
 * BUG FIXES APPLIED: Fixes 1, 2, 3, 5, 6 per bug handover document
 * 
 * 🔥 CRITICAL FIX: Pause/Expiry Bug
 * - Check pause status FIRST before checking enddate
 * - Paused memberships ignore calendar date expiry
 * - Use >= instead of > for enddate to include full day
 */
class Gym_Membership_Service
{

    private $visit_based_plans = array(12, 13);
    private $default_monthly_visits = 12;

    /**
     * 🔥 FIXED: Check pause status BEFORE date validation
     * This prevents memberships from expiring on calendar date if paused
     */
    public function get_user_membership($user_id)
    {
        // Check if PMPro is active
        if (!function_exists('pmpro_getMembershipLevelForUser')) {
            return array(
                'status' => 'no_membership',
                'level_name' => 'No Membership',
                'expiry_date' => null,
                'start_date' => null,
                'is_active' => false,
                'is_paused' => false
            );
        }

        $membership_level = pmpro_getMembershipLevelForUser($user_id);

        if (!$membership_level) {
            return array(
                'status' => 'no_membership',
                'level_name' => 'No Membership',
                'expiry_date' => null,
                'start_date' => null,
                'is_active' => false,
                'is_paused' => false
            );
        }

        // 🔥 FIX: Check pause status FIRST
        $pause_status = $this->get_membership_pause_status($user_id);
        $is_paused = $pause_status['is_paused'];

        global $wpdb;

        // 🔥 FIX: If paused, retrieve membership WITHOUT strict enddate check
        // Paused memberships stay active regardless of calendar date
        if ($is_paused) {
            // For paused members, get the membership record without date restrictions
            $membership_record = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}pmpro_memberships_users 
                 WHERE user_id = %d AND membership_id = %d AND status = 'active'
                 ORDER BY id DESC LIMIT 1",
                $user_id,
                $membership_level->id
            ));

            error_log("DEBUG: User {$user_id} has PAUSED membership - fetched without date check");
        } else {
            // For non-paused members, use strict enddate check with >= (not >)
            // This includes the entire day of expiry
            $membership_record = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}pmpro_memberships_users 
                 WHERE user_id = %d AND membership_id = %d AND status = 'active' 
                 AND (enddate IS NULL OR enddate = '0000-00-00 00:00:00' OR enddate >= CURDATE())
                 ORDER BY id DESC LIMIT 1",
                $user_id,
                $membership_level->id
            ));

            error_log("DEBUG: User {$user_id} has NON-PAUSED membership - using strict date check");
        }

        $expiry_date = null;
        $start_date = null;
        $is_active = true;

        if ($membership_record) {
            // Format start date properly - check if it's a valid date
            if (
                !empty($membership_record->startdate) &&
                $membership_record->startdate !== '0000-00-00 00:00:00' &&
                $membership_record->startdate !== null
            ) {
                $start_date = date('Y-m-d H:i:s', strtotime($membership_record->startdate));
            } else {
                // If no start date, use current date
                $start_date = current_time('mysql');
            }

            // Format expiry date properly - check if it's a valid date
            if (
                !empty($membership_record->enddate) &&
                $membership_record->enddate !== '0000-00-00 00:00:00' &&
                $membership_record->enddate !== null
            ) {
                $expiry_date = date('Y-m-d H:i:s', strtotime($membership_record->enddate));

                // 🔥 FIX: If paused, don't use enddate to determine is_active
                if (!$is_paused) {
                    $is_active = strtotime($expiry_date) > time();
                } else {
                    // Paused members are always active until unpaused
                    $is_active = true;
                }
            } else {
                // FIX 2: Null/zero enddate must check level expiration settings
                // before treating as a lifetime membership.
                if ($this->is_lifetime_membership($membership_record->enddate, $membership_level->id)) {
                    $expiry_date = null;
                    $is_active = true;
                } else {
                    // Level is configured to expire but enddate is missing — fail safe: treat as expired.
                    // UNLESS paused, then it's still active
                    $expiry_date = null;
                    $is_active = $is_paused ? true : false;
                }
            }
        } else {
            // No record found in database
            $is_active = false;
        }

        // Get visit information for visit-based plans
        $visit_info = null;
        $is_visit_based = in_array($membership_level->id, $this->visit_based_plans);

        if ($is_visit_based) {
            // FIX 3: Pass expiry_date so get_user_visit_info() can hard-stop on expired memberships.
            // But if paused, visits don't matter either
            $visit_info = $this->get_user_visit_info($user_id, $start_date, $expiry_date);

            // For visit-based plans, only check if visits are exhausted if NOT paused
            if (!$is_paused && $visit_info['remaining_visits'] <= 0 && $visit_info['is_current_cycle']) {
                $is_active = false;
            }
        }

        $membership_data = array(
            'level_id' => $membership_level->id,
            'level_name' => $membership_level->name,
            'description' => $membership_level->description ?? '',
            'expiry_date' => $expiry_date,
            'start_date' => $start_date,
            'is_active' => $is_active,
            'status' => $is_active ? 'active' : 'expired',
            'is_paused' => $is_paused,
            'pause_info' => $is_paused ? $pause_status : null,
            'is_visit_based' => $is_visit_based
        );

        // Add visit information for visit-based plans
        if ($is_visit_based && $visit_info) {
            $membership_data['visit_info'] = $visit_info;
        }

        return $membership_data;
    }

    /**
     * FIX 2: Helper — determine if a null/zero enddate means a genuine lifetime membership.
     * If the PMPro level has expiration_number > 0 the plan is configured to expire,
     * so a missing enddate is treated as bad data and we fail safe (return false = not lifetime).
     * Only returns true (lifetime) when the level has NO expiration settings at all.
     *
     * @param string|null $enddate      The raw enddate value from the DB row.
     * @param int         $level_id     The PMPro level ID.
     * @return bool
     */
    private function is_lifetime_membership($enddate, $level_id)
    {
        // If enddate is actually set and non-zero, it is not a lifetime membership.
        if (!empty($enddate) && $enddate !== '0000-00-00 00:00:00') {
            return false;
        }

        // enddate is null/zero — check whether the level is configured to expire.
        if (function_exists('pmpro_getLevel') && $level_id) {
            $level = pmpro_getLevel($level_id);
            if ($level && !empty($level->expiration_number) && (int) $level->expiration_number > 0) {
                // Level IS configured to expire — missing enddate is bad data, fail safe.
                return false;
            }
        }

        // Level has no expiration settings — genuine lifetime membership.
        return true;
    }


    /**
     * ENHANCED: Assign membership with visit tracking setup
     */
    public function assign_membership($user_id, $level_id, $start_date = null, $end_date = null)
    {
        if (!function_exists('pmpro_changeMembershipLevel')) {
            return new WP_Error('pmpro_not_active', 'Paid Memberships Pro is not active.');
        }

        $level = pmpro_getLevel($level_id);
        if (!$level) {
            return new WP_Error('invalid_level', 'Invalid membership level.');
        }

        // Prepare the dates
        $formatted_start_date = null;
        $formatted_end_date = null;

        if ($start_date) {
            $formatted_start_date = date('Y-m-d H:i:s', strtotime($start_date));
        } else {
            // Default to current date/time
            $formatted_start_date = current_time('mysql');
        }

        if ($end_date) {
            $formatted_end_date = date('Y-m-d H:i:s', strtotime($end_date));
        } else {
            // Calculate expiry based on level settings if no end_date provided
            if (!empty($level->expiration_number) && !empty($level->expiration_period)) {
                $expiry_timestamp = strtotime("+{$level->expiration_number} {$level->expiration_period}", strtotime($formatted_start_date));
                $formatted_end_date = date('Y-m-d H:i:s', $expiry_timestamp);
            }
            // If no expiration settings, leave as null for lifetime membership
        }

        // Use PMPro's function to assign membership
        $result = pmpro_changeMembershipLevel($level_id, $user_id, 'changed');

        if ($result) {
            // Now update the dates in the database directly
            global $wpdb;
            $table = $wpdb->prefix . 'pmpro_memberships_users';

            $update_data = array();
            $update_format = array();

            // Always set start date
            $update_data['startdate'] = $formatted_start_date;
            $update_format[] = '%s';

            // Set end date if provided or calculated
            if ($formatted_end_date) {
                $update_data['enddate'] = $formatted_end_date;
                $update_format[] = '%s';
            }

            // Update the most recent membership record for this user/level
            $updated = $wpdb->update(
                $table,
                $update_data,
                array(
                    'user_id' => $user_id,
                    'membership_id' => $level_id,
                    'status' => 'active'
                ),
                $update_format,
                array('%d', '%d', '%s')
            );

            if ($updated === false) {
                error_log('Failed to update membership dates for user ' . $user_id);
            }

            // Setup visit tracking for visit-based plans
            $is_visit_based = in_array($level_id, $this->visit_based_plans);
            if ($is_visit_based) {
                $this->setup_visit_tracking($user_id, $this->default_monthly_visits);
            } else {
                // Clear visit tracking for non-visit-based plans
                $this->clear_visit_tracking($user_id);
            }

            // Log the membership change with gym tracking
            $gym_admin = Gym_Admin::get_current_gym_admin_full();
            $gym_suffix = '';
            if ($gym_admin) {
                $gym_suffix = " [Assigned by: {$gym_admin['gym_name']}]";
            }
            $log_message = "Membership changed to: {$level->name}{$gym_suffix}";
            if ($formatted_end_date) {
                $log_message .= " (expires: " . date('Y-m-d', strtotime($formatted_end_date)) . ")";
            }
            if ($is_visit_based) {
                $log_message .= " - Visit-based plan with {$this->default_monthly_visits} monthly visits";
            }
            Gym_Admin::add_user_note($user_id, $log_message);

            $this->send_membership_notification($user_id, 'assigned', $formatted_start_date, $formatted_end_date, $level_id);


            return array(
                'success' => true,
                'message' => 'Membership assigned successfully',
                'membership' => $this->get_user_membership($user_id)
            );
        }

        return new WP_Error('assignment_failed', 'Failed to assign membership.');
    }

    /**
     * ENHANCED: Update membership with visit tracking setup
     */
    public function update_membership($user_id, $level_id, $start_date = null, $end_date = null)
    {
        if (!function_exists('pmpro_changeMembershipLevel')) {
            return new WP_Error('pmpro_not_active', 'Paid Memberships Pro is not active.');
        }

        // Get current membership first
        $current_membership = $this->get_user_membership($user_id);

        // Validate the new level
        $level = pmpro_getLevel($level_id);
        if (!$level) {
            return new WP_Error('invalid_level', 'Invalid membership level.');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pmpro_memberships_users';

        // Start transaction for atomic operation
        $wpdb->query('START TRANSACTION');

        try {
            // Prepare the dates for new membership
            $formatted_start_date = null;
            $formatted_end_date = null;

            if ($start_date) {
                $formatted_start_date = date('Y-m-d H:i:s', strtotime($start_date));
            } else {
                $formatted_start_date = current_time('mysql');
            }

            if ($end_date) {
                $formatted_end_date = date('Y-m-d H:i:s', strtotime($end_date));
            } else {
                // Calculate expiry based on level settings if no end_date provided
                if (!empty($level->expiration_number) && !empty($level->expiration_period)) {
                    $expiry_timestamp = strtotime("+{$level->expiration_number} {$level->expiration_period}", strtotime($formatted_start_date));
                    $formatted_end_date = date('Y-m-d H:i:s', $expiry_timestamp);
                }
            }

            // Determine update strategy based on current membership status
            $has_existing_membership = ($current_membership['status'] !== 'no_membership');
            $is_same_level = $has_existing_membership && ($current_membership['level_id'] == $level_id);

            if ($is_same_level && $current_membership['is_active']) {
                // SAME LEVEL UPDATE for ACTIVE membership: Just update the dates
                $update_data = array(
                    'startdate' => $formatted_start_date,
                    'enddate' => $formatted_end_date,
                    'status' => 'active',
                    'modified' => current_time('mysql')
                );

                $update_result = $wpdb->update(
                    $table,
                    $update_data,
                    array(
                        'user_id' => $user_id,
                        'membership_id' => $level_id,
                        'status' => 'active'
                    ),
                    array('%s', '%s', '%s', '%s'),
                    array('%d', '%d', '%s')
                );

                if ($update_result === false) {
                    throw new Exception("Failed to update same-level membership dates: " . $wpdb->last_error);
                }

            } else {
                // DIFFERENT LEVEL UPDATE or EXPIRED/NO MEMBERSHIP: Cancel current and create new
                // Step 1: Cancel/expire ALL current active memberships for this user
                if ($has_existing_membership) {
                    $cancel_result = $wpdb->update(
                        $table,
                        array(
                            'status' => 'cancelled',
                            'enddate' => current_time('mysql')
                        ),
                        array(
                            'user_id' => $user_id,
                            'status' => 'active'
                        ),
                        array('%s', '%s'),
                        array('%d', '%s')
                    );
                }

                // Step 2: Create new membership record
                $insert_data = array(
                    'user_id' => $user_id,
                    'membership_id' => $level_id,
                    'code_id' => 0,
                    'initial_payment' => $level->initial_payment ?? 0,
                    'billing_amount' => $level->billing_amount ?? 0,
                    'cycle_number' => $level->cycle_number ?? 0,
                    'cycle_period' => $level->cycle_period ?? '',
                    'billing_limit' => $level->billing_limit ?? 0,
                    'trial_amount' => $level->trial_amount ?? 0,
                    'trial_limit' => $level->trial_limit ?? 0,
                    'startdate' => $formatted_start_date,
                    'enddate' => $formatted_end_date,
                    'status' => 'active',
                    'modified' => current_time('mysql')
                );

                $insert_result = $wpdb->insert($table, $insert_data);

                if ($insert_result === false) {
                    throw new Exception("Failed to insert new membership for user {$user_id}: " . $wpdb->last_error);
                }
            }

            // Step 3: Update WordPress user meta (PMPro uses this for caching)
            update_user_meta($user_id, 'pmpro_membership_level_ID', $level_id);

            // Clear any existing pause data when updating membership
            $this->clear_pause_data($user_id);

            // Setup/clear visit tracking based on new membership
            $is_visit_based = in_array($level_id, $this->visit_based_plans);
            if ($is_visit_based) {
                $this->setup_visit_tracking($user_id, $this->default_monthly_visits);
            } else {
                $this->clear_visit_tracking($user_id);
            }

            // Commit transaction
            $wpdb->query('COMMIT');

        } catch (Exception $e) {
            // Rollback on any error
            $wpdb->query('ROLLBACK');
            error_log("Transaction failed: " . $e->getMessage());
            return new WP_Error('update_failed', $e->getMessage());
        }

        // Clear any PMPro caches
        if (function_exists('pmpro_delete_user_membership_level_cache')) {
            pmpro_delete_user_membership_level_cache($user_id);
        }

        // Clear WordPress object cache
        wp_cache_delete($user_id, 'users');
        wp_cache_delete($user_id, 'user_meta');
        wp_cache_delete("pmpro_membership_level_for_user_" . $user_id, 'pmpro');

        // Determine update type for logging
        $update_type = 'new_membership';
        if ($has_existing_membership) {
            if ($is_same_level) {
                $update_type = $current_membership['is_active'] ? 'same_level_date_update' : 'same_level_reactivation';
            } else {
                $update_type = 'level_change';
            }
        }

        // Log the membership change with gym tracking
        $gym_admin = Gym_Admin::get_current_gym_admin_full();
        $gym_suffix = '';
        if ($gym_admin) {
            $gym_suffix = " [Updated by: {$gym_admin['gym_name']}]";
        }

        $log_message = '';
        if ($update_type === 'same_level_date_update') {
            $log_message = "Membership dates updated for {$level->name}{$gym_suffix}";
        } elseif ($update_type === 'same_level_reactivation') {
            $log_message = "Membership reactivated for {$level->name}{$gym_suffix}";
        } elseif ($update_type === 'level_change') {
            $log_message = "Membership updated from {$current_membership['level_name']} to {$level->name}{$gym_suffix}";
        } else {
            $log_message = "New membership assigned: {$level->name}{$gym_suffix}";
        }

        if ($formatted_end_date) {
            $log_message .= " (expires: " . date('Y-m-d', strtotime($formatted_end_date)) . ")";
        }

        $is_visit_based = in_array($level_id, $this->visit_based_plans);
        if ($is_visit_based) {
            $log_message .= " - Visit-based plan with {$this->default_monthly_visits} monthly visits";
        }

        Gym_Admin::add_user_note($user_id, $log_message);

        // Small delay to ensure database consistency
        usleep(100000); // 0.1 second delay

        // Get fresh membership data directly from database
        $fresh_membership = $this->get_user_membership_fresh($user_id);

        // Verify the update actually worked
        if ($fresh_membership['level_id'] != $level_id) {
            error_log("Verification failed: Expected level {$level_id}, got {$fresh_membership['level_id']}");
            return new WP_Error('update_verification_failed', 'Membership update could not be verified.');
        }

        $this->send_membership_notification($user_id, 'updated', $formatted_start_date, $formatted_end_date, $level_id);

        // Return the updated membership
        return array(
            'success' => true,
            'message' => 'Membership updated successfully',
            'membership' => $fresh_membership,
            'update_type' => $update_type
        );
    }

    /**
     * Setup visit tracking for user
     */
    private function setup_visit_tracking($user_id, $monthly_visits)
    {
        update_user_meta($user_id, 'membership_visit_allowance', (int) $monthly_visits);

        // Only reset visit log if user doesn't already have one
        $existing_log = get_user_meta($user_id, 'membership_visit_log', true);
        if (!is_array($existing_log)) {
            update_user_meta($user_id, 'membership_visit_log', array());
        }
    }

    /**
     * Clear visit tracking for user
     */
    private function clear_visit_tracking($user_id)
    {
        delete_user_meta($user_id, 'membership_visit_allowance');
        delete_user_meta($user_id, 'membership_visit_log');
    }

    /**
     * 🔥 FIXED: Use >= instead of > for enddate check
     */
    public function get_user_membership_fresh($user_id)
    {
        global $wpdb;

        // 🔥 FIX: Check pause status first
        $pause_status = $this->get_membership_pause_status($user_id);
        $is_paused = $pause_status['is_paused'];

        // Get the most recent active membership directly from database
        // FIX 1: Added enddate gate — same reason as in get_user_membership().
        if ($is_paused) {
            // Paused members: fetch without date restrictions
            $membership_record = $wpdb->get_row($wpdb->prepare(
                "SELECT mu.*, ml.name, ml.description 
                 FROM {$wpdb->prefix}pmpro_memberships_users mu
                 JOIN {$wpdb->prefix}pmpro_membership_levels ml ON mu.membership_id = ml.id
                 WHERE mu.user_id = %d AND mu.status = 'active' 
                 ORDER BY mu.id DESC LIMIT 1",
                $user_id
            ));
        } else {
            // Non-paused members: use strict date check with >=
            $membership_record = $wpdb->get_row($wpdb->prepare(
                "SELECT mu.*, ml.name, ml.description 
                 FROM {$wpdb->prefix}pmpro_memberships_users mu
                 JOIN {$wpdb->prefix}pmpro_membership_levels ml ON mu.membership_id = ml.id
                 WHERE mu.user_id = %d AND mu.status = 'active' 
                 AND (mu.enddate IS NULL OR mu.enddate = '0000-00-00 00:00:00' OR mu.enddate >= CURDATE())
                 ORDER BY mu.id DESC LIMIT 1",
                $user_id
            ));
        }

        if (!$membership_record) {
            return array(
                'status' => 'no_membership',
                'level_name' => 'No Membership',
                'expiry_date' => null,
                'start_date' => null,
                'is_active' => false,
                'is_paused' => false
            );
        }

        $expiry_date = null;
        $start_date = null;
        $is_active = true;

        // Format start date properly
        if (
            !empty($membership_record->startdate) &&
            $membership_record->startdate !== '0000-00-00 00:00:00' &&
            $membership_record->startdate !== null
        ) {
            $start_date = date('Y-m-d H:i:s', strtotime($membership_record->startdate));
        } else {
            $start_date = current_time('mysql');
        }

        // Format expiry date properly
        if (
            !empty($membership_record->enddate) &&
            $membership_record->enddate !== '0000-00-00 00:00:00' &&
            $membership_record->enddate !== null
        ) {
            $expiry_date = date('Y-m-d H:i:s', strtotime($membership_record->enddate));
            // If paused, don't use enddate to determine is_active
            if (!$is_paused) {
                $is_active = strtotime($expiry_date) > time();
            } else {
                $is_active = true;
            }
        } else {
            // FIX 2: Null/zero enddate — check level expiration settings before treating as lifetime.
            if ($this->is_lifetime_membership($membership_record->enddate, $membership_record->membership_id)) {
                $expiry_date = null;
                $is_active = true;
            } else {
                // Level is configured to expire but enddate is missing — fail safe: treat as expired.
                $expiry_date = null;
                $is_active = $is_paused ? true : false;
            }
        }

        // Check visit status for visit-based plans
        $is_visit_based = in_array($membership_record->membership_id, $this->visit_based_plans);
        $visit_info = null;

        if ($is_visit_based) {
            // FIX 3: Pass expiry_date so get_user_visit_info() can hard-stop on expired memberships.
            $visit_info = $this->get_user_visit_info($user_id, $start_date, $expiry_date);

            // For visit-based plans, only check if visits are exhausted if NOT paused
            if (!$is_paused && $visit_info['remaining_visits'] <= 0 && $visit_info['is_current_cycle']) {
                $is_active = false;
            }
        }

        $membership_data = array(
            'level_id' => (int) $membership_record->membership_id,
            'level_name' => $membership_record->name,
            'description' => $membership_record->description ?? '',
            'expiry_date' => $expiry_date,
            'start_date' => $start_date,
            'is_active' => $is_active,
            'status' => $is_active ? 'active' : 'expired',
            'is_paused' => $is_paused,
            'pause_info' => $is_paused ? $pause_status : null,
            'is_visit_based' => $is_visit_based
        );

        if ($is_visit_based && $visit_info) {
            $membership_data['visit_info'] = $visit_info;
        }

        return $membership_data;
    }


    public function get_all_membership_levels()
    {
        if (!function_exists('pmpro_getAllLevels')) {
            return array();
        }

        $levels = pmpro_getAllLevels();
        $formatted_levels = array();

        foreach ($levels as $level) {
            $formatted_levels[] = array(
                'id' => $level->id,
                'name' => $level->name,
                'description' => $level->description,
                'initial_payment' => $level->initial_payment ?? 0,
                'billing_amount' => $level->billing_amount ?? 0,
                'cycle_number' => $level->cycle_number ?? 0,
                'cycle_period' => $level->cycle_period ?? '',
                'billing_limit' => $level->billing_limit ?? 0,
                'expiration_number' => $level->expiration_number ?? 0,
                'expiration_period' => $level->expiration_period ?? ''
            );
        }

        return $formatted_levels;
    }

    /**
     * NEW METHOD: Update membership level pricing
     */
    public function update_membership_price($level_id, $pricing_data)
    {
        global $wpdb;

        // Check if PMPro is active
        if (!function_exists('pmpro_getLevel')) {
            return new WP_Error('pmpro_not_active', 'Paid Memberships Pro is not active.');
        }

        // Validate level exists
        $level = pmpro_getLevel($level_id);
        if (!$level) {
            return new WP_Error('invalid_level', 'Invalid membership level ID.');
        }

        // Prepare update data with validation
        $update_data = array();
        $update_format = array();

        // Validate and set initial payment
        if (isset($pricing_data['initial_payment'])) {
            $initial_payment = floatval($pricing_data['initial_payment']);
            if ($initial_payment < 0) {
                return new WP_Error('invalid_initial_payment', 'Initial payment must be 0 or greater.');
            }
            $update_data['initial_payment'] = $initial_payment;
            $update_format[] = '%f';
        }

        // Validate and set billing amount
        if (isset($pricing_data['billing_amount'])) {
            $billing_amount = floatval($pricing_data['billing_amount']);
            if ($billing_amount < 0) {
                return new WP_Error('invalid_billing_amount', 'Billing amount must be 0 or greater.');
            }
            $update_data['billing_amount'] = $billing_amount;
            $update_format[] = '%f';
        }

        // Validate and set trial amount
        if (isset($pricing_data['trial_amount'])) {
            $trial_amount = floatval($pricing_data['trial_amount']);
            if ($trial_amount < 0) {
                return new WP_Error('invalid_trial_amount', 'Trial amount must be 0 or greater.');
            }
            $update_data['trial_amount'] = $trial_amount;
            $update_format[] = '%f';
        }

        // Validate and set cycle number
        if (isset($pricing_data['cycle_number'])) {
            $cycle_number = intval($pricing_data['cycle_number']);
            if ($cycle_number < 0) {
                return new WP_Error('invalid_cycle_number', 'Cycle number must be 0 or greater.');
            }
            $update_data['cycle_number'] = $cycle_number;
            $update_format[] = '%d';
        }

        // Validate and set cycle period
        if (isset($pricing_data['cycle_period'])) {
            $valid_periods = array('', 'Day', 'Week', 'Month', 'Year');
            if (!in_array($pricing_data['cycle_period'], $valid_periods)) {
                return new WP_Error('invalid_cycle_period', 'Cycle period must be Day, Week, Month, or Year.');
            }
            $update_data['cycle_period'] = sanitize_text_field($pricing_data['cycle_period']);
            $update_format[] = '%s';
        }

        // Validate and set billing limit
        if (isset($pricing_data['billing_limit'])) {
            $billing_limit = intval($pricing_data['billing_limit']);
            if ($billing_limit < 0) {
                return new WP_Error('invalid_billing_limit', 'Billing limit must be 0 or greater.');
            }
            $update_data['billing_limit'] = $billing_limit;
            $update_format[] = '%d';
        }

        // Validate and set trial limit
        if (isset($pricing_data['trial_limit'])) {
            $trial_limit = intval($pricing_data['trial_limit']);
            if ($trial_limit < 0) {
                return new WP_Error('invalid_trial_limit', 'Trial limit must be 0 or greater.');
            }
            $update_data['trial_limit'] = $trial_limit;
            $update_format[] = '%d';
        }

        // If no valid pricing data provided, return error
        if (empty($update_data)) {
            return new WP_Error('no_pricing_data', 'No valid pricing data provided.');
        }

        // Update the membership level in database
        $table = $wpdb->prefix . 'pmpro_membership_levels';
        $result = $wpdb->update(
            $table,
            $update_data,
            array('id' => $level_id),
            $update_format,
            array('%d')
        );

        if ($result === false) {
            return new WP_Error('update_failed', 'Failed to update membership pricing: ' . $wpdb->last_error);
        }

        // Clear PMPro level cache
        if (function_exists('pmpro_delete_level_cache')) {
            pmpro_delete_level_cache($level_id);
        }

        // Clear WordPress object cache for this level
        wp_cache_delete('pmpro_membership_level_' . $level_id, 'pmpro');

        // Log the pricing update
        $changes_made = array();
        foreach ($update_data as $field => $value) {
            $changes_made[] = "{$field}: {$value}";
        }
        $log_message = "Membership level '{$level->name}' pricing updated: " . implode(', ', $changes_made);
        error_log($log_message);

        // Get updated level data
        $updated_level = pmpro_getLevel($level_id);

        return array(
            'success' => true,
            'message' => 'Membership pricing updated successfully',
            'level_id' => $level_id,
            'updated_fields' => array_keys($update_data),
            'level' => array(
                'id' => $updated_level->id,
                'name' => $updated_level->name,
                'description' => $updated_level->description,
                'initial_payment' => $updated_level->initial_payment ?? 0,
                'billing_amount' => $updated_level->billing_amount ?? 0,
                'cycle_number' => $updated_level->cycle_number ?? 0,
                'cycle_period' => $updated_level->cycle_period ?? '',
                'billing_limit' => $updated_level->billing_limit ?? 0,
                'trial_amount' => $updated_level->trial_amount ?? 0,
                'trial_limit' => $updated_level->trial_limit ?? 0,
                'expiration_number' => $updated_level->expiration_number ?? 0,
                'expiration_period' => $updated_level->expiration_period ?? ''
            )
        );
    }

    public function get_expiring_memberships($days = 7)
    {
        global $wpdb;

        if (!function_exists('pmpro_getMembershipLevelForUser')) {
            return array();
        }

        $table = $wpdb->prefix . 'pmpro_memberships_users';
        $expiry_date = date('Y-m-d H:i:s', strtotime("+{$days} days"));

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT mu.user_id, mu.membership_id, mu.enddate, u.user_email, u.display_name, ml.name as level_name
             FROM {$table} mu
             JOIN {$wpdb->users} u ON mu.user_id = u.ID
             JOIN {$wpdb->prefix}pmpro_membership_levels ml ON mu.membership_id = ml.id
             WHERE mu.status = 'active' 
             AND mu.enddate IS NOT NULL 
             AND mu.enddate != '0000-00-00 00:00:00'
             AND mu.enddate <= %s
             AND mu.enddate > NOW()
             ORDER BY mu.enddate ASC",
            $expiry_date
        ));

        $expiring_members = array();
        foreach ($results as $member) {
            $expiring_members[] = array(
                'user_id' => $member->user_id,
                'email' => $member->user_email,
                'display_name' => $member->display_name,
                'membership_level' => $member->level_name,
                'expiry_date' => $member->enddate,
                'days_until_expiry' => ceil((strtotime($member->enddate) - time()) / 86400)
            );
        }

        return $expiring_members;
    }

    public function get_membership_statistics()
    {
        global $wpdb;

        if (!function_exists('pmpro_getMembershipLevelForUser')) {
            return array(
                'total_members' => 0,
                'active_members' => 0,
                'expired_members' => 0,
                'paused_members' => 0,
                'by_level' => array()
            );
        }

        $table = $wpdb->prefix . 'pmpro_memberships_users';

        // Get total active members
        $active_members = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} 
             WHERE status = 'active' 
             AND (enddate IS NULL OR enddate = '0000-00-00 00:00:00' OR enddate >= CURDATE())"
        );

        // Get expired members
        $expired_members = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} 
             WHERE status = 'active' 
             AND enddate IS NOT NULL 
             AND enddate != '0000-00-00 00:00:00' 
             AND enddate < CURDATE()"
        );

        // Get paused members count
        $paused_members = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} 
             WHERE meta_key = 'membership_is_paused' 
             AND meta_value = '1'"
        );

        // Get members by level
        $levels = $wpdb->get_results(
            "SELECT ml.id, ml.name, COUNT(mu.user_id) as member_count
             FROM {$wpdb->prefix}pmpro_membership_levels ml
             LEFT JOIN {$table} mu ON ml.id = mu.membership_id AND mu.status = 'active'
             GROUP BY ml.id, ml.name
             ORDER BY ml.id"
        );

        $by_level = array();
        foreach ($levels as $level) {
            $by_level[] = array(
                'level_id' => $level->id,
                'level_name' => $level->name,
                'member_count' => (int) $level->member_count
            );
        }

        return array(
            'total_members' => (int) $active_members + (int) $expired_members,
            'active_members' => (int) $active_members,
            'expired_members' => (int) $expired_members,
            'paused_members' => (int) $paused_members,
            'by_level' => $by_level
        );
    }

    public function cancel_membership($user_id)
    {
        if (!function_exists('pmpro_changeMembershipLevel')) {
            return new WP_Error('pmpro_not_active', 'Paid Memberships Pro is not active.');
        }

        $current_membership = $this->get_user_membership($user_id);

        $result = pmpro_changeMembershipLevel(0, $user_id, 'cancelled');

        if ($result) {
            // Clear pause data when cancelling membership
            $this->clear_pause_data($user_id);

            Gym_Admin::add_user_note($user_id, "Membership cancelled: {$current_membership['level_name']}");

            return array(
                'success' => true,
                'message' => 'Membership cancelled successfully'
            );
        }

        return new WP_Error('cancellation_failed', 'Failed to cancel membership.');
    }

    /**
     * Pause membership functionality
     */
    public function pause_membership($user_id, $reason = '')
    {
        global $wpdb;

        // Get current membership
        $current_membership = $this->get_user_membership($user_id);
        if (!$current_membership['is_active']) {
            return new WP_Error('no_active_membership', 'User has no active membership to pause.');
        }

        // Check if already paused
        $current_pause = $this->get_membership_pause_status($user_id);
        if ($current_pause['is_paused']) {
            return new WP_Error('already_paused', 'Membership is already paused.');
        }

        $pause_date = current_time('mysql');
        $expiry_date = $current_membership['expiry_date'];

        // Calculate remaining days
        $remaining_days = 0;
        if ($expiry_date) {
            $remaining_days = ceil((strtotime($expiry_date) - time()) / 86400);
            if ($remaining_days < 0)
                $remaining_days = 0;
        }

        // Store pause data in user meta
        $pause_data = array(
            'is_paused' => true,
            'pause_date' => $pause_date,
            'original_end_date' => $expiry_date,
            'remaining_days' => $remaining_days,
            'reason' => sanitize_textarea_field($reason),
            'total_paused_days' => (int) get_user_meta($user_id, 'membership_total_paused_days', true)
        );

        // Update user meta
        update_user_meta($user_id, 'membership_is_paused', true);
        update_user_meta($user_id, 'membership_pause_date', $pause_date);
        update_user_meta($user_id, 'membership_original_end_date', $expiry_date);
        update_user_meta($user_id, 'membership_remaining_days', $remaining_days);

        // Add to pause history
        $this->add_pause_history_entry($user_id, 'paused', $pause_date, $reason);

        // Log the pause
        $log_message = "Membership paused";
        if ($remaining_days > 0) {
            $log_message .= " ({$remaining_days} days remaining)";
        }
        if ($reason) {
            $log_message .= " - Reason: {$reason}";
        }
        Gym_Admin::add_user_note($user_id, $log_message);

        return array(
            'success' => true,
            'message' => 'Membership paused successfully',
            'user_id' => $user_id,
            'pause_date' => $pause_date,
            'remaining_days' => $remaining_days,
            'original_end_date' => $expiry_date,
            'paused_status' => true,
            'reason' => $reason
        );
    }

    /**
     * 🔥 FIXED: Unpause membership - now works even on expiry date
     * Update by record ID instead of status to handle expired records
     */
    public function unpause_membership($user_id)
    {
        global $wpdb;

        // Check if membership is paused
        $pause_status = $this->get_membership_pause_status($user_id);
        if (!$pause_status['is_paused']) {
            return new WP_Error('not_paused', 'Membership is not currently paused.');
        }

        $unpause_date = current_time('mysql');
        $pause_date = $pause_status['pause_date'];
        $original_end_date = $pause_status['original_end_date'];
        $remaining_days = $pause_status['remaining_days'];

        // Calculate days paused
        $days_paused = ceil((strtotime($unpause_date) - strtotime($pause_date)) / 86400);
        if ($days_paused < 0)
            $days_paused = 0;

        // Calculate new end date
        $new_end_date = null;
        if ($original_end_date) {
            $new_end_date = date('Y-m-d H:i:s', strtotime($original_end_date . " +{$days_paused} days"));

            // 🔥 FIX: Get the membership record by ID, not by status
            // This allows updating even if the record shows as expired
            $membership_table = $wpdb->prefix . 'pmpro_memberships_users';

            $current_record = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$membership_table}
                 WHERE user_id = %d
                 ORDER BY id DESC LIMIT 1",
                $user_id
            ));

            if (!$current_record) {
                return new WP_Error('no_membership_record', 'No membership record found for user.');
            }

            // 🔥 FIX: Update by primary key ID instead of status
            // This ensures we update the record regardless of its current status
            $update_result = $wpdb->update(
                $membership_table,
                array(
                    'enddate' => $new_end_date,
                    'status' => 'active',  // Re-activate the membership
                    'modified' => current_time('mysql')
                ),
                array(
                    'id' => $current_record->id  // Update by primary key, not status
                ),
                array('%s', '%s', '%s'),
                array('%d')
            );

            if ($update_result === false) {
                error_log("Failed to update membership enddate for user {$user_id}: " . $wpdb->last_error);
                return new WP_Error('update_failed', 'Failed to update membership end date: ' . $wpdb->last_error);
            }

            if ($update_result === 0) {
                error_log("No membership record updated for user {$user_id} - record ID: {$current_record->id}");
                return new WP_Error('update_failed', 'Could not find membership record to update.');
            }

            error_log("Successfully updated membership enddate for user {$user_id}: {$new_end_date}");
        }

        // Update total paused days
        $total_paused_days = (int) get_user_meta($user_id, 'membership_total_paused_days', true) + $days_paused;
        update_user_meta($user_id, 'membership_total_paused_days', $total_paused_days);

        // Clear pause status
        $this->clear_pause_data($user_id);

        // Add to pause history
        $this->add_pause_history_entry($user_id, 'unpaused', $unpause_date, '', $days_paused);

        // Clear PMPro caches - IMPORTANT for expired memberships
        if (function_exists('pmpro_delete_user_membership_level_cache')) {
            pmpro_delete_user_membership_level_cache($user_id);
        }

        // Clear WordPress caches
        wp_cache_delete($user_id, 'users');
        wp_cache_delete($user_id, 'user_meta');
        wp_cache_delete("pmpro_membership_level_for_user_" . $user_id, 'pmpro');
        clean_user_cache($user_id);

        // Add delay to ensure database consistency
        usleep(100000); // 0.1 second delay

        // Log the unpause
        $log_message = "Membership unpaused (paused for {$days_paused} days)";
        if ($new_end_date) {
            $log_message .= " - New expiry: " . date('Y-m-d', strtotime($new_end_date));
        }
        Gym_Admin::add_user_note($user_id, $log_message);

        // Get fresh membership data to verify
        $fresh_membership = $this->get_user_membership_fresh($user_id);

        // Verify the update worked
        if (!$fresh_membership['is_active']) {
            error_log("WARNING: Membership still shows as inactive after unpause for user {$user_id}");
            error_log("Fresh membership data: " . print_r($fresh_membership, true));
        }

        return array(
            'success' => true,
            'message' => 'Membership unpaused successfully',
            'user_id' => $user_id,
            'unpause_date' => $unpause_date,
            'days_paused' => $days_paused,
            'original_end_date' => $original_end_date,
            'new_end_date' => $new_end_date,
            'paused_status' => false,
            'total_paused_days' => $total_paused_days,
            'membership_is_now_active' => $fresh_membership['is_active']  // Verification flag
        );
    }

    /**
     * Get membership pause status
     */
    public function get_membership_pause_status($user_id)
    {
        $is_paused = (bool) get_user_meta($user_id, 'membership_is_paused', true);

        if (!$is_paused) {
            return array(
                'success' => true,
                'user_id' => $user_id,
                'is_paused' => false,
                'pause_date' => null,
                'days_paused_so_far' => 0,
                'total_paused_days' => (int) get_user_meta($user_id, 'membership_total_paused_days', true),
                'remaining_days' => null,
                'original_end_date' => null,
                'current_end_date' => null,
                'pause_history' => $this->get_pause_history($user_id)
            );
        }

        $pause_date = get_user_meta($user_id, 'membership_pause_date', true);
        $original_end_date = get_user_meta($user_id, 'membership_original_end_date', true);
        $remaining_days = (int) get_user_meta($user_id, 'membership_remaining_days', true);
        $total_paused_days = (int) get_user_meta($user_id, 'membership_total_paused_days', true);

        // Calculate days paused so far
        $days_paused_so_far = 0;
        if ($pause_date) {
            $days_paused_so_far = ceil((time() - strtotime($pause_date)) / 86400);
            if ($days_paused_so_far < 0)
                $days_paused_so_far = 0;
        }

        // Calculate current end date (original + days paused so far)
        $current_end_date = null;
        if ($original_end_date) {
            $current_end_date = date('Y-m-d H:i:s', strtotime($original_end_date . " +{$days_paused_so_far} days"));
        }

        return array(
            'success' => true,
            'user_id' => $user_id,
            'is_paused' => true,
            'pause_date' => $pause_date,
            'days_paused_so_far' => $days_paused_so_far,
            'total_paused_days' => $total_paused_days,
            'remaining_days' => $remaining_days,
            'original_end_date' => $original_end_date,
            'current_end_date' => $current_end_date,
            'pause_history' => $this->get_pause_history($user_id)
        );
    }

    /**
     * Clear pause data
     */
    private function clear_pause_data($user_id)
    {
        delete_user_meta($user_id, 'membership_is_paused');
        delete_user_meta($user_id, 'membership_pause_date');
        delete_user_meta($user_id, 'membership_original_end_date');
        delete_user_meta($user_id, 'membership_remaining_days');
    }

    /**
     * Add pause history entry
     */
    private function add_pause_history_entry($user_id, $action, $date, $reason = '', $days_paused = 0)
    {
        $history = get_user_meta($user_id, 'membership_pause_history', true);
        if (!is_array($history)) {
            $history = array();
        }

        $entry = array(
            'action' => $action,
            'date' => $date,
            'admin_id' => get_current_user_id(),
            'timestamp' => time()
        );

        if ($reason) {
            $entry['reason'] = $reason;
        }

        if ($days_paused > 0) {
            $entry['days_paused'] = $days_paused;
        }

        $history[] = $entry;
        update_user_meta($user_id, 'membership_pause_history', $history);
    }

    /**
     * Get pause history
     */
    private function get_pause_history($user_id)
    {
        $history = get_user_meta($user_id, 'membership_pause_history', true);
        if (!is_array($history)) {
            return array();
        }

        return $history;
    }

    /**
     * Get user visit information for visit-based memberships.
     *
     * FIX 3: Added $membership_expiry_date parameter (defaults to null for
     * backward compatibility). When provided and the date has passed,
     * this method returns immediately with zero visits and expired=true —
     * ensuring date expiry always beats visit counts.
     *
     * FIX 6: Fixed mktime() edge case when start_day = 1 (day 0 was invalid).
     */
    public function get_user_visit_info($user_id, $membership_start_date = null, $membership_expiry_date = null)
    {
        // FIX 3: Hard-stop on expired membership
        if ($membership_expiry_date !== null && $membership_expiry_date !== '' && $membership_expiry_date !== '0000-00-00 00:00:00') {
            $expiry_ts = strtotime($membership_expiry_date);
            if ($expiry_ts !== false && $expiry_ts <= time()) {
                return array(
                    'total_visits' => 0,
                    'remaining_visits' => 0,
                    'used_visits' => 0,
                    'visit_log' => array(),
                    'cycle_start_date' => null,
                    'cycle_end_date' => null,
                    'next_reset_date' => null,
                    'is_current_cycle' => false,
                    'expired' => true,
                );
            }
        }

        // Get current membership if start date not provided
        if (!$membership_start_date) {
            $membership = $this->get_user_membership($user_id);
            $membership_start_date = $membership['start_date'];
            if (!$membership_expiry_date) {
                $membership_expiry_date = $membership['expiry_date'];
            }
        }

        if (!$membership_start_date) {
            return array(
                'total_visits' => 0,
                'remaining_visits' => 0,
                'used_visits' => 0,
                'visit_log' => array(),
                'next_reset_date' => null,
                'is_current_cycle' => false
            );
        }

        // FIXED: Cycle = membership startdate to enddate directly.
        // No more monthly rolling window — visits are per membership period only.
        $cycle_start_date = date('Y-m-d', strtotime($membership_start_date));
        $cycle_end_date = $membership_expiry_date
            ? date('Y-m-d', strtotime($membership_expiry_date))
            : null;

        $current_time = current_time('timestamp');
        $cycle_start_ts = strtotime($cycle_start_date);
        $cycle_end_ts = $cycle_end_date ? strtotime($cycle_end_date . ' 23:59:59') : PHP_INT_MAX;

        $next_reset_date = $cycle_end_date
            ? date('Y-m-d H:i:s', $cycle_end_ts + 1)
            : null;

        // Get visit allowance
        $total_visits = (int) get_user_meta($user_id, 'membership_visit_allowance', true);
        if ($total_visits <= 0) {
            $total_visits = $this->default_monthly_visits;
            update_user_meta($user_id, 'membership_visit_allowance', $total_visits);
        }

        // Get visit log
        $visit_log = get_user_meta($user_id, 'membership_visit_log', true);
        if (!is_array($visit_log)) {
            $visit_log = array();
        }

        // Filter visits that fall within this membership period only
        $current_cycle_visits = array();
        foreach ($visit_log as $visit_date) {
            if ($visit_date >= $cycle_start_date && ($cycle_end_date === null || $visit_date <= $cycle_end_date)) {
                $current_cycle_visits[] = $visit_date;
            }
        }

        $used_visits = count($current_cycle_visits);
        $remaining_visits = max(0, $total_visits - $used_visits);
        $is_current_cycle = ($current_time >= $cycle_start_ts && $current_time <= $cycle_end_ts);

        return array(
            'total_visits' => $total_visits,
            'remaining_visits' => $remaining_visits,
            'used_visits' => $used_visits,
            'visit_log' => $current_cycle_visits,
            'cycle_start_date' => $cycle_start_date,
            'cycle_end_date' => $cycle_end_date,
            'next_reset_date' => $next_reset_date,
            'is_current_cycle' => $is_current_cycle
        );
    }

    /**
     * Record a visit check-in for user
     */
    public function record_visit_checkin($user_id)
    {
        // Verify user has visit-based membership
        $membership = $this->get_user_membership($user_id);

        if (!$membership['is_visit_based']) {
            return new WP_Error('not_visit_based', 'User does not have a visit-based membership plan.');
        }

        // FIX 5: Block paused members from checking in — give a specific error code
        // rather than falling through to the generic 'inactive' error below.
        if ($membership['is_paused']) {
            return new WP_Error('membership_paused', 'Check-in not allowed: membership is currently paused.');
        }

        if (!$membership['is_active']) {
            return new WP_Error('membership_inactive', 'User membership is not active.');
        }

        // FIX 3: Pass expiry_date so get_user_visit_info() can hard-stop if membership has expired.
        $visit_info = $this->get_user_visit_info($user_id, $membership['start_date'], $membership['expiry_date']);

        if ($visit_info['remaining_visits'] <= 0) {
            return new WP_Error('no_visits_remaining', 'No visits remaining for current cycle.');
        }

        $today = current_time('Y-m-d');

        // Check if already checked in today
        if (in_array($today, $visit_info['visit_log'])) {
            return new WP_Error('already_checked_in', 'User already checked in today.');
        }

        // Add today's visit to the log
        $visit_log = get_user_meta($user_id, 'membership_visit_log', true);
        if (!is_array($visit_log)) {
            $visit_log = array();
        }

        $visit_log[] = $today;
        update_user_meta($user_id, 'membership_visit_log', $visit_log);

        // Log the check-in
        Gym_Admin::add_user_note($user_id, "Visit check-in recorded for {$today} - Visits remaining: " . ($visit_info['remaining_visits'] - 1));

        // Get updated visit info
        $updated_visit_info = $this->get_user_visit_info($user_id, $membership['start_date'], $membership['expiry_date']);

        return array(
            'success' => true,
            'message' => 'Check-in recorded successfully',
            'check_in_date' => $today,
            'visit_info' => $updated_visit_info
        );
    }

    /**
     * Update visit allowance for user
     */
    public function update_visit_allowance($user_id, $new_allowance)
    {
        if ($new_allowance < 0) {
            return new WP_Error('invalid_allowance', 'Visit allowance must be 0 or greater.');
        }

        $old_allowance = (int) get_user_meta($user_id, 'membership_visit_allowance', true);
        update_user_meta($user_id, 'membership_visit_allowance', (int) $new_allowance);

        Gym_Admin::add_user_note($user_id, "Visit allowance updated from {$old_allowance} to {$new_allowance}");

        return array(
            'success' => true,
            'message' => 'Visit allowance updated successfully',
            'old_allowance' => $old_allowance,
            'new_allowance' => (int) $new_allowance
        );
    }

    /**
     * Reset visit log for user (admin function)
     */
    public function reset_visit_log($user_id)
    {
        $old_log = get_user_meta($user_id, 'membership_visit_log', true);
        $visit_count = is_array($old_log) ? count($old_log) : 0;

        update_user_meta($user_id, 'membership_visit_log', array());

        Gym_Admin::add_user_note($user_id, "Visit log reset - {$visit_count} previous visits cleared");

        return array(
            'success' => true,
            'message' => 'Visit log reset successfully',
            'cleared_visits' => $visit_count
        );
    }

    /**
     * Send membership change notification email
     * 
     * @param int $user_id User ID
     * @param string $action Action type: 'assigned' or 'updated'
     * @param string|null $start_date Actual start date that was assigned
     * @param string|null $end_date Actual end date that was assigned
     * @param int|null $level_id Membership level ID that was assigned
     */
    private function send_membership_notification($user_id, $action = 'assigned', $start_date = null, $end_date = null, $level_id = null)
    {
        $email_service = new Gym_Email_Service();

        $user = get_userdata($user_id);
        if (!$user) {
            error_log("Cannot send membership email: User {$user_id} not found");
            return false;
        }

        // ✅ Get the actual level name from the level_id provided (not from database)
        $level_name = 'Membership';
        if ($level_id && function_exists('pmpro_getLevel')) {
            $level = pmpro_getLevel($level_id);
            if ($level) {
                $level_name = $level->name;
            }
        }

        // If no level_id provided, fall back to getting from database
        if (!$level_id || $level_name === 'Membership') {
            $membership = $this->get_user_membership($user_id);
            $level_name = $membership['level_name'];
        }

        $actual_start_date = $start_date;
        $actual_end_date = $end_date;

        // If dates not provided, get from database as fallback
        if (!$actual_start_date || !$actual_end_date) {
            $membership = $this->get_user_membership($user_id);
            $actual_start_date = $actual_start_date ?: $membership['start_date'];
            $actual_end_date = $actual_end_date ?: $membership['expiry_date'];
        }

        $formatted_expiry = 'Lifetime';
        if ($actual_end_date && $actual_end_date !== '0000-00-00 00:00:00') {
            $formatted_expiry = date('F j, Y', strtotime($actual_end_date));
        }

        $formatted_start = 'Today';
        if ($actual_start_date && $actual_start_date !== '0000-00-00 00:00:00') {
            $formatted_start = date('F j, Y', strtotime($actual_start_date));
        }

        // Prepare custom data for the email template
        $custom_data = array(
            'membership_plan' => $level_name,  // ✅ FIXED: Now uses actual assigned level
            'expiry_date' => $formatted_expiry,  // ✅ FIXED: Now uses actual assigned date
            'start_date' => $formatted_start,
            'action' => $action // 'assigned', 'updated', 'changed'
        );

        // Log what we're sending for debugging
        error_log("Sending membership email for user {$user_id}:");
        error_log("  - Level: {$level_name}");
        error_log("  - Start: {$formatted_start}");
        error_log("  - Expiry: {$formatted_expiry}");
        error_log("  - Action: {$action}");

        // Send welcome email for new assignments, or custom notification for updates
        if ($action === 'assigned') {
            $result = $email_service->send_email($user_id, 'welcome', $custom_data);
        } else {
            // For updates, send a custom email
            $subject = "Your membership at {{gym_name}} has been updated";
            $content = "
    <h2>Membership Updated</h2>
    <p>Hi {{first_name}},</p>
    <p>Your gym membership has been updated with the following details:</p>
    <div class='highlight-box'>
        <p><strong>Membership Plan:</strong> {{membership_plan}}</p>
        <p><strong>Start Date:</strong> {{start_date}}</p>
        <p><strong>Expiry Date:</strong> {{expiry_date}}</p>
    </div>
    <p>If you have any questions about your membership, please contact us.</p>
    <p>Thank you!</p>
";
            $result = $email_service->send_custom_email($user_id, $subject, $content, $custom_data);
        }

        if (is_wp_error($result)) {
            error_log("Membership notification email failed for user {$user_id}: " . $result->get_error_message());
            return false;
        }

        error_log("Membership notification email sent successfully to {$user->user_email}");
        return true;
    }
}