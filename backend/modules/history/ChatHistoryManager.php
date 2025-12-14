<?php

namespace Epixel\FontalisChatBot\Backend\Modules\History;

/**
 * Gerencia o histórico de conversas do chatbot.
 */
class ChatHistoryManager
{
    private $table_name;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'fontalis_chat_conversations';
    }

    /**
     * Obtém o histórico completo de uma sessão.
     */
    public function get_session_history(string $session_id, ?int $user_id = null): array
    {
        global $wpdb;

        $query = $wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE session_id = %s ORDER BY timestamp ASC",
            $session_id
        );

        $results = $wpdb->get_results($query, ARRAY_A);
        $history = [];

        foreach ($results as $row) {
            $content = $row['message'];

            // Decodifica JSON se necessário
            if (in_array($row['sender'], ['function_call', 'function_response'])) {
                $decoded = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $content = $decoded;
                }
            }

            $history[] = [
                'sender_type' => $row['sender'],
                'message_content' => $content,
                'timestamp' => $row['timestamp']
            ];
        }

        return $history;
    }

    /**
     * Obtém o histórico de conversas de um usuário.
     */
    public function get_user_history(int $user_id, int $limit = 10, int $offset = 0): array
    {
        global $wpdb;

        // Busca sessões distintas do usuário
        $sessions_query = $wpdb->prepare(
            "SELECT DISTINCT session_id FROM {$this->table_name}
            WHERE user_id = %d
            ORDER BY timestamp DESC
            LIMIT %d OFFSET %d",
            $user_id,
            $limit,
            $offset
        );

        $session_ids = $wpdb->get_col($sessions_query);

        if (empty($session_ids)) {
            return [];
        }

        // Busca mensagens das sessões
        $placeholders = implode(',', array_fill(0, count($session_ids), '%s'));
        $messages_query = $wpdb->prepare(
            "SELECT * FROM {$this->table_name}
            WHERE session_id IN ($placeholders)
            ORDER BY timestamp ASC",
            ...$session_ids
        );

        $results = $wpdb->get_results($messages_query, ARRAY_A);
        $grouped = [];

        foreach ($results as $row) {
            $sid = $row['session_id'];

            if (!isset($grouped[$sid])) {
                $grouped[$sid] = [];
            }

            $content = $row['message'];

            // Formata mensagens de função para exibição amigável
            if ($row['sender'] === 'function_call') {
                $decoded = json_decode($content, true);
                $func_name = $decoded['name'] ?? 'Função';
                $content = '⚙️ ' . $func_name;
            } elseif ($row['sender'] === 'function_response') {
                continue; // Não mostra responses de função no histórico visual
            }

            $grouped[$sid][] = [
                'sender_type' => $row['sender'],
                'message_content' => $content,
                'timestamp' => $row['timestamp']
            ];
        }

        return $grouped;
    }

    /**
     * Salva mensagem do usuário.
     */
    public function save_user_message(string $session_id, string $message): bool
    {
        return $this->save_message($session_id, $message, 'user');
    }

    /**
     * Salva resposta da IA.
     */
    public function save_ai_response(string $session_id, string $message): bool
    {
        return $this->save_message($session_id, $message, 'ai');
    }

    /**
     * Salva chamada de função.
     */
    public function save_function_call(string $session_id, string $message, array $function_call): bool
    {
        $content = json_encode($function_call);
        return $this->save_message($session_id, $content, 'function_call');
    }

    /**
     * Salva resposta de função.
     */
    public function save_function_response(string $session_id, string $function_name, $response): bool
    {
        $response_content = is_string($response) ? $response : json_encode($response);

        $content = json_encode([
            'name' => $function_name,
            'content' => $response_content
        ]);

        return $this->save_message($session_id, $content, 'function_response');
    }

    /**
     * Método privado para salvar mensagem.
     */
    private function save_message(string $session_id, string $message, string $sender): bool
    {
        global $wpdb;

        $user_id = get_current_user_id();

        $data = [
            'session_id' => $session_id,
            'user_id' => $user_id ?: null,
            'message' => $message,
            'sender' => $sender,
            'timestamp' => current_time('mysql')
        ];

        $format = ['%s', '%d', '%s', '%s', '%s'];

        $result = $wpdb->insert($this->table_name, $data, $format);

        if ($result === false) {
            error_log("Fontalis ChatBot Error: Falha ao salvar mensagem no histórico. DB Error: " . $wpdb->last_error);
            return false;
        }

        return true;
    }
}
