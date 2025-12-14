<?php

namespace Epixel\FontalisChatBot\Backend\Modules\WooCommerce;

use Epixel\FontalisChatBot\Backend\Modules\Cache\CacheManager;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class ProposalManager
{
    private const PROPOSAL_PREFIX = 'fontalis_proposal_';
    private const PROPOSAL_EXPIRATION = 10 * MINUTE_IN_SECONDS;

    private $cache_manager;

    public function __construct()
    {
        $this->cache_manager = new CacheManager();
    }

    public function create_secure_proposal(array $products, int $user_id): string
    {
        $proposal_id = 'prop_' . bin2hex(random_bytes(8));
        $proposal = ['items' => [], 'summary' => [], 'errors' => []];
        $total_items = 0;

        foreach ($products as $product_info) {
            $product_name = \sanitize_text_field($product_info['product_name']);
            $variation_name = \sanitize_text_field($product_info['variation_name']);

            $found_product = $this->find_product_by_name_and_variation($product_name, $variation_name);

            if ($found_product) {
                $proposal['items'][] = [
                    'product_id' => $found_product['product_id'],
                    'variation_id' => $found_product['variation_id'],
                    'name' => $found_product['name'],
                    'quantity' => 1,
                    'available' => true,
                ];
                $total_items++;
            } else {
                $proposal['errors'][] = "Produto não encontrado ou indisponível: {$product_name} - {$variation_name}";
            }
        }

        $proposal['summary'] = [
            'total_items' => $total_items,
            'distinct_products' => count($proposal['items']),
            'expires_at' => time() + self::PROPOSAL_EXPIRATION,
        ];

        $signature = hash_hmac('sha256', $proposal_id . json_encode($proposal['items']), \wp_salt());

        $data_to_store = [
            'proposal' => $proposal,
            'signature' => $signature,
            'user_id' => $user_id,
        ];

        $this->cache_manager->set(self::PROPOSAL_PREFIX . $proposal_id, $data_to_store, self::PROPOSAL_EXPIRATION);

        return json_encode([
            'status' => 'proposal_ready',
            'proposal_id' => $proposal_id,
            'proposal' => $proposal,
            'signature' => $signature,
        ]);
    }

    public function validate_proposal(string $proposal_id, int $user_id): bool
    {
        $stored_data = $this->cache_manager->get(self::PROPOSAL_PREFIX . $proposal_id);

        if (!$stored_data) {
            return false; // Not found or expired
        }

        if ($stored_data['user_id'] !== $user_id) {
            return false; // Ownership mismatch
        }

        $expected_signature = hash_hmac('sha256', $proposal_id . json_encode($stored_data['proposal']['items']), \wp_salt());

        return hash_equals($expected_signature, $stored_data['signature']);
    }

    public function get_proposal_items(string $proposal_id): ?array
    {
        $stored_data = $this->cache_manager->get(self::PROPOSAL_PREFIX . $proposal_id);
        return $stored_data['proposal']['items'] ?? null;
    }

    public function delete_proposal(string $proposal_id): bool
    {
        return $this->cache_manager->delete(self::PROPOSAL_PREFIX . $proposal_id);
    }

    private function find_product_by_name_and_variation(string $product_name, string $variation_name): ?array
    {
        $args = ['s' => $product_name, 'limit' => 1, 'status' => 'publish', 'return' => 'ids'];
        $product_ids = \wc_get_products($args);

        if (empty($product_ids)) {
            return null;
        }

        $product = \wc_get_product($product_ids);

        if ($product && $product->is_type('variable')) {
            foreach ($product->get_children() as $variation_id) {
                $variation = \wc_get_product($variation_id);
                if ($variation && str_ireplace('-', ' ', $variation->get_name()) === str_ireplace('-', ' ', $product->get_name() . ' - ' . $variation_name)) {
                    return [
                        'product_id' => $product->get_id(),
                        'variation_id' => $variation_id,
                        'name' => $variation->get_name(),
                    ];
                }
            }
        }

        return null;
    }
}
