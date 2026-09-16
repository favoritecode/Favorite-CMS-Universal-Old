<?php
/**
 * Sandboxed Live Preview Canvas for Favorite Multimedia Theme Studio.
 *
 * Rendered inside an iframe in the admin Theme Studio.
 *
 * @var \FavoriteCMS\Multimedia\Theme\ThemeConfig $config
 * @var \FavoriteCMS\Multimedia\Theme\ThemeTokenResolver $tokenResolver
 * @var array $mockData
 */
?>
<!DOCTYPE html>
<html lang="en" class="fm-theme-<?php echo htmlspecialchars((string)$config->get('general', 'default_mode', 'dark'), ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Theme Preview Canvas</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-player.css">
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/theme/theme-tokens.css">
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/theme/multimedia-frontend.css">
    <?php echo $tokenResolver->renderHtmlStyleTag(); ?>
    <style>
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--fm-font-body);
            font-size: var(--fm-font-size-body);
            line-height: var(--fm-line-height);
            background-color: var(--fm-color-bg-page);
            color: var(--fm-color-text-primary);
            overflow-x: hidden;
            transition: background-color var(--fm-motion-speed) var(--fm-motion-easing),
                        color var(--fm-motion-speed) var(--fm-motion-easing);
            -webkit-font-smoothing: antialiased;
        }

        .fm-canvas-wrap {
            max-width: var(--fm-layout-max-width);
            margin: 0 auto;
            padding: 0 var(--fm-layout-page-padding);
        }

        /* Header Foundation */
        .fm-preview-header {
            height: var(--fm-header-height);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 var(--fm-layout-page-padding);
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(18, 24, 36, 0.75);
            backdrop-filter: blur(var(--fm-glass-blur));
            -webkit-backdrop-filter: blur(var(--fm-glass-blur));
            border-bottom: var(--fm-border-strength) solid var(--fm-color-border);
            transition: all var(--fm-motion-speed) ease;
        }

        .fm-header-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-family: var(--fm-font-heading);
            font-weight: var(--fm-font-weight-heading);
            font-size: 1.25rem;
            color: var(--fm-color-text-primary);
            text-decoration: none;
        }

        .fm-brand-icon {
            color: var(--fm-color-primary);
            font-size: 1.5rem;
        }

        .fm-header-nav {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .fm-nav-item {
            color: var(--fm-color-text-secondary);
            text-decoration: none;
            font-size: 0.95rem;
            font-weight: 500;
            transition: color var(--fm-motion-speed) ease;
        }

        .fm-nav-item:hover, .fm-nav-item.active {
            color: var(--fm-color-primary);
        }

        .fm-header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .fm-search-pill {
            background: var(--fm-color-bg-surface);
            border: var(--fm-border-strength) solid var(--fm-color-border);
            border-radius: var(--fm-radius-button);
            padding: 6px 14px;
            color: var(--fm-color-text-muted);
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Hero Foundation */
        .fm-preview-hero {
            height: var(--fm-hero-height);
            border-radius: var(--fm-radius-card);
            margin: 24px 0 var(--fm-layout-section-gap);
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: flex-end;
            padding: 48px;
            background-size: cover;
            background-position: center;
            box-shadow: var(--fm-shadow-card);
        }

        .fm-hero-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(0deg, var(--fm-color-bg-page) 0%, rgba(11, 14, 20, 0.4) 60%, rgba(11, 14, 20, 0.1) 100%);
            z-index: 1;
        }

        .fm-hero-content {
            position: relative;
            z-index: 2;
            max-width: 680px;
        }

        .fm-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: var(--fm-radius-button);
            background: var(--fm-color-primary);
            color: var(--fm-btn-primary-text);
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 12px;
        }

        .fm-hero-title {
            font-family: var(--fm-font-heading);
            font-size: calc(2.2rem * var(--fm-title-scale));
            font-weight: var(--fm-font-weight-heading);
            line-height: 1.15;
            color: var(--fm-color-text-primary);
            margin-bottom: 12px;
        }

        .fm-hero-meta {
            display: flex;
            align-items: center;
            gap: 14px;
            color: var(--fm-color-text-secondary);
            font-size: 0.95rem;
            margin-bottom: 16px;
        }

        .fm-hero-rating {
            color: var(--fm-color-accent);
            font-weight: 700;
        }

        .fm-hero-desc {
            color: var(--fm-color-text-secondary);
            font-size: 1.05rem;
            line-height: 1.5;
            margin-bottom: 24px;
        }

        .fm-btn-group {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .fm-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: var(--fm-btn-height);
            padding: var(--fm-btn-padding);
            border-radius: var(--fm-btn-radius);
            font-weight: 600;
            font-size: 0.95rem;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: transform var(--fm-motion-speed) var(--fm-motion-easing),
                        filter var(--fm-motion-speed) var(--fm-motion-easing);
        }

        .fm-btn:hover {
            transform: scale(var(--fm-btn-hover-intensity));
        }

        .fm-btn-primary {
            background-color: var(--fm-btn-primary-bg);
            color: var(--fm-btn-primary-text);
        }

        .fm-btn-secondary {
            background: var(--fm-color-bg-elevated);
            color: var(--fm-color-text-primary);
            border: var(--fm-border-strength) solid var(--fm-color-border);
        }

        /* Section Layout */
        .fm-section {
            margin-bottom: var(--fm-layout-section-gap);
        }

        .fm-section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .fm-section-title {
            font-family: var(--fm-font-heading);
            font-size: calc(1.4rem * var(--fm-title-scale));
            font-weight: var(--fm-font-weight-heading);
            color: var(--fm-color-text-primary);
        }

        .fm-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: var(--fm-layout-card-gap);
        }

        /* Media Card */
        .fm-card {
            background: var(--fm-color-bg-surface);
            border-radius: var(--fm-card-radius);
            overflow: hidden;
            border: var(--fm-border-strength) solid var(--fm-color-border);
            box-shadow: var(--fm-card-shadow);
            transition: transform var(--fm-motion-speed) var(--fm-motion-easing),
                        box-shadow var(--fm-motion-speed) var(--fm-motion-easing);
            position: relative;
            cursor: pointer;
        }

        .fm-card:hover {
            transform: translateY(var(--fm-card-hover-lift));
            box-shadow: var(--fm-shadow-card);
        }

        .fm-card-poster {
            width: 100%;
            aspect-ratio: 2 / 3;
            object-fit: cover;
            display: block;
        }

        .fm-card-body {
            padding: 14px;
        }

        .fm-card-title {
            font-family: var(--fm-font-heading);
            font-size: 1rem;
            font-weight: 600;
            color: var(--fm-color-text-primary);
            margin-bottom: 6px;
            display: -webkit-box;
            -webkit-line-clamp: var(--fm-card-title-lines);
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .fm-card-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.85rem;
            color: var(--fm-color-text-secondary);
        }

        /* Music Track List */
        .fm-song-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            background: var(--fm-color-bg-surface);
            border-radius: var(--fm-radius-button);
            border: var(--fm-border-strength) solid var(--fm-color-border);
            margin-bottom: 8px;
            transition: background var(--fm-motion-speed) ease;
        }

        .fm-song-row:hover {
            background: var(--fm-color-bg-elevated);
        }

        .fm-song-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .fm-song-num {
            color: var(--fm-color-text-muted);
            font-weight: 600;
            width: 24px;
        }

        .fm-song-title {
            font-weight: 600;
            color: var(--fm-color-text-primary);
        }

        .fm-song-artist {
            font-size: 0.85rem;
            color: var(--fm-color-text-secondary);
        }

        /* Typography Showcase */
        .fm-typo-box {
            background: var(--fm-color-bg-surface);
            border-radius: var(--fm-radius-card);
            border: var(--fm-border-strength) solid var(--fm-color-border);
            padding: 24px;
            margin-top: 16px;
        }

        .fm-bangla-text {
            font-family: var(--fm-font-bangla);
            font-size: 1.15rem;
            color: var(--fm-color-text-secondary);
            margin-top: 8px;
        }
    </style>
</head>
<body>

    <!-- Header Foundation -->
    <header class="fm-preview-header">
        <a href="#" class="fm-header-brand">
            <span class="fm-brand-icon">🎬</span>
            <span id="fm-preview-brand-title"><?php echo htmlspecialchars((string)$config->get('branding', 'brand_title', 'Favorite Multimedia'), ENT_QUOTES, 'UTF-8'); ?></span>
        </a>
        <nav class="fm-header-nav">
            <a href="#" class="fm-nav-item active">Featured</a>
            <a href="#" class="fm-nav-item">Movies</a>
            <a href="#" class="fm-nav-item">Series</a>
            <a href="#" class="fm-nav-item">Music</a>
            <a href="#" class="fm-nav-item">Playlists</a>
        </nav>
        <div class="fm-header-actions">
            <div class="fm-search-pill">
                <span>🔍</span>
                <span>Search titles, artists...</span>
            </div>
            <button class="fm-btn fm-btn-primary" style="height: 34px; padding: 0 14px; font-size: 0.85rem;">Sign In</button>
        </div>
    </header>

        <!-- Dynamic Homepage Sections -->
        <div id="fm-homepage-sections-container">
            <?php
            $hpCfg = $homepageConfig ?? \FavoriteCMS\Multimedia\Theme\Homepage\HomepageManager::getInstance()->getActiveConfig();
            $renderer = new \FavoriteCMS\Multimedia\Theme\Homepage\SectionRenderer();
            $hpUser = $user ?? current_user();
            echo $renderer->renderAll($hpCfg, $hpUser);
            ?>
        </div>

        <!-- Typography & Localization Preview -->
        <section class="fm-section">
            <div class="fm-typo-box">
                <h3 style="font-family: var(--fm-font-heading); font-size: 1.15rem; margin-bottom: 8px;">Typography &amp; Localization System</h3>
                <p style="color: var(--fm-color-text-secondary);">Latin display text rendered using the configured body font.</p>
                <p class="fm-bangla-text">বাংলা ও ইংরেজি ফন্ট প্রাকদর্শন — চলচ্চিত্র, ধারাবাহিক ও সংগীত উপভোগ করুন।</p>
            </div>
        </section>

    </div>

    <script src="/plugins/favorite-multimedia/assets/js/frontend/multimedia-frontend.js"></script>

    <!-- Live PostMessage Receiver -->
    <script>
        window.addEventListener('message', function (event) {
            if (!event.data || typeof event.data !== 'object') return;

            if (event.data.type === 'FM_UPDATE_TOKENS' && event.data.tokens) {
                var tokens = event.data.tokens;
                var root = document.documentElement;
                for (var key in tokens) {
                    if (tokens.hasOwnProperty(key)) {
                        root.style.setProperty(key, tokens[key]);
                    }
                }
            }

            if (event.data.type === 'FM_UPDATE_BRAND_TITLE' && event.data.title) {
                var brandEl = document.getElementById('fm-preview-brand-title');
                if (brandEl) {
                    brandEl.textContent = event.data.title;
                }
            }

            if (event.data.type === 'FM_TOGGLE_MODE' && event.data.mode) {
                document.documentElement.className = 'fm-theme-' + event.data.mode;
            }

            if (event.data.type === 'FM_UPDATE_HOMEPAGE' && event.data.html !== undefined) {
                var container = document.getElementById('fm-homepage-sections-container');
                if (container) {
                    container.innerHTML = event.data.html;
                    if (window.FMFrontend && typeof window.FMFrontend.init === 'function') {
                        window.FMFrontend.init();
                    }
                }
            }
        });
    </script>
</body>
</html>

