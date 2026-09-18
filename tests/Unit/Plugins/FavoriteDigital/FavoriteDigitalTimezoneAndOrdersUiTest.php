<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteDigital;

use DateTimeImmutable;
use DateTimeZone;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\DateTime;
use FavoriteCMS\Digital\Support\TimezoneHelper;
use FavoriteCMS\Models\Setting;
use PHPUnit\Framework\TestCase;

class DigitalSettingTestProxy extends Setting
{
    public static function setTestTimezone(string $timezone): void
    {
        self::$cache['general.timezone'] = $timezone;
    }
}

class FavoriteDigitalTimezoneAndOrdersUiTest extends TestCase
{
    protected function tearDown(): void
    {
        Setting::clearCache();
    }

    public function testFormatDatetimeConvertsUtcToConfiguredSiteTimezone(): void
    {
        $utcTimestamp = '2026-09-18 08:00:00';

        // 1. When timezone is UTC
        DigitalSettingTestProxy::setTestTimezone('UTC');
        $this->assertSame('18 Sep 2026, 08:00 AM', fdig_format_datetime($utcTimestamp));

        // 2. When timezone is Asia/Dhaka (+06:00)
        DigitalSettingTestProxy::setTestTimezone('Asia/Dhaka');
        $this->assertSame('18 Sep 2026, 02:00 PM', fdig_format_datetime($utcTimestamp));

        // 3. When timezone is America/New_York (EDT, UTC-4 in September)
        DigitalSettingTestProxy::setTestTimezone('America/New_York');
        $this->assertSame('18 Sep 2026, 04:00 AM', fdig_format_datetime($utcTimestamp));
    }

    public function testFormatDateOutputsDateOnlyInSiteTimezone(): void
    {
        $utcTimestamp = '2026-09-18 20:00:00';

        DigitalSettingTestProxy::setTestTimezone('UTC');
        $this->assertSame('18 Sep 2026', fdig_format_date($utcTimestamp));

        DigitalSettingTestProxy::setTestTimezone('Asia/Dhaka');
        $this->assertSame('19 Sep 2026', fdig_format_date($utcTimestamp));
    }

    public function testFormatHandlesEpochIntegersAndDateTimeObjects(): void
    {
        DigitalSettingTestProxy::setTestTimezone('Asia/Dhaka');

        $epoch = strtotime('2026-09-18 08:00:00 UTC');
        $this->assertSame('18 Sep 2026, 02:00 PM', fdig_format_datetime($epoch));

        $dt = new DateTimeImmutable('2026-09-18 08:00:00', new DateTimeZone('UTC'));
        $this->assertSame('18 Sep 2026, 02:00 PM', fdig_format_datetime($dt));
    }

    public function testDirectHelperClassFormatWithExplicitOverride(): void
    {
        $utc = '2026-09-18 08:00:00';
        $formatted = TimezoneHelper::format($utc, 'Y-m-d H:i:s', 'Asia/Dhaka');
        $this->assertSame('2026-09-18 14:00:00', $formatted);
    }

    public function testOrdersAdminIndexViewRendersPolishedUiAndHumanReadableTimestamps(): void
    {
        DigitalSettingTestProxy::setTestTimezone('Asia/Dhaka');

        $mockOrder = (object)[
            'id'                 => 42,
            'order_number'       => 'ORD-202609-0042',
            'user_id'            => 7,
            'status'             => 'completed',
            'payment_status'     => 'paid',
            'fulfillment_status' => 'fulfilled',
            'currency'           => 'USD',
            'total_amount'       => '149.00',
            'notes'              => 'VIP priority order',
            'created_at'         => '2026-09-18 08:00:00', // In Asia/Dhaka -> 18 Sep 2026, 02:00 PM
            'updated_at'         => '2026-09-18 08:30:00',
        ];

        // Capture view output
        $orders = [$mockOrder];
        $total = 1;
        $page = 1;
        $totalPages = 1;
        $statusFilter = 'all';
        $paymentFilter = 'all';
        $fulfillmentFilter = 'all';
        $search = '';
        $csrfToken = 'test-token-orders-ui';
        $flashSuccess = 'Bulk status updated successfully.';
        $flashError = null;

        ob_start();
        include 'E:/Favorite-CMS-Assets/plugins/favorite-digital/views/admin/orders/index.php';
        $html = ob_get_clean();

        // 1. Verify Human-Readable Formatted Date in Site Timezone
        $this->assertStringContainsString('18 Sep 2026, 02:00 PM', $html);

        // 2. Verify Order ID & Notes
        $this->assertStringContainsString('ORD-202609-0042', $html);
        $this->assertStringContainsString('VIP priority order', $html);
        $this->assertStringContainsString('User #7', $html);

        // 3. Verify Status Badges
        $this->assertStringContainsString('badge-completed', $html);
        $this->assertStringContainsString('badge-pay-paid', $html);
        $this->assertStringContainsString('badge-ful-fulfilled', $html);

        // 4. Verify Filters and Search
        $this->assertStringContainsString('name="search"', $html);
        $this->assertStringContainsString('name="status"', $html);
        $this->assertStringContainsString('name="payment_status"', $html);
        $this->assertStringContainsString('name="fulfillment_status"', $html);

        // 5. Verify Bulk Action form elements
        $this->assertStringContainsString('id="fd-orders-bulk-form"', $html);
        $this->assertStringContainsString('name="_token" value="test-token-orders-ui"', $html);
        $this->assertStringContainsString('name="action" value="bulk_action"', $html);
        $this->assertStringContainsString('name="bulk_action"', $html);
        $this->assertStringContainsString('data-select-all', $html);
        $this->assertStringContainsString('name="ids[]" value="42"', $html);
        $this->assertStringContainsString('initAdminMultiSelect', $html);
    }
}

