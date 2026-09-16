<?php
/**
 * Favorite Multimedia — Storage & CDN Administration
 */
$driver = (string)($settings['multimedia_storage_driver'] ?? 'local');
$s3Endpoint = (string)($settings['multimedia_s3_endpoint'] ?? 'https://s3.amazonaws.com');
$s3Region = (string)($settings['multimedia_s3_region'] ?? 'us-east-1');
$s3Bucket = (string)($settings['multimedia_s3_bucket'] ?? '');
$s3AccessKey = (string)($settings['multimedia_s3_access_key'] ?? '');
$s3HasSecret = !empty($settings['multimedia_s3_secret_key']);
$s3Prefix = (string)($settings['multimedia_s3_path_prefix'] ?? 'multimedia');
$cdnBaseUrl = (string)($settings['multimedia_cdn_base_url'] ?? '');
$signedTtl = (int)($settings['multimedia_signed_url_ttl'] ?? 300);

$totalBytes = (int)($stats['total_bytes'] ?? 0);
$totalFormatted = $totalBytes > 1073741824
    ? round($totalBytes / 1073741824, 2) . ' GB'
    : ($totalBytes > 1048576 ? round($totalBytes / 1048576, 2) . ' MB' : round($totalBytes / 1024, 2) . ' KB');

$health = $stats['health'] ?? ['success' => false, 'message' => 'Not tested'];
?>
<link rel="stylesheet" href="/plugins/favorite-multimedia/assets/css/multimedia-admin.css">

<div class="wrap multimedia-admin">
    <div class="multimedia-header" style="margin-bottom: 24px;">
        <h1 style="font-size: 24px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px;">
            <span>☁️</span> Media Storage & Secure CDN Delivery
        </h1>
        <p style="color: #64748b; font-size: 14px; margin-top: 4px;">
            Configure local file storage, S3-compatible object storage, secure CDN delivery, and media lifecycle cleanup.
        </p>
    </div>

    <!-- Storage Metrics Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <div style="background: #ffffff; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase;">Active Storage Driver</div>
            <div style="font-size: 24px; font-weight: 700; color: #0284c7; margin-top: 6px;">
                <?= htmlspecialchars(strtoupper($driver), ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;">
                <?= $driver === 's3' ? 'S3-compatible object storage' : 'Confined local filesystem' ?>
            </div>
        </div>

        <div style="background: #ffffff; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase;">Managed Storage Size</div>
            <div style="font-size: 24px; font-weight: 700; color: #0f172a; margin-top: 6px;">
                <?= htmlspecialchars($totalFormatted, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;"><?= number_format($totalBytes) ?> bytes tracked</div>
        </div>

        <div style="background: #ffffff; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase;">Storage Health</div>
            <div style="font-size: 20px; font-weight: 700; margin-top: 6px; color: <?= $health['success'] ? '#16a34a' : '#dc2626' ?>;">
                <?= $health['success'] ? '● Operational' : '● Attention Needed' ?>
            </div>
            <div style="font-size: 12px; color: #64748b; margin-top: 4px;"><?= htmlspecialchars($health['message'], ENT_QUOTES, 'UTF-8') ?></div>
        </div>

        <div style="background: #ffffff; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase;">CDN Status</div>
            <div style="font-size: 20px; font-weight: 700; color: <?= !empty($cdnBaseUrl) ? '#16a34a' : '#64748b' ?>; margin-top: 6px;">
                <?= !empty($cdnBaseUrl) ? '● Active' : '○ Direct Origin' ?>
            </div>
            <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;"><?= !empty($cdnBaseUrl) ? htmlspecialchars($cdnBaseUrl, ENT_QUOTES, 'UTF-8') : 'No custom CDN configured' ?></div>
        </div>
    </div>

    <!-- Settings Form -->
    <div style="background: #ffffff; border-radius: 8px; border: 1px solid #e2e8f0; padding: 24px; margin-bottom: 24px;">
        <h2 style="font-size: 18px; font-weight: 600; color: #1e293b; margin-bottom: 16px;">Storage Configuration</h2>

        <form method="POST" action="/admin/multimedia/storage/save" id="storageSettingsForm">
            <?= csrf_field() ?>

            <div style="margin-bottom: 20px;">
                <label style="display: block; font-weight: 600; font-size: 14px; margin-bottom: 6px;">Active Storage Driver</label>
                <div style="display: flex; gap: 20px;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="radio" name="multimedia_storage_driver" value="local" <?= $driver === 'local' ? 'checked' : '' ?>>
                        <span style="font-weight: 500;">Local Storage (Default, no cloud dependencies)</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="radio" name="multimedia_storage_driver" value="s3" <?= $driver === 's3' ? 'checked' : '' ?>>
                        <span style="font-weight: 500;">S3-Compatible Object Storage (AWS S3, R2, MinIO, Wasabi)</span>
                    </label>
                </div>
            </div>

            <div id="s3ConfigSection" style="display: <?= $driver === 's3' ? 'block' : 'none' ?>; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 20px; margin-bottom: 20px;">
                <h3 style="font-size: 15px; font-weight: 600; color: #334155; margin-bottom: 14px;">S3-Compatible Credentials & Endpoint</h3>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 14px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;">Endpoint URL</label>
                        <input type="text" name="multimedia_s3_endpoint" value="<?= htmlspecialchars($s3Endpoint, ENT_QUOTES, 'UTF-8') ?>" class="form-control" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 4px;" placeholder="https://s3.amazonaws.com">
                        <small style="color: #64748b; font-size: 11px;">e.g. AWS S3, Cloudflare R2, or local MinIO instance.</small>
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;">Region</label>
                        <input type="text" name="multimedia_s3_region" value="<?= htmlspecialchars($s3Region, ENT_QUOTES, 'UTF-8') ?>" class="form-control" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 4px;" placeholder="us-east-1">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 14px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;">Bucket Name</label>
                        <input type="text" name="multimedia_s3_bucket" value="<?= htmlspecialchars($s3Bucket, ENT_QUOTES, 'UTF-8') ?>" class="form-control" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 4px;" placeholder="my-media-bucket">
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;">Root Path Prefix</label>
                        <input type="text" name="multimedia_s3_path_prefix" value="<?= htmlspecialchars($s3Prefix, ENT_QUOTES, 'UTF-8') ?>" class="form-control" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 4px;" placeholder="multimedia">
                        <small style="color: #64748b; font-size: 11px;">Plugin operates strictly inside this prefix to prevent cross-bucket deletions.</small>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 14px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;">Access Key ID</label>
                        <input type="text" name="multimedia_s3_access_key" value="<?= htmlspecialchars($s3AccessKey, ENT_QUOTES, 'UTF-8') ?>" class="form-control" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 4px;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;">
                            Secret Access Key <?= $s3HasSecret ? '<span style="color: #16a34a; font-size: 11px;">(Configured)</span>' : '' ?>
                        </label>
                        <input type="password" name="multimedia_s3_secret_key" value="" class="form-control" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 4px;" placeholder="<?= $s3HasSecret ? '•••••••••••••••• (Leave blank to keep existing)' : 'Enter secret key' ?>">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;">Custom CDN / Base URL (Optional)</label>
                        <input type="text" name="multimedia_cdn_base_url" value="<?= htmlspecialchars($cdnBaseUrl, ENT_QUOTES, 'UTF-8') ?>" class="form-control" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 4px;" placeholder="https://cdn.example.com">
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;">Temporary Signed URL Expiration (seconds)</label>
                        <input type="number" name="multimedia_signed_url_ttl" value="<?= (int)$signedTtl ?>" class="form-control" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 4px;" min="60" max="86400">
                    </div>
                </div>
            </div>

            <div style="display: flex; gap: 12px; align-items: center;">
                <button type="submit" class="button button-primary" style="background: #2563eb; color: #fff; padding: 8px 16px; border: none; border-radius: 4px; font-weight: 600; cursor: pointer;">
                    Save Storage Settings
                </button>
                <button type="button" id="btnTestConnection" class="button" style="background: #f1f5f9; color: #334155; padding: 8px 16px; border: 1px solid #cbd5e1; border-radius: 4px; font-weight: 600; cursor: pointer;">
                    Test Connection
                </button>
                <span id="testConnectionStatus" style="font-size: 13px; margin-left: 8px;"></span>
            </div>
        </form>
    </div>

    <!-- Media Migration & Lifecycle Cleanup -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
        <div style="background: #ffffff; border-radius: 8px; border: 1px solid #e2e8f0; padding: 24px;">
            <h3 style="font-size: 16px; font-weight: 600; color: #1e293b; margin-bottom: 8px;">Media Migration Tool</h3>
            <p style="color: #64748b; font-size: 13px; margin-bottom: 16px;">
                Safely migrate existing local media sources and HLS playlists to the configured object storage backend. Local files are preserved by default.
            </p>
            <button type="button" id="btnRunMigration" class="button" style="background: #0284c7; color: #fff; padding: 8px 16px; border: none; border-radius: 4px; font-weight: 600; cursor: pointer;">
                Run Migration to Object Storage
            </button>
            <div id="migrationStatus" style="margin-top: 12px; font-size: 13px; color: #64748b;"></div>
        </div>

        <div style="background: #ffffff; border-radius: 8px; border: 1px solid #e2e8f0; padding: 24px;">
            <h3 style="font-size: 16px; font-weight: 600; color: #1e293b; margin-bottom: 8px;">Orphaned Files & Lifecycle Cleanup</h3>
            <p style="color: #64748b; font-size: 13px; margin-bottom: 16px;">
                Detect and clean unreferenced generated outputs from deleted media. Operates strictly within the plugin prefix.
            </p>
            <div style="display: flex; gap: 10px;">
                <button type="button" id="btnScanOrphans" class="button" style="background: #f1f5f9; color: #334155; padding: 8px 16px; border: 1px solid #cbd5e1; border-radius: 4px; font-weight: 600; cursor: pointer;">
                    Scan Orphans (Dry Run)
                </button>
                <button type="button" id="btnCleanOrphans" class="button" style="background: #ef4444; color: #fff; padding: 8px 16px; border: none; border-radius: 4px; font-weight: 600; cursor: pointer;">
                    Clean Orphans
                </button>
            </div>
            <div id="orphanStatus" style="margin-top: 12px; font-size: 13px; color: #64748b;"></div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const driverRadios = document.querySelectorAll('input[name="multimedia_storage_driver"]');
    const s3Section = document.getElementById('s3ConfigSection');

    driverRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            s3Section.style.display = (this.value === 's3') ? 'block' : 'none';
        });
    });

    const btnTest = document.getElementById('btnTestConnection');
    const testStatus = document.getElementById('testConnectionStatus');

    btnTest.addEventListener('click', function() {
        testStatus.textContent = 'Testing connection...';
        testStatus.style.color = '#64748b';

        fetch('/api/multimedia/admin/storage/test-connection', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('input[name="_token"]')?.value || ''
            }
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                testStatus.textContent = '✓ ' + (data.message || 'Connection successful!');
                testStatus.style.color = '#16a34a';
            } else {
                testStatus.textContent = '✗ ' + (data.error || data.message || 'Connection failed.');
                testStatus.style.color = '#dc2626';
            }
        })
        .catch(err => {
            testStatus.textContent = '✗ Connection error.';
            testStatus.style.color = '#dc2626';
        });
    });
});
</script>
