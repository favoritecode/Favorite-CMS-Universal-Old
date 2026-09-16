<?php
/**
 * Favorite Multimedia — Movies Catalog View
 * 
 * Powered by Theme Tokens & Modular Components
 *
 * @var string $metaTitle
 * @var array $items
 * @var array $genres
 * @var string|null $currentGenre
 * @var string|null $searchQuery
 * @var int $page
 * @var int $totalPages
 */

use FavoriteCMS\Multimedia\Theme\ThemeManager;

$themeManager = ThemeManager::getInstance();
$componentsDir = __DIR__ . '/components';
$currentNav = 'movies';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($metaTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-player.css">
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/theme/theme-tokens.css">
    <link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/theme/multimedia-frontend.css?v=1.0.7">
    <?php echo $themeManager->renderHeadTokens(); ?>
</head>
<body class="fm-theme-body">

    <!-- Global Responsive Header -->
    <?php include $componentsDir . '/header.php'; ?>

    <main class="fm-main-content">
        <div class="fm-page-header">
            <div class="fm-page-title-wrap">
                <h1 class="fm-page-title">🎬 Movies</h1>
                <p class="fm-page-subtitle">Explore featured blockbusters, indie cinema, and classic favorites.</p>
            </div>

            <!-- Filter Bar -->
            <form method="GET" action="/movies" class="fm-filter-bar">
                <input type="text" name="q" class="fm-filter-input" value="<?php echo htmlspecialchars($searchQuery ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search movies by title, director, cast...">
                <select name="genre" class="fm-filter-select" onchange="this.form.submit()">
                    <option value="">All Genres</option>
                    <?php foreach ($genres as $g): ?>
                        <option value="<?php echo htmlspecialchars($g->slug, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($currentGenre === $g->slug) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($g->name, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="fm-btn fm-btn-primary fm-filter-btn">Filter</button>
            </form>
        </div>

        <!-- Movies Grid -->
        <?php if (empty($items)): ?>
            <?php 
            $emptyIcon = '🎬';
            $emptyTitle = 'No Movies Found';
            $emptyMessage = 'No movies matched your current filter criteria.';
            $emptyActionUrl = '/movies';
            $emptyActionLabel = 'Reset All Filters';
            include $componentsDir . '/empty-state.php'; 
            ?>
        <?php else: ?>
            <div class="fm-grid fm-grid-posters">
                <?php foreach ($items as $item): ?>
                    <?php 
                    $cardType = 'movie';
                    include $componentsDir . '/poster-card.php'; 
                    ?>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <nav class="fm-pagination" aria-label="Movies pagination">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="/movies?p=<?php echo $i; ?><?php echo $currentGenre ? '&genre=' . urlencode($currentGenre) : ''; ?><?php echo $searchQuery ? '&q=' . urlencode($searchQuery) : ''; ?>" 
                           class="fm-page-link <?php echo ($i === $page) ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <!-- Mobile Bottom Navigation -->
    <?php include $componentsDir . '/mobile-nav.php'; ?>

    <script src="/plugins/favorite-multimedia/assets/js/frontend/multimedia-frontend.js?v=1.0.7"></script>
</body>
</html>
