<?php
require_once __DIR__ . '/functions.php';
require __DIR__ . '/header.php';
?>
<main class="fm-theme-main" style="flex: 1; padding: 60px 24px; text-align: center; max-width: 600px; margin: 0 auto; width: 100%; box-sizing: border-box;">
    <div style="font-size: 72px; margin-bottom: 16px;">🎬</div>
    <h1 style="font-size: 2.5rem; font-weight: 800; margin-bottom: 12px;">404 - Page Not Found</h1>
    <p style="color: var(--fm-color-text-secondary); font-size: 1.05rem; margin-bottom: 28px;">The page or title you are looking for does not exist or has been relocated.</p>
    <div style="display: flex; justify-content: center; gap: 12px;">
        <a href="/" style="background: var(--fm-color-primary); color: #fff; padding: 10px 20px; border-radius: 20px; font-weight: 600;">Go to Home</a>
        <a href="/movies" style="background: var(--fm-color-bg-surface); border: 1px solid var(--fm-color-border); padding: 10px 20px; border-radius: 20px; font-weight: 600;">Browse Movies</a>
    </div>
</main>
<?php require __DIR__ . '/footer.php'; ?>
