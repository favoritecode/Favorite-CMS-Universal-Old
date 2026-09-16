<?php
require_once __DIR__ . '/functions.php';
require __DIR__ . '/header.php';
?>
<main class="fm-theme-main" style="flex: 1; padding: 40px 24px; max-width: 900px; margin: 0 auto; width: 100%; box-sizing: border-box;">
    <article style="background: var(--fm-color-bg-surface); border: 1px solid var(--fm-color-border); border-radius: var(--fm-radius-card); padding: 32px 40px;">
        <h1 style="font-size: 2rem; font-weight: 800; margin-bottom: 20px;"><?php echo htmlspecialchars($page->title ?? 'Page', ENT_QUOTES, 'UTF-8'); ?></h1>
        <div style="line-height: 1.8; color: var(--fm-color-text-secondary); font-size: 1rem;">
            <?php echo $page->content ?? ''; ?>
        </div>
    </article>
</main>
<?php require __DIR__ . '/footer.php'; ?>
