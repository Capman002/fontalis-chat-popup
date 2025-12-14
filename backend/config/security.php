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
// SEGURANÇA: Gerada dinamicamente a partir do wp_salt() único de cada instalação WordPress.
// Isso garante que cada site tenha sua própria chave, mesmo com código aberto.
if (! defined('FONTALIS_CHATBOT_OBFUSCATION_KEY')) {
	// Gera uma chave única para esta instalação usando o salt do WordPress.
	// Cada site WordPress tem um wp_salt() único definido em wp-config.php,
	// então esta chave será diferente em cada instalação.
	$generated_key = hash('sha256', wp_salt('fontalis_chatbot_obfuscation'));
	define('FONTALIS_CHATBOT_OBFUSCATION_KEY', $generated_key);
}

// Adicionar outras configurações de segurança aqui conforme necessário.
