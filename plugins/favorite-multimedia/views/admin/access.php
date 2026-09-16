<?php
/**
 * Favorite Multimedia — Admin Access Control View
 */
?>
<link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-admin.css">

<div class="fav-admin-wrap">
    <div class="fav-admin-header">
        <h2 class="fav-admin-title">🔒 Access Control &amp; Ecosystem Integrations</h2>
    </div>

    <div class="fav-admin-stats-grid">
        <div class="fav-admin-stat-card">
            <p class="fav-admin-stat-label">Favorite Digital</p>
            <p class="fav-admin-stat-number" style="font-size: 20px; color: <?php echo $digitalAvailable ? '#10b981' : '#f59e0b'; ?>;">
                <?php echo $digitalAvailable ? '✅ Integrated' : '⚠️ Not Detected'; ?>
            </p>
            <p style="font-size: 12px; color: #64748b; margin-top: 6px;">
                Powers membership tiers, customer pass verification, and premium digital access.
            </p>
        </div>

        <div class="fav-admin-stat-card">
            <p class="fav-admin-stat-label">Favorite Pay</p>
            <p class="fav-admin-stat-number" style="font-size: 20px; color: <?php echo $payAvailable ? '#10b981' : '#f59e0b'; ?>;">
                <?php echo $payAvailable ? '✅ Integrated' : '⚠️ Optional / Not Active'; ?>
            </p>
            <p style="font-size: 12px; color: #64748b; margin-top: 6px;">
                Powers financial checkout orchestration, BDT payment gateways, and wallet ledger.
            </p>
        </div>
    </div>

    <!-- Access Architecture Documentation Card -->
    <div class="fav-admin-stat-card" style="margin-top: 20px;">
        <h3 style="margin-top:0; font-size: 18px; color: #0f172a;">Core Access Modes</h3>
        <p style="color: #475569; font-size: 14px; line-height: 1.6;">
            Favorite Multimedia enforces a strict 3-tier access architecture verified server-side on every request:
        </p>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; margin: 20px 0;">
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 16px;">
                <span class="fav-badge fav-badge-public" style="margin-bottom: 8px;">PUBLIC</span>
                <p style="font-size: 13px; color: #334155; margin: 6px 0 0 0;">
                    Freely accessible by all visitors and guests. No login or subscription required.
                </p>
            </div>

            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 16px;">
                <span class="fav-badge fav-badge-login" style="margin-bottom: 8px;">LOGIN REQUIRED</span>
                <p style="font-size: 13px; color: #334155; margin: 6px 0 0 0;">
                    Accessible only by registered, authenticated users. Unauthenticated visitors are redirected to login.
                </p>
            </div>

            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 16px;">
                <span class="fav-badge fav-badge-premium" style="margin-bottom: 8px;">PREMIUM</span>
                <p style="font-size: 13px; color: #334155; margin: 6px 0 0 0;">
                    Requires an active Favorite Digital membership or entitlement pass. Non-subscribers see the Premium Subscription CTA.
                </p>
            </div>
        </div>

        <h4 style="margin: 24px 0 8px 0; font-size: 15px;">Web Series Inheritance Rules</h4>
        <ul style="color: #475569; font-size: 13px; line-height: 1.6;">
            <li><strong>Series Level:</strong> Defines default access mode (e.g. Premium).</li>
            <li><strong>Season Level:</strong> Grouping container for episodes.</li>
            <li><strong>Episode Level:</strong> Can inherit from parent Series or explicitly override (e.g. Episode 1 can be set to <code>PUBLIC</code> as a free teaser while remaining episodes remain <code>PREMIUM</code>).</li>
        </ul>

        <h4 style="margin: 20px 0 8px 0; font-size: 15px;">Download Permission vs. Viewing Access</h4>
        <p style="color: #475569; font-size: 13px; line-height: 1.6;">
            Downloading is evaluated independently from viewing. A user must first hold viewing clearance before download permission is checked. If downloads are globally disabled or denied at the content/source level, streaming remains available while the download button is safely disabled.
        </p>
    </div>
</div>

