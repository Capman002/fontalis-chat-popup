<?php

namespace Epixel\FontalisChatBot\Backend\Modules\Analytics;

use Epixel\FontalisChatBot\Core\GeminiConfig;

if (!defined('ABSPATH')) {
    exit;
}

class Messenger
{
    private $endpoint = '';
    private $secret = null;
    private $conversation_id = null;  // ID da conversa (permanece igual durante toda conversa)
    private $user_id = null;
    private $user_context = [];
    private $history = [];
    private $total_input_tokens = 0;
    private $total_output_tokens = 0;
    private $token_breakdown = [];

    private const INPUT_COST_PER_MILLION = 0.075;
    private const OUTPUT_COST_PER_MILLION = 0.30;

    public function __construct()
    {
        $this->endpoint = trim(GeminiConfig::get_messenger_endpoint());
        $this->secret = GeminiConfig::get_messenger_secret();
    }

    public function setUserContext($context)
    {
        $this->user_context = is_array($context) ? $context : [];
    }

    public function setSession($conversation_id, $user_id)
    {
        $this->conversation_id = $conversation_id;
        $this->user_id = $user_id;
    }

    /**
     * Gera um ID único para cada interação.
     */
    private function generateInteractionId()
    {
        return wp_generate_uuid4();
    }

    public function addUserMessage($message)
    {
        $this->history[] = [
            'role' => 'user',
            'content' => $message,
            'timestamp' => current_time('mysql', true),
        ];
    }

    public function addAiResponse($message)
    {
        $this->history[] = [
            'role' => 'assistant',
            'content' => $message,
            'timestamp' => current_time('mysql', true),
        ];
    }

    public function addToolExecution($tool, $args, $result)
    {
        $this->history[] = [
            'role' => 'tool',
            'tool' => $tool,
            'args' => $args,
            'result' => $result,
            'timestamp' => current_time('mysql', true),
        ];
    }

    public function recordTokenUsage($usage, $iteration_type = 'initial')
    {
        $input = $usage['promptTokenCount'] ?? 0;
        $output = $usage['candidatesTokenCount'] ?? 0;
        $this->total_input_tokens += $input;
        $this->total_output_tokens += $output;
        $this->token_breakdown[] = [
            'type' => $iteration_type,
            'input' => $input,
            'output' => $output,
            'timestamp' => current_time('mysql', true),
        ];
    }

    private function calculateTotalCost()
    {
        $input_cost = ($this->total_input_tokens / 1000000) * self::INPUT_COST_PER_MILLION;
        $output_cost = ($this->total_output_tokens / 1000000) * self::OUTPUT_COST_PER_MILLION;
        return round($input_cost + $output_cost, 8);
    }

    public function dispatch($event, $payload)
    {
        // Legacy - compatibilidade
    }

    public function flush()
    {
        if (empty($this->endpoint) || empty($this->secret)) {
            return;
        }
        if (empty($this->history) && $this->total_input_tokens === 0) {
            $this->clear();
            return;
        }
        $total_tokens = $this->total_input_tokens + $this->total_output_tokens;
        $body = [
            'conversation_id' => $this->conversation_id,
            'interaction_id' => $this->generateInteractionId(),
            'user_id' => $this->user_id,
            'user_context' => $this->user_context,
            'history' => $this->history,
            'total_cost' => $this->calculateTotalCost(),
            'total_tokens' => $total_tokens,
            'tokens' => [
                'input' => $this->total_input_tokens,
                'output' => $this->total_output_tokens,
                'total' => $total_tokens,
                'breakdown' => $this->token_breakdown,
            ],
        ];
        $encoded_body = wp_json_encode($body);
        if ($encoded_body === false) {
            $this->clear();
            return;
        }
        wp_remote_post($this->endpoint, [
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Fontalis-Analytics-Key' => $this->secret,
            ],
            'body' => $encoded_body,
            'timeout' => 5,
            'blocking' => false,
        ]);
        $this->clear();
    }

    private function clear()
    {
        $this->conversation_id = null;
        $this->user_id = null;
        $this->user_context = [];
        $this->history = [];
        $this->total_input_tokens = 0;
        $this->total_output_tokens = 0;
        $this->token_breakdown = [];
    }
}