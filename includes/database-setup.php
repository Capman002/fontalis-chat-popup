<?php
/**
 * Configuração do banco de dados para o plugin Fontalis CHATBOT.
 *
 * @package Fontalis_CHATBOT
 */

if (!defined("WPINC")) {
    die();
}

/**
 * Cria a tabela para armazenar as conversas do chat.
 */
function fontalis_create_chat_conversations_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . "fontalis_chat_conversations";
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        session_id varchar(255) NOT NULL,
        message text NOT NULL,
        sender varchar(20) NOT NULL,
        timestamp datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once ABSPATH . "wp-admin/includes/upgrade.php";
    dbDelta($sql);

    // Atualiza tabelas existentes
    fontalis_update_chat_conversations_table();

    // Cria tabela de auditoria
    fontalis_create_audit_log_table();
}

/**
 * Atualiza a estrutura da tabela existente para suportar function_call e function_response.
 */
function fontalis_update_chat_conversations_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . "fontalis_chat_conversations";

    // Verifica se a tabela existe
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
        return;
    }

    // Verifica o tamanho atual do campo sender
    $column_info = $wpdb->get_row(
        "SHOW COLUMNS FROM $table_name LIKE 'sender'",
    );

    if ($column_info && strpos($column_info->Type, "varchar(10)") !== false) {
        // Atualiza o campo sender para suportar valores maiores
        $wpdb->query(
            "ALTER TABLE $table_name MODIFY COLUMN sender varchar(20) NOT NULL",
        );
        error_log(
            "Fontalis ChatBot: Tabela atualizada - campo sender expandido para varchar(20)",
        );
    }
}

/**
 * Cria a tabela para armazenar logs de auditoria.
 */
function fontalis_create_audit_log_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . "fontalis_audit_log";
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED DEFAULT NULL,
        session_id VARCHAR(64) DEFAULT NULL,
        action VARCHAR(50) NOT NULL,
        details LONGTEXT,
        ip_address VARCHAR(45) NOT NULL,
        user_agent VARCHAR(255),
        timestamp DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY idx_action (action),
        KEY idx_user_session (user_id, session_id),
        KEY idx_timestamp (timestamp),
        KEY idx_ip (ip_address)
    ) $charset_collate;";

    require_once ABSPATH . "wp-admin/includes/upgrade.php";
    dbDelta($sql);
}

/**
 * Manipulador de erros para registrar erros fatais.
 */
function fontalis_chatbot_handle_fatal_error()
{
    $error = error_get_last();
    if (
        $error !== null &&
        in_array($error["type"], [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_USER_ERROR,
        ])
    ) {
        // Log do erro
        error_log(
            "Erro fatal no plugin Fontalis Chatbot: " .
                $error["message"] .
                " no arquivo " .
                $error["file"] .
                " na linha " .
                $error["line"],
        );
    }
}

register_shutdown_function("fontalis_chatbot_handle_fatal_error");
