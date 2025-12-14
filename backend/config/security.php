<?php

/**
 * Configurações de Segurança do Plugin Fontalis ChatBot.
 *
 * Este arquivo pode ser usado para definir chaves de ofuscação,
 * configurações de Content Security Policy (CSP) se gerenciadas aqui,
 * ou outras diretivas de segurança.
 *
 * @package FontalisChatBot
 * @subpackage Config
 */

// Se este arquivo for chamado diretamente, aborte.
if (! defined('WPINC')) {
	die;
}

// Chave de Ofuscação para a Chave Privada da Vertex AI
// SEGURANÇA: Gerada dinamicamente a partir de constantes únicas de cada instalação WordPress.
// Isso garante que cada site tenha sua própria chave, mesmo com código aberto.
if (! defined('FONTALIS_CHATBOT_OBFUSCATION_KEY')) {
	// Usa constantes que SEMPRE existem no WordPress (definidas em wp-config.php)
	// NONCE_SALT e AUTH_SALT são únicos por instalação
	$salt_source = '';

	if (defined('NONCE_SALT')) {
		$salt_source .= NONCE_SALT;
	}
	if (defined('AUTH_SALT')) {
		$salt_source .= AUTH_SALT;
	}
	if (defined('ABSPATH')) {
		$salt_source .= ABSPATH;
	}

	// Fallback caso nenhuma constante esteja definida (muito raro)
	if (empty($salt_source)) {
		$salt_source = 'fontalis_default_salt_' . php_uname('n');
	}

	$generated_key = hash('sha256', $salt_source . 'fontalis_chatbot_obfuscation');
	define('FONTALIS_CHATBOT_OBFUSCATION_KEY', $generated_key);
}

// Adicionar outras configurações de segurança aqui conforme necessário.
