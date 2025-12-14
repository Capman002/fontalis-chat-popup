<?php

namespace Epixel\FontalisChatBot\Backend\Modules\Analytics;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds the analytics payload for a full interaction (user turn + AI turn).
 */
class InteractionPayloadBuilder
{
    private const TIER_THRESHOLD_TOKENS = 200000;
    private const INPUT_RATES = [
        'low' => 2.00,
        'high' => 4.00,
    ];
    private const OUTPUT_RATES = [
        'low' => 12.00,
        'high' => 18.00,
    ];

    private string $conversationId;
    private array $context;
    private array $interaction = [
        'user_input' => null,
        'user_timestamp' => null,
        'ai_output' => null,
        'ai_timestamp' => null,
        'model_info' => [],
        'usage' => [
            'cost' => null,
            'tier' => null,
            'tokens' => [
                'in' => null,
                'out' => null,
                'total' => null,
            ],
        ],
        'performance' => [
            'ttft' => null,
            'latency' => null,
        ],
        'tools' => [],
    ];
    private ?string $payloadTimestamp = null;

    public function __construct(string $conversationId, array $context, array $modelInfo)
    {
        $this->conversationId = $conversationId;
        $this->context = $context;
        $this->interaction['model_info'] = $modelInfo;
    }

    public function setUserInput(string $message, string $timestamp): void
    {
        $this->interaction['user_input'] = $message;
        $this->interaction['user_timestamp'] = $timestamp;
        if ($this->payloadTimestamp === null) {
            $this->payloadTimestamp = $timestamp;
        }
    }

    public function setAiOutput(?string $message, string $timestamp): void
    {
        $this->interaction['ai_output'] = $message;
        $this->interaction['ai_timestamp'] = $timestamp;
        $this->payloadTimestamp = $timestamp;
    }

    public function setUsage(array $usageMetadata, ?float $cost = null): void
    {
        $input = $usageMetadata['promptTokenCount'] ?? null;
        $output = $usageMetadata['candidatesTokenCount'] ?? null;
        $total = $usageMetadata['totalTokenCount'] ?? null;

        if ($total === null && $input !== null && $output !== null) {
            $total = $input + $output;
        }

        $this->interaction['usage']['tokens'] = [
            'in' => $input,
            'out' => $output,
            'total' => $total,
        ];

        $pricingTier = $this->determinePricingTier($total);
        $calculatedCost = $this->calculateUsageCost($pricingTier, $input, $output);

        if ($calculatedCost !== null) {
            $this->interaction['usage']['cost'] = $calculatedCost;
        } elseif ($cost !== null) {
            $this->interaction['usage']['cost'] = $cost;
        }

        $this->interaction['usage']['tier'] = $pricingTier;
    }

    public function setPerformance(?float $ttft, ?float $latency): void
    {
        $this->interaction['performance'] = [
            'ttft' => $ttft,
            'latency' => $latency,
        ];
    }

    public function addToolExecution(string $name, array $args, $result): void
    {
        $normalizedResult = $this->normalizeResult($result);

        $this->interaction['tools'][] = [
            'name' => $name,
            'args' => $args,
            'result' => $normalizedResult,
        ];
    }

    public function dispatch(Messenger $messenger): void
    {
        if (empty($this->interaction['user_input']) && empty($this->interaction['ai_output'])) {
            return;
        }

        $payload = [
            'conversation_id' => $this->conversationId,
            'timestamp' => $this->payloadTimestamp ?? wp_date(DATE_ATOM),
            'context' => $this->context,
            'interaction' => $this->interaction,
        ];

        $messenger->dispatch('chat_turn', $payload);
    }

    private function normalizeResult($result): array
    {
        if (is_array($result)) {
            return $result;
        }

        if (is_string($result)) {
            $decoded = json_decode($result, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }

            return [
                'raw' => mb_substr($result, 0, 500),
            ];
        }

        return ['raw' => $result];
    }

    private function determinePricingTier(?int $totalTokens): string
    {
        if ($totalTokens !== null && $totalTokens > self::TIER_THRESHOLD_TOKENS) {
            return 'high';
        }

        return 'low';
    }

    private function calculateUsageCost(string $tier, ?int $inputTokens, ?int $outputTokens): ?float
    {
        if ($inputTokens === null && $outputTokens === null) {
            return null;
        }

        $cost = 0.0;

        if ($inputTokens !== null) {
            $cost += ($inputTokens / 1_000_000) * self::INPUT_RATES[$tier];
        }

        if ($outputTokens !== null) {
            $cost += ($outputTokens / 1_000_000) * self::OUTPUT_RATES[$tier];
        }

        return round($cost, 6);
    }
}
