<?php

/**
 * Logger.
 *
 * Fornece um sistema de logging para o plugin.
 *
 * @package FontalisChatBot
 * @subpackage Utils
 */

namespace Epixel\FontalisChatBot\Utils;

use Fontalis\Chatbot\Config\LoggingSettings;

require_once __DIR__ . '/../config/constants.php'; // Inclui as constantes do plugin.

// Se este arquivo for chamado diretamente, aborte.
if (! defined('WPINC')) {
	die;
}

/**
 * Classe Logger.
 */
class Logger
{

	private static $log_level = 'DEBUG'; // Níveis: DEBUG, INFO, WARNING, ERROR, CRITICAL
	private static $logging_settings;

	/**
	 * Define o nível de log.
	 *
	 * @param string $level O nível de log (DEBUG, INFO, WARNING, ERROR, CRITICAL).
	 */
	public static function set_log_level(string $level): void
	{
		$valid_levels = array('DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL');
		if (in_array(strtoupper($level), $valid_levels, true)) {
			self::$log_level = strtoupper($level);
		}
	}

	/**
	 * Inicializa o Logger com as configurações de log.
	 *
	 * @param LoggingSettings $settings Instância de LoggingSettings.
	 */
	public static function init(LoggingSettings $settings): void
	{
		self::$logging_settings = $settings;
	}

	/**
	 * Loga uma mensagem de debug.
	 *
	 * @param string $message A mensagem a ser logada.
	 * @param array  $context Contexto adicional (opcional).
	 * @param string $type O tipo de log (Frontend, Backend, Session).
	 */
	public static function debug(string $message, array $context = array(), string $type = ''): void
	{
		self::log('DEBUG', $message, $context, $type);
	}

	/**
	 * Loga uma mensagem informativa.
	 *
	 * @param string $message A mensagem a ser logada.
	 * @param array  $context Contexto adicional (opcional).
	 * @param string $type O tipo de log (Frontend, Backend, Session).
	 */
	public static function info(string $message, array $context = array(), string $type = ''): void
	{
		self::log('INFO', $message, $context, $type);
	}

	/**
	 * Loga uma mensagem de aviso.
	 *
	 * @param string $message A mensagem a ser logada.
	 * @param array  $context Contexto adicional (opcional).
	 * @param string $type O tipo de log (Frontend, Backend, Session).
	 */
	public static function warning(string $message, array $context = array(), string $type = ''): void
	{
		self::log('WARNING', $message, $context, $type);
	}

	/**
	 * Loga uma mensagem de erro.
	 *
	 * @param string $message A mensagem a ser logada.
	 * @param array  $context Contexto adicional (opcional).
	 */
	public static function error(string $message, array $context = array()): void
	{
		self::log('ERROR', $message, $context);
	}

	/**
	 * Loga uma mensagem crítica.
	 *
	 * @param string $message A mensagem a ser logada.
	 * @param array  $context Contexto adicional (opcional).
	 */
	public static function critical(string $message, array $context = array()): void
	{
		self::log('CRITICAL', $message, $context);
	}

	/**
	 * Método principal de log.
	 *
	 * @param string $level O nível da mensagem (DEBUG, INFO, etc.).
	 * @param string $message A mensagem a ser logada.
	 * @param array  $context Contexto adicional.
	 * @param string $type O tipo de log (Frontend, Backend, Session).
	 */
	private static function log(string $level, string $message, array $context = array(), string $type = ''): void
	{
		// Logs de erro e crítico são sempre incondicionais.
		if (! in_array(strtoupper($level), array('ERROR', 'CRITICAL'), true)) {
			if (self::$logging_settings instanceof LoggingSettings) {
				switch (strtoupper($type)) {
					case 'FRONTEND':
						if (! self::$logging_settings->isFrontendLoggingEnabled()) {
							return;
						}
						break;
					case 'BACKEND':
						if (! self::$logging_settings->isBackendLoggingEnabled()) {
							return;
						}
						break;
					case 'SESSION':
						if (! self::$logging_settings->isSessionLoggingEnabled()) {
							return;
						}
						break;
					default:
						// Se nenhum tipo específico for fornecido, ou um tipo inválido,
						// e não for um log de erro/crítico, não logar por padrão.
						// Ou você pode decidir logar se não houver um tipo específico.
						// Por enquanto, vamos retornar para não logar.
						return;
				}
			} else {
				// Se LoggingSettings não estiver inicializado, não logar nada além de erros.
				return;
			}
		}

		$level_priority = array(
			'DEBUG'    => 100,
			'INFO'     => 200,
			'WARNING'  => 300,
			'ERROR'    => 400,
			'CRITICAL' => 500,
		);

		if ($level_priority[strtoupper($level)] < $level_priority[self::$log_level]) {
			return; // Não loga se o nível da mensagem for menor que o nível configurado.
		}

		$formatted_message = sprintf(
			"[%s] FontalisChatBot %s: %s",
			gmdate('Y-m-d H:i:s') . ' UTC', // Data e hora em UTC
			strtoupper($level),
			$message
		);

		if (! empty($context)) {
			// Sanitizar contexto para evitar logar dados muito sensíveis diretamente.
			// Esta é uma sanitização básica, pode ser necessário algo mais robusto.
			$sanitized_context = array();
			foreach ($context as $key => $value) {
				if (is_string($value) && (stripos($key, 'key') !== false || stripos($key, 'token') !== false || stripos($key, 'password') !== false)) {
					$sanitized_context[$key] = '********';
				} elseif (is_string($value) && mb_strlen($value) > 256) {
					$sanitized_context[$key] = mb_substr($value, 0, 256) . '... [TRUNCATED]';
				} else {
					$sanitized_context[$key] = $value;
				}
			}
			$formatted_message .= ' ' . wp_json_encode($sanitized_context);
		}

		$log_file_path = FONTALIS_CHATBOT_LOG_DIR;

		if (in_array(strtoupper($level), array('ERROR', 'CRITICAL'), true)) {
			$log_file_path .= 'errors/errors.log';
		} else {
			$log_file_path .= 'chat/chat.log';
		}

		// Garante que o diretório de log exista.
		$log_dir = dirname($log_file_path);
		if (! is_dir($log_dir)) {
			mkdir($log_dir, 0755, true);
		}

		// Escreve a mensagem no arquivo de log.
		file_put_contents($log_file_path, $formatted_message . PHP_EOL, FILE_APPEND);
	}
}
