<?php

/**
 * Plugin Name: AI Provider for Cloudflare
 * Plugin URI: https://aiacfpro.online
 * Description: Cloudflare Workers AI provider for the WordPress AI Client (Llama, Mistral, Qwen, and FLUX images).
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Version: 1.0.0
 * Author: Deepak Bhojwani
 * Author URI: https://github.com/Deepak-Bhojwani
 * License: GPL-2.0-or-later
 * License URI: https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain: ai-provider-for-cloudflare
 *
 * Cloudflare and Workers AI are trademarks of Cloudflare, Inc. This plugin is an
 * independent integration and is not affiliated with, endorsed by, or sponsored
 * by Cloudflare, Inc.
 *
 * @package WordPress\CloudflareAiProvider
 */

declare(strict_types=1);

namespace WordPress\CloudflareAiProvider;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\CloudflareAiProvider\Metadata\CloudflareModelMetadataDirectory;
use WordPress\CloudflareAiProvider\Provider\CloudflareProvider;

if (!defined('ABSPATH')) {
    return;
}

require_once __DIR__ . '/src/autoload.php';

/**
 * Registers the AI Provider for Cloudflare with the AI Client.
 *
 * @since 1.0.0
 *
 * @return void
 */
function register_provider(): void
{
    if (!class_exists(AiClient::class)) {
        return;
    }

    $registry = AiClient::defaultRegistry();

    if ($registry->hasProvider(CloudflareProvider::class)) {
        return;
    }

    $registry->registerProvider(CloudflareProvider::class);
}

add_action('init', __NAMESPACE__ . '\\register_provider', 5);

/**
 * Determines whether usable Cloudflare credentials are configured.
 *
 * Credentials are usable when an API key is set (via the AI connector screen or the registry) and an
 * account ID can be resolved from it — either embedded in the key as `{account_id}:{token}` or supplied
 * through the CLOUDFLARE_ACCOUNT_ID constant/environment variable.
 *
 * @since 1.0.0
 *
 * @return bool True when both a token and an account ID are available.
 */
function credentials_configured(): bool
{
    if (!class_exists(AiClient::class)) {
        return false;
    }

    try {
        $auth = AiClient::defaultRegistry()->getProviderRequestAuthentication(CloudflareProvider::class);
    } catch (\Throwable $e) {
        return false;
    }

    if (!$auth instanceof ApiKeyRequestAuthentication) {
        return false;
    }

    $key = (string) $auth->getApiKey();
    if ($key === '') {
        return false;
    }

    [$accountId, $token] = CloudflareProvider::parseCredential($key);

    return $accountId !== '' && $token !== '';
}

/**
 * Prepends Cloudflare to the WordPress AI plugin's preferred text-model list.
 *
 * The `wpai_preferred_text_models` filter is provided by the WordPress "AI" plugin and consumed by
 * every ability that calls `get_preferred_models_for_text_generation()` (excerpt generation, comment
 * moderation, and so on). Each entry is a `[provider_id, model_id]` tuple tried in order; the first
 * available provider wins. Prepending Cloudflare means a site that has only configured Cloudflare gets
 * working AI features out of the box, without choosing a provider per feature.
 *
 * The entry is only added when credentials are configured, so an unconfigured provider is never queued
 * ahead of the built-in fallbacks (which would make the first request fail).
 *
 * @since 1.0.0
 *
 * @param mixed $models Existing preference list (expected list<array{0: string, 1: string}>).
 * @return array<int, array{0: string, 1: string}> The filtered preference list.
 */
function filter_preferred_text_models($models): array
{
    if (!is_array($models)) {
        $models = [];
    }

    if (!credentials_configured()) {
        return $models;
    }

    $entry = ['cloudflare', CloudflareModelMetadataDirectory::DEFAULT_TEXT_MODEL];

    foreach ($models as $candidate) {
        if (
            is_array($candidate)
            && isset($candidate[0], $candidate[1])
            && $candidate[0] === $entry[0]
            && $candidate[1] === $entry[1]
        ) {
            return $models;
        }
    }

    array_unshift($models, $entry);

    return $models;
}

add_filter('wpai_preferred_text_models', __NAMESPACE__ . '\\filter_preferred_text_models', 5);

/**
 * AJAX action name used to dismiss the missing-client notice.
 *
 * @since 1.0.0
 */
const DISMISS_NOTICE_ACTION = 'dbaipfc_dismiss_notice';

/**
 * User meta key storing the per-user dismissal of the missing-client notice.
 *
 * @since 1.0.0
 */
const DISMISS_NOTICE_META = 'dbaipfc_notice_dismissed';

/**
 * Shows an admin notice when the PHP AI Client is not available.
 *
 * The provider can only register when the AI Client is present (bundled with WordPress 7.0 or the
 * WordPress AI plugin). Without it the plugin degrades gracefully; this notice tells administrators
 * why nothing is happening. It is scoped to the Dashboard and Plugins screens and is dismissible per
 * user.
 *
 * The Cloudflare account ID is supplied with the API key (as `{account_id}:{token}`) or via the
 * CLOUDFLARE_ACCOUNT_ID constant, so it is not surfaced here; an invalid/missing account ID surfaces
 * naturally as the provider being unavailable on the AI connector screen.
 *
 * @since 1.0.0
 *
 * @return void
 */
function maybe_show_missing_client_notice(): void
{
    if (class_exists(AiClient::class)) {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    // Only show on the Dashboard and Plugins screens, not on every admin page.
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || !in_array($screen->id, ['dashboard', 'plugins'], true)) {
        return;
    }

    // Respect a previous per-user dismissal.
    if (get_user_meta(get_current_user_id(), DISMISS_NOTICE_META, true)) {
        return;
    }

    printf(
        // phpcs:ignore Generic.Files.LineLength.TooLong -- Static notice markup.
        '<div id="ai-provider-for-cloudflare-notice" class="notice notice-warning is-dismissible" data-nonce="%s"><p>%s</p></div>',
        esc_attr(wp_create_nonce(DISMISS_NOTICE_ACTION)),
        esc_html__(
            // phpcs:ignore Generic.Files.LineLength.TooLong -- Translatable strings must not be split.
            'AI Provider for Cloudflare requires the PHP AI Client, which is bundled with WordPress 7.0 or the WordPress AI plugin. Please install and activate it to enable the Cloudflare provider.',
            'ai-provider-for-cloudflare'
        )
    );

    // Persist the dismissal when the user clicks the notice's "X" (no jQuery dependency).
    wp_print_inline_script_tag(
        "document.addEventListener('click',function(e){"
        . "if(!e.target.classList.contains('notice-dismiss')){return;}"
        . "var n=e.target.closest('#ai-provider-for-cloudflare-notice');"
        . "if(!n){return;}"
        . "var b=new FormData();"
        . "b.append('action'," . wp_json_encode(DISMISS_NOTICE_ACTION) . ");"
        . "b.append('nonce',n.getAttribute('data-nonce'));"
        . "fetch(window.ajaxurl,{method:'POST',credentials:'same-origin',body:b});"
        . "});"
    );
}

add_action('admin_notices', __NAMESPACE__ . '\\maybe_show_missing_client_notice');

/**
 * Persists the per-user dismissal of the missing-client notice.
 *
 * @since 1.0.0
 *
 * @return void
 */
function dismiss_missing_client_notice(): void
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(null, 403);
    }

    check_ajax_referer(DISMISS_NOTICE_ACTION, 'nonce');

    update_user_meta(get_current_user_id(), DISMISS_NOTICE_META, 1);

    wp_send_json_success();
}

add_action('wp_ajax_' . DISMISS_NOTICE_ACTION, __NAMESPACE__ . '\\dismiss_missing_client_notice');
