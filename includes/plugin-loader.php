<?php
/**
 * Plugin Loader.
 *
 * Responsável por carregar dependências, registrar autoloader e inicializar o plugin.
 *
 * @package FontalisChatBot
 * @subpackage Includes
 */

namespace Epixel\FontalisChatBot\Includes;

// Se este arquivo for chamado diretamente, aborte.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Classe Plugin_Loader.
 *
 * Gerencia o carregamento e a inicialização do plugin.
 */
class Plugin_Loader {

	/**
	 * A única instância da classe.
	 *
	 * @var Plugin_Loader|null
	 */
	private static $instance = null;

	/**
	 * Construtor privado para o padrão Singleton.
	 */
	private function __construct() {
		$this->setup_constants();
		$this->load_dependencies();
		$this->register_autoloader();
		$this->init_hooks();
	}

	/**
	 * Obtém a instância da classe usando o padrão Singleton.
	 *
	 * @return Plugin_Loader
	 */
	public static function get_instance(): Plugin_Loader {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Define as constantes do plugin.
	 */
	private function setup_constants(): void {
		// Constantes já definidas em fontalis-chatbot.php
		// FONTALIS_CHATBOT_PLUGIN_DIR
		// FONTALIS_CHATBOT_PLUGIN_URL
		// FONTALIS_CHATBOT_VERSION

		// Caminho para o diretório 'backend'
		if ( ! defined( 'FONTALIS_CHATBOT_BACKEND_DIR' ) ) {
			define( 'FONTALIS_CHATBOT_BACKEND_DIR', FONTALIS_CHATBOT_PLUGIN_DIR . 'backend/' );
		}

		// Caminho para o diretório 'includes'
		if ( ! defined( 'FONTALIS_CHATBOT_INCLUDES_DIR' ) ) {
			define( 'FONTALIS_CHATBOT_INCLUDES_DIR', FONTALIS_CHATBOT_PLUGIN_DIR . 'includes/' );
		}

        // Caminho para o diretório 'config'
		if ( ! defined( 'FONTALIS_CHATBOT_CONFIG_DIR' ) ) {
			define( 'FONTALIS_CHATBOT_CONFIG_DIR', FONTALIS_CHATBOT_BACKEND_DIR . 'config/' );
		}
	}

	/**
	 * Carrega os arquivos de dependência.
	 */
	private function load_dependencies(): void {
		// Carrega arquivos de configuração que não são classes ou que precisam ser carregados antes do autoloader.
		require_once FONTALIS_CHATBOT_CONFIG_DIR . 'constants.php';
		require_once FONTALIS_CHATBOT_CONFIG_DIR . 'security.php';
		// wp-hooks.php contém a classe WP_Hooks que é carregada pelo autoloader, mas também registra os hooks.
		// É seguro manter o require_once aqui se WP_Hooks for a única classe no arquivo e for necessária para registrar os hooks.
	}

	/**
	 * Registra o autoloader PSR-4.
	 */
	private function register_autoloader(): void {
		spl_autoload_register( array( $this, 'autoload_psr4' ) );
	}

	/**
	 * Lógica do autoloader PSR-4.
	 *
	 * @param string $class O nome completo da classe.
	 */
	public function autoload_psr4( string $class ): void {
		// error_log("Fontalis Autoloader: Tentando carregar a classe: " . $class);

		$prefixes_to_check = [
			'Epixel\\FontalisChatBot\\' => FONTALIS_CHATBOT_PLUGIN_DIR,
			'Fontalis\\Chatbot\\'      => FONTALIS_CHATBOT_PLUGIN_DIR,
		];

		$plugin_root_dir = '';
		$matched_prefix = null;
		$relative_class = '';

		foreach ($prefixes_to_check as $current_prefix => $base_dir) {
			$len = strlen($current_prefix);
			if (strncmp($current_prefix, $class, $len) === 0) {
				$plugin_root_dir = $base_dir;
				$matched_prefix = $current_prefix;
				$relative_class = substr($class, $len);
				// error_log("Fontalis Autoloader: Classe '{$class}' corresponde ao prefixo '{$matched_prefix}'. Relative class: '{$relative_class}'");
				break;
			}
		}

		if ($matched_prefix === null) {
			// error_log("Fontalis Autoloader: Classe '{$class}' ignorada (nenhum prefixo corresponde).");
			return;
		}

		// Mapeamento de subnamespaces para seus diretórios base relativos à raiz do plugin
		$namespace_to_dir_map = [
			'Core'    => 'backend/core/',
			'Backend' => 'backend/',
			'Modules' => 'backend/modules/',
			'Admin'   => 'admin/',
			'Utils'   => 'backend/utils/',
			'Config'  => 'backend/config/',
			'Includes'=> 'includes/',
			'Frontend'=> 'frontend/', // Adicionado para classes como UserHistoryPanel
		];

		$parts = explode( '\\', $relative_class );
		$first_segment = array_shift( $parts ); // Pega o primeiro segmento (ex: 'Core', 'Backend', 'Includes', 'Frontend')

		$current_base_dir_suffix = '';

		if ( isset( $namespace_to_dir_map[ $first_segment ] ) ) {
			$current_base_dir_suffix = $namespace_to_dir_map[ $first_segment ];

			// $parts agora contém o restante do namespace, ex: ['Config', 'LoggingSettings'] ou ['WP_Hooks']
			$class_file_name_part = array_pop($parts); // O nome da classe em si, ex: LoggingSettings, WP_Hooks
			
			$sub_namespace_path = '';
			if (!empty($parts)) { // Se houver subdiretórios no namespace
				// Converte os segmentos do subnamespace para minúsculas, como 'modules/history'
				$sub_namespace_path = strtolower(implode(DIRECTORY_SEPARATOR, $parts)) . DIRECTORY_SEPARATOR;
			}

			// Tentativa 1: Nome do arquivo = Nome da Classe (Ex: LoggingSettings.php, WP_Hooks.php)
			$file_path_option1 = $plugin_root_dir . $current_base_dir_suffix . $sub_namespace_path . $class_file_name_part . '.php';
			// error_log("Fontalis Autoloader: Tentando Opção 1 (CamelCase): " . $file_path_option1);
			if ( file_exists( $file_path_option1 ) ) {
				require $file_path_option1;
				// error_log("Fontalis Autoloader: Carregado Opção 1: " . $file_path_option1);
				return;
			}

			// Tentativa 2: Nome do arquivo = kebab-case do Nome da Classe (Ex: logging-settings.php, admin-history-panel.php)
			$kebab_case_file_name = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $class_file_name_part));
			$file_path_option2 = $plugin_root_dir . $current_base_dir_suffix . $sub_namespace_path . $kebab_case_file_name . '.php';
			// error_log("Fontalis Autoloader: Tentando Opção 2 (kebab-case): " . $file_path_option2);
			if ( file_exists( $file_path_option2 ) ) {
				require $file_path_option2;
				// error_log("Fontalis Autoloader: Carregado Opção 2: " . $file_path_option2);
				return;
			}
			
			// Se ambas as tentativas falharem
			// error_log("Fontalis Autoloader: Arquivo não encontrado para classe '$class'. Tentativa 1: '$file_path_option1'. Tentativa 2: '$file_path_option2'");

		} else {
			// Fallback ou erro se o primeiro segmento não estiver mapeado.
			// error_log("Fontalis Autoloader: Segmento de namespace '$first_segment' não mapeado para a classe '$class'");
			// Não retorna aqui, permite que o WordPress continue tentando outros autoloaders se houver.
		}
	}

	/**
	 * Inicializa os hooks do WordPress.
	 */
	private function init_hooks(): void {
		if ( class_exists( 'Epixel\\FontalisChatBot\\Includes\\WP_Hooks' ) ) {
			WP_Hooks::register_hooks();
		}
	}
}