<?php
require_once __DIR__ . '/functions.php';
require __DIR__ . '/header.php';

$isCompatible = favorite_multimedia_theme_is_compatible();
$app = \FavoriteCMS\Core\Application::getInstance();
?>

<main class="fm-theme-main" style="flex: 1; padding: 24px; max-width: 1400px; margin: 0 auto; width: 100%; box-sizing: border-box;">

<?php if (!$isCompatible): ?>
    <!-- Dependency Notice Banner -->
    <div class="fm-notice-banner" style="background: rgba(229, 9, 20, 0.12); border: 1px solid var(--fm-color-primary, #e50914); border-radius: var(--fm-radius-card, 12px); padding: 20px 24px; margin-bottom: 32px; color: #fff;">
        <h3 style="margin: 0 0 8px 0; font-size: 1.15rem; display: flex; align-items: center; gap: 8px; font-weight: 700;">
            <span>⚠️</span> Favorite Multimedia Plugin Required
        </h3>
        <p style="margin: 0 0 12px 0; font-size: 0.92rem; line-height: 1.5; color: var(--fm-color-text-secondary, #94a3b8);">
            Favorite Multimedia Theme requires Favorite Multimedia 1.0.6 or newer. Please install and activate the Favorite Multimedia plugin to enable the complete unified video and audio streaming platform.
        </p>
        <a href="/admin/plugins" style="display: inline-block; background: var(--fm-color-primary, #e50914); color: #fff; padding: 8px 16px; border-radius: 6px; font-size: 0.85rem; font-weight: 600;">Manage Plugins &rarr;</a>
    </div>

    <!-- Fallback Content Stream -->
    <section class="fm-posts-fallback">
        <h2 style="font-size: 1.4rem; font-weight: 700; margin-bottom: 16px;">Latest Articles</h2>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px;">
            <?php foreach ($posts ?? [] as $post): ?>
                <article style="background: var(--fm-color-bg-surface); border: 1px solid var(--fm-color-border); border-radius: var(--fm-radius-card); padding: 20px;">
                    <h3 style="margin: 0 0 8px 0; font-size: 1.1rem;">
                        <a href="/post/<?php echo htmlspecialchars($post->slug ?? '', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($post->title ?? 'Untitled', ENT_QUOTES, 'UTF-8'); ?></a>
                    </h3>
                    <p style="font-size: 0.88rem; color: var(--fm-color-text-secondary); line-height: 1.5; margin: 0 0 12px 0;">
                        <?php echo htmlspecialchars(mb_substr(strip_tags($post->content ?? ''), 0, 120)); ?>...
                    </p>
                    <a href="/post/<?php echo htmlspecialchars($post->slug ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="color: var(--fm-color-primary); font-weight: 600; font-size: 0.85rem;">Read More &rarr;</a>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

<?php else: ?>
    <!-- Full Multimedia Streaming Hub -->
    <?php
    try {
        $frontendCtrl = $app->make(\FavoriteCMS\Multimedia\Controllers\MultimediaFrontendController::class);
        $request = $app->has(\FavoriteCMS\Core\Request::class) ? $app->make(\FavoriteCMS\Core\Request::class) : new \FavoriteCMS\Core\Request();
        $response = $frontendCtrl->hub($request);
        echo $response->getContent();
    } catch (\Throwable $e) {
        echo '<div style="padding: 24px; background: rgba(229,9,20,0.1); border-radius: 8px;">Failed to render streaming hub: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
    }
    ?>
<?php endif; ?>

</main>

<?php require __DIR__ . '/footer.php'; ?>
