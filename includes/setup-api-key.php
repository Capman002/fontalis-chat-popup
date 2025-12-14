<?php

if (! defined('WPINC')) {
    die;
}

/**
 * Configura uma opção inicial para a chave da API do Gemini na ativação do plugin.
 * Isso garante que a opção exista no banco de dados, mesmo que vazia.
 */
function fontalis_setup_gemini_api_key()
{
    if (get_option('fontalis_gemini_api_key') === false) {
        add_option('fontalis_gemini_api_key', '');
    }
}

/**
 * Remove a opção da chave da API do Gemini durante a desinstalação do plugin.
 */
function fontalis_remove_gemini_api_key()
{
    delete_option('fontalis_gemini_api_key');
}
