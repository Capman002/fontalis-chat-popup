<?php
/**
 * JsonLoader.
 *
 * Responsável por carregar e validar arquivos JSON de configuração de forma segura.
 *
 * @package FontalisChatBot
 * @subpackage Core
 */

namespace Epixel\FontalisChatBot\Core;

// Se este arquivo for chamado diretamente, aborte.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Classe JsonLoader.
 *
 * Carrega e valida arquivos JSON.
 */
class JsonLoader {

	/**
	 * Carrega e decodifica um arquivo JSON.
	 *
	 * @param string $file_name O nome do arquivo JSON (sem o caminho do diretório).
	 * @param bool   $is_credentials_file Indica se o arquivo é de credenciais (para tratamento especial, se necessário).
	 * @return array|null Os dados decodificados do JSON como um array associativo, ou null em caso de erro.
	 */
	public static function load_json_file( string $file_name, bool $is_credentials_file = false ): ?array {
		// Os arquivos JSON estão na raiz do plugin, conforme a estrutura inicial.
		$file_path = FONTALIS_CHATBOT_PLUGIN_DIR . $file_name;

		if ( ! file_exists( $file_path ) ) {
			// Logar erro: arquivo não encontrado
			// Logger::log_error( "Arquivo JSON não encontrado: {$file_path}" ); // Descomentar quando o Logger estiver pronto
			error_log( "Fontalis ChatBot Error: Arquivo JSON não encontrado: {$file_path}" ); // Log temporário
			return null;
		}

		if ( ! is_readable( $file_path ) ) {
			// Logar erro: arquivo não pode ser lido
			// Logger::log_error( "Arquivo JSON não pode ser lido: {$file_path}" );
			error_log( "Fontalis ChatBot Error: Arquivo JSON não pode ser lido: {$file_path}" );
			return null;
		}

		// Validação de segurança básica: garantir que o arquivo é realmente um .json
        // e não está tentando acessar algo fora do diretório esperado (embora $file_name seja só o nome).
        if ( pathinfo( $file_path, PATHINFO_EXTENSION ) !== 'json' ) {
            // Logger::log_error( "Tentativa de carregar arquivo não JSON: {$file_path}" );
            error_log( "Fontalis ChatBot Error: Tentativa de carregar arquivo não JSON: {$file_path}" );
            return null;
        }


		$content = file_get_contents( $file_path );

		if ( false === $content ) {
			// Logar erro: falha ao ler o conteúdo do arquivo
			// Logger::log_error( "Falha ao ler o conteúdo do arquivo JSON: {$file_path}" );
			error_log( "Fontalis ChatBot Error: Falha ao ler o conteúdo do arquivo JSON: {$file_path}" );
			return null;
		}

		$decoded_json = json_decode( $content, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			// Logar erro: JSON inválido
			// Logger::log_error( "Erro ao decodificar JSON do arquivo {$file_path}: " . json_last_error_msg() );
			error_log( "Fontalis ChatBot Error: Erro ao decodificar JSON do arquivo {$file_path}: " . json_last_error_msg() );
			return null;
		}

		return $decoded_json;
	}


}