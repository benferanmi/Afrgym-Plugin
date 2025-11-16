<?php
/**
 * Media/Profile Picture API endpoints
 */
class Gym_Media_Endpoints
{

    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes()
    {
        // Get user profile picture
        register_rest_route('gym-admin/v1', '/users/(?P<id>\d+)/profile-picture', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_profile_picture'),
            'permission_callback' => array($this, 'check_permission')
        ));

        // Update user profile picture
        register_rest_route('gym-admin/v1', '/users/(?P<id>\d+)/profile-picture', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_profile_picture'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'profile_picture_url' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                    'description' => 'Cloudinary URL for user profile picture'
                )
            )
        ));

        // Delete user profile picture
        register_rest_route('gym-admin/v1', '/users/(?P<id>\d+)/profile-picture', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_profile_picture'),
            'permission_callback' => array($this, 'check_permission')
        ));
    }

    /**
     * Get user profile picture
     */
    public function get_profile_picture($request)
    {
        $user_id = $request->get_param('id');
        $user = get_user_by('id', $user_id);

        if (!$user) {
            return new WP_Error('user_not_found', 'User not found.', array('status' => 404));
        }

        $profile_picture_url = get_user_meta($user_id, 'profile_picture_url', true);

        $response_data = array(
            'success' => true,
            'user_id' => $user_id,
            'has_profile_picture' => !empty($profile_picture_url),
            'profile_picture_url' => !empty($profile_picture_url) ? $profile_picture_url : null,
            'avatar_url' => get_avatar_url($user_id), // WordPress gravatar fallback
            'last_updated' => !empty($profile_picture_url) ? get_user_meta($user_id, 'profile_picture_updated', true) : null
        );

        return rest_ensure_response($response_data);
    }

    /**
     * Update user profile picture
     */
    public function update_profile_picture($request)
    {
        $user_id = $request->get_param('id');
        $profile_picture_url = $request->get_param('profile_picture_url');

        $user = get_user_by('id', $user_id);

        if (!$user) {
            return new WP_Error('user_not_found', 'User not found.', array('status' => 404));
        }

        // Validate URL format
        if (empty($profile_picture_url) || !filter_var($profile_picture_url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_url', 'Invalid profile picture URL format.', array('status' => 400));
        }

        // Optional: Validate that URL is from Cloudinary (if you want to restrict to Cloudinary only)
        if (!$this->is_valid_cloudinary_url($profile_picture_url)) {
            return new WP_Error('invalid_cloudinary_url', 'Profile picture must be a valid Cloudinary URL.', array('status' => 400));
        }

        // Get previous profile picture URL for logging
        $previous_url = get_user_meta($user_id, 'profile_picture_url', true);

        // Update profile picture URL
        $updated = update_user_meta($user_id, 'profile_picture_url', $profile_picture_url);

        // Update timestamp
        update_user_meta($user_id, 'profile_picture_updated', current_time('mysql'));

        if (!$updated && $previous_url !== $profile_picture_url) {
            return new WP_Error('update_failed', 'Failed to update profile picture.', array('status' => 500));
        }

        // Log the action
        $action = empty($previous_url) ? 'added' : 'updated';
        Gym_Admin::add_user_note($user_id, "Profile picture $action via API");

        $response_data = array(
            'success' => true,
            'message' => "Profile picture $action successfully",
            'user_id' => $user_id,
            'profile_picture_url' => $profile_picture_url,
            'previous_url' => $previous_url,
            'action' => $action,
            'updated_at' => current_time('mysql')
        );

        return rest_ensure_response($response_data);
    }

    /**
     * Delete user profile picture
     */
    public function delete_profile_picture($request)
    {
        $user_id = $request->get_param('id');
        $user = get_user_by('id', $user_id);

        if (!$user) {
            return new WP_Error('user_not_found', 'User not found.', array('status' => 404));
        }

        // Get current profile picture URL before deletion
        $current_url = get_user_meta($user_id, 'profile_picture_url', true);

        if (empty($current_url)) {
            return new WP_Error('no_profile_picture', 'User does not have a profile picture to delete.', array('status' => 400));
        }

        // Delete profile picture URL and timestamp
        $deleted_url = delete_user_meta($user_id, 'profile_picture_url');
        $deleted_timestamp = delete_user_meta($user_id, 'profile_picture_updated');

        if (!$deleted_url) {
            return new WP_Error('delete_failed', 'Failed to delete profile picture.', array('status' => 500));
        }

        // Log the action
        Gym_Admin::add_user_note($user_id, 'Profile picture removed via API');

        $response_data = array(
            'success' => true,
            'message' => 'Profile picture deleted successfully',
            'user_id' => $user_id,
            'deleted_url' => $current_url,
            'avatar_url' => get_avatar_url($user_id), // WordPress gravatar fallback
            'deleted_at' => current_time('mysql')
        );

        return rest_ensure_response($response_data);
    }

    /**
     * Validate Cloudinary URL format
     */
    private function is_valid_cloudinary_url($url)
    {
        // Basic Cloudinary URL validation
        // You can make this more strict based on your Cloudinary setup
        return strpos($url, 'cloudinary.com') !== false ||
            strpos($url, 'res.cloudinary.com') !== false;
    }

    /**
     * Check permissions for media endpoints
     */
    public function check_permission($request)
    {
        return Gym_Admin::check_api_permission($request, 'manage_options');
    }
}