<?php

/**
 * WordPress Hooks.
 *
 * Responsável por registrar todas as ações e filtros do WordPress.
 * Focado APENAS no endpoint AJAX e no shortcode [fontalis_chat_embed].
 *
 * @package FontalisChatBot
 * @subpackage Includes
 */

namespace Epixel\FontalisChatBot\Includes;

// Classes Essenciais do "Motor" do Bot
use Epixel\FontalisChatBot\Core\FontalisBot;
use Epixel\FontalisChatBot\Backend\Modules\Logging\SessionManager;
use Epixel\FontalisChatBot\Backend\Modules\Security\AuditLogger;
use Epixel\FontalisChatBot\Backend\Modules\Security\SecureSessionManager;
use Epixel\FontalisChatBot\Backend\Modules\History\ChatHistoryManager;
use Epixel\FontalisChatBot\Utils\Logger;

// Se este arquivo for chamado diretamente, aborte.
if (!defined("WPINC")) {
    die();
}

/**
 * Classe WP_Hooks.
 *
 * Gerencia o registro dos hooks do WordPress para o plugin.
 */
class WP_Hooks
{
    /**
     * Registra todos os hooks (ações e filtros) do plugin.
     */
    public static function register_hooks(): void
    {
        // Hook para o endpoint AJAX do chat (O Backend principal)
        add_action("wp_ajax_fontalis_chat", [__CLASS__, "handle_chat_request"]);

        // Hook para buscar histórico de conversas
        add_action("wp_ajax_fontalis_get_history", [
            __CLASS__,
            "handle_get_history_request",
        ]);

        // Hook para enfileirar scripts e estilos do frontend (O Frontend principal)
        add_action("wp_enqueue_scripts", [
            __CLASS__,
            "enqueue_frontend_assets",
        ]);

        // Shortcode para o novo chat embutido
        add_shortcode("fontalis_chat_embed", [
            __CLASS__,
            "render_embedded_chat_shortcode",
        ]);

        // Hook para atualizar fragmentos do carrinho WooCommerce via AJAX
        add_filter("woocommerce_add_to_cart_fragments", [
            __CLASS__,
            "cart_count_fragments",
        ]);
    }

    /**
     * Enfileira os scripts e estilos do frontend para o chat.
     */
    public static function enqueue_frontend_assets(): void
    {
        global $post;

        // --- Carregamento dos assets do NOVO SHORTCODE ---
        // --- Carregamento dos assets do NOVO SHORTCODE ---
        // Carrega os assets em todas as páginas para garantir que o shortcode funcione
        // mesmo em widgets, footers ou page builders que não atualizam $post->post_content.

        $is_dev = defined("WP_DEBUG") && WP_DEBUG;

        if ($is_dev) {
            $shortcode_chat_css_version = @filemtime(
                FONTALIS_CHATBOT_PLUGIN_DIR . "frontend/css/shortcode-chat.css",
            ) ?: FONTALIS_CHATBOT_VERSION;

            $shortcode_mobile_css_version = @filemtime(
                FONTALIS_CHATBOT_PLUGIN_DIR . "frontend/css/shortcode-mobile.css",
            ) ?: FONTALIS_CHATBOT_VERSION;

            $shortcode_chat_js_version = @filemtime(
                FONTALIS_CHATBOT_PLUGIN_DIR . "frontend/js/shortcode-chat.js",
            ) ?: FONTALIS_CHATBOT_VERSION;
        } else {
            $shortcode_chat_css_version = FONTALIS_CHATBOT_VERSION;
            $shortcode_mobile_css_version = FONTALIS_CHATBOT_VERSION;
            $shortcode_chat_js_version = FONTALIS_CHATBOT_VERSION;
        }

        // 1. Carrega o CSS do FontAwesome (necessário para os ícones do seu template)
        wp_enqueue_style(
            "font-awesome-6",
            "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css",
            [],
            "6.4.0",
        );

        // 2. Carrega o CSS do novo shortcode
        wp_enqueue_style(
            "fontalis-shortcode-chat-style",
            FONTALIS_CHATBOT_PLUGIN_URL . "frontend/css/shortcode-chat.css",
            [],
            $shortcode_chat_css_version,
        );

        // 3. CSS específico para ajustes mobile
        wp_enqueue_style(
            "fontalis-shortcode-chat-style-mobile",
            FONTALIS_CHATBOT_PLUGIN_URL . "frontend/css/shortcode-mobile.css",
            ["fontalis-shortcode-chat-style"],
            $shortcode_mobile_css_version,
        );

        // 4. Carrega GSAP Core
        wp_enqueue_script(
            "gsap-core",
            "https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js",
            [],
            "3.12.5",
            true,
        );

        // 5. Carrega GSAP TextPlugin
        wp_enqueue_script(
            "gsap-text-plugin",
            "https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/TextPlugin.min.js",
            ["gsap-core"],
            "3.12.5",
            true,
        );

        // 6. Carrega o JS do novo shortcode
        wp_enqueue_script(
            "fontalis-shortcode-chat-script",
            FONTALIS_CHATBOT_PLUGIN_URL . "frontend/js/shortcode-chat.js",
            ["gsap-core", "gsap-text-plugin"],
            $shortcode_chat_js_version,
            true, // Carregar no footer
        );

        // 7. Passa os dados do PHP (AJAX URL, Nonce, etc.) para o nosso novo JS

        // Inicia/Recupera Sessão Segura
        $session_manager = new SecureSessionManager();
        // Nota: Idealmente, verificaríamos um cookie aqui para retomar a sessão.
        // Por enquanto, geramos uma nova para garantir funcionamento.
        $session_id = $session_manager->start_new_session();

        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            $user_avatar = get_avatar_url($current_user->ID, [
                "size" => 40,
                "default" => "identicon",
            ]);

            if (empty($user_avatar) && !empty($current_user->user_email)) {
                $user_avatar =
                    "https://www.gravatar.com/avatar/" .
                    md5(strtolower(trim($current_user->user_email))) .
                    "?s=40&d=identicon";
            }

            $localized_data = [
                "ajax_url" => admin_url("admin-ajax.php"),
                "nonce" => wp_create_nonce("fontalis_chat_nonce"),
                "user_avatar" => $user_avatar,
                "is_logged_in" => true,
                "session_id" => $session_id,
            ];
        } else {
            $localized_data = [
                "ajax_url" => admin_url("admin-ajax.php"),
                "nonce" => wp_create_nonce("fontalis_chat_nonce"),
                "user_avatar" => "",
                "is_logged_in" => false,
                "session_id" => $session_id,
            ];
        }

        // Passa os dados para o 'fontalis-shortcode-chat-script'
        wp_localize_script(
            "fontalis-shortcode-chat-script",
            "fontalisChat",
            $localized_data,
        );
    }

    /**
     * Renderiza o HTML do chat embutido via shortcode.
     * Esta função agora lê o seu template.html.
     */
    public static function render_embedded_chat_shortcode(): string
    {
        // Pega o caminho para o template profissional
        $template_path =
            FONTALIS_CHATBOT_PLUGIN_DIR .
            "frontend/templates/chat-template.php";

        if (!file_exists($template_path) || !is_readable($template_path)) {
            Logger::error(
                "Arquivo chat-template.php não encontrado ou não legível para o shortcode.",
            );
            return "<!-- Erro: template do chat não encontrado. -->";
        }

        // Inicia o buffer de saída para capturar o HTML
        ob_start();
        include $template_path;
        $template_content = ob_get_clean();

        return $template_content;
    }

    /**
     * Manipula as requisições AJAX para o chat.
     */
    public static function handle_chat_request(): void
    {
        // 1. Verificação de Nonce
        if (!check_ajax_referer("fontalis_chat_nonce", "nonce", false)) {
            wp_send_json_error([
                "message" => self::get_user_friendly_error(403),
            ], 403);
            return;
        }

        // 2. Verificação de Login
        if (!is_user_logged_in()) {
            wp_send_json_error([
                "message" => self::get_user_friendly_error(401),
            ], 401);
            return;
        }

        // 3. Validação de Origem
        $allowed_origins = [home_url()];
        $origin = $_SERVER["HTTP_ORIGIN"] ?? "";
        if (strpos($origin, home_url()) !== 0) {
            // Em produção, descomente as linhas abaixo para segurança estrita
            // wp_send_json_error(['message' => 'Invalid origin'], 403);
            // return;
        }

        // 4. Rate Limiting Global (Simples por IP para o endpoint AJAX)
        $ip = $_SERVER["REMOTE_ADDR"];
        $rate_key = "ajax_rate_" . md5($ip);
        $attempts = get_transient($rate_key) ?: 0;

        if ($attempts > 50) {
            wp_send_json_error([
                "message" => self::get_user_friendly_error(429),
            ], 429);
            return;
        }
        set_transient($rate_key, $attempts + 1, 60);

        // 5. Processar Requisição
        $message = sanitize_text_field($_POST["message"] ?? "");
        $session_id = sanitize_text_field($_POST["session_id"] ?? "");

        if (
            empty($session_id) ||
            !preg_match('/^[a-f0-9]{64}$/i', $session_id)
        ) {
            wp_send_json_error([
                "message" => self::get_user_friendly_error(400),
            ], 400);
            return;
        }

        // 6. Processar com o Bot
        try {
            $fontalis_bot = new FontalisBot();
            $response = $fontalis_bot->process_message($message, $session_id);

            if ($response["success"]) {
                wp_send_json_success([
                    "message" => $response["message"],
                    "cart_count" => $response["cart_count"],
                    "session_id" => $session_id,
                ]);
            } else {
                wp_send_json_error([
                    "message" => $response["message"] ??
                        self::get_user_friendly_error(500),
                    "retry_after" => $response["retry_after"] ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            // Log do erro real para debug
            error_log(
                "Fontalis Chatbot Error: " .
                    $e->getMessage() .
                    " in " .
                    $e->getFile() .
                    ":" .
                    $e->getLine(),
            );
            error_log("Stack trace: " . $e->getTraceAsString());

            try {
                $audit = new AuditLogger();
                $audit->log_error("ajax_handler", $e);
            } catch (\Throwable $audit_error) {
                error_log(
                    "Falha ao registrar log de auditoria: " .
                        $audit_error->getMessage(),
                );
            }

            $status_code = 500;
            wp_send_json_error(
                [
                    "message" => self::get_user_friendly_error($status_code),
                ],
                $status_code,
            );
        }
    }

    /**
     * Manipula a requisição para buscar o histórico de conversas.
     */
    public static function handle_get_history_request(): void
    {
        // 1. Verificação de Nonce
        if (!check_ajax_referer("fontalis_chat_nonce", "nonce", false)) {
            wp_send_json_error([
                "message" => self::get_user_friendly_error(403),
            ], 403);
            return;
        }

        // 2. Verificação de Login
        if (!is_user_logged_in()) {
            wp_send_json_error([
                "message" => self::get_user_friendly_error(401),
            ], 401);
            return;
        }

        $user_id = get_current_user_id();
        $history_manager = new ChatHistoryManager();

        // Parâmetros de paginação
        $limit = isset($_POST["limit"]) ? intval($_POST["limit"]) : 10;
        $offset = isset($_POST["offset"]) ? intval($_POST["offset"]) : 0;

        // Busca o histórico com paginação
        $history = $history_manager->get_user_history(
            $user_id,
            $limit,
            $offset,
        );

        // Formata para o frontend
        $formatted_history = [];
        foreach ($history as $session_id => $messages) {
            // Pega a primeira mensagem do usuário como título, ou "Nova conversa"
            $title = "Nova conversa";
            $last_timestamp = "";

            foreach ($messages as $msg) {
                if ($msg["sender_type"] === "user") {
                    $title =
                        mb_substr($msg["message_content"], 0, 30) .
                        (mb_strlen($msg["message_content"]) > 30 ? "..." : "");
                    break; // Usa a primeira mensagem do usuário
                }
            }

            // Pega o timestamp da última mensagem
            if (!empty($messages)) {
                $last_msg = end($messages);
                $last_timestamp = $last_msg["timestamp"];
            }

            $formatted_history[] = [
                "session_id" => $session_id,
                "title" => $title,
                "timestamp" => $last_timestamp,
                "messages" => $messages, // Opcional: enviar mensagens completas se quiser carregar ao clicar
            ];
        }

        wp_send_json_success(["history" => $formatted_history]);
    }

    /**
     * Adiciona fragmentos personalizados para atualização via AJAX do carrinho.
     * Este método é chamado pelo filtro 'woocommerce_add_to_cart_fragments'.
     *
     * @param array $fragments Array de fragmentos a serem atualizados.
     * @return array Array de fragmentos atualizado.
     */
    public static function cart_count_fragments($fragments)
    {
        if (!function_exists("WC") || !\WC()->cart) {
            return $fragments;
        }

        $cart_count = \WC()->cart->get_cart_contents_count();
        $cart_total = \WC()->cart->get_total();

        // Fragmento para o contador do tema Woodmart
        ob_start();
        ?>
        <span class="wd-cart-number wd-tools-count"><?php echo esc_html(
            $cart_count,
        ); ?> <span>item<?php echo $cart_count != 1 ? "s" : ""; ?></span></span>
        <?php
        $fragments[".wd-cart-number"] = ob_get_clean();

        // Fragmento para o subtotal do tema Woodmart
        ob_start();
        ?>
        <span class="wd-cart-subtotal"><?php echo \WC()->cart->get_cart_subtotal(); ?></span>
<?php
$fragments[".wd-cart-subtotal"] = ob_get_clean();

// Adiciona seletores genéricos para compatibilidade com outros temas
$selectors = [
    ".cart-contents-count",
    ".cart-count",
    ".shopping-cart-count",
    ".minicart-count",
    ".cart-items-count",
    ".header-cart-count",
    ".woocommerce-cart-count",
    "[data-cart-count]",
];

foreach ($selectors as $selector) {
    ob_start();
    echo esc_html($cart_count);
    $fragments[$selector] = ob_get_clean();
}

return $fragments;
    }

    /**
     * Retorna mensagens de erro amigáveis ao usuário.
     */
    private static function get_user_friendly_error(int $code): string
    {
        $messages = [
            400 => "Requisição inválida. Por favor, tente novamente.",
            401 => "Você precisa estar logado para usar o chat.",
            403 => "Acesso negado. Por favor, recarregue a página.",
            429 => "Muitas requisições. Aguarde um momento.",
            500 => "Ocorreu um erro interno. Tente novamente mais tarde.",
        ];

        return $messages[$code] ?? "Ocorreu um erro inesperado.";
    }
} // Fecha a classe WP_Hooks
