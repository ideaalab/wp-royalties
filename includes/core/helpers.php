<?php

if (!defined('ABSPATH')) {
    exit;
}

function ial_sanitize_url($url)
{
    $replacements = array(
        'http://http://' => 'http://',
        'https://https://' => 'https://',
        'http://https://' => 'https://',
        'https://http://' => 'https://',
    );
    return str_replace(array_keys($replacements), array_values($replacements), $url);
}
