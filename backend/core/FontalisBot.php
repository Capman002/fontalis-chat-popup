<?php

/**
 * FontalisBot.
 *
 * Classe principal do chatbot, responsável por processar mensagens e interagir
 * com a API Gemini do Google usando Function Calling.
 *
 * @package FontalisChatBot
 * @subpackage Core
 */

namespace Epixel\FontalisChatBot\Core;

// Se este arquivo for chamado diretamente, aborte.
if (!defined("WPINC")) {
    die();
}

use Epixel\FontalisChatBot\Core\GeminiConfig;
use Epixel\FontalisChatBot\Backend\Modules\Analytics\Messenger;
use Epixel\FontalisChatBot\Backend\Modules\History\ChatHistoryManager;
use Epixel\FontalisChatBot\Backend\Modules\Security\SecureSessionManager;
use Epixel\FontalisChatBot\Backend\Modules\Security\RateLimiter;
use Epixel\FontalisChatBot\Backend\Modules\Security\AuditLogger;
use Epixel\FontalisChatBot\Backend\Modules\Cache\CacheManager;
use Epixel\FontalisChatBot\Backend\Modules\WooCommerce\WooCommerceActions;
use Epixel\FontalisChatBot\Backend\Modules\WooCommerce\ProposalManager;

/**
 * Classe FontalisBot.
 */
class FontalisBot
{
    private $chat_history_manager;
    private $model_config;
    private $audit_logger;
    private $messenger;
    private $interaction_builder;
    private $ttft;

    public function __construct()
    {
        $this->audit_logger = new AuditLogger();
        $this->chat_history_manager = new ChatHistoryManager();
        $this->messenger = new Messenger();
        $this->interaction_builder = null;
        $this->ttft = null;

        if (!GeminiConfig::is_configured()) {
            error_log(
                "Fontalis ChatBot Error: API Gemini não está configurada.",
            );
            return;
        }

        $this->model_config = GeminiConfig::get_model_config();
    }

    /**
     * Processa a mensagem do usuário e retorna a resposta do bot.
     */
    public function process_message(string $message, string $session_id): array
    {
        if (!GeminiConfig::is_configured()) {
            return [
                "success" => false,
                "message" =>
                    "Erro de configuração: API Key não encontrada. Por favor, configure a chave da API Gemini.",
            ];
        }

        $MAX_STEPS = 5;
        $TIMEOUT_SECONDS = 25;

        // 1. Validações de Segurança
        $secure_session = new SecureSessionManager();
        if (!$secure_session->validate_session($session_id)) {
            return ["success" => false, "message" => "Sessão inválida"];
        }

        // 2. Rate Limiting
        $rate_limiter = new RateLimiter();
        $identifier = get_current_user_id() ?: $_SERVER["REMOTE_ADDR"];

        if (!$rate_limiter->check_limit($identifier)) {
            return [
                "success" => false,
                "message" =>
                    "Muitas requisições. Por favor, aguarde um momento.",
                "retry_after" => $rate_limiter->get_retry_after($identifier),
            ];
        }

        // 3. Sanitização
        try {
            $clean_message = $this->sanitize_user_input($message);
        } catch (\Exception $e) {
            $this->audit_logger->log("security_violation", [
                "session" => $session_id,
                "reason" => $e->getMessage(),
            ]);
            return ["success" => false, "message" => "Mensagem inválida"];
        }

        $analytics_user_id = $this->get_user_identifier($session_id);

        // Configura o messenger para esta sessão
        $this->messenger->setSession($session_id, $analytics_user_id);
        $this->messenger->setUserContext([
            'wp_user_id' => get_current_user_id(),
            'device' => $this->detect_device_info(),
        ]);

        // Adiciona mensagem do usuário ao analytics
        if (!empty($clean_message)) {
            $this->messenger->addUserMessage($clean_message);
        }

        // 4. Loop do Agente
        $start_time = microtime(true);
        $iterations = 0;
        $final_response = "";
        $function_calls_made = [];

        try {
            $current_user_id = get_current_user_id();

            // Salva mensagem do usuário se não for vazia (primeira iteração)
            if (!empty($clean_message)) {
                $this->chat_history_manager->save_user_message(
                    $session_id,
                    $clean_message,
                );
            }

            while ($iterations < $MAX_STEPS) {
                // Verifica timeout
                if (microtime(true) - $start_time > $TIMEOUT_SECONDS) {
                    $this->audit_logger->log("timeout", [
                        "session" => $session_id,
                        "iterations" => $iterations,
                    ]);
                    break;
                }

                $iterations++;

                // Obtém histórico atualizado
                $history = $this->chat_history_manager->get_session_history(
                    $session_id,
                    $current_user_id,
                );

                // Chama API Gemini
                $payload = $this->build_gemini_request_payload($history);
                $api_response = $this->call_gemini_api_with_retry($payload);

                // Determina o tipo de iteração para tracking de tokens
                $iteration_type = $iterations === 1 ? 'initial' : 'tool_response';
                $this->capture_ai_usage_event($api_response, $iteration_type);

                // Processa resposta
                $has_function_call = false;
                $finish_reason =
                    $api_response["candidates"][0]["finishReason"] ?? "STOP";
                $parts =
                    $api_response["candidates"][0]["content"]["parts"] ?? [];

                foreach ($parts as $part) {
                    if (isset($part["functionCall"])) {
                        $has_function_call = true;
                        $function_call = $part["functionCall"];

                        // Executa a função
                        $function_result = $this->execute_function_safely(
                            $function_call,
                            $session_id,
                        );
                        $function_calls_made[] = $function_call["name"];

                        // Salva no histórico
                        $this->chat_history_manager->save_function_call(
                            $session_id,
                            "",
                            $function_call,
                        );
                        $this->chat_history_manager->save_function_response(
                            $session_id,
                            $function_call["name"],
                            $function_result,
                        );
                    } elseif (isset($part["text"])) {
                        $final_response .= $part["text"];
                    }
                }

                // Condições de saída
                if ($finish_reason === "STOP" && !$has_function_call) {
                    break;
                }

                if (
                    $finish_reason !== "STOP" &&
                    $finish_reason !== "MAX_TOKENS"
                ) {
                    $this->audit_logger->log("unusual_finish", [
                        "reason" => $finish_reason,
                        "session" => $session_id,
                    ]);
                    break;
                }
            }

            // Salva resposta final
            if (!empty($final_response)) {
                $this->chat_history_manager->save_ai_response(
                    $session_id,
                    $final_response,
                );
                // Adiciona resposta da IA ao analytics
                $this->messenger->addAiResponse($final_response);
            }

            // Audit log
            $this->audit_logger->log("chat_completed", [
                "session" => $session_id,
                "iterations" => $iterations,
                "functions_called" => $function_calls_made,
                "execution_time" => microtime(true) - $start_time,
            ]);

            // Envia todos os dados de analytics em um único JSON
            $this->messenger->flush();

            return [
                "success" => true,
                "message" =>
                    $final_response ?:
                    "Desculpe, não consegui processar sua solicitação.",
                "cart_count" =>
                    function_exists("WC") && WC()->cart
                        ? WC()->cart->get_cart_contents_count()
                        : 0,
                "metadata" => [
                    "iterations" => $iterations,
                ],
            ];
        } catch (\Exception $e) {
            $this->audit_logger->log_error("process_message", $e);

            // Envia analytics mesmo em caso de erro
            $this->messenger->flush();

            return [
                "success" => false,
                "message" =>
                    "Ocorreu um erro ao processar sua mensagem: " .
                    $e->getMessage(),
                "cart_count" =>
                    function_exists("WC") && WC()->cart
                        ? WC()->cart->get_cart_contents_count()
                        : 0,
            ];
        }
    }

    private function sanitize_user_input(string $message): string
    {
        $message = preg_replace('/[\x00-\x1F\x7F]/u', "", $message);
        $message = mb_substr($message, 0, 500);

        $dangerous_patterns = [
            "/ignore previous instructions/i",
            "/disregard all prior/i",
            "/system prompt/i",
            "/reveal instructions/i",
        ];

        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $message)) {
                throw new \Exception("Input validation failed");
            }
        }

        return $message;
    }

    private function execute_function_safely(
        array $function_call,
        string $session_id,
    ): string {
        $user_identifier = $this->get_user_identifier($session_id);
        $function_name = $function_call["name"];
        $function_args = $function_call["args"] ?? [];

        // Cache check
        $cache_manager = new CacheManager();
        if (
            in_array($function_name, [
                "get_products",
                "view_cart",
                "get_specialty_kits",
            ])
        ) {
            $cache_key = $cache_manager->generate_key(
                $function_name,
                $function_args,
            );
            $cached_result = $cache_manager->get($cache_key);

            if ($cached_result !== false) {
                return $cached_result;
            }
        }

        $woo_actions = new WooCommerceActions();
        $function_result = "";

        try {
            switch ($function_name) {
                case "get_products":
                    $function_result = $woo_actions->get_products(
                        $function_args["search_query"],
                        $function_args["limit"] ?? 5,
                    );
                    $cache_manager->set($cache_key, $function_result, 300);
                    break;

                case "get_specialty_kits":
                    $function_result = $woo_actions->get_specialty_kits(
                        $function_args["kit_name"] ?? null,
                        $function_args["model_name"] ?? "Padrão",
                    );
                    $cache_manager->set($cache_key, $function_result, 300);
                    break;

                case "add_products_by_name":
                    try {
                        $function_result = $woo_actions->add_products_by_name(
                            $function_args["product_names"] ?? [],
                            $function_args["model_name"] ?? "Padrão",
                        );
                    } catch (\Exception $e) {
                        $function_result = json_encode(["status" => "error", "message" => $e->getMessage()]);
                    } catch (\Error $e) {
                        $function_result = json_encode(["status" => "error", "message" => $e->getMessage()]);
                    }
                    $cache_manager->invalidate_pattern("cart:");
                    break;

                case "add_to_cart":
                    $function_result = $woo_actions->add_to_cart(
                        (int) $function_args["product_id"],
                        isset($function_args["variation_id"])
                            ? (int) $function_args["variation_id"]
                            : 0,
                        1, // quantity sempre 1
                    );
                    $cache_manager->invalidate_pattern("cart:");
                    break;

                case "view_cart":
                    $function_result = $woo_actions->view_cart();
                    $cache_manager->set($cache_key, $function_result, 30);
                    break;

                case "remove_from_cart":
                    $function_result = $woo_actions->remove_from_cart(
                        $function_args["identifier"],
                    );
                    $cache_manager->invalidate_pattern("cart:");
                    break;

                case "clear_cart":
                    $function_result = $woo_actions->clear_cart();
                    $cache_manager->invalidate_pattern("cart:");
                    break;

                case "create_proposed_cart":
                    $proposal_manager = new ProposalManager();
                    $function_result = $proposal_manager->create_secure_proposal(
                        $function_args["products"],
                        get_current_user_id(),
                    );
                    break;

                case "add_multiple_products_to_cart":
                    // Verifica se recebeu produtos diretamente (de get_specialty_kits)
                    if (
                        isset($function_args["products"]) &&
                        is_array($function_args["products"])
                    ) {
                        $function_result = $woo_actions->add_multiple_products_to_cart(
                            $function_args["products"],
                        );
                    } elseif (isset($function_args["proposal_id"])) {
                        // Recebeu proposal_id (fluxo com create_proposed_cart)
                        $proposal_manager = new ProposalManager();
                        $products = $proposal_manager->get_proposal_items(
                            $function_args["proposal_id"],
                        );

                        if ($products) {
                            $function_result = $woo_actions->add_multiple_products_to_cart(
                                $products,
                            );
                        } else {
                            $function_result = json_encode([
                                "status" => "error",
                                "message" =>
                                    "Proposta não encontrada ou expirada.",
                            ]);
                        }
                    } else {
                        $function_result = json_encode([
                            "status" => "error",
                            "message" =>
                                "Parâmetros inválidos para adicionar produtos.",
                        ]);
                    }

                    $cache_manager->invalidate_pattern("cart:");
                    break;

                default:
                    $function_result = json_encode([
                        "status" => "error",
                        "message" =>
                            "Ferramenta desconhecida: " . $function_name,
                    ]);
            }

            if (
                in_array($function_name, [
                    "add_to_cart",
                    "remove_from_cart",
                    "add_multiple_products_to_cart",
                ])
            ) {
                $this->audit_logger->log("cart_action", [
                    "action" => $function_name,
                    "args" => $function_args,
                    "session" => $session_id,
                    "result" =>
                        json_decode($function_result, true)["status"] ??
                        "unknown",
                ]);
            }

            // Adiciona execução da ferramenta ao analytics
            $this->messenger->addToolExecution(
                $function_name,
                $function_args,
                $this->normalize_function_result($function_result),
            );
        } catch (\Exception $e) {
            $function_result = json_encode([
                "status" => "error",
                "message" => "Erro ao executar ação: " . $e->getMessage(),
            ]);
            $this->audit_logger->log_error("function_execution", $e);

            // Adiciona execução com erro ao analytics
            $this->messenger->addToolExecution(
                $function_name,
                $function_args,
                $this->normalize_function_result($function_result),
            );
        }

        return $function_result;
    }

    /**
     * Records token usage from Gemini API response.
     * 
     * @param array $api_response Response from Gemini API
     * @param string $iteration_type 'initial' | 'tool_response' | 'continuation'
     */
    private function capture_ai_usage_event(
        array $api_response,
        string $iteration_type = 'initial'
    ): void {
        if (
            empty($api_response["usageMetadata"]) ||
            !is_array($api_response["usageMetadata"])
        ) {
            return;
        }

        $this->messenger->recordTokenUsage(
            $api_response["usageMetadata"],
            $iteration_type
        );
    }

    private function build_gemini_request_payload(array $history): array
    {
        $contents = [];

        foreach ($history as $entry) {
            $role = $entry["sender_type"] === "user" ? "user" : "model";
            $parts = [];

            if ($entry["sender_type"] === "function_call") {
                $function_call_data = is_array($entry["message_content"])
                    ? $entry["message_content"]
                    : json_decode($entry["message_content"], true);
                // Garante que args seja sempre um objeto vazio {} e não array []
                if (
                    !isset($function_call_data["args"]) ||
                    (is_array($function_call_data["args"]) &&
                        empty($function_call_data["args"]))
                ) {
                    $function_call_data["args"] = (object) [];
                }
                $parts[] = [
                    "functionCall" => $function_call_data,
                ];
            } elseif ($entry["sender_type"] === "function_response") {
                $data = is_array($entry["message_content"])
                    ? $entry["message_content"]
                    : json_decode($entry["message_content"], true);
                
                // Garante que temos as chaves necessárias
                $func_name = $data["name"] ?? "unknown";
                $func_response = $data["response"] ?? $data["content"] ?? json_encode($data);
                
                $parts[] = [
                    "functionResponse" => [
                        "name" => $func_name,
                        "response" => ["content" => $func_response],
                    ],
                ];
                $role = "user"; // Gemini requer role 'user' para functionResponse
            } else {
                $parts[] = ["text" => $entry["message_content"]];
            }

            $contents[] = [
                "role" => $role,
                "parts" => $parts,
            ];
        }

        return [
            "contents" => $contents,
            "tools" => GeminiConfig::get_tools_definition(),
            "systemInstruction" => GeminiConfig::get_system_instruction(),
            "generationConfig" => [
                "temperature" => $this->model_config["temperature"],
                "topP" => $this->model_config["topP"],
                "topK" => $this->model_config["topK"],
                "maxOutputTokens" => $this->model_config["maxOutputTokens"],
            ],
        ];
    }

    private function call_gemini_api_with_retry(
        array $payload,
        int $max_retries = 3,
    ): array {
        $retry_count = 0;
        $last_exception = null;

        while ($retry_count < $max_retries) {
            try {
                $api_endpoint = GeminiConfig::get_api_endpoint(
                    $this->model_config["model"],
                    "generateContent",
                );

                $api_response = wp_remote_post($api_endpoint, [
                    "method" => "POST",
                    "headers" => [
                        "Content-Type" => "application/json",
                    ],
                    "body" => $this->encode_payload_to_json($payload),
                    "timeout" => 30,
                ]);

                if (is_wp_error($api_response)) {
                    throw new \Exception(
                        "Request failed: " . $api_response->get_error_message(),
                    );
                }

                $response_code = wp_remote_retrieve_response_code(
                    $api_response,
                );
                $response_body = wp_remote_retrieve_body($api_response);
                $decoded_body = json_decode($response_body, true);

                if ($response_code === 429) {
                    $retry_after =
                        wp_remote_retrieve_header(
                            $api_response,
                            "retry-after",
                        ) ?:
                        2;
                    sleep(min($retry_after, 5));
                    $retry_count++;
                    continue;
                }

                if ($response_code !== 200) {
                    $error_message =
                        $decoded_body["error"]["message"] ?? "Unknown error";
                    throw new \Exception(
                        "API Error (Code {$response_code}): {$error_message}",
                    );
                }

                if (empty($decoded_body["candidates"])) {
                    throw new \Exception("Invalid API response: no candidates");
                }

                return $decoded_body;
            } catch (\Exception $e) {
                $last_exception = $e;
                if ($retry_count < $max_retries - 1) {
                    $backoff = pow(2, $retry_count);
                    sleep(min($backoff, 10));
                }
                $retry_count++;
            }
        }

        throw $last_exception ?? new \Exception("Max retries exceeded");
    }

    private function get_user_identifier(string $session_id): string
    {
        $current_user_id = get_current_user_id();
        if (!empty($current_user_id)) {
            return (string) $current_user_id;
        }

        $hash_source = $session_id . "|" . wp_salt("fontalis_analytics");

        return substr(hash("sha256", $hash_source), 0, 16);
    }

    private function normalize_function_result(string $function_result): array
    {
        $decoded = json_decode($function_result, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return [
            "status" => "unknown",
            "raw" => mb_substr($function_result, 0, 500),
        ];
    }

    /**
     * Codifica o payload para JSON garantindo que objetos vazios sejam {} e não [].
     *
     * @param array $payload O payload a ser codificado.
     * @return string JSON string.
     */
    private function encode_payload_to_json(array $payload): string
    {
        // Percorre contents e corrige args vazios para serem objetos
        if (isset($payload["contents"])) {
            foreach ($payload["contents"] as &$content) {
                if (isset($content["parts"])) {
                    foreach ($content["parts"] as &$part) {
                        if (
                            isset($part["functionCall"]["args"]) &&
                            is_array($part["functionCall"]["args"]) &&
                            empty($part["functionCall"]["args"])
                        ) {
                            // Converte array vazio em objeto vazio
                            $part["functionCall"]["args"] = (object) [];
                        }
                    }
                }
            }
        }

        return json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    private function detect_device_info(): string
    {
        $agent = $_SERVER["HTTP_USER_AGENT"] ?? "";
        $device_type = wp_is_mobile() ? "Mobile" : "Desktop";
        $platform = "Unknown";

        $platform_map = [
            "Windows" => "Windows",
            "Macintosh" => "macOS",
            "Linux" => "Linux",
            "Android" => "Android",
            "iPhone" => "iOS",
            "iPad" => "iOS",
        ];

        foreach ($platform_map as $needle => $label) {
            if (stripos($agent, $needle) !== false) {
                $platform = $label;
                break;
            }
        }

        return trim($device_type . " (" . $platform . ")");
    }

    private function finalize_interaction(?string $ai_message): void
    {
        if (!$this->interaction_builder instanceof InteractionPayloadBuilder) {
            return;
        }

        $this->interaction_builder->setAiOutput(
            $ai_message,
            $this->current_timestamp(),
        );
        $this->interaction_builder->dispatch($this->messenger);
        $this->interaction_builder = null;
    }

    private function record_tool_execution(
        string $function_name,
        array $function_args,
        string $function_result,
    ): void {
        if (!$this->interaction_builder instanceof InteractionPayloadBuilder) {
            return;
        }

        $this->interaction_builder->addToolExecution(
            $function_name,
            $function_args,
            $function_result,
        );
    }

    private function current_timestamp(): string
    {
        return wp_date(DATE_ATOM);
    }
}
