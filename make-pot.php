<?php
/**
 * Minimal POT generator for BundlePilot.
 *
 * Scans the plugin source for __() / _e() / esc_html__() / esc_attr__() /
 * esc_html_e() / esc_attr_e() / _n() / _x() calls with the 'bundlepilot'
 * text domain and emits a gettext .pot file at /languages/bundlepilot.pot.
 *
 * Usage: php make-pot.php (run from plugin root)
 *
 * NOTE: This is a build-time script and is removed from distribution ZIPs.
 * For richer extraction, use `wp i18n make-pot` (WP-CLI) instead.
 */

$plugin_dir  = __DIR__;
$text_domain = 'bundlepilot';
$plugin_name = 'BundlePilot';
$plugin_ver  = '1.0.0';
$out_file    = $plugin_dir . '/languages/bundlepilot.pot';

$strings = array();

$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator( $plugin_dir, RecursiveDirectoryIterator::SKIP_DOTS )
);

foreach ( $rii as $file ) {
    if ( ! $file->isFile() ) {
        continue;
    }
    $path = str_replace( '\\', '/', $file->getPathname() );
    $rel  = str_replace( str_replace( '\\', '/', $plugin_dir ) . '/', '', $path );

    // Skip vendored / generated / language directories.
    if ( strpos( $rel, 'freemius/' ) === 0 ) {
        continue;
    }
    if ( strpos( $rel, 'vendor/' ) === 0 ) {
        continue;
    }
    if ( strpos( $rel, 'languages/' ) === 0 ) {
        continue;
    }
    if ( strpos( $rel, 'frontend/build/' ) === 0 ) {
        continue;
    }

    if ( substr( $rel, -4 ) !== '.php' ) {
        continue;
    }

    $code = file_get_contents( $path );
    if ( $code === false ) {
        continue;
    }

    // Single-arg single-quoted: __( 'foo', 'bundlepilot' ).
    $pattern = "/(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\\(\\s*'((?:[^'\\\\]|\\\\.)+)'\\s*,\\s*'" . preg_quote( $text_domain, '/' ) . "'/";
    if ( preg_match_all( $pattern, $code, $m, PREG_OFFSET_CAPTURE ) ) {
        foreach ( $m[1] as $i => $item ) {
            $str               = stripslashes( $item[0] );
            $offset            = $item[1];
            $line              = substr_count( substr( $code, 0, $offset ), "\n" ) + 1;
            $strings[ $str ][] = $rel . ':' . $line;
        }
    }

    // _n( 'one', 'many', $n, 'bundlepilot' ).
    $plural_pattern = "/_n\\(\\s*'((?:[^'\\\\]|\\\\.)+)'\\s*,\\s*'((?:[^'\\\\]|\\\\.)+)'\\s*,[^,]+,\\s*'" . preg_quote( $text_domain, '/' ) . "'/";
    if ( preg_match_all( $plural_pattern, $code, $m, PREG_OFFSET_CAPTURE ) ) {
        foreach ( $m[0] as $i => $whole ) {
            $singular = stripslashes( $m[1][ $i ][0] );
            $plural   = stripslashes( $m[2][ $i ][0] );
            $line     = substr_count( substr( $code, 0, $whole[1] ), "\n" ) + 1;
            $key      = "PLURAL\0" . $singular . "\0" . $plural;

            $strings[ $key ][] = $rel . ':' . $line;
        }
    }
}

ksort( $strings );

$header  = "# BundlePilot — translation template\n";
$header .= "# Copyright (C) " . date( 'Y' ) . " Add One Plugins\n";
$header .= "# This file is distributed under the same license as the BundlePilot plugin.\n";
$header .= "msgid \"\"\n";
$header .= "msgstr \"\"\n";
$header .= '"Project-Id-Version: ' . $plugin_name . ' ' . $plugin_ver . '\n"' . "\n";
$header .= '"Report-Msgid-Bugs-To: https://addoneplugins.com/contact/\n"' . "\n";
$header .= '"POT-Creation-Date: ' . gmdate( 'Y-m-d H:i' ) . '+0000\n"' . "\n";
$header .= '"MIME-Version: 1.0\n"' . "\n";
$header .= '"Content-Type: text/plain; charset=UTF-8\n"' . "\n";
$header .= '"Content-Transfer-Encoding: 8bit\n"' . "\n";
$header .= '"Plural-Forms: nplurals=2; plural=(n != 1);\n"' . "\n";
$header .= '"X-Domain: ' . $text_domain . '\n"' . "\n";
$header .= "\n";

$body = '';
foreach ( $strings as $key => $locations ) {
    foreach ( $locations as $loc ) {
        $body .= '#: ' . $loc . "\n";
    }

    if ( strpos( $key, "PLURAL\0" ) === 0 ) {
        list( , $singular, $plural ) = explode( "\0", $key );
        $body .= 'msgid "' . addcslashes( $singular, "\"\\" ) . '"' . "\n";
        $body .= 'msgid_plural "' . addcslashes( $plural, "\"\\" ) . '"' . "\n";
        $body .= 'msgstr[0] ""' . "\n";
        $body .= 'msgstr[1] ""' . "\n\n";
    } else {
        $body .= 'msgid "' . addcslashes( $key, "\"\\" ) . '"' . "\n";
        $body .= 'msgstr ""' . "\n\n";
    }
}

if ( ! is_dir( dirname( $out_file ) ) ) {
    mkdir( dirname( $out_file ), 0755, true );
}
file_put_contents( $out_file, $header . $body );

echo 'Wrote ' . count( $strings ) . ' translatable strings to ' . $out_file . "\n";
