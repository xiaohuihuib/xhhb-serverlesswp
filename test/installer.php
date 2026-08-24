<?php

// Do not use this on a live site! For testing purposes only!
if (getenv('SERVERLESSWP_TESTING') !== '1') {
    exit('Not allowed.');
}

define('WP_INSTALLING', true);

require_once dirname(__DIR__) . '/wp/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/translation-install.php';
require_once ABSPATH . WPINC . '/class-wpdb.php';

if (!is_blog_installed()) {
    $weblog_title = 'ServerlessWP Site';
    $user_name = 'admin';
    $admin_email = 'admin@example.com';
    $public = TRUE;

    // The bundled WordPress package has $wp_local_package = 'zh_CN', which
    // makes the login/admin UI default to Chinese. Pass an empty language and
    // force WPLANG to empty so Playwright selectors can rely on English labels.
    $result = wp_install($weblog_title, $user_name, $admin_email, $public, '', 'testpassword123', '');
    update_option('WPLANG', '');
    update_user_meta( 1, 'default_password_nag', false );
    // Suppress Gutenberg's welcome guide modal
    update_user_meta( 1, 'wp_persisted_preferences', [
        'core/edit-post' => [ 'welcomeGuide' => false ],
        '_modified' => date( 'c' ),
    ] );

    // Suppress Argon theme outbound calls that can time out in CI and make
    // every page load hang (analytics ping, update checker, Google Fonts).
    update_option('argon_has_inited', 'true');
    update_option('argon_update_source', 'stop');
    update_option('argon_disable_googlefont', 'true');

    // Prevent WordPress from making external api.wordpress.org update checks
    // on the first admin page loads in CI, which can each block for several
    // seconds and exceed Playwright's navigation timeout.
    set_site_transient('update_plugins', (object) ['response' => [], 'translations' => [], 'no_update' => []], WEEK_IN_SECONDS);
    set_site_transient('update_themes', (object) ['response' => [], 'translations' => [], 'no_update' => []], WEEK_IN_SECONDS);
    set_site_transient('update_core', (object) ['updates' => [], 'last_checked' => time()], WEEK_IN_SECONDS);
} else {
    echo 'WordPress is already installed.';
}