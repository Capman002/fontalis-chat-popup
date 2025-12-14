<?php

namespace Epixel\FontalisChatBot\Backend\Modules\Cache;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * CacheManager Class
 *
 * Provides a unified interface for caching data, supporting Redis as the primary
 * driver and falling back to WordPress Transients if Redis is unavailable.
 * This ensures high performance in production environments while maintaining
 * compatibility with standard WordPress hosting.
 */
class CacheManager
{
    private const CACHE_GROUP = 'fontalis_chatbot';
    private $redis = null;
    private $redis_connected = false;

    /**
     * Constructor.
     *
     * Attempts to connect to Redis if the extension is loaded and configured.
     */
    public function __construct()
    {
        if (class_exists('Redis')) {
            try {
                $this->redis = new \Redis();
                $redis_host = defined('FONTALIS_REDIS_HOST') ? FONTALIS_REDIS_HOST : '127.0.0.1';
                $redis_port = defined('FONTALIS_REDIS_PORT') ? FONTALIS_REDIS_PORT : 6379;
                $redis_password = defined('FONTALIS_REDIS_PASSWORD') ? FONTALIS_REDIS_PASSWORD : null;

                if ($this->redis->connect($redis_host, $redis_port, 1.0)) {
                    if ($redis_password) {
                        $this->redis->auth($redis_password);
                    }
                    $this->redis_connected = true;
                }
            } catch (\RedisException $e) {
                $this->redis_connected = false;
            }
        }
    }

    /**
     * Retrieves a value from the cache.
     *
     * @param string $key The cache key.
     * @return mixed The cached value, or false if not found.
     */
    public function get(string $key)
    {
        $cache_key = $this->generate_key($key);

        if ($this->redis_connected) {
            $value = $this->redis->get($cache_key);
            return $value !== false ? unserialize($value) : false;
        } else {
            return get_transient($cache_key);
        }
    }

    /**
     * Stores a value in the cache.
     *
     * @param string $key The cache key.
     * @param mixed $value The value to store.
     * @param int $expiration Expiration time in seconds.
     * @return bool True on success, false on failure.
     */
    public function set(string $key, $value, int $expiration = 300): bool
    {
        $cache_key = $this->generate_key($key);

        if ($this->redis_connected) {
            return $this->redis->setex($cache_key, $expiration, serialize($value));
        } else {
            return set_transient($cache_key, $value, $expiration);
        }
    }

    /**
     * Deletes a value from the cache.
     *
     * @param string $key The cache key.
     * @return bool True on success, false on failure.
     */
    public function delete(string $key): bool
    {
        $cache_key = $this->generate_key($key);

        if ($this->redis_connected) {
            return $this->redis->del($cache_key) > 0;
        } else {
            return delete_transient($cache_key);
        }
    }

    /**
     * Invalidates all cache entries matching a pattern (Redis only).
     *
     * @param string $pattern The pattern to match (e.g., 'cart:*').
     * @return int The number of keys deleted.
     */
    public function invalidate_pattern(string $pattern): int
    {
        if (!$this->redis_connected) {
            // Transients do not support pattern deletion efficiently.
            // A more complex solution would be needed here if Redis is not used,
            // such as storing keys in an option and clearing them, but that's slow.
            return 0;
        }

        $full_pattern = $this->generate_key($pattern);
        $keys = $this->redis->keys($full_pattern);
        if (!empty($keys)) {
            return $this->redis->del($keys);
        }
        return 0;
    }

    /**
     * Generates a prefixed and grouped cache key.
     *
     * @param string $key The original key.
     * @return string The final cache key.
     */
    public function generate_key(string $key, $context = null): string
    {
        $suffix = '';
        if ($context !== null) {
            $suffix = '_' . md5(json_encode($context));
        }

        return self::CACHE_GROUP . '_' . $key . $suffix;
    }

    /**
     * Checks if Redis is the active cache driver.
     *
     * @return bool
     */
    public function is_redis_active(): bool
    {
        return $this->redis_connected;
    }
}
