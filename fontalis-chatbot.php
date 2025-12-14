<?php
/**
 * Plugin Name:       Fontalis - AI Assistant CHATBOT
 * Plugin URI:        https://epixeltecnologias.tech/fontalis-chatbot
 * Description:       Um chatbot assistente com IA integrado ao WordPress, utilizando Google Gemini API.
 * Version:           2.0.0
 * Author:            ePixel Tecnologias
 * Author URI:        https://epixeltecnologias.tech/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fontalis-chatbot
 * Domain Path:       /languages
 * Requires PHP:      7.4
 * Requires at least: 5.8
 */

// Se este arquivo for chamado diretamente, aborte.
if (!defined("WPINC")) {
    die();
}

// Define uma constante para o caminho do plugin, se ainda não definida.
if (!defined("FONTALIS_CHATBOT_PLUGIN_DIR")) {
    define("FONTALIS_CHATBOT_PLUGIN_DIR", plugin_dir_path(__FILE__));
}

// Define uma constante para a URL do plugin, se ainda não definida.
if (!defined("FONTALIS_CHATBOT_PLUGIN_URL")) {
    define("FONTALIS_CHATBOT_PLUGIN_URL", plugin_dir_url(__FILE__));
}

// Define uma constante para a versão do plugin.
if (!defined("FONTALIS_CHATBOT_VERSION")) {
    define("FONTALIS_CHATBOT_VERSION", "2.0.0");
}

// Carrega as funções de configuração do banco de dados.
require_once FONTALIS_CHATBOT_PLUGIN_DIR . "includes/database-setup.php";

// Carrega a configuração da API Key
require_once FONTALIS_CHATBOT_PLUGIN_DIR . "includes/setup-api-key.php";

// Registra as funções de ativação do plugin
register_activation_hook(__FILE__, "fontalis_chatbot_activate");

/**
 * Função executada na ativação do plugin.
 */
function fontalis_chatbot_activate()
{
    // Cria a tabela do banco de dados
    fontalis_create_chat_conversations_table();

    // Configura a chave API do Gemini
    fontalis_setup_gemini_api_key();
}

// Registra a função de desinstalação
register_uninstall_hook(__FILE__, "fontalis_chatbot_uninstall");

/**
 * Função executada na desinstalação do plugin.
 */
function fontalis_chatbot_uninstall()
{
    // Remove a chave API
    fontalis_remove_gemini_api_key();

    // Remove as tabelas do banco se desejado
    // global $wpdb;
    // $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}fontalis_chat_conversations" );
}

// Hook para detectar atualizações
add_action("plugins_loaded", "fontalis_chatbot_check_version");

function fontalis_chatbot_check_version()
{
    // Obtém a versão do plugin armazenada no banco de dados
    $installed_version = get_option("fontalis_chatbot_version");

    // Se a versão instalada for diferente da versão atual do código
    if ($installed_version !== FONTALIS_CHATBOT_VERSION) {
        // Executa o código de atualização
        fontalis_chatbot_perform_update($installed_version);

        // Atualiza a versão no banco de dados para a versão atual do código
        update_option("fontalis_chatbot_version", FONTALIS_CHATBOT_VERSION);
    }
}

function fontalis_chatbot_perform_update($old_version)
{
    // Este é o local para adicionar a lógica de atualização do banco de dados,
    // migrações de dados, etc., com base na $old_version.

    // Atualização 2.0.0: Expande o campo sender para suportar function_call e function_response
    if (version_compare($old_version, "2.0.0", "<")) {
        fontalis_update_chat_conversations_table();
    }

    // Exemplos de futuras atualizações:
    // if ( version_compare( $old_version, '2.1.0', '<' ) ) {
    //     // Código para atualizar do 2.0.0 para 2.1.0
    // }
}
// Adiciona suporte a upload de SVG apenas para administradores
function fontalis_chat_allow_svg_uploads_for_admins($mimes)
{
    // Verifica se o usuário atual tem a capacidade de 'manage_options' (geralmente administradores)
    if (current_user_can("manage_options")) {
        $mimes["svg"] = "image/svg+xml";
    }
    return $mimes;
}
add_filter("upload_mimes", "fontalis_chat_allow_svg_uploads_for_admins");

// Carrega o inicializador do plugin.
require_once FONTALIS_CHATBOT_PLUGIN_DIR . "includes/plugin-loader.php";

// Inicia o plugin.
if (class_exists("Epixel\\FontalisChatBot\\Includes\\Plugin_Loader")) {
    Epixel\FontalisChatBot\Includes\Plugin_Loader::get_instance();
}
