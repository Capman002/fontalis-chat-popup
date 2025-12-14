<?php
/**
 * Constantes do Plugin Fontalis ChatBot.
 *
 * Este arquivo define constantes específicas para a funcionalidade do plugin.
 *
 * @package FontalisChatBot
 * @subpackage Config
 */

// Se este arquivo for chamado diretamente, aborte.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Constantes de configuração do plugin
// A chave API do Gemini é armazenada de forma segura no banco de dados WordPress
// usando a classe GeminiConfig

// Chave para o nonce AJAX (usado em wp-hooks.php e popup.js)
if ( ! defined( 'FONTALIS_CHATBOT_NONCE_ACTION' ) ) {
    define( 'FONTALIS_CHATBOT_NONCE_ACTION', 'fontalis_chat_nonce_action' );
}

// Nome do campo do nonce que será enviado pelo AJAX
if ( ! defined( 'FONTALIS_CHATBOT_NONCE_FIELD' ) ) {
    define( 'FONTALIS_CHATBOT_NONCE_FIELD', 'fontalis_chat_security_nonce' );
}

// Limite de taxa: número de requisições permitidas
if ( ! defined( 'FONTALIS_CHATBOT_RATE_LIMIT_REQUESTS' ) ) {
    define( 'FONTALIS_CHATBOT_RATE_LIMIT_REQUESTS', 10 ); // Ex: 10 requisições
}

// Limite de taxa: período em segundos
if ( ! defined( 'FONTALIS_CHATBOT_RATE_LIMIT_PERIOD' ) ) {
    define( 'FONTALIS_CHATBOT_RATE_LIMIT_PERIOD', 60 ); // Ex: por 60 segundos
}

// Prefixo para chaves de transient
if ( ! defined( 'FONTALIS_CHATBOT_TRANSIENT_PREFIX' ) ) {
    define( 'FONTALIS_CHATBOT_TRANSIENT_PREFIX', 'fcb_' );
}

// Máximo de interações (usuário + bot) para guardar no histórico da conversa
if ( ! defined( 'FONTALIS_CHATBOT_CONVERSATION_HISTORY_LENGTH' ) ) {
    define( 'FONTALIS_CHATBOT_CONVERSATION_HISTORY_LENGTH', 10 ); // 5 perguntas do usuário e 5 respostas do bot
}

// Diretório base para os arquivos de log
if ( ! defined( 'FONTALIS_CHATBOT_LOG_DIR' ) ) {
    define( 'FONTALIS_CHATBOT_LOG_DIR', __DIR__ . '/../../log/' );
}
// Configurações do banco de dados
define('FONTALIS_CHATBOT_DB_VERSION', '1.0.0');
define('FONTALIS_CHATBOT_RETENTION_DAYS', 90);
define('FONTALIS_CHATBOT_ENABLE_FILE_BACKUP', false);