<?php
/**
 * Favorite Multimedia Theme Studio Admin Interface
 *
 * @var \FavoriteCMS\Multimedia\Theme\ThemeConfig $config
 * @var array $presets
 * @var \FavoriteCMS\Multimedia\Theme\ThemeTokenResolver $tokenResolver
 * @var string $schemaVersion
 * @var string $csrfToken
 */
?>
<link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/theme/theme-studio.css">
<link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/theme/homepage-builder.css">

<div class="fm-studio-container">
    <!-- Studio Header -->
    <header class="fm-studio-header">
        <div class="fm-studio-header-left">
            <h1 class="fm-studio-title">
                <span>🎨</span>
                <span>Theme Studio</span>
            </h1>
            <span class="fm-studio-badge">v<?php echo htmlspecialchars($schemaVersion, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>

        <!-- Viewport Switcher -->
        <div class="fm-studio-header-center">
            <button type="button" class="fm-viewport-btn active" data-viewport="desktop" title="Desktop View">
                <span>🖥</span> Desktop
            </button>
            <button type="button" class="fm-viewport-btn" data-viewport="tablet" title="Tablet View">
                <span>📱</span> Tablet
            </button>
            <button type="button" class="fm-viewport-btn" data-viewport="mobile" title="Mobile View">
                <span>📲</span> Mobile
            </button>
        </div>

        <!-- Action Controls -->
        <div class="fm-studio-header-right">
            <button type="button" id="fm-btn-export-theme" class="fm-studio-btn fm-studio-btn-secondary" title="Export Theme &amp; Homepage configuration to JSON">
                📥 Export
            </button>
            <button type="button" id="fm-btn-import-theme" class="fm-studio-btn fm-studio-btn-secondary" title="Import Theme configuration from JSON">
                📤 Import
            </button>
            <input type="file" id="fm-theme-import-file" accept=".json,application/json" style="display: none;">
            <button type="button" id="fm-btn-reset-section" class="fm-studio-btn fm-studio-btn-secondary" title="Reset current active tab settings to default">
                ↺ Reset Section
            </button>
            <button type="button" id="fm-btn-reset-all" class="fm-studio-btn fm-studio-btn-danger" title="Reset all theme settings to Cinematic defaults">
                ⚠ Reset All
            </button>
            <button type="button" id="fm-btn-save" class="fm-studio-btn fm-studio-btn-primary" onclick="document.getElementById('fm-theme-form').dispatchEvent(new Event('submit'));">
                💾 Save Changes
            </button>
        </div>
    </header>

    <!-- Studio Main Workspace -->
    <div class="fm-studio-workspace">
        <!-- Left Sidebar Controls -->
        <aside class="fm-studio-sidebar">
            <!-- Tabs Bar -->
            <nav class="fm-tabs-bar">
                <button type="button" class="fm-tab-btn active" data-tab="presets">Presets</button>
                <button type="button" class="fm-tab-btn" data-tab="homepage" style="color: #38bdf8; font-weight: 700;">🏠 Homepage</button>
                <button type="button" class="fm-tab-btn" data-tab="general">General</button>
                <button type="button" class="fm-tab-btn" data-tab="branding">Branding</button>
                <button type="button" class="fm-tab-btn" data-tab="colors">Colors</button>
                <button type="button" class="fm-tab-btn" data-tab="typography">Typography</button>
                <button type="button" class="fm-tab-btn" data-tab="layout">Layout</button>
                <button type="button" class="fm-tab-btn" data-tab="surfaces">Surfaces</button>
                <button type="button" class="fm-tab-btn" data-tab="cards">Cards</button>
                <button type="button" class="fm-tab-btn" data-tab="header">Header</button>
                <button type="button" class="fm-tab-btn" data-tab="hero">Hero</button>
                <button type="button" class="fm-tab-btn" data-tab="buttons">Buttons</button>
                <button type="button" class="fm-tab-btn" data-tab="motion">Motion</button>
                <button type="button" class="fm-tab-btn" data-tab="player">🎵 Audio Player</button>
            </nav>

            <!-- Controls Scroll Area -->
            <div class="fm-controls-content">
                <form id="fm-theme-form" method="POST" action="/admin/page/multimedia-theme">
                    <input type="hidden" name="_token" id="fm-csrf-token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="save">

                    <!-- Tab: Presets -->
                    <div id="tab-presets" class="fm-tab-panel active">
                        <div class="fm-form-group">
                            <label class="fm-form-label">Theme Presets</label>
                            <p class="fm-form-desc">Select a crafted preset to instantly load harmonized color schemes, typography, and layout settings.</p>
                        </div>
                        <div class="fm-presets-grid">
                            <?php foreach ($presets as $pId => $preset): ?>
                                <div class="fm-preset-card" data-preset-id="<?php echo htmlspecialchars($pId, ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="fm-preset-info">
                                        <div class="fm-preset-swatch" style="background-color: <?php echo htmlspecialchars($preset['preview_color'], ENT_QUOTES, 'UTF-8'); ?>;"></div>
                                        <div>
                                            <div class="fm-preset-name"><?php echo htmlspecialchars($preset['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                            <div class="fm-preset-desc"><?php echo htmlspecialchars($preset['description'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        </div>
                                    </div>
                                    <button type="button" class="fm-studio-btn fm-studio-btn-secondary" style="padding: 4px 10px; font-size: 0.8rem;">Apply</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Tab: Homepage Builder -->
                    <div id="tab-homepage" class="fm-tab-panel">
                        <div class="fm-hp-header">
                            <div class="fm-hp-title">
                                <span>Modular Sections</span>
                                <span id="fm-section-count" class="fm-badge-count"><?php echo count($homepageConfig->getSections()); ?> / <?php echo \FavoriteCMS\Multimedia\Theme\Homepage\HomepageConfig::MAX_SECTIONS; ?></span>
                            </div>
                            <div class="fm-hp-actions">
                                <button type="button" id="fm-btn-add-section" class="fm-studio-btn fm-studio-btn-primary" style="padding: 5px 10px; font-size: 0.8rem;">
                                    + Add Section
                                </button>
                                <button type="button" id="fm-btn-reset-homepage" class="fm-studio-btn fm-studio-btn-secondary" style="padding: 5px 10px; font-size: 0.8rem;" title="Reset Homepage to default layout">
                                    ↺ Reset
                                </button>
                                <button type="button" id="fm-btn-save-homepage" class="fm-studio-btn fm-studio-btn-primary" style="padding: 5px 12px; font-size: 0.8rem; background: #10b981;">
                                    💾 Save Layout
                                </button>
                            </div>
                        </div>

                        <p class="fm-form-desc" style="margin-bottom: 12px;">
                            Drag to reorder sections, customize limits and layouts, or add new discovery rows to your streaming homepage.
                        </p>

                        <!-- Sections List -->
                        <div id="fm-hp-sections-list" class="fm-hp-sections-list">
                            <?php foreach ($homepageConfig->getSections() as $sec): 
                                $secId = $sec['id'] ?? ('sec_' . bin2hex(random_bytes(4)));
                                $secType = $sec['type'] ?? 'latest_movies';
                                $secTitle = $sec['title'] ?? 'Section';
                                $secSubtitle = $sec['subtitle'] ?? '';
                                $secLayout = $sec['layout'] ?? 'rail';
                                $secCardStyle = $sec['card_style'] ?? 'poster';
                                $secLimit = (int)($sec['limit'] ?? 8);
                                $secSort = $sec['sort'] ?? 'latest';
                                $secViewAll = $sec['view_all_url'] ?? '';
                                $secEnabled = !empty($sec['enabled']);
                                $secVisDesk = !empty($sec['visible_desktop']);
                                $secVisTab = !empty($sec['visible_tablet']);
                                $secVisMob = !empty($sec['visible_mobile']);
                            ?>
                                <div class="fm-builder-card <?php echo $secEnabled ? '' : 'is-disabled'; ?>" data-id="<?php echo htmlspecialchars($secId, ENT_QUOTES, 'UTF-8'); ?>" data-type="<?php echo htmlspecialchars($secType, ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="fm-builder-card-head">
                                        <div class="fm-reorder-ctrls">
                                            <span class="fm-grip-handle" title="Drag to reorder">⋮⋮</span>
                                            <button type="button" class="fm-reorder-btn fm-btn-move-up" title="Move Up">▲</button>
                                            <button type="button" class="fm-reorder-btn fm-btn-move-down" title="Move Down">▼</button>
                                        </div>
                                        <div class="fm-card-main-info">
                                            <div class="fm-card-title-row">
                                                <strong class="fm-sec-title-display"><?php echo htmlspecialchars($secTitle, ENT_QUOTES, 'UTF-8'); ?></strong>
                                                <span class="fm-pill fm-pill-type"><?php echo htmlspecialchars($secType, ENT_QUOTES, 'UTF-8'); ?></span>
                                                <span class="fm-pill fm-pill-layout"><?php echo htmlspecialchars($secLayout, ENT_QUOTES, 'UTF-8'); ?></span>
                                                <span class="fm-pill fm-pill-limit"><?php echo $secLimit; ?> items</span>
                                            </div>
                                            <span class="fm-sec-subtitle-display"><?php echo htmlspecialchars($secSubtitle, ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                        <div class="fm-card-ctrls">
                                            <label class="fm-switch" title="Toggle Section Visibility">
                                                <input type="checkbox" class="fm-sec-toggle" <?php echo $secEnabled ? 'checked' : ''; ?>>
                                                <span class="fm-switch-slider"></span>
                                            </label>
                                            <button type="button" class="fm-btn-icon fm-btn-edit-sec" title="Edit Settings">✎</button>
                                            <button type="button" class="fm-btn-icon fm-btn-duplicate-sec" title="Duplicate">⎘</button>
                                            <button type="button" class="fm-btn-icon fm-btn-icon-danger fm-btn-delete-sec" title="Delete">🗑</button>
                                        </div>
                                    </div>
                                    <div class="fm-sec-drawer">
                                        <div class="fm-drawer-grid">
                                            <div class="fm-drawer-full">
                                                <label class="fm-drawer-label">Section Title</label>
                                                <input type="text" class="fm-drawer-input fm-sec-input-title" value="<?php echo htmlspecialchars($secTitle, ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                            <div class="fm-drawer-full">
                                                <label class="fm-drawer-label">Subtitle</label>
                                                <input type="text" class="fm-drawer-input fm-sec-input-subtitle" value="<?php echo htmlspecialchars($secSubtitle, ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                            <div>
                                                <label class="fm-drawer-label">Layout Style</label>
                                                <select class="fm-drawer-select fm-sec-input-layout">
                                                    <option value="rail" <?php echo ($secLayout === 'rail') ? 'selected' : ''; ?>>Rail (Horizontal Scroll)</option>
                                                    <option value="grid" <?php echo ($secLayout === 'grid') ? 'selected' : ''; ?>>Grid (Responsive Multiline)</option>
                                                    <option value="hero_slider" <?php echo ($secLayout === 'hero_slider') ? 'selected' : ''; ?>>Hero Billboard Slider</option>
                                                    <option value="list" <?php echo ($secLayout === 'list') ? 'selected' : ''; ?>>List</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="fm-drawer-label">Card Style</label>
                                                <select class="fm-drawer-select fm-sec-input-card-style">
                                                    <option value="poster" <?php echo ($secCardStyle === 'poster') ? 'selected' : ''; ?>>Poster Card (2:3)</option>
                                                    <option value="landscape" <?php echo ($secCardStyle === 'landscape') ? 'selected' : ''; ?>>Landscape Card (16:9)</option>
                                                    <option value="album" <?php echo ($secCardStyle === 'album') ? 'selected' : ''; ?>>Album Square (1:1)</option>
                                                    <option value="artist" <?php echo ($secCardStyle === 'artist') ? 'selected' : ''; ?>>Artist Avatar (Circle)</option>
                                                    <option value="playlist" <?php echo ($secCardStyle === 'playlist') ? 'selected' : ''; ?>>Playlist Card</option>
                                                    <option value="song_row" <?php echo ($secCardStyle === 'song_row') ? 'selected' : ''; ?>>Song Track Row</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="fm-drawer-label">Item Limit</label>
                                                <input type="number" class="fm-drawer-input fm-sec-input-limit" min="1" max="24" value="<?php echo $secLimit; ?>">
                                            </div>
                                            <div>
                                                <label class="fm-drawer-label">Sorting</label>
                                                <select class="fm-drawer-select fm-sec-input-sort">
                                                    <option value="latest" <?php echo ($secSort === 'latest') ? 'selected' : ''; ?>>Latest Released</option>
                                                    <option value="popular" <?php echo ($secSort === 'popular') ? 'selected' : ''; ?>>Most Popular</option>
                                                    <option value="rating" <?php echo ($secSort === 'rating') ? 'selected' : ''; ?>>Top Rated</option>
                                                    <option value="recent" <?php echo ($secSort === 'recent') ? 'selected' : ''; ?>>Recently Added</option>
                                                </select>
                                            </div>
                                            <div class="fm-drawer-full">
                                                <label class="fm-drawer-label">View All URL</label>
                                                <input type="text" class="fm-drawer-input fm-sec-input-viewall" value="<?php echo htmlspecialchars($secViewAll, ENT_QUOTES, 'UTF-8'); ?>" placeholder="/movies or /songs">
                                            </div>
                                            <div class="fm-drawer-full">
                                                <label class="fm-drawer-label">Device Visibility</label>
                                                <div class="fm-drawer-checkboxes">
                                                    <label><input type="checkbox" class="fm-sec-input-vis-desktop" <?php echo $secVisDesk ? 'checked' : ''; ?>> 🖥 Desktop</label>
                                                    <label><input type="checkbox" class="fm-sec-input-vis-tablet" <?php echo $secVisTab ? 'checked' : ''; ?>> 📱 Tablet</label>
                                                    <label><input type="checkbox" class="fm-sec-input-vis-mobile" <?php echo $secVisMob ? 'checked' : ''; ?>> 📲 Mobile</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Tab: General -->
                    <div id="tab-general" class="fm-tab-panel">
                        <div class="fm-form-group">
                            <label class="fm-form-label">Theme System Active</label>
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; color: #cbd5e1;">
                                <input type="checkbox" name="general[active]" value="1" <?php echo $config->get('general', 'active', true) ? 'checked' : ''; ?>>
                                Enable custom theme engine rendering on multimedia pages
                            </label>
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label" for="general-default-mode">Default Color Mode</label>
                            <select id="general-default-mode" name="general[default_mode]" class="fm-select">
                                <option value="dark" <?php echo $config->get('general', 'default_mode') === 'dark' ? 'selected' : ''; ?>>Dark (Default)</option>
                                <option value="light" <?php echo $config->get('general', 'default_mode') === 'light' ? 'selected' : ''; ?>>Light</option>
                                <option value="system" <?php echo $config->get('general', 'default_mode') === 'system' ? 'selected' : ''; ?>>System (Follows OS)</option>
                            </select>
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label">Frontend Mode Switcher</label>
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; color: #cbd5e1;">
                                <input type="checkbox" name="general[enable_mode_switcher]" value="1" <?php echo $config->get('general', 'enable_mode_switcher', true) ? 'checked' : ''; ?>>
                                Allow visitors to toggle between Dark and Light mode
                            </label>
                        </div>
                    </div>

                    <!-- Tab: Branding -->
                    <div id="tab-branding" class="fm-tab-panel">
                        <div class="fm-form-group">
                            <label class="fm-form-label" for="branding-brand-title">Brand Title</label>
                            <input type="text" id="branding-brand-title" name="branding[brand_title]" class="fm-input-text" value="<?php echo htmlspecialchars((string)$config->get('branding', 'brand_title'), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label" for="branding-tagline">Tagline</label>
                            <input type="text" id="branding-tagline" name="branding[tagline]" class="fm-input-text" value="<?php echo htmlspecialchars((string)$config->get('branding', 'tagline'), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label" for="branding-logo-url">Logo URL</label>
                            <input type="text" id="branding-logo-url" name="branding[logo_url]" class="fm-input-text" placeholder="https://... or /uploads/..." value="<?php echo htmlspecialchars((string)$config->get('branding', 'logo_url'), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label" for="branding-alt-logo-url">Light Mode Logo URL</label>
                            <input type="text" id="branding-alt-logo-url" name="branding[alt_logo_url]" class="fm-input-text" placeholder="Optional light mode logo" value="<?php echo htmlspecialchars((string)$config->get('branding', 'alt_logo_url'), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label" for="branding-favicon-url">Favicon URL</label>
                            <input type="text" id="branding-favicon-url" name="branding[favicon_url]" class="fm-input-text" placeholder="https://... or /favicon.ico" value="<?php echo htmlspecialchars((string)$config->get('branding', 'favicon_url'), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>

                    <!-- Tab: Colors -->
                    <div id="tab-colors" class="fm-tab-panel">
                        <?php
                        $colorFields = [
                            'primary' => ['label' => 'Primary Brand Color', 'token' => '--fm-color-primary', 'desc' => 'Key accents, play buttons, active indicators'],
                            'secondary' => ['label' => 'Secondary Color', 'token' => '--fm-color-secondary', 'desc' => 'Secondary elements, control bars'],
                            'accent' => ['label' => 'Accent / Highlight', 'token' => '--fm-color-accent', 'desc' => 'Badges, star ratings, special tags'],
                            'bg_page' => ['label' => 'Page Background', 'token' => '--fm-color-bg-page', 'desc' => 'Base canvas background color'],
                            'bg_surface' => ['label' => 'Surface / Card Background', 'token' => '--fm-color-bg-surface', 'desc' => 'Cards, rows, modal boxes'],
                            'bg_elevated' => ['label' => 'Elevated Background', 'token' => '--fm-color-bg-elevated', 'desc' => 'Dropdowns, hover surfaces, search inputs'],
                            'text_primary' => ['label' => 'Primary Text', 'token' => '--fm-color-text-primary', 'desc' => 'Headings and high-emphasis body text'],
                            'text_secondary' => ['label' => 'Secondary Text', 'token' => '--fm-color-text-secondary', 'desc' => 'Metadata, subtitles, descriptions'],
                            'text_muted' => ['label' => 'Muted Text', 'token' => '--fm-color-text-muted', 'desc' => 'Disabled states, placeholders, icons'],
                            'border' => ['label' => 'Border Color', 'token' => '--fm-color-border', 'desc' => 'Dividers, subtle card strokes'],
                            'success' => ['label' => 'Success Color', 'token' => '--fm-color-success', 'desc' => 'Completed progress, green alerts'],
                            'warning' => ['label' => 'Warning Color', 'token' => '--fm-color-warning', 'desc' => 'Notice indicators, expiring badges'],
                            'danger' => ['label' => 'Danger Color', 'token' => '--fm-color-danger', 'desc' => 'Errors, cancel actions, delete tags'],
                        ];
                        foreach ($colorFields as $cKey => $cMeta):
                            $val = (string)$config->get('colors', $cKey, '#FFFFFF');
                        ?>
                            <div class="fm-form-group">
                                <label class="fm-form-label"><?php echo htmlspecialchars($cMeta['label'], ENT_QUOTES, 'UTF-8'); ?></label>
                                <div class="fm-color-row" data-token="<?php echo htmlspecialchars($cMeta['token'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="color" class="fm-color-input" value="<?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="text" name="colors[<?php echo $cKey; ?>]" class="fm-input-text fm-color-hex" value="<?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div class="fm-form-desc"><?php echo htmlspecialchars($cMeta['desc'], ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Tab: Typography -->
                    <div id="tab-typography" class="fm-tab-panel">
                        <div class="fm-form-group">
                            <label class="fm-form-label">Heading Font Family</label>
                            <input type="text" name="typography[heading_font]" class="fm-input-text" value="<?php echo htmlspecialchars((string)$config->get('typography', 'heading_font'), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label">Body Font Family</label>
                            <input type="text" name="typography[body_font]" class="fm-input-text" value="<?php echo htmlspecialchars((string)$config->get('typography', 'body_font'), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label">Bangla Font Family</label>
                            <input type="text" name="typography[bangla_font]" class="fm-input-text" value="<?php echo htmlspecialchars((string)$config->get('typography', 'bangla_font'), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label">Heading Font Weight</label>
                            <select name="typography[heading_weight]" class="fm-select">
                                <option value="600" <?php echo $config->get('typography', 'heading_weight') === '600' ? 'selected' : ''; ?>>Semi-Bold (600)</option>
                                <option value="700" <?php echo $config->get('typography', 'heading_weight') === '700' ? 'selected' : ''; ?>>Bold (700)</option>
                                <option value="800" <?php echo $config->get('typography', 'heading_weight') === '800' ? 'selected' : ''; ?>>Extra Bold (800)</option>
                            </select>
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label">Body Base Size</label>
                            <div class="fm-range-row" data-token="--fm-font-size-body" data-unit="px">
                                <input type="range" name="typography[body_size]" class="fm-range-slider" min="13" max="18" value="<?php echo (int)$config->get('typography', 'body_size', 15); ?>">
                                <span class="fm-range-val"><?php echo htmlspecialchars((string)$config->get('typography', 'body_size', '15px'), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label">Title Scale Ratio</label>
                            <div class="fm-range-row" data-token="--fm-title-scale">
                                <input type="range" name="typography[title_scale]" class="fm-range-slider" min="1.0" max="1.5" step="0.05" value="<?php echo (float)$config->get('typography', 'title_scale', 1.25); ?>">
                                <span class="fm-range-val"><?php echo htmlspecialchars((string)$config->get('typography', 'title_scale', '1.25'), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Layout -->
                    <div id="tab-layout" class="fm-tab-panel">
                        <div class="fm-form-group">
                            <label class="fm-form-label">Layout Density</label>
                            <select name="layout[density]" class="fm-select">
                                <option value="compact" <?php echo $config->get('layout', 'density') === 'compact' ? 'selected' : ''; ?>>Compact (Dense, high information per screen)</option>
                                <option value="comfortable" <?php echo $config->get('layout', 'density') === 'comfortable' ? 'selected' : ''; ?>>Comfortable (Balanced default)</option>
                                <option value="spacious" <?php echo $config->get('layout', 'density') === 'spacious' ? 'selected' : ''; ?>>Spacious (Cinematic breathing room)</option>
                            </select>
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label">Max Container Width</label>
                            <input type="text" name="layout[max_width]" class="fm-input-text" value="<?php echo htmlspecialchars((string)$config->get('layout', 'max_width', '1440px'), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label">Section Gap</label>
                            <div class="fm-range-row" data-token="--fm-layout-section-gap" data-unit="px">
                                <input type="range" name="layout[section_gap]" class="fm-range-slider" min="24" max="72" value="<?php echo (int)$config->get('layout', 'section_gap', 48); ?>">
                                <span class="fm-range-val"><?php echo htmlspecialchars((string)$config->get('layout', 'section_gap', '48px'), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label">Card Grid Gap</label>
                            <div class="fm-range-row" data-token="--fm-layout-card-gap" data-unit="px">
                                <input type="range" name="layout[card_gap]" class="fm-range-slider" min="8" max="32" value="<?php echo (int)$config->get('layout', 'card_gap', 20); ?>">
                                <span class="fm-range-val"><?php echo htmlspecialchars((string)$config->get('layout', 'card_gap', '20px'), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Surfaces -->
                    <div id="tab-surfaces" class="fm-tab-panel">
                        <div class="fm-form-group">
                            <label class="fm-form-label">Card Corner Radius</label>
                            <div class="fm-range-row" data-token="--fm-radius-card" data-unit="px">
                                <input type="range" name="surfaces[card_radius]" class="fm-range-slider" min="0" max="24" value="<?php echo (int)$config->get('surfaces', 'card_radius', 12); ?>">
                                <span class="fm-range-val"><?php echo htmlspecialchars((string)$config->get('surfaces', 'card_radius', '12px'), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label">Button Corner Radius</label>
                            <div class="fm-range-row" data-token="--fm-radius-button" data-unit="px">
                                <input type="range" name="surfaces[button_radius]" class="fm-range-slider" min="0" max="30" value="<?php echo (int)$config->get('surfaces', 'button_radius', 8); ?>">
                                <span class="fm-range-val"><?php echo htmlspecialchars((string)$config->get('surfaces', 'button_radius', '8px'), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label">Glass Blur Strength</label>
                            <div class="fm-range-row" data-token="--fm-glass-blur" data-unit="px">
                                <input type="range" name="surfaces[glass_blur]" class="fm-range-slider" min="0" max="30" value="<?php echo (int)$config->get('surfaces', 'glass_blur', 12); ?>">
                                <span class="fm-range-val"><?php echo htmlspecialchars((string)$config->get('surfaces', 'glass_blur', '12px'), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Cards -->
                    <div id="tab-cards" class="fm-tab-panel">
                        <div class="fm-form-group">
                            <label class="fm-form-label">Badge Style</label>
                            <select name="cards[badge_style]" class="fm-select">
                                <option value="pill" <?php echo $config->get('cards', 'badge_style') === 'pill' ? 'selected' : ''; ?>>Pill (Fully rounded)</option>
                                <option value="rounded" <?php echo $config->get('cards', 'badge_style') === 'rounded' ? 'selected' : ''; ?>>Rounded (Subtle corners)</option>
                                <option value="square" <?php echo $config->get('cards', 'badge_style') === 'square' ? 'selected' : ''; ?>>Square (Sharp)</option>
                            </select>
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label">Title Truncation Lines</label>
                            <select name="cards[title_lines]" class="fm-select">
                                <option value="1" <?php echo (int)$config->get('cards', 'title_lines') === 1 ? 'selected' : ''; ?>>1 Line</option>
                                <option value="2" <?php echo (int)$config->get('cards', 'title_lines') === 2 ? 'selected' : ''; ?>>2 Lines (Default)</option>
                                <option value="3" <?php echo (int)$config->get('cards', 'title_lines') === 3 ? 'selected' : ''; ?>>3 Lines</option>
                            </select>
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label">Card Hover Lift</label>
                            <div class="fm-range-row" data-token="--fm-card-hover-lift" data-unit="px">
                                <input type="range" name="cards[hover_lift]" class="fm-range-slider" min="-12" max="0" value="<?php echo (int)$config->get('cards', 'hover_lift', -6); ?>">
                                <span class="fm-range-val"><?php echo htmlspecialchars((string)$config->get('cards', 'hover_lift', '-6px'), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Header -->
                    <div id="tab-header" class="fm-tab-panel">
                        <div class="fm-form-group">
                            <label class="fm-form-label">Header Style</label>
                            <select name="header[style]" class="fm-select">
                                <option value="glass" <?php echo $config->get('header', 'style') === 'glass' ? 'selected' : ''; ?>>Glassmorphic (Blurred backdrop)</option>
                                <option value="solid" <?php echo $config->get('header', 'style') === 'solid' ? 'selected' : ''; ?>>Solid Surface</option>
                                <option value="transparent" <?php echo $config->get('header', 'style') === 'transparent' ? 'selected' : ''; ?>>Transparent (Floats over hero)</option>
                            </select>
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label">Header Height</label>
                            <div class="fm-range-row" data-token="--fm-header-height" data-unit="px">
                                <input type="range" name="header[height]" class="fm-range-slider" min="50" max="90" value="<?php echo (int)$config->get('header', 'height', 68); ?>">
                                <span class="fm-range-val"><?php echo htmlspecialchars((string)$config->get('header', 'height', '68px'), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label">Sticky Header</label>
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; color: #cbd5e1;">
                                <input type="checkbox" name="header[sticky]" value="1" <?php echo $config->get('header', 'sticky', true) ? 'checked' : ''; ?>>
                                Keep navigation header fixed at the top on scroll
                            </label>
                        </div>
                    </div>

                    <!-- Tab: Hero -->
                    <div id="tab-hero" class="fm-tab-panel">
                        <div class="fm-form-group">
                            <label class="fm-form-label">Hero Section Enabled</label>
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; color: #cbd5e1;">
                                <input type="checkbox" name="hero[enabled]" value="1" <?php echo $config->get('hero', 'enabled', true) ? 'checked' : ''; ?>>
                                Display prominent hero billboard on catalog hubs
                            </label>
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label">Hero Height</label>
                            <div class="fm-range-row" data-token="--fm-hero-height" data-unit="px">
                                <input type="range" name="hero[height]" class="fm-range-slider" min="360" max="680" value="<?php echo (int)$config->get('hero', 'height', 520); ?>">
                                <span class="fm-range-val"><?php echo htmlspecialchars((string)$config->get('hero', 'height', '520px'), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label">Text Alignment</label>
                            <select name="hero[text_align]" class="fm-select">
                                <option value="left" <?php echo $config->get('hero', 'text_align') === 'left' ? 'selected' : ''; ?>>Left Aligned (Default)</option>
                                <option value="center" <?php echo $config->get('hero', 'text_align') === 'center' ? 'selected' : ''; ?>>Center Aligned</option>
                            </select>
                        </div>
                    </div>

                    <!-- Tab: Buttons -->
                    <div id="tab-buttons" class="fm-tab-panel">
                        <div class="fm-form-group">
                            <label class="fm-form-label">Primary Button Background</label>
                            <div class="fm-color-row" data-token="--fm-btn-primary-bg">
                                <input type="color" class="fm-color-input" value="<?php echo htmlspecialchars((string)$config->get('buttons', 'primary_bg', '#E50914'), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="text" name="buttons[primary_bg]" class="fm-input-text fm-color-hex" value="<?php echo htmlspecialchars((string)$config->get('buttons', 'primary_bg', '#E50914'), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label">Button Height</label>
                            <div class="fm-range-row" data-token="--fm-btn-height" data-unit="px">
                                <input type="range" name="buttons[height]" class="fm-range-slider" min="32" max="54" value="<?php echo (int)$config->get('buttons', 'height', 42); ?>">
                                <span class="fm-range-val"><?php echo htmlspecialchars((string)$config->get('buttons', 'height', '42px'), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label">Button Hover Scale Intensity</label>
                            <div class="fm-range-row" data-token="--fm-btn-hover-intensity">
                                <input type="range" name="buttons[hover_intensity]" class="fm-range-slider" min="1.0" max="1.15" step="0.01" value="<?php echo (float)$config->get('buttons', 'hover_intensity', 1.05); ?>">
                                <span class="fm-range-val"><?php echo htmlspecialchars((string)$config->get('buttons', 'hover_intensity', '1.05'), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Tab: Motion -->
                    <div id="tab-motion" class="fm-tab-panel">
                        <div class="fm-form-group">
                            <label class="fm-form-label">Animations Enabled</label>
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; color: #cbd5e1;">
                                <input type="checkbox" name="motion[enabled]" value="1" <?php echo $config->get('motion', 'enabled', true) ? 'checked' : ''; ?>>
                                Enable micro-animations, card lifts, and hover transitions
                            </label>
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label">Animation Speed</label>
                            <div class="fm-range-row" data-token="--fm-motion-speed" data-unit="ms">
                                <input type="range" name="motion[transition_speed]" class="fm-range-slider" min="100" max="500" step="25" value="<?php echo (int)$config->get('motion', 'transition_speed', 250); ?>">
                                <span class="fm-range-val"><?php echo htmlspecialchars((string)$config->get('motion', 'transition_speed', '250ms'), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label">Respect OS Reduced Motion</label>
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; color: #cbd5e1;">
                                <input type="checkbox" name="motion[reduced_motion]" value="1" <?php echo $config->get('motion', 'reduced_motion', false) ? 'checked' : ''; ?>>
                                Disable transforms if visitor enables 'prefers-reduced-motion' in OS
                            </label>
                        </div>
                    </div>

                    <!-- Tab: Audio Player -->
                    <div id="tab-player" class="fm-tab-panel">
                        <div class="fm-form-group">
                            <label class="fm-form-label">Player Background (Glass)</label>
                            <div class="fm-color-row" data-token="--fm-player-bg">
                                <input type="text" name="player[bg]" class="fm-input-text" value="<?php echo htmlspecialchars((string)$config->get('player', 'bg', 'rgba(15, 23, 42, 0.94)'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="rgba(15, 23, 42, 0.94)">
                            </div>
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label">Player Surface Color</label>
                            <div class="fm-color-row" data-token="--fm-player-surface">
                                <input type="color" class="fm-color-input" value="<?php echo htmlspecialchars((string)$config->get('player', 'surface', '#151d2c'), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="text" name="player[surface]" class="fm-input-text fm-color-hex" value="<?php echo htmlspecialchars((string)$config->get('player', 'surface', '#151d2c'), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label">Progress Accent Color</label>
                            <div class="fm-color-row" data-token="--fm-player-progress">
                                <input type="color" class="fm-color-input" value="<?php echo htmlspecialchars((string)$config->get('player', 'progress_color', '#E50914'), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="text" name="player[progress_color]" class="fm-input-text fm-color-hex" value="<?php echo htmlspecialchars((string)$config->get('player', 'progress_color', '#E50914'), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label">Mini Player Height</label>
                            <div class="fm-range-row" data-token="--fm-player-height" data-unit="px">
                                <input type="range" name="player[height]" class="fm-range-slider" min="56" max="96" value="<?php echo (int)$config->get('player', 'height', 76); ?>">
                                <span class="fm-range-val"><?php echo htmlspecialchars((string)$config->get('player', 'height', '76px'), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                        <div class="fm-form-group">
                            <label class="fm-form-label">Artwork Border Radius</label>
                            <div class="fm-range-row" data-token="--fm-player-art-radius" data-unit="px">
                                <input type="range" name="player[art_radius]" class="fm-range-slider" min="0" max="24" value="<?php echo (int)$config->get('player', 'art_radius', 8); ?>">
                                <span class="fm-range-val"><?php echo htmlspecialchars((string)$config->get('player', 'art_radius', '8px'), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                    </div>

                </form>
            </div>
        </aside>

        <!-- Right Live Preview Frame -->
        <main class="fm-studio-preview-wrap">
            <div id="fm-preview-container" class="fm-preview-frame-container viewport-desktop">
                <iframe id="fm-preview-iframe" class="fm-preview-iframe" src="/admin/page/multimedia-theme?preview=canvas" title="Live Preview Canvas"></iframe>
            </div>
        </main>
    </div>

    <!-- Add Section Modal -->
    <div id="fm-modal-add-section" class="fm-modal-backdrop">
        <div class="fm-modal-box">
            <div class="fm-modal-head">
                <h3 class="fm-modal-title">
                    <span>🧩</span> Add Homepage Section
                </h3>
                <button type="button" id="fm-modal-close" class="fm-modal-close">&times;</button>
            </div>
            <div class="fm-modal-tabs">
                <button type="button" class="fm-modal-tab active" data-cat="all">All</button>
                <button type="button" class="fm-modal-tab" data-cat="featured">Featured</button>
                <button type="button" class="fm-modal-tab" data-cat="video">Video</button>
                <button type="button" class="fm-modal-tab" data-cat="music">Music</button>
                <button type="button" class="fm-modal-tab" data-cat="personal">Personal</button>
                <button type="button" class="fm-modal-tab" data-cat="taxonomy">Taxonomy</button>
            </div>
            <div class="fm-modal-body">
                <?php foreach (($canonicalSections ?? \FavoriteCMS\Multimedia\Theme\Homepage\SectionRegistry::canonicalDefinitions()) as $cType => $cDef): ?>
                    <div class="fm-section-picker-card" data-cat="<?php echo htmlspecialchars($cDef['category'] ?? 'all', ENT_QUOTES, 'UTF-8'); ?>">
                        <div>
                            <div class="fm-sec-picker-name"><?php echo htmlspecialchars($cDef['name'] ?? $cType, ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="fm-sec-picker-desc"><?php echo htmlspecialchars($cDef['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <button type="button" class="fm-sec-picker-btn"
                            data-type="<?php echo htmlspecialchars($cType, ENT_QUOTES, 'UTF-8'); ?>"
                            data-title="<?php echo htmlspecialchars($cDef['default_title'] ?? $cDef['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            data-subtitle="<?php echo htmlspecialchars($cDef['default_subtitle'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            data-layout="<?php echo htmlspecialchars($cDef['default_layout'] ?? 'rail', ENT_QUOTES, 'UTF-8'); ?>"
                            data-card-style="<?php echo htmlspecialchars($cDef['default_card_style'] ?? 'poster', ENT_QUOTES, 'UTF-8'); ?>"
                            data-limit="<?php echo (int)($cDef['default_limit'] ?? 8); ?>">
                            + Add to Page
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="fm-toast" class="fm-toast"></div>
</div>

<script src="/plugins/favorite-multimedia/assets/js/theme-studio/theme-studio.js"></script>
<script src="/plugins/favorite-multimedia/assets/js/homepage-builder/homepage-builder.js"></script>

