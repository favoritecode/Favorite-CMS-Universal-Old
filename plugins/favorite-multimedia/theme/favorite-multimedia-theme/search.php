<?php
require_once __DIR__ . '/functions.php';
if (favorite_multimedia_theme_is_compatible()) {
    $q = $_GET['q'] ?? '';
    header('Location: /multimedia/search' . ($q !== '' ? '?q=' . urlencode($q) : ''));
    exit;
}
require __DIR__ . '/header.php';
?>
<main class="fm-theme-main" style="flex: 1; padding: 32px 24px; max-width: 1000px; margin: 0 auto; width: 100%; box-sizing: border-box;">
    <h1 style="font-size: 1.8rem; font-weight: 700; margin-bottom: 24px;">Search Results: &ldquo;<?php echo htmlspecialchars($query ?? '', ENT_QUOTES, 'UTF-8'); ?>&rdquo;</h1>
    <div style="display: grid; gap: 16px;">
        <?php foreach ($posts ?? [] as $post): ?>
            <article style="background: var(--fm-color-bg-surface); border: 1px solid var(--fm-color-border); border-radius: var(--fm-radius-card); padding: 20px;">
                <h3 style="margin: 0 0 8px 0;"><a href="/post/<?php echo htmlspecialchars($post->slug ?? '', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($post->title ?? '', ENT_QUOTES, 'UTF-8'); ?></a></h3>
                <p style="color: var(--fm-color-text-secondary); margin: 0; font-size: 0.9rem;"><?php echo htmlspecialchars(mb_substr(strip_tags($post->content ?? ''), 0, 140)); ?>...</p>
            </article>
        <?php endforeach; ?>
    </div>
</main>
<?php require __DIR__ . '/footer.php'; ?>
