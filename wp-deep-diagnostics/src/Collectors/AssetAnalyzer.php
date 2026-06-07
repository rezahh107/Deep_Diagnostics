<?php
declare(strict_types=1);

namespace WDDTF\Collectors;

if ( ! defined('ABSPATH') ) {
    exit;
}

final class AssetAnalyzer {
    public function snapshot(): array {
        if ( ! is_admin() ) {
            return [
                'total_enqueued' => 0,
                'heavy'          => [],
            ];
        }

        global $wp_scripts, $wp_styles;

        $heavy          = [];
        $totalEnqueued  = 0;
        $dependenciesMap = [
            'scripts' => $wp_scripts,
            'styles'  => $wp_styles,
        ];

        foreach ( $dependenciesMap as $type => $dependencies ) {
            if ( ! $dependencies instanceof \WP_Dependencies ) {
                continue;
            }

            $queue          = $dependencies->queue ?? [];
            $totalEnqueued += count($queue);

            foreach ( $queue as $handle ) {
                if ( ! isset($dependencies->registered[$handle]) ) {
                    continue;
                }

                $item = $dependencies->registered[$handle];

                if ( empty($item->src) ) {
                    continue;
                }

                $host = wp_parse_url($item->src, PHP_URL_HOST);
                $siteHost = wp_parse_url(site_url(), PHP_URL_HOST);
                $isExternal = $host && $host !== $siteHost;

                $size = null;
                if ( ! $isExternal ) {
                    $path = $this->localPath($item->src);
                    if ( $path && file_exists($path) ) {
                        $size = filesize($path);
                    }
                }

                $heavy[] = [
                    'type'     => $type,
                    'handle'   => $handle,
                    'src'      => $item->src,
                    'size'     => $size,
                    'external' => $isExternal,
                ];
            }
        }

        usort(
            $heavy,
            static function(array $left, array $right): int {
                $leftSize = $left['size'] ?? 0;
                $rightSize = $right['size'] ?? 0;
                
                if ( $left['external'] && ! $right['external'] ) {
                    return -1;
                }
                if ( ! $left['external'] && $right['external'] ) {
                    return 1;
                }
                
                return $rightSize <=> $leftSize;
            }
        );

        return [
            'total_enqueued' => $totalEnqueued,
            'heavy'          => array_slice($heavy, 0, 10),
        ];
    }

    private function localPath(string $src): ?string {
        $contentUrl = content_url();
        $contentDir = WP_CONTENT_DIR;

        $src = strtok($src, '?#');

        if ( 0 === strpos($src, $contentUrl) ) {
            return $contentDir . substr($src, strlen($contentUrl));
        }

        $siteUrl = site_url();

        if ( 0 === strpos($src, $siteUrl) ) {
            return ABSPATH . substr($src, strlen($siteUrl));
        }

        return null;
    }
}
