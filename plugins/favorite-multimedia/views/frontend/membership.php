<?php
/**
 * Favorite Multimedia — Dedicated Membership Hub.
 *
 * Displays membership status, entitlement tier, and canonical Favorite Pay / Favorite Digital checkout flow.
 *
 * @var string $metaTitle
 * @var \FavoriteCMS\Models\User|null $user
 * @var array $membership
 * @var bool $payAvailable
 */

use FavoriteCMS\Multimedia\Theme\ThemeManager;

$themeManager = ThemeManager::getInstance();
$componentsDir = __DIR__ . '/components';
$user = $user ?? current_user();

$isAvailable = !empty($membership['available']);
$hasActive   = !empty($membership['has_active']);
$status      = (string)($membership['status'] ?? 'none');
$statusLabel = (string)($membership['status_label'] ?? 'No Active Membership');
$planTitle   = (string)($membership['plan'] ?? 'Premium Membership');
$expiryDate  = (string)($membership['expiry'] ?? 'Unlimited');
$accessLevel = (string)($membership['access'] ?? 'Standard');
$checkoutUrl = $membership['checkout_url'] ?? null;
$manageUrl   = $membership['manage_url'] ?? null;

?>
<style>
    .fm-membership-wrap {
        max-width: 860px;
        margin: 0 auto;
        padding: 40px 16px 80px;
    }
        .fm-membership-card {
            background: var(--fm-color-surface, #1e293b);
            border: 1px solid var(--fm-color-border, #334155);
            border-radius: var(--fm-radius-lg, 12px);
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
        }
        .fm-membership-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 16px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--fm-color-border, #334155);
            margin-bottom: 24px;
        }
        .fm-membership-title {
            font-family: var(--fm-font-heading, sans-serif);
            font-size: 1.6rem;
            font-weight: 800;
            margin: 0 0 8px;
            color: var(--fm-color-text, #f8fafc);
        }
        .fm-membership-badge {
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 6px 14px;
            border-radius: 20px;
            display: inline-block;
        }
        .fm-badge-active {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        .fm-badge-grace {
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        .fm-badge-expired {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        .fm-badge-none {
            background: rgba(148, 163, 184, 0.15);
            color: #94a3b8;
            border: 1px solid rgba(148, 163, 184, 0.3);
        }
        .fm-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }
        .fm-detail-box {
            background: var(--fm-color-bg-alt, rgba(15, 23, 42, 0.6));
            border: 1px solid var(--fm-color-border, #334155);
            border-radius: var(--fm-radius-md, 8px);
            padding: 16px;
        }
        .fm-detail-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--fm-color-text-muted, #94a3b8);
            font-weight: 600;
            margin-bottom: 4px;
        }
        .fm-detail-value {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--fm-color-text, #f8fafc);
        }
        .fm-actions-row {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            align-items: center;
        }
        .fm-notice-box {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: var(--fm-radius-md, 8px);
            padding: 18px 24px;
            margin-bottom: 24px;
            color: #fca5a5;
        }
        .fm-notice-warn {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.3);
            color: #fcd34d;
        }
        .fm-notice-info {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: #93c5fd;
    </style>

    <section class="membership-page fm-membership-wrap">

            <div style="margin-bottom: 32px;">
                <h1 style="font-family: var(--fm-font-heading); font-size: 2.2rem; font-weight: 800; margin-bottom: 6px; color: var(--fm-color-text);">
                    👑 Membership Status
                </h1>
                <p style="color: var(--fm-color-text-secondary); font-size: 1.05rem; margin: 0;">
                    Manage your multimedia subscription, entitlements, and premium access privileges.
                </p>
            </div>

            <?php if (!$isAvailable): ?>
                <!-- Service Unavailable Graceful State -->
                <div class="fm-notice-box fm-notice-warn" style="font-size: 1.05rem;">
                    <strong>Notice:</strong> Membership service is currently unavailable.
                </div>
                <div class="fm-membership-card">
                    <p style="color: var(--fm-color-text-secondary); margin: 0 0 16px;">
                        The membership and digital entitlement system is temporarily offline or undergoing maintenance.
                        Free public multimedia remains available.
                    </p>
                    <a href="/multimedia" class="fm-btn fm-btn-secondary">Browse Public Catalog</a>
                </div>

            <?php elseif ($hasActive): ?>
                <!-- Active / Grace Period Membership State -->
                <div class="fm-membership-card">
                    <div class="fm-membership-header">
                        <div>
                            <h2 class="fm-membership-title"><?php echo htmlspecialchars($planTitle, ENT_QUOTES, 'UTF-8'); ?></h2>
                            <p style="color: var(--fm-color-text-secondary); margin: 0; font-size: 0.95rem;">
                                Entitlement verified via Favorite Digital
                            </p>
                        </div>
                        <div>
                            <?php if ($status === 'grace'): ?>
                                <span class="fm-membership-badge fm-badge-grace">Grace Period</span>
                            <?php else: ?>
                                <span class="fm-membership-badge fm-badge-active">Active</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="fm-details-grid">
                        <div class="fm-detail-box">
                            <div class="fm-detail-label">Membership Status</div>
                            <div class="fm-detail-value" style="color: #10b981;"><?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div class="fm-detail-box">
                            <div class="fm-detail-label">Plan</div>
                            <div class="fm-detail-value"><?php echo htmlspecialchars($planTitle, ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div class="fm-detail-box">
                            <div class="fm-detail-label">Valid Until</div>
                            <div class="fm-detail-value"><?php echo htmlspecialchars($expiryDate, ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div class="fm-detail-box">
                            <div class="fm-detail-label">Access Level</div>
                            <div class="fm-detail-value" style="color: var(--fm-color-accent, #ffb800);">Premium Access</div>
                        </div>
                    </div>

                    <div class="fm-actions-row">
                        <a href="/multimedia" class="fm-btn fm-btn-primary">
                            ▶ Explore Premium Catalog
                        </a>
                        <?php if (!empty($manageUrl)): ?>
                            <a href="<?php echo htmlspecialchars($manageUrl, ENT_QUOTES, 'UTF-8'); ?>" class="fm-btn fm-btn-secondary">
                                Manage Membership
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

            <?php else: ?>
                <!-- No Active Membership / Expired State -->
                <div class="fm-membership-card">
                    <div class="fm-membership-header">
                        <div>
                            <h2 class="fm-membership-title">
                                <?php echo ($status === 'expired') ? 'Membership Expired' : 'No Active Membership'; ?>
                            </h2>
                            <p style="color: var(--fm-color-text-secondary); margin: 0; font-size: 0.95rem;">
                                Upgrade your account with Favorite Digital to unlock full premium catalog access.
                            </p>
                        </div>
                        <div>
                            <?php if ($status === 'expired'): ?>
                                <span class="fm-membership-badge fm-badge-expired">Expired</span>
                            <?php else: ?>
                                <span class="fm-membership-badge fm-badge-none">Inactive</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="fm-details-grid">
                        <div class="fm-detail-box">
                            <div class="fm-detail-label">Membership Status</div>
                            <div class="fm-detail-value" style="color: #94a3b8;"><?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <?php if (!empty($membership['plan'])): ?>
                            <div class="fm-detail-box">
                                <div class="fm-detail-label">Previous Plan</div>
                                <div class="fm-detail-value"><?php echo htmlspecialchars($planTitle, ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                        <?php endif; ?>
                        <div class="fm-detail-box">
                            <div class="fm-detail-label">Access Level</div>
                            <div class="fm-detail-value">Standard (Free)</div>
                        </div>
                        <div class="fm-detail-box">
                            <div class="fm-detail-label">Payment Gateway</div>
                            <div class="fm-detail-value"><?php echo $payAvailable ? 'Favorite Pay (Ready)' : 'Unavailable'; ?></div>
                        </div>
                    </div>

                    <div class="fm-actions-row">
                        <?php if ($payAvailable && !empty($checkoutUrl)): ?>
                            <a href="<?php echo htmlspecialchars($checkoutUrl, ENT_QUOTES, 'UTF-8'); ?>" class="fm-btn fm-btn-primary">
                                🛒 <?php echo ($status === 'expired') ? 'Renew Membership' : 'Buy Membership'; ?>
                            </a>
                            <a href="<?php echo htmlspecialchars($checkoutUrl, ENT_QUOTES, 'UTF-8'); ?>" class="fm-btn fm-btn-secondary">
                                View Membership Plans
                            </a>
                        <?php else: ?>
                            <div class="fm-notice-box fm-notice-info" style="margin-bottom: 0; width: 100%;">
                                Payment gateway is currently unavailable. Please check back later.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

    </section>
