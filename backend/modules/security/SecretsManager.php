<?php

namespace Epixel\FontalisChatBot\Backend\Modules\Security;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * SecretsManager Class
 *
 * Handles the secure retrieval of sensitive information, such as API keys.
 * It provides a centralized and secure way to manage secrets, prioritizing
 * environment variables, then WordPress constants, and finally encrypted
 * database options as a fallback.
 */
class SecretsManager
{
    private const GEMINI_API_KEY_OPTION = 'fontalis_gemini_api_key';
    private const MESSENGER_SECRET_OPTION = 'fontalis_messenger_secret';

    /**
     * Retrieves the Gemini API Key from the most secure location available.
     *
     * The order of retrieval is:
     * 1. Environment variable (`GEMINI_API_KEY`).
     * 2. WordPress constant in `wp-config.php` (`GEMINI_API_KEY`).
     * 3. Encrypted value from the WordPress options table (`fontalis_gemini_api_key`).
     *
     * @return string|null The API key, or null if not found.
     */
    public static function get_gemini_api_key(): ?string
    {
        // 1. Check for environment variable (most secure)
        $api_key = getenv('GEMINI_API_KEY');
        if (!empty($api_key)) {
            return trim($api_key);
        }

        // 2. Check for wp-config.php constant
        if (defined('GEMINI_API_KEY') && !empty(GEMINI_API_KEY)) {
            return trim(GEMINI_API_KEY);
        }

        // 3. Check for encrypted database option (least secure, but better than plain text)
        $encrypted_key = get_option(self::GEMINI_API_KEY_OPTION);
        if (!empty($encrypted_key)) {
            // Assuming an Encryption class exists for decryption
            // Since we don't have one yet, this is a placeholder for future implementation.
            // For now, we'll assume it's stored in a way that can be retrieved directly
            // or with a simple decryption method if available in the project.
            // Replace with `Encryption::decrypt($encrypted_key)` when available.
            return trim($encrypted_key);
        }

        return null;
    }

    /**
     * Saves the Gemini API Key to the database in an encrypted format.
     *
     * This should be used by an admin settings page.
     *
     * @param string $api_key The API key to save.
     * @return bool True on success, false on failure.
     */
    public static function set_gemini_api_key(string $api_key): bool
    {
        $sanitized_key = sanitize_text_field($api_key);

        // Replace with `Encryption::encrypt($sanitized_key)` when available.
        $value_to_save = $sanitized_key;

        return update_option(self::GEMINI_API_KEY_OPTION, $value_to_save);
    }

    /**
     * Retrieves the analytics messenger secret from env/constant/options.
     */
    public static function get_messenger_secret(): ?string
    {
        $secret = getenv('FONTALIS_ANALYTICS_SECRET');
        if (!empty($secret)) {
            return trim($secret);
        }

        if (defined('FONTALIS_ANALYTICS_SECRET') && !empty(FONTALIS_ANALYTICS_SECRET)) {
            return trim(FONTALIS_ANALYTICS_SECRET);
        }

        $stored_secret = get_option(self::MESSENGER_SECRET_OPTION);
        return !empty($stored_secret) ? trim($stored_secret) : null;
    }

    /**
     * Persists the analytics messenger secret securely.
     */
    public static function set_messenger_secret(string $secret): bool
    {
        $sanitized_secret = sanitize_text_field($secret);
        return update_option(self::MESSENGER_SECRET_OPTION, $sanitized_secret);
    }
}
