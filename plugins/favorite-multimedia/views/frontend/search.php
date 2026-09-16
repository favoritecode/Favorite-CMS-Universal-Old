<?php
/**
 * Favorite Multimedia — Universal Search Experience
 *
 * @var string $metaTitle
 * @var string $query
 * @var string $filter
 * @var array $searchResult
 * @var array<string, \FavoriteCMS\Multimedia\Theme\Search\SearchResultGroup> $groups
 * @var int $totalMatches
 * @var \FavoriteCMS\Models\User|null $user
 */

use FavoriteCMS\Multimedia\Theme\ThemeManager;

$themeManager = ThemeManager::getInstance();
$user = $user ?? current_user();
$componentsDir = __DIR__ . '/components';
$activeFilter = $filter ?? 'all';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($metaTitle ?? 'Search — Favorite Multimedia', ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-player.css">
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/theme/theme-tokens.css">
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/theme/multimedia-frontend.css?v=1.0.7">
    <?php echo $themeManager->renderHeadTokens(); ?>
</head>
<body class="fm-theme-body">

    <!-- Global Responsive Header -->
    <?php 
    $currentNav = 'search';
    include $componentsDir . '/header.php'; 
    ?>

    <!-- Main Search Layout -->
    <main class="fm-main-content">
        <div class="fm-container" style="padding-top: 36px; padding-bottom: 60px;">

            <!-- Search Banner & Form -->
            <div class="fm-search-hero-box" style="margin-bottom: 32px; max-width: 800px;">
                <h1 style="font-family: var(--fm-font-heading); font-size: 2.2rem; font-weight: 800; margin-bottom: 12px;">
                    🔍 Explore Catalog
                </h1>
                <p style="color: var(--fm-color-text-secondary); margin-bottom: 24px;">
                    Search across movies, web series, songs, albums, artists, and playlists.
                </p>

                <!-- Search Input Form with Live Suggestions -->
                <form method="GET" action="/multimedia/search" class="fm-search-page-form" role="search" style="position: relative;">
                    <div style="display: flex; gap: 12px; align-items: center;">
                        <div style="position: relative; flex: 1;">
                            <input 
                                type="search" 
                                name="q" 
                                id="fm-search-input-main"
                                class="fm-search-page-input"
                                value="<?php echo htmlspecialchars($query ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                placeholder="Search by title, artist, director, or genre..."
                                autocomplete="off"
                                autofocus
                                style="width: 100%; height: 50px; padding: 0 48px 0 20px; font-size: 1.05rem; border-radius: var(--fm-radius-button, 8px); background: var(--fm-color-bg-elevated, #1c2436); border: 1px solid var(--fm-color-border, #2a3548); color: var(--fm-color-text-primary, #ffffff); outline: none;"
                            >
                            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($activeFilter, ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="button" id="fm-search-clear-btn" style="position: absolute; right: 14px; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--fm-color-text-muted); cursor: pointer; display: <?php echo !empty($query) ? 'block' : 'none'; ?>;" aria-label="Clear search">✕</button>
                        </div>
                        <button type="submit" class="fm-btn fm-btn-primary" style="height: 50px; padding: 0 28px; font-weight: 700;">Search</button>
                    </div>

                    <!-- Autocomplete Suggestions Dropdown -->
                    <div id="fm-search-suggestions" class="fm-search-suggestions-dropdown" style="display: none;" role="listbox" aria-label="Search suggestions"></div>
                </form>

                <!-- Client-Side Recent Searches -->
                <div id="fm-recent-searches-box" style="margin-top: 14px; display: none;">
                    <span style="font-size: 0.85rem; color: var(--fm-color-text-muted); margin-right: 8px;">Recent:</span>
                    <span id="fm-recent-searches-list" style="display: inline-flex; gap: 8px; flex-wrap: wrap;"></span>
                </div>
            </div>

            <!-- Filter Tabs -->
            <?php if (!empty($query)): ?>
                <div class="fm-search-tabs-bar" style="display: flex; gap: 8px; margin-bottom: 32px; border-bottom: 1px solid var(--fm-color-border, #2a3548); padding-bottom: 12px; overflow-x: auto;">
                    <?php
                    $tabs = [
                        'all'       => ['label' => 'All', 'count' => $totalMatches],
                        'movies'    => ['label' => 'Movies', 'count' => isset($groups['movies']) ? $groups['movies']->getCount() : 0],
                        'series'    => ['label' => 'Series', 'count' => isset($groups['series']) ? $groups['series']->getCount() : 0],
                        'music'     => ['label' => 'Music', 'count' => (isset($groups['songs']) ? $groups['songs']->getCount() : 0) + (isset($groups['albums']) ? $groups['albums']->getCount() : 0)],
                        'artists'   => ['label' => 'Artists', 'count' => isset($groups['artists']) ? $groups['artists']->getCount() : 0],
                        'playlists' => ['label' => 'Playlists', 'count' => isset($groups['playlists']) ? $groups['playlists']->getCount() : 0],
                    ];
                    foreach ($tabs as $tKey => $tData):
                        $isActive = ($activeFilter === $tKey);
                    ?>
                        <a href="/multimedia/search?q=<?php echo urlencode($query); ?>&tab=<?php echo $tKey; ?>" 
                           class="fm-tab-pill <?php echo $isActive ? 'active' : ''; ?>"
                           style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 20px; font-size: 0.9rem; font-weight: 600; text-decoration: none; <?php echo $isActive ? 'background: var(--fm-color-primary, #e50914); color: #fff;' : 'background: var(--fm-color-bg-surface, #121824); color: var(--fm-color-text-secondary); border: 1px solid var(--fm-color-border);'; ?>">
                            <span><?php echo $tData['label']; ?></span>
                            <?php if ($tData['count'] > 0): ?>
                                <span style="font-size: 0.75rem; opacity: 0.85; background: rgba(0,0,0,0.25); padding: 1px 6px; border-radius: 10px;"><?php echo $tData['count']; ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Results Display -->
            <?php if (!empty($query) && !empty($groups)): ?>
                <?php foreach ($groups as $gKey => $group): ?>
                    <section class="fm-search-group-section fav-mm-search-group" style="margin-bottom: 48px;">
                        <div class="fm-section-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                            <h2 style="font-family: var(--fm-font-heading); font-size: 1.4rem; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                                <span><?php echo $group->getIcon(); ?></span>
                                <span><?php echo htmlspecialchars($group->getTitle(), ENT_QUOTES, 'UTF-8'); ?></span>
                                <span style="font-size: 0.85rem; color: var(--fm-color-text-muted); font-weight: 500;">(<?php echo $group->getCount(); ?>)</span>
                            </h2>
                        </div>

                        <?php if ($gKey === 'songs'): ?>
                            <!-- Songs List -->
                            <div class="fm-songs-list" style="background: var(--fm-color-bg-surface, #121824); border-radius: var(--fm-radius-card, 12px); border: 1px solid var(--fm-color-border, #2a3548); padding: 8px 16px;">
                                <?php 
                                $idx = 1;
                                foreach ($group->getItems() as $sg): 
                                    $item = $sg;
                                    $trackNumber = $idx++;
                                    include $componentsDir . '/song-row.php';
                                endforeach; 
                                ?>
                            </div>
                        <?php elseif ($gKey === 'albums'): ?>
                            <!-- Albums Grid -->
                            <div class="fm-grid fm-grid-albums" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: var(--fm-layout-card-gap, 20px);">
                                <?php foreach ($group->getItems() as $alb): 
                                    $item = $alb;
                                    include $componentsDir . '/album-card.php';
                                endforeach; ?>
                            </div>
                        <?php elseif ($gKey === 'artists'): ?>
                            <!-- Artists Grid -->
                            <div class="fm-grid fm-grid-artists" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: var(--fm-layout-card-gap, 20px);">
                                <?php foreach ($group->getItems() as $art): 
                                    $item = $art;
                                    include $componentsDir . '/artist-card.php';
                                endforeach; ?>
                            </div>
                        <?php elseif ($gKey === 'playlists'): ?>
                            <!-- Playlists Grid -->
                            <div class="fm-grid fm-grid-playlists" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: var(--fm-layout-card-gap, 20px);">
                                <?php foreach ($group->getItems() as $pl): 
                                    $item = $pl;
                                    include $componentsDir . '/playlist-card.php';
                                endforeach; ?>
                            </div>
                        <?php else: ?>
                            <!-- Movies & Series Grid -->
                            <div class="fm-grid fm-grid-posters" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: var(--fm-layout-card-gap, 20px);">
                                <?php foreach ($group->getItems() as $it): 
                                    $item = $it;
                                    include $componentsDir . '/poster-card.php';
                                endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>

            <?php elseif (!empty($query) && empty($groups)): ?>
                <!-- No Results State -->
                <?php
                $title = "No matches for \"{$query}\"";
                $message = "We couldn't find any titles, artists, songs, or playlists matching your search. Try checking your spelling or using broader search terms.";
                $icon = "🔍";
                $actionUrl = "/multimedia";
                $actionText = "Browse Catalog";
                include $componentsDir . '/empty-state.php';
                ?>
            <?php else: ?>
                <!-- Blank Initial Search State -->
                <div style="text-align: center; padding: 60px 20px; color: var(--fm-color-text-secondary);">
                    <div style="font-size: 3rem; margin-bottom: 12px;" aria-hidden="true">🎬🎵</div>
                    <h3 style="font-size: 1.3rem; color: var(--fm-color-text-primary); margin-bottom: 8px;">Start typing to search</h3>
                    <p style="max-width: 480px; margin: 0 auto; font-size: 0.95rem;">Find your favorite movies, TV shows, songs, albums, and curated playlists in one place.</p>
                </div>
            <?php endif; ?>

        </div>
    </main>

    <!-- Global Toast Notifications -->
    <?php include $componentsDir . '/toast.php'; ?>

    <!-- Mobile Bottom Navigation -->
    <?php include $componentsDir . '/mobile-nav.php'; ?>

    <script src="/plugins/favorite-multimedia/assets/js/frontend/multimedia-frontend.js?v=1.0.7"></script>
</body>
</html>
