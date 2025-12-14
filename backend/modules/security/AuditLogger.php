<?php

namespace Epixel\FontalisChatBot\Backend\Modules\Security;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * AuditLogger Class
 *
 * Handles the logging of critical security and operational events.
 * It provides a structured way to record events to a custom database table
 * for later analysis, alerting, and reporting.
 */
class AuditLogger
{
    private $table_name;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'fontalis_audit_log';
    }

    /**
     * Logs an event to the audit table.
     *
     * @param string $action The action being logged (e.g., 'security_violation', 'cart_action').
     * @param array $details Additional details about the event to be stored as JSON.
     */
    public function log(string $action, array $details = []): void
    {
        // Don't log if debugging is disabled and it's a non-critical event
        if (!defined('FONTALIS_DEBUG') || !FONTALIS_DEBUG) {
            $non_critical = ['chat_completed'];
            if (in_array($action, $non_critical)) {
                return;
            }
        }

        global $wpdb;

        $current_user = wp_get_current_user();
        $user_id = $current_user->ID;
        $session_id = $details['session'] ?? null;

        $wpdb->insert(
            $this->table_name,
            [
                'user_id'    => $user_id,
                'session_id' => is_string($session_id) ? sanitize_text_field($session_id) : null,
                'action'     => sanitize_text_field($action),
                'details'    => wp_json_encode($details),
                'ip_address' => $this->get_client_ip(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'timestamp'  => current_time('mysql', 1), // GMT timestamp
            ],
            [
                '%d', // user_id
                '%s', // session_id
                '%s', // action
                '%s', // details
                '%s', // ip_address
                '%s', // user_agent
                '%s', // timestamp
            ]
        );
    }

    /**
     * Logs an error event, including exception details.
     *
     * @param string $action The context in which the error occurred.
     * @param \Exception $exception The exception object.
     */
    public function log_error(string $action, \Exception $exception): void
    {
        $this->log($action . '_error', [
            'error' => $exception->getMessage(),
            'file'  => $exception->getFile(),
            'line'  => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);
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
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip_address = trim($ips[0]);
        } elseif (isset($_SERVER['HTTP_X_REAL_IP'])) {
            $ip_address = $_SERVER['HTTP_X_REAL_IP'];
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip_address = $_SERVER['REMOTE_ADDR'];
        }
        return sanitize_text_field(trim((string)$ip_address));
    }
}
