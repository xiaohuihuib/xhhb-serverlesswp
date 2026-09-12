<?php

/**
 * Uninstall handler for AI Provider for Cloudflare.
 *
 * The plugin stores no options, tables, transients, or cron events. The only
 * persisted data is a per-user meta flag recording dismissal of the
 * "PHP AI Client is missing" admin notice, which is removed here.
 *
 * @package WordPress\CloudflareAiProvider
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove the dismissal flag for all users. Keep this key in sync with
// DISMISS_NOTICE_META in ai-provider-for-cloudflare.php.
delete_metadata('user', 0, 'dbaipfc_notice_dismissed', '', true);
