<?php
/**
 * Validator.
 *
 * Fornece métodos para validar e sanitizar dados de entrada.
 *
 * @package FontalisChatBot
 * @subpackage Utils
 */

namespace Epixel\FontalisChatBot\Utils;

// Se este arquivo for chamado diretamente, aborte.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Classe Validator.
 */
class Validator {

	/**
	 * Valida e sanitiza uma mensagem de chat do usuário.
	 *
	 * @param mixed $message A mensagem do usuário (espera-se uma string).
	 * @return string|null A mensagem sanitizada ou null se inválida.
	 */
	public static function sanitize_chat_message( $message ): ?string {
		if ( ! is_string( $message ) ) {
			return null;
		}

		// Remove espaços em branco extras no início e no fim.
		$message = trim( $message );

		// Verifica se a mensagem não está vazia após o trim.
		if ( empty( $message ) ) {
			return null;
		}

		// Limita o tamanho da mensagem para evitar payloads excessivos.
		// 2000 caracteres deve ser suficiente para uma mensagem de chat.
		$max_length = 2000;
		if ( mb_strlen( $message ) > $max_length ) {
			$message = mb_substr( $message, 0, $max_length );
		}

		// Sanitiza o texto para remover HTML potencialmente malicioso,
		// mas permite algumas tags básicas se necessário (neste caso, nenhuma).
		// wp_kses_post() é bom para conteúdo de posts, mas para chat simples,
		// sanitize_textarea_field ou uma combinação mais restrita pode ser melhor.
		// sanitize_text_field remove todas as tags HTML.
		$sanitized_message = sanitize_text_field( $message );
		
		// Se após a sanitização a mensagem ficar vazia (ex: só tinha HTML), retorna null.
		if ( empty( $sanitized_message ) ) {
			return null;
		}

		return $sanitized_message;
	}

	/**
	 * Valida um ID de sessão.
	 *
	 * Espera-se um ID alfanumérico, possivelmente com hífens ou underscores.
	 *
	 * @param mixed $session_id O ID da sessão.
	 * @return string|null O ID da sessão validado ou null se inválido.
	 */
	public static function validate_session_id( $session_id ): ?string {
		if ( ! is_string( $session_id ) ) {
			return null;
		}

		// Remove espaços em branco extras.
		$session_id = trim( $session_id );

		// Verifica se não está vazio e se corresponde a um padrão esperado.
		// Ex: 32 caracteres alfanuméricos, ou um UUID.
		// Para simplificar, vamos permitir alfanuméricos, hífens e underscores, com um limite de tamanho.
		if ( empty( $session_id ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,128}$/', $session_id ) ) {
			return null;
		}

		return $session_id;
	}

	/**
     * Valida um nonce do WordPress.
     *
     * @param string $nonce_value O valor do nonce recebido.
     * @param string $action A ação do nonce que foi registrada.
     * @return bool True se o nonce for válido, false caso contrário.
     */
    public static function verify_nonce( string $nonce_value, string $action ): bool {
        return wp_verify_nonce( $nonce_value, $action ) !== false;
    }
}