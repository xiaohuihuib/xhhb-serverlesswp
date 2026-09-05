<<<<<<< HEAD
<?php
if(!defined('ABSPATH')) {
    exit;
}

add_action('cleanlogin_after_login_form', 'cfturnstile_field_login');
add_action('cleanlogin_after_register_form', 'cfturnstile_field_register');
=======
<?php
if(!defined('ABSPATH')) {
    exit;
}

add_action('cleanlogin_after_login_form', 'cfturnstile_field_login');
add_action('cleanlogin_after_register_form', 'cfturnstile_field_register');
>>>>>>> b611d588c17c8190405bdb439d52598f63e685e7
add_action('cleanlogin_after_resetpassword_form', 'cfturnstile_field_reset');