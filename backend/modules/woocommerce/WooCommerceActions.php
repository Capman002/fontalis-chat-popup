<?php

namespace Epixel\FontalisChatBot\Backend\Modules\WooCommerce;

if (!defined("ABSPATH")) {
    exit(); // Exit if accessed directly.
}

use Epixel\FontalisChatBot\Backend\Modules\Cache\CacheManager;

/**
 * Class WooCommerceActions
 *
 * Handles interactions with WooCommerce: searching products, managing cart.
 *
 * @package Epixel\FontalisChatBot\Backend\Modules\WooCommerce
 */
class WooCommerceActions
{
    /**
     * Garante que a sessão do WooCommerce está inicializada para AJAX.
     */
    private function ensure_wc_session(): void
    {
        if (!function_exists('WC') || !\WC()) {
            return;
        }

        // Garante que o customer existe
        if (\WC()->customer === null && is_user_logged_in()) {
            \WC()->customer = new \WC_Customer(get_current_user_id(), true);
        }

        // Inicializa a sessão se necessário
        if (\WC()->session === null) {
            \WC()->session = new \WC_Session_Handler();
            \WC()->session->init();
        }

        // Inicializa o carrinho se necessário
        if (\WC()->cart === null) {
            \WC()->cart = new \WC_Cart();
        }

        // Força o carregamento do carrinho da sessão
        \WC()->cart->get_cart_from_session();
    }

    /**
     * Search for products in WooCommerce.
     *
     * @param string $search_query The search term.
     * @param int    $limit        Number of products to return.
     * @return string JSON encoded response.
     */
    public function get_products(string $search_query, int $limit = 5): string
    {
        if (!class_exists("WooCommerce")) {
            return json_encode([
                "status" => "error",
                "message" => "WooCommerce não está ativo.",
            ]);
        }

        $args = [
            "status" => "publish",
            "limit" => $limit,
            "s" => \sanitize_text_field($search_query),
            "orderby" => "relevance",
            "order" => "DESC",
        ];

        $products = \wc_get_products($args);

        if (empty($products)) {
            return json_encode([
                "status" => "success",
                "total" => 0,
                "message" => "Nenhum produto encontrado para a busca.",
                "products" => [],
            ]);
        }

        $products_data = [];
        foreach ($products as $product) {
            $product_info = [
                "id" => $product->get_id(),
                "name" => $product->get_name(),
                "price" => $product->get_price(),
                "type" => $product->get_type(),
                "in_stock" => $product->is_in_stock(),
            ];

            if ($product->is_type("variable")) {
                $variations = $product->get_available_variations();
                $product_info["variations"] = array_map(function ($variation) {
                    return [
                        "id" => $variation["variation_id"],
                        "attributes" => $variation["attributes"],
                        "price" => $variation["display_price"],
                    ];
                }, $variations);
            }

            $products_data[] = $product_info;
        }

        return json_encode([
            "status" => "success",
            "total" => count($products_data),
            "products" => $products_data,
        ]);
    }

    /**
     * Add a single product to cart.
     *
     * @param int $product_id   Product ID.
     * @param int $variation_id Variation ID (optional).
     * @param int $quantity     Quantity to add.
     * @return string JSON response.
     */
    public function add_to_cart(
        int $product_id,
        int $variation_id = 0,
        int $quantity = 1,
    ): string {
        if (!class_exists("WooCommerce")) {
            return json_encode([
                "status" => "error",
                "message" => "WooCommerce não está ativo.",
            ]);
        }

        $this->ensure_wc_session();
        $cart_item_key = \WC()->cart->add_to_cart(
            $product_id,
            $quantity,
            $variation_id,
        );

        if ($cart_item_key) {
            return json_encode([
                "status" => "success",
                "message" => "Produto adicionado ao carrinho.",
            ]);
        }

        return json_encode([
            "status" => "error",
            "message" => "Erro ao adicionar produto ao carrinho.",
        ]);
    }

    /**
     * View cart contents.
     *
     * @return string JSON with cart contents.
     */
    public function view_cart(): string
    {
        if (!class_exists("WooCommerce")) {
            return json_encode([
                "status" => "error",
                "message" => "WooCommerce não está ativo.",
            ]);
        }

        $this->ensure_wc_session();
        $cart = \WC()->cart;
        $items = [];

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $items[] = [
                "cart_item_key" => $cart_item_key,
                "product_id" => $cart_item["product_id"],
                "variation_id" => $cart_item["variation_id"] ?? 0,
                "product_name" => $cart_item["data"]->get_name(),
                "quantity" => $cart_item["quantity"],
                "price" => $cart_item["data"]->get_price(),
                "subtotal" => $cart_item["line_subtotal"],
            ];
        }

        return json_encode([
            "status" => "success",
            "total_items" => count($items),
            "items" => $items,
            "cart_total" => $cart->get_cart_total(),
            "cart_subtotal" => $cart->get_cart_subtotal(),
        ]);
    }

    /**
     * Limpa todo o carrinho de compras.
     *
     * @return string JSON response.
     */
    public function clear_cart(): string
    {
        if (!class_exists("WooCommerce")) {
            return json_encode([
                "status" => "error",
                "message" => "WooCommerce não está ativo.",
            ]);
        }

        $this->ensure_wc_session();
        $cart = \WC()->cart;

        $items_count = $cart->get_cart_contents_count();

        if ($items_count === 0) {
            return json_encode([
                "status" => "success",
                "message" => "O carrinho já está vazio.",
                "items_removed" => 0,
            ]);
        }

        $cart->empty_cart();

        return json_encode([
            "status" => "success",
            "message" => "Carrinho esvaziado com sucesso.",
            "items_removed" => $items_count,
        ]);
    }

    /**
     * Remove item from cart by name or key.
     *
     * @param string $identifier Product name or cart_item_key.
     * @return string JSON response.
     */
    public function remove_from_cart(string $identifier): string
    {
        if (!class_exists("WooCommerce")) {
            return json_encode([
                "status" => "error",
                "message" => "WooCommerce não está ativo.",
            ]);
        }

        $this->ensure_wc_session();
        $cart = \WC()->cart;

        // Tenta remover diretamente se for um cart_item_key válido (32 caracteres hexadecimais)
        if (preg_match('/^[a-f0-9]{32}$/', $identifier)) {
            if ($cart->remove_cart_item($identifier)) {
                return json_encode([
                    "status" => "success",
                    "message" => "Item removido do carrinho com sucesso.",
                ]);
            }
        }

        // Prepara lista de itens do carrinho para análise
        $cart_items = [];
        $position = 1;
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $cart_items[] = [
                "key" => $cart_item_key,
                "name" => $cart_item["data"]->get_name(),
                "position" => $position++,
            ];
        }

        // ETAPA 1: Verifica se é uma posição/número
        $position_to_remove = $this->parse_position_identifier($identifier);
        if (
            $position_to_remove !== null &&
            $position_to_remove > 0 &&
            $position_to_remove <= count($cart_items)
        ) {
            $item = $cart_items[$position_to_remove - 1];
            if ($cart->remove_cart_item($item["key"])) {
                return json_encode([
                    "status" => "success",
                    "message" => "Item removido: " . $item["name"],
                    "removed_count" => 1,
                    "removed_items" => [$item["name"]],
                ]);
            }
        }

        // ETAPA 2: Busca exata ou parcial case-insensitive
        $removed = false;
        $removed_items = [];

        foreach ($cart_items as $item) {
            // Busca case-insensitive e permite match parcial
            if (stripos($item["name"], $identifier) !== false) {
                if ($cart->remove_cart_item($item["key"])) {
                    $removed = true;
                    $removed_items[] = $item["name"];
                }
            }
        }

        if ($removed) {
            $message =
                count($removed_items) === 1
                ? "Item removido: " . $removed_items[0]
                : "Itens removidos: " . implode(", ", $removed_items);

            return json_encode([
                "status" => "success",
                "message" => $message,
                "removed_count" => count($removed_items),
                "removed_items" => $removed_items,
            ]);
        }

        // ETAPA 3: Busca fuzzy (similaridade de texto e erros de digitação)
        $best_match = null;
        $best_similarity = 0;
        $threshold = 60; // 60% de similaridade mínima

        foreach ($cart_items as $item) {
            $similarity = 0;
            similar_text(
                strtolower($identifier),
                strtolower($item["name"]),
                $similarity,
            );

            if ($similarity > $best_similarity && $similarity >= $threshold) {
                $best_similarity = $similarity;
                $best_match = $item;
            }
        }

        if ($best_match) {
            if ($cart->remove_cart_item($best_match["key"])) {
                return json_encode([
                    "status" => "success",
                    "message" =>
                    "Item removido: " .
                        $best_match["name"] .
                        " (encontrado por similaridade)",
                    "removed_count" => 1,
                    "removed_items" => [$best_match["name"]],
                    "similarity" => round($best_similarity, 2),
                ]);
            }
        }

        return json_encode([
            "status" => "error",
            "message" =>
            "Não encontrei nenhum item com esse nome no carrinho. Por favor, verifique o nome correto ou me diga qual item deseja remover.",
        ]);
    }

    /**
     * Identifica se o identificador é uma posição numérica.
     *
     * @param string $identifier Identificador que pode ser número, posição textual, etc.
     * @return int|null Posição numérica ou null se não for uma posição.
     */
    private function parse_position_identifier(string $identifier): ?int
    {
        $identifier = trim(strtolower($identifier));

        // Números diretos: "1", "2", "3"
        if (is_numeric($identifier)) {
            return (int) $identifier;
        }

        // Números por extenso em português
        $number_words = [
            "primeiro" => 1,
            "primeira" => 1,
            "segundo" => 2,
            "segunda" => 2,
            "terceiro" => 3,
            "terceira" => 3,
            "quarto" => 4,
            "quarta" => 4,
            "quinto" => 5,
            "quinta" => 5,
            "sexto" => 6,
            "sexta" => 6,
            "sétimo" => 7,
            "sétima" => 7,
            "setimo" => 7,
            "setima" => 7,
            "oitavo" => 8,
            "oitava" => 8,
            "nono" => 9,
            "nona" => 9,
            "décimo" => 10,
            "décima" => 10,
            "decimo" => 10,
            "decima" => 10,
        ];

        if (isset($number_words[$identifier])) {
            return $number_words[$identifier];
        }

        // Números romanos: "i", "ii", "iii", "iv", "v"
        $roman_numerals = [
            "i" => 1,
            "ii" => 2,
            "iii" => 3,
            "iv" => 4,
            "v" => 5,
            "vi" => 6,
            "vii" => 7,
            "viii" => 8,
            "ix" => 9,
            "x" => 10,
        ];

        if (isset($roman_numerals[$identifier])) {
            return $roman_numerals[$identifier];
        }

        // Padrões como "item 3", "número 2", "posição 1"
        if (
            preg_match(
                "/(?:item|número|numero|posição|posicao|n[°º]?)\s*(\d+)/i",
                $identifier,
                $matches,
            )
        ) {
            return (int) $matches[1];
        }

        // Padrões como "3º", "2°", "1ª"
        if (preg_match('/^(\d+)[°ºª]?$/i', $identifier, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Remove all items matching product name from cart.
     *
     * @param string $product_name Product name to search.
     * @return string JSON response.
     */
    public function remove_from_cart_by_name(string $product_name): string
    {
        if (!class_exists("WooCommerce")) {
            return json_encode([
                "status" => "error",
                "message" => "WooCommerce não está ativo.",
            ]);
        }

        $cart = \WC()->cart;
        $removed_items = [];

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $current_product_name = $cart_item["data"]->get_name();

            // Match case-insensitive e permite match parcial
            if (stripos($current_product_name, $product_name) !== false) {
                if ($cart->remove_cart_item($cart_item_key)) {
                    $removed_items[] = [
                        "name" => $current_product_name,
                        "quantity" => $cart_item["quantity"],
                    ];
                }
            }
        }

        if (!empty($removed_items)) {
            return json_encode([
                "status" => "success",
                "message" =>
                "Removido " .
                    count($removed_items) .
                    " item(ns) do carrinho.",
                "removed_items" => $removed_items,
            ]);
        }

        return json_encode([
            "status" => "not_found",
            "message" => "Nenhum item encontrado com o nome '{$product_name}' no carrinho.",
        ]);
    }

    /**
     * Add multiple products to cart.
     *
     * @param array $products Array of product data.
     * @return string JSON response.
     */
    public function add_multiple_products_to_cart(array $products): string
    {
        if (!class_exists("WooCommerce")) {
            return json_encode([
                "status" => "error",
                "message" => "WooCommerce não está ativo.",
            ]);
        }

        $this->ensure_wc_session();

        $added = [];
        $failed = [];

        foreach ($products as $index => $product) {
            try {
                $product_id = isset($product["product_id"])
                    ? intval($product["product_id"])
                    : 0;
                $variation_id = isset($product["variation_id"])
                    ? intval($product["variation_id"])
                    : 0;
                $quantity = isset($product["quantity"])
                    ? intval($product["quantity"])
                    : 1;

                if ($product_id > 0) {
                    $cart_item_key = \WC()->cart->add_to_cart(
                        $product_id,
                        $quantity,
                        $variation_id,
                    );

                    if ($cart_item_key) {
                        $added[] = [
                            "product_id" => $product_id,
                            "name" => $product["product_name"] ?? "Produto #{$product_id}",
                        ];
                    } else {
                        $failed[] = [
                            "product_id" => $product_id,
                            "name" => $product["product_name"] ?? "Produto #{$product_id}",
                            "reason" => "Falha ao adicionar",
                        ];
                    }
                } else {
                    $failed[] = [
                        "product_id" => $product_id,
                        "reason" => "ID inválido",
                    ];
                }
            } catch (\Exception $e) {
                $failed[] = [
                    "product_id" => $product["product_id"] ?? 0,
                    "reason" => $e->getMessage(),
                ];
            }
        }

        return json_encode([
            "status" => count($added) > 0 ? "success" : "error",
            "message" => count($added) > 0
                ? "Adicionados " . count($added) . " produtos ao carrinho."
                : "Nenhum produto foi adicionado.",
            "added" => count($added),
            "failed" => count($failed),
            "total_requested" => count($products),
            "details" => [
                "added_products" => $added,
                "failed_products" => $failed,
            ],
        ]);
    }

    /**
     * Adiciona múltiplos produtos ao carrinho por nome.
     * Usa busca fuzzy para encontrar produtos mesmo com nomes aproximados.
     *
     * @param array $product_names Lista de nomes de produtos.
     * @param string $model_name Modelo/variação desejada.
     * @return string JSON response.
     */
    public function add_products_by_name(array $product_names, string $model_name = "Padrão"): string
    {
        if (!class_exists("WooCommerce")) {
            return json_encode([
                "status" => "error",
                "message" => "WooCommerce não está ativo.",
            ]);
        }

        $this->ensure_wc_session();

        // Busca todos os produtos de uma vez (otimizado)
        $all_products = $this->get_all_specialty_products();

        // Cria índice para busca rápida
        $product_index = [];
        foreach ($all_products as $product) {
            $normalized_name = $this->normalize_for_search($product->get_name());
            $product_index[$normalized_name] = $product;
        }

        $model_search = $this->normalize_for_search($model_name);

        $added = [];
        $not_found = [];

        foreach ($product_names as $name) {
            $normalized_search = $this->normalize_for_search($name);
            $found_product = null;

            // Remove prefixos comuns para busca mais flexível
            $search_clean = preg_replace('/^(prova de especialidade|prova|especialidade)[\s\-]*/i', '', $normalized_search);
            $search_clean = trim($search_clean);
            if (empty($search_clean)) {
                $search_clean = $normalized_search;
            }

            // Busca o melhor match
            foreach ($product_index as $indexed_name => $product) {
                // Limpa o nome indexado também
                $indexed_clean = preg_replace('/^(prova de especialidade|prova|especialidade)[\s\-]*/i', '', $indexed_name);
                $indexed_clean = trim($indexed_clean);

                // Match 1: busca limpa dentro do nome limpo
                if (!empty($search_clean) && stripos($indexed_clean, $search_clean) !== false) {
                    $found_product = $product;
                    break;
                }
                // Match 2: nome limpo dentro da busca limpa
                if (!empty($indexed_clean) && stripos($search_clean, $indexed_clean) !== false) {
                    $found_product = $product;
                    break;
                }
                // Match 3: busca original no nome original
                if (stripos($indexed_name, $normalized_search) !== false) {
                    $found_product = $product;
                    break;
                }
            }

            if ($found_product) {
                $product_id = $found_product->get_id();
                $variation_id = 0;
                $actual_model = "Padrão";

                // Se for variável, busca a variação correta
                if ($found_product->is_type("variable")) {
                    $variations = $found_product->get_available_variations();
                    if (!empty($variations)) {
                        $chosen_variation = null;

                        foreach ($variations as $variation) {
                            foreach ($variation["attributes"] as $attr_value) {
                                if (stripos($this->normalize_for_search($attr_value), $model_search) !== false) {
                                    $chosen_variation = $variation;
                                    $actual_model = $attr_value;
                                    break 2;
                                }
                            }
                        }

                        if (!$chosen_variation) {
                            $chosen_variation = $variations[0];
                            $first_attr = reset($chosen_variation["attributes"]);
                            $actual_model = $first_attr ?: "Padrão";
                        }

                        $variation_id = $chosen_variation["variation_id"];
                    }
                }

                // Adiciona ao carrinho
                $cart_item_key = \WC()->cart->add_to_cart($product_id, 1, $variation_id);

                if ($cart_item_key) {
                    $added[] = [
                        "requested" => $name,
                        "found" => $found_product->get_name(),
                        "model" => $actual_model,
                    ];
                } else {
                    $not_found[] = [
                        "name" => $name,
                        "reason" => "Erro ao adicionar ao carrinho",
                    ];
                }
            } else {
                $not_found[] = [
                    "name" => $name,
                    "reason" => "Produto não encontrado",
                ];
            }
        }

        return json_encode([
            "status" => count($added) > 0 ? "success" : "error",
            "message" => count($added) > 0
                ? "Adicionados " . count($added) . " de " . count($product_names) . " produtos ao carrinho."
                : "Nenhum produto foi encontrado.",
            "model_used" => $model_name,
            "added" => count($added),
            "not_found_count" => count($not_found),
            "total_requested" => count($product_names),
            "added_products" => $added,
            "not_found" => $not_found,
        ]);
    }

    /**
     * Busca todos os produtos de especialidade de uma vez (otimização).
     * Usa cache transient para evitar queries repetidas.
     *
     * @return array Lista de produtos WC_Product
     */
    private function get_all_specialty_products(): array
    {
        $cache_key = 'fontalis_all_specialty_products_v3'; // v3 nova normalização
        $cached = get_transient($cache_key);

        if ($cached !== false && is_array($cached) && count($cached) > 0) {
            // Reconstrói os objetos WC_Product a partir dos IDs
            $products = [];
            foreach ($cached as $product_id) {
                $product = wc_get_product($product_id);
                if ($product) {
                    $products[] = $product;
                }
            }
            return $products;
        }

        // Busca TODOS os produtos publicados (a loja é especializada em provas)
        $args = [
            'status' => 'publish',
            'limit' => -1, // Sem limite - pega todos
            'type' => ['simple', 'variable'],
            'orderby' => 'title',
            'order' => 'ASC',
        ];

        $specialty_products = \wc_get_products($args);

        // Salva apenas os IDs no cache (5 minutos)
        $product_ids = array_map(function ($p) {
            return $p->get_id();
        }, $specialty_products);
        set_transient($cache_key, $product_ids, 300);

        return array_values($specialty_products);
    }

    /**
     * Normaliza string para busca (remove acentos, lowercase).
     *
     * @param string $str String a normalizar
     * @return string String normalizada
     */
    private function normalize_for_search(string $str): string
    {
        $str = mb_strtolower($str, 'UTF-8');

        // Remove acentos usando transliteração
        if (function_exists('iconv')) {
            $str = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
        }

        // Fallback manual para acentos
        $replacements = [
            'á' => 'a',
            'à' => 'a',
            'ã' => 'a',
            'â' => 'a',
            'ä' => 'a',
            'Á' => 'a',
            'é' => 'e',
            'è' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'É' => 'e',
            'í' => 'i',
            'ì' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'Í' => 'i',
            'ó' => 'o',
            'ò' => 'o',
            'õ' => 'o',
            'ô' => 'o',
            'ö' => 'o',
            'Ó' => 'o',
            'ú' => 'u',
            'ù' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'Ú' => 'u',
            'ç' => 'c',
            'Ç' => 'c',
            'ñ' => 'n',
        ];
        $str = strtr($str, $replacements);

        // Remove caracteres especiais mantendo espaços
        $str = preg_replace('/[^a-z0-9\s]/', '', $str);

        // Normaliza espaços múltiplos
        $str = preg_replace('/\s+/', ' ', $str);

        return trim($str);
    }

    /**
     * Get specialty kits for Clube de Desbravadores.
     *
     * @param string|null $kit_name Name of the kit to retrieve.
     * @param string $model_name Model name (Padrão, Neutro, Detalhado, Retrô).
     * @return string JSON with products for the kit.
     */
    public function get_specialty_kits(?string $kit_name = null, string $model_name = "Padrão"): string
    {
        if (!class_exists("WooCommerce")) {
            return json_encode([
                "status" => "error",
                "message" => "WooCommerce não está ativo.",
            ]);
        }

        // Define os kits com nomes de especialidades
        $specialty_names = [
            "kit_completo_todas_as_classes" => [
                "name" => "Kit Completo - Todas as Classes",
                "description" =>
                "Todas as especialidades necessárias para completar as 6 classes do Clube de Desbravadores",
                "items" => [
                    "Natação principiante I",
                    "Cultura física",
                    "Nós e amarras",
                    "Segurança básica na água",
                    "Felinos",
                    "Cães",
                    "Mamíferos",
                    "Sementes",
                    "Aves de Estimação",
                    "Arte de Acampar",
                    "Natação Principiante II",
                    "Acampamento II",
                    "Anfíbios",
                    "Aves",
                    "Aves domésticas",
                    "Pecuária",
                    "Répteis",
                    "Moluscos",
                    "Árvores",
                    "Arbustos",
                    "Excursionismo Pedestre com Mochila",
                    "Astronomia",
                    "Cactos",
                    "Climatologia",
                    "Flores",
                    "Rastreio de animais",
                    "Acampamento III",
                    "Primeiros Socorros - básico",
                    "Asseio e Cortesia Cristã",
                    "Vida Familiar",
                    "Resgate Básico",
                    "Cidadania Cristã",
                    "Mapa e Bussola",
                    "Fogueiras e cozinha ao ar livre",
                    "Temperança",
                    "Aventuras com Cristo",
                    "Pioneiras",
                    "Testemunho Juvenil",
                    "Vida Silvestre",
                    "Ordem Unida",
                    "Nutrição",
                    "Ecologia",
                    "Conservação ambiental",
                    "Mordomia",
                    "Vida Campestre",
                    "Orçamento Familiar",
                    "Liderança Campestre",
                ],
            ],
            "classe_amigo" => [
                "name" => "Classe Amigo",
                "description" =>
                "Especialidades obrigatórias para a Classe Amigo",
                "items" => [
                    "Natação principiante I",
                    "Cultura física",
                    "Nós e amarras",
                    "Segurança básica na água",
                    "Felinos",
                    "Cães",
                    "Mamíferos",
                    "Sementes",
                    "Aves de Estimação",
                    "Arte de Acampar",
                ],
            ],
            "classe_companheiro" => [
                "name" => "Classe Companheiro",
                "description" =>
                "Especialidades obrigatórias para a Classe Companheiro",
                "items" => [
                    "Natação Principiante II",
                    "Acampamento II",
                    "Anfíbios",
                    "Aves",
                    "Aves domésticas",
                    "Pecuária",
                    "Répteis",
                    "Moluscos",
                    "Árvores",
                    "Arbustos",
                    "Excursionismo Pedestre com Mochila",
                ],
            ],
            "classe_pesquisador" => [
                "name" => "Classe Pesquisador",
                "description" =>
                "Especialidades obrigatórias para a Classe Pesquisador",
                "items" => [
                    "Astronomia",
                    "Cactos",
                    "Climatologia",
                    "Flores",
                    "Rastreio de animais",
                    "Acampamento III",
                    "Primeiros Socorros - básico",
                    "Asseio e Cortesia Cristã",
                    "Vida Familiar",
                ],
            ],
            "classe_pioneiro" => [
                "name" => "Classe Pioneiro",
                "description" =>
                "Especialidades obrigatórias para a Classe Pioneiro",
                "items" => [
                    "Resgate Básico",
                    "Cidadania Cristã",
                    "Mapa e Bussola",
                    "Fogueiras e cozinha ao ar livre",
                ],
            ],
            "classe_excursionista" => [
                "name" => "Classe Excursionista",
                "description" =>
                "Especialidades obrigatórias para a Classe Excursionista",
                "items" => [
                    "Temperança",
                    "Aventuras com Cristo",
                    "Pioneiras",
                    "Testemunho Juvenil",
                    "Vida Silvestre",
                    "Ordem Unida",
                ],
            ],
            "classe_guia" => [
                "name" => "Classe Guia",
                "description" =>
                "Especialidades obrigatórias para a Classe Guia",
                "items" => [
                    "Nutrição",
                    "Cultura Física",
                    "Ecologia",
                    "Conservação ambiental",
                    "Mordomia",
                    "Vida Campestre",
                    "Orçamento Familiar",
                    "Liderança Campestre",
                ],
            ],
        ];

        // Se nenhum kit foi especificado, lista todos
        if ($kit_name === null) {
            return json_encode([
                "status" => "success",
                "total_kits" => count($specialty_names),
                "available_models" => ["Padrão", "Neutro", "Detalhado", "Retrô"],
                "available_kits" => array_map(function ($kit) {
                    return [
                        "name" => $kit["name"],
                        "description" => $kit["description"],
                        "total_items" => count($kit["items"]),
                    ];
                }, $specialty_names),
            ]);
        }

        // Normaliza o nome do kit para busca
        $normalized_name = strtolower(str_replace([" ", "-"], "_", $kit_name));

        $selected_kit = null;
        $kit_id = null;

        foreach ($specialty_names as $id => $kit_data) {
            if (
                $id === $normalized_name ||
                strtolower($kit_data["name"]) === strtolower($kit_name)
            ) {
                $selected_kit = $kit_data;
                $kit_id = $id;
                break;
            }
        }

        if (!$selected_kit) {
            return json_encode([
                "status" => "error",
                "message" => "Kit '{$kit_name}' não encontrado.",
                "available_kits" => array_keys($specialty_names),
            ]);
        }

        // OTIMIZAÇÃO: Busca TODOS os produtos de especialidade de uma vez
        $all_products = $this->get_all_specialty_products();

        // Cria índice para busca rápida (normalizado)
        $product_index = [];
        foreach ($all_products as $product) {
            $normalized_name = $this->normalize_for_search($product->get_name());
            $product_index[$normalized_name] = $product;
        }

        // Normaliza o nome do modelo para busca
        $model_search = $this->normalize_for_search($model_name);

        $products_for_proposal = [];
        $not_found = [];

        foreach ($selected_kit["items"] as $specialty_name) {
            $normalized_search = $this->normalize_for_search($specialty_name);
            $found_product = null;

            // Busca exata primeiro
            foreach ($product_index as $indexed_name => $product) {
                if (stripos($indexed_name, $normalized_search) !== false) {
                    $found_product = $product;
                    break;
                }
            }

            if ($found_product) {
                if ($found_product->is_type("variable")) {
                    $variations = $found_product->get_available_variations();
                    if (!empty($variations)) {
                        $chosen_variation = null;
                        $actual_model_name = $model_name;

                        // Procura pela variação do modelo solicitado
                        foreach ($variations as $variation) {
                            foreach ($variation["attributes"] as $attr_value) {
                                if (stripos($this->normalize_for_search($attr_value), $model_search) !== false) {
                                    $chosen_variation = $variation;
                                    $actual_model_name = $attr_value;
                                    break 2;
                                }
                            }
                        }

                        // Fallback para primeira variação
                        if (!$chosen_variation) {
                            $chosen_variation = $variations[0];
                            $first_attr = reset($chosen_variation["attributes"]);
                            $actual_model_name = $first_attr ?: "Padrão";
                        }

                        $products_for_proposal[] = [
                            "product_id" => $found_product->get_id(),
                            "variation_id" => $chosen_variation["variation_id"],
                            "product_name" => $found_product->get_name(),
                            "variation_name" => $actual_model_name,
                            "quantity" => 1,
                        ];
                    } else {
                        $not_found[] = $specialty_name;
                    }
                } else {
                    $products_for_proposal[] = [
                        "product_id" => $found_product->get_id(),
                        "variation_id" => 0,
                        "product_name" => $found_product->get_name(),
                        "variation_name" => "Simples",
                        "quantity" => 1,
                    ];
                }
            } else {
                $not_found[] = $specialty_name;
            }
        }

        return json_encode([
            "status" => "success",
            "kit_name" => $selected_kit["name"],
            "kit_id" => $kit_id,
            "model_name" => $model_name,
            "total_requested" => count($selected_kit["items"]),
            "total_found" => count($products_for_proposal),
            "products" => $products_for_proposal,
            "not_found" => $not_found,
            "message" =>
            count($products_for_proposal) > 0
                ? "Encontrei " .
                count($products_for_proposal) .
                " de " .
                count($selected_kit["items"]) .
                " especialidades no modelo " . $model_name . "."
                : "Nenhum produto encontrado para este kit.",
        ]);
    }
}
