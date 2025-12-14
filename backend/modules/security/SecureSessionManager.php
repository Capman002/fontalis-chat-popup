<?php

namespace Epixel\FontalisChatBot\Backend\Modules\Security;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * SecureSessionManager Class
 *
 * Handles the creation, validation, and management of secure user sessions.
 * This implementation uses cryptographically secure random tokens instead of JWT
 * to avoid external dependencies, making it suitable for standard WordPress environments.
 */
class SecureSessionManager
{
    private const SESSION_TRANSIENT_PREFIX = 'fontalis_session_';
    private const SESSION_OWNER_TRANSIENT_PREFIX = 'fontalis_owner_';
    private const SESSION_IP_TRANSIENT_PREFIX = 'fontalis_ip_';
    private const SESSION_TIMEOUT = 30 * MINUTE_IN_SECONDS; // 30 minutes

    /**
     * Generates a new, cryptographically secure session ID.
     *
     * @return string The 64-character hexadecimal session ID.
     */
    public function create_session_id(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Starts a new session for the current user or visitor.
     *
     * Associates the session with the user ID for logged-in users or the IP address for visitors.
     *
     * @return string The newly created session ID.
     */
    public function start_new_session(): string
    {
        $session_id = $this->create_session_id();
        $user_id = get_current_user_id();
        $ip_address = $this->get_client_ip();

        // Store the session data with a timeout
        set_transient(self::SESSION_TRANSIENT_PREFIX . $session_id, true, self::SESSION_TIMEOUT);

        // Associate the session with the user/IP for ownership validation
        if ($user_id > 0) {
            set_transient(self::SESSION_OWNER_TRANSIENT_PREFIX . $session_id, $user_id, self::SESSION_TIMEOUT);
        } else {
            set_transient(self::SESSION_IP_TRANSIENT_PREFIX . $session_id, $ip_address, self::SESSION_TIMEOUT);
        }

        return $session_id;
    }

    /**
     * Validates a given session ID.
     *
     * Checks for existence, format, expiration, and ownership.
     *
     * @param string|null $session_id The session ID to validate.
     * @return bool True if the session is valid, false otherwise.
     */
    public function validate_session(?string $session_id): bool
    {
        if (empty($session_id) || !preg_match('/^[a-f0-9]{64}$/i', $session_id)) {
            return false; // Invalid format
        }

        // Check if the session transient exists (i.e., not expired)
        if (get_transient(self::SESSION_TRANSIENT_PREFIX . $session_id) === false) {
            return false; // Session expired or does not exist
        }

        // Check ownership
        return $this->validate_session_ownership($session_id);
    }

    /**
     * Validates that the current user owns the session.
     *
     * @param string $session_id The session ID.
     * @return bool True if the owner matches, false otherwise.
     */
    private function validate_session_ownership(string $session_id): bool
    {
        $user_id = get_current_user_id();

        if ($user_id > 0) {
            $stored_owner = get_transient(self::SESSION_OWNER_TRANSIENT_PREFIX . $session_id);
            return $stored_owner !== false && (int) $stored_owner === $user_id;
        } else {
            $stored_ip = get_transient(self::SESSION_IP_TRANSIENT_PREFIX . $session_id);
            return $stored_ip !== false && $stored_ip === $this->get_client_ip();
        }
    }

    /**
     * Refreshes the session timeout.
     *
     * @param string $session_id The session ID to refresh.
     */
    public function refresh_session(string $session_id): void
    {
        if ($this->validate_session($session_id)) {
            // Just by validating, we know the main transient exists. We just need to reset its timer.
            set_transient(self::SESSION_TRANSIENT_PREFIX . $session_id, true, self::SESSION_TIMEOUT);

            $user_id = get_current_user_id();
            if ($user_id > 0) {
                set_transient(self::SESSION_OWNER_TRANSIENT_PREFIX . $session_id, $user_id, self::SESSION_TIMEOUT);
            } else {
                set_transient(self::SESSION_IP_TRANSIENT_PREFIX . $session_id, $this->get_client_ip(), self::SESSION_TIMEOUT);
            }
        }
    }

    /**
     * Ends a session by deleting its associated transients.
     *
     * @param string $session_id The session ID to end.
     */
    public function end_session(string $session_id): void
    {
        delete_transient(self::SESSION_TRANSIENT_PREFIX . $session_id);
        delete_transient(self::SESSION_OWNER_TRANSIENT_PREFIX . $session_id);
        delete_transient(self::SESSION_IP_TRANSIENT_PREFIX . $session_id);
    }

    /**
     * Gets the client's IP address safely.
     *
     * @return string The client's IP address.
     */
    private function get_client_ip(): string
    {
        $ip_address = '';
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip_address = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        } elseif (isset($_SERVER['HTTP_X_REAL_IP'])) {
            $ip_address = $_SERVER['HTTP_X_REAL_IP'];
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip_address = $_SERVER['REMOTE_ADDR'];
        }
        return sanitize_text_field($ip_address);
    }

    /**
     * Schedules a cron job to clean up expired session transients from the options table.
     * WordPress automatically handles transient cleanup, but this provides an extra layer of cleanup.
     */
    public static function schedule_cleanup(): void
    {
        if (!wp_next_scheduled('fontalis_cleanup_expired_sessions')) {
            wp_schedule_event(time(), 'daily', 'fontalis_cleanup_expired_sessions');
        }
    }

    /**
     * Executes the cleanup of expired session data.
     * This is a more forceful cleanup than the default WordPress behavior.
     */
    public static function cleanup_expired_sessions(): void
    {
        global $wpdb;
        $expired_transients = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
                $wpdb->esc_like('_transient_timeout_' . self::SESSION_TRANSIENT_PREFIX) . '%',
                time()
            )
        );

        foreach ($expired_transients as $transient) {
            $session_key = str_replace('_transient_timeout_', '', $transient);
            delete_transient($session_key);
        }
    }
}