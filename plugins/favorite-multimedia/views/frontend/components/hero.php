<?php
/**
 * Professional Multi-Item Hero Billboard Component.
 *
 * @var array $section
 * @var array $items
 * @var \FavoriteCMS\Models\User|null $user
 */

use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Models\Album;
use FavoriteCMS\Multimedia\Models\Playlist;

if (empty($items)) {
    return;
}

$options = (array)($section['options'] ?? []);
$slideDuration = max(3, min(20, (int)($options['slide_duration'] ?? 6)));
$autoRotation = (bool)($options['auto_rotation'] ?? true);
$hasAnyBackdrop = false;
foreach ($items as $it) {
    if (!empty($it->backdrop_image) || !empty($it->poster_image) || !empty($it->cover_image)) {
        $hasAnyBackdrop = true;
        break;
    }
}
?>
<section class="fm-hero-container fm-hero-section<?php echo !$hasAnyBackdrop ? ' has-compact-hero' : ''; ?>" data-slide-duration="<?php echo $slideDuration; ?>" data-auto-rotate="<?php echo $autoRotation ? '1' : '0'; ?>" aria-label="Featured Showcase">
    <div class="fm-hero-slides-wrapper">
        <?php foreach ($items as $index => $item): 
            $title = (string)($item->title ?? $item->name ?? '');
            $desc = (string)($item->description ?? $item->tagline ?? $item->excerpt ?? '');
            $backdrop = (string)($item->backdrop_image ?? $item->poster_image ?? $item->cover_image ?? '');
            $year = (string)($item->release_year ?? $item->year ?? '');
            $rating = !empty($item->rating) ? number_format((float)$item->rating, 1) : '';
            
            // Determine content type and URLs
            $kind = 'movie';
            $playUrl = '';
            $detailUrl = '';
            $primaryCtaText = 'Watch Now';

            if ($item instanceof Series || isset($item->total_seasons)) {
                $kind = 'series';
                $detailUrl = '/series/' . ($item->slug ?? $item->id);
                $playUrl = $detailUrl;
                $primaryCtaText = 'Stream Series';
            } elseif ($item instanceof Song) {
                $kind = 'song';
                $playUrl = '/multimedia/play/' . $item->id;
                $detailUrl = '/song/' . ($item->slug ?? $item->id);
                $primaryCtaText = 'Listen Now';
            } elseif ($item instanceof Album) {
                $kind = 'album';
                $detailUrl = '/multimedia/album/' . ($item->slug ?? $item->id);
                $playUrl = $detailUrl;
                $primaryCtaText = 'Play Album';
            } elseif ($item instanceof Playlist) {
                $kind = 'playlist';
                $detailUrl = '/playlist/' . ($item->slug ?? $item->id);
                $playUrl = $detailUrl;
                $primaryCtaText = 'Start Playlist';
            } else { // Default Movie
                $detailUrl = '/movie/' . ($item->slug ?? $item->id);
                $playUrl = '/multimedia/play/' . $item->id;
                $primaryCtaText = 'Watch Now';
            }

            $isActive = ($index === 0);
            $hasSlideBackdrop = ($backdrop !== '');
        ?>
            <article class="fm-hero-slide <?php echo $isActive ? 'active' : ''; ?><?php echo !$hasSlideBackdrop ? ' is-compact-fallback' : ''; ?>" data-slide-index="<?php echo $index; ?>" style="<?php echo $hasSlideBackdrop ? "background-image: url('" . htmlspecialchars($backdrop, ENT_QUOTES, 'UTF-8') . "');" : ''; ?>">
                <div class="fm-hero-gradient-overlay" aria-hidden="true"></div>
                
                <div class="fm-hero-content-wrap">
                    <div class="fm-hero-content">
                        <!-- Badge -->
                        <div class="fm-badge-row">
                            <span class="fm-badge fm-badge-primary">Featured</span>
                            <?php if (!empty($item->is_premium) && (int)$item->is_premium === 1): ?>
                                <span class="fm-badge fm-badge-premium">Premium</span>
                            <?php endif; ?>
                        </div>

                        <!-- Title -->
                        <h2 class="fm-hero-title"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h2>

                        <!-- Metadata Row -->
                        <div class="fm-hero-meta">
                            <?php if ($rating !== ''): ?>
                                <span class="fm-hero-rating" aria-label="Rating: <?php echo $rating; ?> out of 10">
                                    <span class="fm-star" aria-hidden="true">★</span> <?php echo $rating; ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($year !== ''): ?>
                                <span class="fm-hero-year"><?php echo htmlspecialchars($year, ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                            <span class="fm-hero-kind"><?php echo ucfirst($kind); ?></span>
                        </div>

                        <!-- Description -->
                        <?php if ($desc !== ''): ?>
                            <p class="fm-hero-desc"><?php echo htmlspecialchars(mb_strimwidth($desc, 0, 220, '...'), ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>

                        <!-- CTA Actions -->
                        <div class="fm-hero-actions">
                            <a href="<?php echo htmlspecialchars($playUrl, ENT_QUOTES, 'UTF-8'); ?>" class="fm-btn fm-btn-primary fm-btn-lg">
                                <span class="fm-btn-icon" aria-hidden="true">▶</span>
                                <span><?php echo htmlspecialchars($primaryCtaText, ENT_QUOTES, 'UTF-8'); ?></span>
                            </a>
                            <a href="<?php echo htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8'); ?>" class="fm-btn fm-btn-secondary fm-btn-lg">
                                <span>More Info</span>
                            </a>
                        </div>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <!-- Multi-Slide Controls -->
    <?php if (count($items) > 1): ?>
        <button type="button" class="fm-hero-nav-arrow fm-hero-prev" aria-label="Previous Slide">‹</button>
        <button type="button" class="fm-hero-nav-arrow fm-hero-next" aria-label="Next Slide">›</button>

        <div class="fm-hero-dots" role="tablist" aria-label="Hero Slide Selector">
            <?php foreach ($items as $idx => $item): ?>
                <button type="button" class="fm-hero-dot <?php echo $idx === 0 ? 'active' : ''; ?>" data-slide-target="<?php echo $idx; ?>" role="tab" aria-selected="<?php echo $idx === 0 ? 'true' : 'false'; ?>" aria-label="Slide <?php echo $idx + 1; ?>"></button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
