<?php
/*
Plugin Name: Cloudflare Cache Manager
Description: Gerencie configurações de limpeza de cache Cloudflare.
Version: 2.0
Author: alfredojry
*/

/**
 * Loader para Must-Use plugins.
 *
 * Copie este arquivo para wp-content/mu-plugins/ccm-loader.php
 * e a pasta cloudflare-cache-manager/ para wp-content/mu-plugins/cloudflare-cache-manager/
 *
 * Estrutura esperada:
 *   wp-content/mu-plugins/ccm-loader.php              ← este arquivo
 *   wp-content/mu-plugins/cloudflare-cache-manager/    ← pasta do plugin
 */

require_once __DIR__ . '/cloudflare-cache-manager/cloudflare-cache-manager.php';
