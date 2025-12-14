<?php
/**
 * Chat Logger.
 *
 * Gerencia o log de mensagens do chat.
 *
 * @package FontalisChatBot
 * @subpackage Backend\Modules\Logging
 */

namespace Epixel\FontalisChatBot\Backend\Modules\Logging;

// Se este arquivo for chamado diretamente, aborte.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Classe ChatLogger.
 *
 * Responsável por registrar todas as mensagens de chat em arquivo de log.
 */
class ChatLogger {

	/**
	 * Diretório base do plugin.
	 *
	 * @var string
	 */
	private $plugin_dir;

	/**
	 * Diretório de logs.
	 *
	 * @var string
	 */
	private $log_dir;

	/**
	 * Construtor.
	 *
	 * @param string $plugin_dir Diretório base do plugin.
	 */
	public function __construct( string $plugin_dir ) {
		$this->plugin_dir = $plugin_dir;
		$this->log_dir = $plugin_dir . 'logs/chat/';
		
		// Cria o diretório de logs se não existir
		if ( ! file_exists( $this->log_dir ) ) {
			wp_mkdir_p( $this->log_dir );
			
			// Adiciona arquivo .htaccess para proteger os logs
			$htaccess_file = $this->log_dir . '.htaccess';
			if ( ! file_exists( $htaccess_file ) ) {
				file_put_contents( $htaccess_file, "Deny from all\n" );
			}
		}
	}

	/**
	 * Registra uma mensagem do chat.
	 *
	 * @param string $chat_id O ID do chat.
	 * @param string $user_id O ID do usuário.
	 * @param string $user_name O nome do usuário.
	 * @param string $sender_type O tipo de remetente ('user' ou 'ai').
	 * @param string $message A mensagem.
	 * @return bool True se o log foi gravado com sucesso.
	 */
	public function logMessage( string $chat_id, string $user_id, string $user_name, string $sender_type, string $message ): bool {
		// Prepara os dados do log
		$log_entry = array(
			'timestamp'   => current_time( 'mysql' ),
			'chat_id'     => $chat_id,
			'user_id'     => $user_id,
			'user_name'   => $user_name,
			'sender_type' => $sender_type,
			'message'     => $message,
		);

		// Nome do arquivo baseado na data (um arquivo por dia)
		$log_filename = 'chat-' . date( 'Y-m-d' ) . '.log';
		$log_filepath = $this->log_dir . $log_filename;

		// Formata a entrada de log
		$log_line = sprintf(
			"[%s] CHAT_ID:%s | USER:%s(%s) | TYPE:%s | MSG:%s\n",
			$log_entry['timestamp'],
			$log_entry['chat_id'],
			$log_entry['user_name'],
			$log_entry['user_id'],
			$log_entry['sender_type'],
			wp_strip_all_tags( $log_entry['message'] )
		);

		// Grava no arquivo de log
		$result = file_put_contents( $log_filepath, $log_line, FILE_APPEND | LOCK_EX );

		if ( false === $result ) {
			error_log( 'Fontalis ChatBot Error: Falha ao gravar log de chat no arquivo: ' . $log_filepath );
			return false;
		}

		return true;
	}

	/**
	 * Obtém os logs de uma sessão de chat específica.
	 *
	 * @param string $chat_id O ID do chat.
	 * @param string $date Data no formato Y-m-d (opcional, usa hoje se não fornecido).
	 * @return array Array com as linhas do log.
	 */
	public function getChatLogs( string $chat_id, string $date = '' ): array {
		if ( empty( $date ) ) {
			$date = date( 'Y-m-d' );
		}

		$log_filename = 'chat-' . $date . '.log';
		$log_filepath = $this->log_dir . $log_filename;

		if ( ! file_exists( $log_filepath ) ) {
			return array();
		}

		$logs = file( $log_filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$filtered_logs = array();

		// Filtra logs apenas desta sessão de chat
		foreach ( $logs as $log_line ) {
			if ( strpos( $log_line, 'CHAT_ID:' . $chat_id ) !== false ) {
				$filtered_logs[] = $log_line;
			}
		}

		return $filtered_logs;
	}

	/**
	 * Remove logs antigos (mais de 30 dias).
	 *
	 * @return int Número de arquivos removidos.
	 */
	public function cleanOldLogs(): int {
		$removed = 0;
		$files = glob( $this->log_dir . 'chat-*.log' );

		if ( false === $files ) {
			return 0;
		}

		$cutoff_time = strtotime( '-30 days' );

		foreach ( $files as $file ) {
			if ( filemtime( $file ) < $cutoff_time ) {
				if ( wp_delete_file( $file ) ) {
					$removed++;
				}
			}
		}

		return $removed;
	}
}