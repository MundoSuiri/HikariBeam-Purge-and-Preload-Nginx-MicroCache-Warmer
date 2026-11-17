<?php
/**
 * Plugin Name:  光 HikariBeam: Purge & Preload Nginx MicroCache Warmer
 * Plugin URI:  https://github.com/MundoSuiri/suiri-fullstack-cache
 * Description: Purga quirúrgica en memoria RAM (/dev/shm), purga selectiva de URLs en Cloudflare Edge API y precalentamiento asíncrono no bloqueante.
 * Version:     3.0.0
 * Author:      Antigravity (AGY) & Samuel Suiri
 * Author URI:  https://github.com/SamuelSuiri
 * License:     GPL-2.0-or-later
 * Text Domain: suiri-fullstack-cache
 */

if (!defined('ABSPATH')) {
    exit;
}

class Suiri_Fullstack_Cache_Engine {

    /**
     * Ruta base de la zona de microcache en RAM de Nginx (definida en /dev/shm).
     */
    const CACHE_PATH = '/dev/shm/nginx-cache';

    /**
     * Inicialización de hooks nativos del ciclo de vida de WordPress.
     */
    public static function init() {
        add_action('save_post', [__CLASS__, 'on_post_change'], 20, 2);
        add_action('comment_post', [__CLASS__, 'on_comment_change'], 20, 2);
        add_action('edit_terms', [__CLASS__, 'on_terms_change'], 20);
    }

    /**
     * Disparador ante creación, modificación o actualización de posts.
     */
    public static function on_post_change($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if ($post->post_status !== 'publish') return;

        $urls = [
            home_url('/'),
            home_url('/feed/'),
        ];

        $post_url = get_permalink($post_id);
        if ($post_url) {
            $urls[] = $post_url;
        }

        self::execute_full_lifecycle($urls);
    }

    /**
     * Disparador ante aprobación de comentarios.
     */
    public static function on_comment_change($comment_id, $approved) {
        if ($approved === 1 || $approved === '1') {
            $comment = get_comment($comment_id);
            if ($comment && !empty($comment->comment_post_ID)) {
                $url = get_permalink($comment->comment_post_ID);
                if ($url) {
                    self::execute_full_lifecycle([$url]);
                }
            }
        }
    }

    /**
     * Disparador ante modificación de categorías o etiquetas.
     */
    public static function on_terms_change() {
        self::execute_full_lifecycle([
            home_url('/'),
            home_url('/feed/')
        ]);
    }

    /**
     * Orquestación de ciclo completo: Purga Local -> Purga Borde -> Warm-up Asíncrono.
     */
    private static function execute_full_lifecycle(array $urls) {
        $clean_urls = array_values(array_unique($urls));

        // 1. Purga local de Nginx en RAM (/dev/shm)
        foreach ($clean_urls as $url) {
            self::purge_local_nginx($url);
        }

        // 2. Purga quirúrgica en Cloudflare Edge (vía API no bloqueante)
        self::purge_cloudflare_edge_async($clean_urls);

        // 3. Precalentamiento asíncrono inmediato (Warm-up anónimo)
        self::warm_urls_async($clean_urls);
    }

    /**
     * Calcula la clave FastCGI MD5 de Nginx y elimina el archivo físico en RAM.
     * Esquema: $scheme$request_method$host$request_uri con estructura levels=1:2
     */
    public static function purge_local_nginx($url) {
        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['host'])) return;

        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'];
        $request_uri = ($parsed['path'] ?? '/') . (!empty($parsed['query']) ? '?' . $parsed['query'] : '');

        // Formato clave Nginx: "$scheme$request_method$host$request_uri"
        $key = "{$scheme}GET{$host}{$request_uri}";
        $hash = md5($key);

        // Estructura levels=1:2 en Nginx
        $level1 = substr($hash, -1);
        $level2 = substr($hash, -3, 2);

        $file = self::CACHE_PATH . '/' . $level1 . '/' . $level2 . '/' . $hash;
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    /**
     * Envía purga selectiva de URLs a la API de Cloudflare sin bloquear la ejecución.
     */
    public static function purge_cloudflare_edge_async(array $urls) {
        $zone_id = defined('CLOUDFLARE_ZONE_ID') ? CLOUDFLARE_ZONE_ID : get_option('cloudflare_zone_id');
        $token   = defined('CLOUDFLARE_PURGE_API_TOKEN') ? CLOUDFLARE_PURGE_API_TOKEN : (defined('CLOUDFLARE_API_TOKEN') ? CLOUDFLARE_API_TOKEN : '');

        if (!$zone_id || !$token) return;

        wp_remote_post("https://api.cloudflare.com/client/v4/zones/{$zone_id}/purge_cache", [
            'timeout'   => 1.0,
            'blocking'  => false,
            'headers'   => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json'
            ],
            'body'      => json_encode(['files' => $urls])
        ]);
    }

    /**
     * Dispara peticiones HTTP anónimas no bloqueantes para regenerar la microcaché
     */
    public static function warm_urls_async(array $urls) {
        foreach ($urls as $url) {
            wp_remote_get($url, [
                'timeout'   => 0.5,
                'blocking'  => false,
                'sslverify' => false,
                'headers'   => [
                    'User-Agent'      => 'SuiriCacheWarmer/3.0 (GCP e2-micro)',
                    'Accept-Encoding' => 'gzip, br',
                ],
                'cookies'   => [] // Crucial: Sin cookies de sesión para forzar microcache HIT
            ]);
        }
    }
}

Suiri_Fullstack_Cache_Engine::init();

