<?php
/**
 * Favorite Multimedia — Shared Discovery Card Component
 * Variables expected: $item (array with candidate hydration data)
 */
$cType = htmlspecialchars($item['content_type'] ?? 'media', ENT_QUOTES, 'UTF-8');
$cTitle = htmlspecialchars($item['title'] ?? 'Untitled', ENT_QUOTES, 'UTF-8');
$cPoster = htmlspecialchars($item['poster'] ?? '', ENT_QUOTES, 'UTF-8');
$cUrl = htmlspecialchars($item['detail_url'] ?? '#', ENT_QUOTES, 'UTF-8');
$cMeta = htmlspecialchars($item['meta_label'] ?? '', ENT_QUOTES, 'UTF-8');
$cBadge = htmlspecialchars($item['badge'] ?? 'Free', ENT_QUOTES, 'UTF-8');
$cBadgeClass = htmlspecialchars($item['badge_class'] ?? 'fmm-badge-free', ENT_QUOTES, 'UTF-8');
$hasAccess = (bool)($item['has_access'] ?? true);
?>
<div class="fav-mm-card fav-discovery-card">
    <div class="fav-discovery-poster-wrap">
        <a href="<?= $cUrl ?>" class="fav-discovery-poster-link" aria-label="<?= $cTitle ?>">
            <?php if ($cPoster): ?>
                <img src="<?= $cPoster ?>" alt="<?= $cTitle ?>" class="fav-discovery-poster" loading="lazy">
            <?php else: ?>
                <div class="fav-discovery-placeholder">
                    <span><?= strtoupper(substr($cTitle, 0, 2)) ?></span>
                </div>
            <?php endif; ?>
        </a>

        <!-- Access Badge -->
        <span class="fav-access-badge <?= $cBadgeClass ?>"><?= $cBadge ?></span>

        <!-- Content Type Tag -->
        <span class="fav-type-badge"><?= ucfirst($cType) ?></span>

        <?php if (!$hasAccess): ?>
            <div class="fav-lock-overlay" title="Locked content">
                <span>🔒</span>
            </div>
        <?php endif; ?>
    </div>

    <div class="fav-discovery-body">
        <h4 class="fav-discovery-title">
            <a href="<?= $cUrl ?>"><?= $cTitle ?></a>
        </h4>
        <div class="fav-discovery-meta">
            <?php if ($cMeta): ?>
                <span class="fav-discovery-meta-text"><?= $cMeta ?></span>
            <?php endif; ?>
            <a href="<?= $cUrl ?>" class="fav-discovery-play-btn" title="View details">
                <?= ($cType === 'song') ? '▶ Listen' : '▶ Watch' ?>
            </a>
        </div>
    </div>
</div>

