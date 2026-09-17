<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Theme;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Core\Router;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Rendering\Engine;

/**
 * Service that delegates public customer page shell rendering to the Active Favorite CMS Theme.
 *
 * CORE ARCHITECTURAL RULE:
 * Favorite Multimedia plugin NEVER creates or owns the public header, footer, or outer layout.
 * The active CMS theme (Official Default Theme or Favorite Multimedia Theme) owns the public shell.
 * Favorite Multimedia only provides page-specific content.
 */
final class ThemeShellService
{
    private static bool $routesWrapped = false;

    public static function reset(): void
    {
        self::$routesWrapped = false;
        self::purgeGlobalTemplatePathPollution();
    }

    /**
     * Purge any registered Multimedia Theme paths from the Engine's shared customTemplatePaths stack
     * so other themes (Official Default Theme, third-party themes) are never polluted.
     */
    public static function purgeGlobalTemplatePathPollution(): void
    {
        if (class_exists(Engine::class)) {
            try {
                $ref = new \ReflectionProperty(Engine::class, 'customTemplatePaths');
                $paths = $ref->getValue();
                if (is_array($paths)) {
                    $filtered = array_values(array_filter($paths, function ($p) {
                        return !str_contains((string)$p, 'favorite-multimedia-theme');
                    }));
                    $ref->setValue(null, $filtered);
                }
            } catch (\Throwable) {}
        }
    }

    /**
     * Get the active public theme ID using the canonical Favorite CMS mechanism.
     */
    public static function getActiveTheme(): string
    {
        try {
            if (class_exists(Setting::class)) {
                $theme = Setting::get('theme', 'active_theme', 'default');
                if (is_string($theme) && trim($theme) !== '') {
                    return trim($theme);
                }
            }
        } catch (\Throwable) {}

        if (class_exists(Container::class) && Container::getInstance()->has(Application::class)) {
            try {
                $app = Container::getInstance()->get(Application::class);
                if (class_exists(Engine::class)) {
                    $engineTheme = (new Engine($app))->getActiveTheme();
                    if (is_string($engineTheme) && trim($engineTheme) !== '') {
                        return trim($engineTheme);
                    }
                }
            } catch (\Throwable) {}
        }

        return 'default';
    }

    /**
     * Check whether Favorite Multimedia Theme is the active public theme.
     */
    public static function isMultimediaThemeActive(): bool
    {
        return self::getActiveTheme() === 'favorite-multimedia-theme';
    }

    /**
     * Check whether the Official Theme is the active public theme.
     */
    public static function isOfficialThemeActive(): bool
    {
        $theme = self::getActiveTheme();
        return $theme === 'default' || $theme === 'official';
    }

    /**
     * Resolve active theme header and footer templates strictly from the active theme's own directory.
     *
     * STRICT ARCHITECTURAL RULES:
     * 1. If Official Theme is active: resolve header/footer ONLY from Official Theme.
     *    Multimedia Theme header/footer are strictly forbidden.
     * 2. If Multimedia Theme is active: resolve header/footer ONLY from Multimedia Theme.
     *    Official Theme header/footer are strictly forbidden.
     * 3. Zero cross-theme template resolution or fallback.
     * 4. Zero global template path pollution via Engine::addTemplatePath().
     *
     * @return array{0: ?string, 1: ?string} [headerTemplatePath, footerTemplatePath]
     */
    public static function resolveThemeTemplates(): array
    {
        self::purgeGlobalTemplatePathPollution();

        $activeTheme = self::getActiveTheme();
        $appRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 4);

        $header = null;
        $footer = null;

        // SCENARIO 1: Official Theme (default / official)
        if ($activeTheme === 'default' || $activeTheme === 'official') {
            $officialDir = $appRoot . '/themes/default';
            if (file_exists("{$officialDir}/header.php")) {
                $header = "{$officialDir}/header.php";
            } elseif (file_exists("{$officialDir}/templates/header.php")) {
                $header = "{$officialDir}/templates/header.php";
            }

            if (file_exists("{$officialDir}/footer.php")) {
                $footer = "{$officialDir}/footer.php";
            } elseif (file_exists("{$officialDir}/templates/footer.php")) {
                $footer = "{$officialDir}/templates/footer.php";
            }

            return [$header, $footer];
        }

        // SCENARIO 2: Favorite Multimedia Theme
        if ($activeTheme === 'favorite-multimedia-theme') {
            $mmDirs = [
                $appRoot . '/themes/favorite-multimedia-theme',
                $appRoot . '/plugins/favorite-multimedia/theme/favorite-multimedia-theme',
                dirname(__DIR__, 2) . '/theme/favorite-multimedia-theme',
            ];

            foreach ($mmDirs as $dir) {
                if ($header === null) {
                    if (file_exists("{$dir}/header.php")) {
                        $header = "{$dir}/header.php";
                    } elseif (file_exists("{$dir}/templates/header.php")) {
                        $header = "{$dir}/templates/header.php";
                    }
                }
                if ($footer === null) {
                    if (file_exists("{$dir}/footer.php")) {
                        $footer = "{$dir}/footer.php";
                    } elseif (file_exists("{$dir}/templates/footer.php")) {
                        $footer = "{$dir}/templates/footer.php";
                    }
                }
                if ($header !== null && $footer !== null) {
                    break;
                }
            }

            return [$header, $footer];
        }

        // SCENARIO 3: Third-party active theme
        $themeDir = $appRoot . '/themes/' . $activeTheme;
        if (file_exists("{$themeDir}/header.php")) {
            $header = "{$themeDir}/header.php";
        } elseif (file_exists("{$themeDir}/templates/header.php")) {
            $header = "{$themeDir}/templates/header.php";
        }

        if (file_exists("{$themeDir}/footer.php")) {
            $footer = "{$themeDir}/footer.php";
        } elseif (file_exists("{$themeDir}/templates/footer.php")) {
            $footer = "{$themeDir}/templates/footer.php";
        }

        return [$header, $footer];
    }

    /**
     * Render page content inside the canonical active theme shell.
     * The plugin NEVER creates its own header, footer, or outer html.
     * The active Favorite CMS Theme owns the layout.
     */
    public static function renderPageInActiveTheme(string $content, string $pageTitle = 'Membership', array $vars = []): string
    {
        [$headerPath, $footerPath] = self::resolveThemeTemplates();

        // Extract variables for theme header/footer consumption
        extract($vars, EXTR_SKIP);
        $siteTitle = class_exists(Setting::class)
            ? (string)Setting::get('general', 'site_name', 'Favorite CMS')
            : 'Favorite CMS';
        $metaTitle = $pageTitle !== '' ? $pageTitle : $siteTitle;

        if (defined('APP_ROOT') && file_exists(APP_ROOT . '/app/Core/helpers.php')) {
            require_once APP_ROOT . '/app/Core/helpers.php';
        }

        ob_start();
        try {
            if ($headerPath && file_exists($headerPath)) {
                try {
                    include $headerPath;
                } catch (\Throwable) {
                    // Plugin must never generate its own header/footer markup
                }
            }

            echo $content;

            if ($footerPath && file_exists($footerPath)) {
                try {
                    include $footerPath;
                } catch (\Throwable) {
                    // Plugin must never generate its own header/footer markup
                }
            }
        } catch (\Throwable) {
            ob_clean();
            return $content;
        }

        return (string)ob_get_clean();
    }

    /**
     * Intercept and wrap customer human-facing membership and checkout routes with the active public theme shell.
     * Machine endpoints (webhooks, IPN, callbacks, verification APIs) are strictly ignored and preserved raw.
     */
    public static function wrapHumanFacingRoutes(): void
    {
        if (!class_exists(Router::class)) {
            return;
        }

        try {
            $ref = new \ReflectionProperty(Router::class, 'routes');
            $routes = $ref->getValue();
            if (!is_array($routes)) {
                return;
            }

            $targetPatterns = [
                '#^/account/membership#',
                '#^/account/memberships#',
                '#^/membership#',
                '#^/multimedia/membership#',
                '#^/store#',
                '#^/digital-store#',
                '#^/checkout#',
                '#^/account/orders#',
                '#^/account/orders/[^/]+#',
                '#^/account/wallet#',
                '#^/account/digital#',
                '#^/account/library#',
                '#^/account/downloads#',
                '#^/account/refunds#',
            ];

            $updated = false;
            foreach ($routes as $idx => $route) {
                if (!empty($route['_theme_shell_wrapped'])) {
                    continue;
                }

                $path = $route['path'] ?? '';

                // Strictly skip machine endpoints
                if (
                    str_starts_with($path, '/api/') ||
                    str_contains($path, '/webhook') ||
                    str_contains($path, '/ipn') ||
                    str_contains($path, '/callback') ||
                    str_contains($path, '/download/') ||
                    str_contains($path, '/verify') ||
                    str_contains($path, '/stream') ||
                    str_contains($path, '/hls')
                ) {
                    continue;
                }

                $isTarget = false;
                foreach ($targetPatterns as $pattern) {
                    if (preg_match($pattern, $path)) {
                        $isTarget = true;
                        break;
                    }
                }

                if (!$isTarget) {
                    continue;
                }

                $originalHandler = $route['handler'];
                $routes[$idx]['_theme_shell_wrapped'] = true;
                $routes[$idx]['handler'] = function (Request $req, ...$params) use ($originalHandler, $path) {
                    // Check if request expects JSON or is AJAX: keep raw
                    $isAjax = $req->isAjax()
                        || str_contains(strtolower($req->header('Accept')), 'application/json')
                        || str_contains(strtolower($req->header('X-Requested-With')), 'xmlhttprequest');

                    $resp = null;
                    if (is_callable($originalHandler)) {
                        $resp = call_user_func($originalHandler, $req, ...$params);
                    } elseif (is_array($originalHandler) && count($originalHandler) === 2) {
                        [$class, $method] = $originalHandler;
                        $instance = is_object($class) ? $class : new $class();
                        $resp = call_user_func([$instance, $method], $req, ...$params);
                    }

                    if (is_string($resp)) {
                        $resp = Response::make($resp, 200);
                    }

                    if ($isAjax || !$resp instanceof Response) {
                        return $resp;
                    }

                    // Only wrap successful HTTP 200 responses with HTML content
                    $status = $resp->getStatusCode();
                    if ($status !== 200) {
                        return $resp;
                    }

                    $headers = $resp->getHeaders();
                    $contentType = '';
                    foreach ($headers as $hName => $hVal) {
                        if (strcasecmp($hName, 'Content-Type') === 0) {
                            $contentType = (string)$hVal;
                            break;
                        }
                    }
                    if ($contentType !== '' && !str_contains(strtolower($contentType), 'text/html')) {
                        return $resp;
                    }

                    $html = $resp->getContent();
                    // If already rendered with canonical theme header or site header, skip
                    if (
                        str_contains($html, 'fm-canonical_header_rendered') ||
                        str_contains($html, 'site-header') ||
                        str_contains($html, 'class="fm-header') ||
                        str_contains($html, 'id="fm-header') ||
                        str_contains($html, 'fm-theme-header') ||
                        str_contains($html, 'fm-site-header')
                    ) {
                        return $resp;
                    }

                    $title = 'Favorite CMS';
                    if (preg_match('#<title>(.*?)</title>#is', $html, $m)) {
                        $title = htmlspecialchars_decode(trim($m[1]), ENT_QUOTES);
                    }

                    // Extract inner body content if an external view returned a standalone document
                    $content = $html;
                    if (preg_match('#<body[^>]*>(.*?)</body>#is', $html, $bodyMatch)) {
                        $styles = '';
                        if (preg_match_all('#<style[^>]*>.*?</style>#is', $html, $styleMatches)) {
                            $styles = implode("\n", $styleMatches[0]);
                        }
                        $scripts = '';
                        if (preg_match_all('#<script[^>]*>.*?</script>#is', $html, $scriptMatches)) {
                            $scripts = implode("\n", $scriptMatches[0]);
                        }
                        $content = $styles . "\n" . $bodyMatch[1] . "\n" . $scripts;
                    }

                    $wrapped = self::renderPageInActiveTheme($content, $title, [
                        'req' => $req,
                    ]);

                    return Response::make($wrapped, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
                };
                $updated = true;
            }

            if ($updated) {
                $ref->setValue(null, $routes);
                self::$routesWrapped = true;
            }
        } catch (\Throwable) {}
    }
}
