<?php

namespace Epixel\FontalisChatBot\Backend\Modules\Security;

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

/**
 * Class RateLimiter
 *
 * Handles rate limiting for the chatbot to prevent abuse.
 *
 * @package Epixel\FontalisChatBot\Backend\Modules\Security
 */
class RateLimiter
{

	/**
	 * Maximum number of requests allowed within the time window.
	 *
	 * @var int
	 */
	private $limit;

	/**
	 * Time window in seconds.
	 *
	 * @var int
	 */
	private $window;

	/**
	 * Constructor.
	 *
	 * @param int $limit  Max requests. Default 10.
	 * @param int $window Time window in seconds. Default 60 (1 minute).
	 */
	public function __construct(int $limit = 10, int $window = 60)
	{
		$this->limit  = $limit;
		$this->window = $window;
	}

	/**
	 * Checks if the limit has been reached for the given identifier.
	 *
	 * @param string $identifier Unique identifier for the user (e.g., User ID or IP).
	 * @return bool True if request is allowed, False if limit exceeded.
	 */
	public function check_limit(string $identifier): bool
	{
		$key = $this->get_cache_key($identifier);
		$current_count = (int) get_transient($key);

		if ($current_count >= $this->limit) {
			return false;
		}

		if ($current_count === 0) {
			set_transient($key, 1, $this->window);
		} else {
			$current_count++;
			set_transient($key, $current_count, $this->window);
		}

		return true;
	}

	/**
	 * Gets the time remaining until the limit resets.
	 *
	 * @param string $identifier Unique identifier.
	 * @return int Seconds remaining.
	 */
	public function get_retry_after(string $identifier): int
	{
		return $this->window;
	}

	/**
	 * Generates the cache key.
	 *
	 * @param string $identifier Unique identifier.
	 * @return string Cache key.
	 */
	private function get_cache_key(string $identifier): string
	{
		return 'fontalis_rate_limit_' . md5($identifier);
	}
}
