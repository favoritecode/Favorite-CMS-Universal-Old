<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Integrations;

use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\User;

class FavoriteDigitalAdapter
{
    /**
     * Check if Favorite Digital plugin is installed, booted, or active in the CMS.
     */
    public static function isAvailable(): bool
    {
        // 1. Check test injection
        if (isset($GLOBALS['_test_favorite_digital_available'])) {
            return (bool)$GLOBALS['_test_favorite_digital_available'];
        }

        // 2. Database table presence is required for active membership operations
        try {
            $container = Container::getInstance();
            if ($container && $container->has(Database::class)) {
                $db = $container->get(Database::class);
                if ($db && method_exists($db, 'tableExists') && $db->tableExists('favorite_digital_memberships')) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    /**
     * Verify whether a user holds a valid premium entitlement for the requested multimedia item.
     *
     * @param User|int|null $user
     * @param string $contentType 'movie', 'series', 'episode', 'song', 'playlist'
     * @param int $contentId
     * @return bool
     */
    public static function userHasEntitlement(User|int|null $user, string $contentType, int $contentId): bool
    {
        $resolvedUser = null;
        if ($user instanceof User) {
            $resolvedUser = $user;
        } elseif (is_numeric($user) && (int)$user > 0) {
            $resolvedUser = User::find((int)$user);
        } elseif (function_exists('current_user')) {
            $resolvedUser = current_user();
        }

        if (!$resolvedUser || \FavoriteCMS\Multimedia\Permissions\MultimediaPermission::isSuspendedUser($resolvedUser)) {
            return false;
        }

        // Test mock injection for entitlement
        if (isset($GLOBALS['_test_favorite_digital_entitled_users'])) {
            $entitled = (array)$GLOBALS['_test_favorite_digital_entitled_users'];
            $uid = (int)$resolvedUser->id;
            if (isset($entitled[$uid]) && $entitled[$uid]) {
                return true;
            }
            return in_array($uid, $entitled, true) || in_array((string)$uid, $entitled, true);
        }

        // Check if Favorite Digital hook is registered
        if (function_exists('apply_filters')) {
            try {
                $filtered = apply_filters('favorite_multimedia.user_has_entitlement', null, $resolvedUser, $contentType, $contentId);
                if ($filtered !== null) {
                    return (bool)$filtered;
                }
            } catch (\Throwable) {
                // Fail closed on error
                return false;
            }
        }

        // If Favorite Digital is installed, inspect active memberships table
        if (self::isAvailable()) {
            try {
                $container = Container::getInstance();
                if ($container && $container->has(Database::class)) {
                    $db = $container->get(Database::class);
                    if ($db && method_exists($db, 'tableExists') && $db->tableExists('favorite_digital_memberships')) {
                        $now = date('Y-m-d H:i:s');
                        $row = $db->selectOne(
                            "SELECT id FROM favorite_digital_memberships 
                             WHERE user_id = ? AND status = 'active' AND (expires_at IS NULL OR expires_at > ?) 
                             LIMIT 1",
                            [(int)$resolvedUser->id, $now]
                        );
                        return $row !== null;
                    }
                }
            } catch (\Throwable) {
                // Fail closed on error
                return false;
            }
        }

        // Fail closed: if Favorite Digital is missing, inactive, unentitled, or expired
        return false;
    }

    /**
     * Retrieve membership details for a user from Favorite Digital.
     */
    public static function getMembershipDetails(User|int|null $user = null): array
    {
        if (isset($GLOBALS['_test_favorite_digital_membership_data'])) {
            return (array)$GLOBALS['_test_favorite_digital_membership_data'];
        }

        if (!self::isAvailable()) {
            return [
                'available'    => false,
                'has_active'   => false,
                'is_in_grace'  => false,
                'status'       => 'unavailable',
                'status_label' => 'Unavailable',
                'plan'         => null,
                'expiry'       => null,
                'raw_expiry'   => null,
                'access'       => 'Standard',
                'auto_renew'   => false,
                'manage_url'   => null,
                'checkout_url' => null,
                'message'      => 'Membership service is currently unavailable.',
            ];
        }

        $resolvedUser = null;
        if ($user instanceof User) {
            $resolvedUser = $user;
        } elseif (is_numeric($user) && (int)$user > 0) {
            $resolvedUser = User::find((int)$user);
        } elseif (function_exists('current_user')) {
            $resolvedUser = current_user();
        }

        if (!$resolvedUser) {
            return [
                'available'    => true,
                'has_active'   => false,
                'is_in_grace'  => false,
                'status'       => 'guest',
                'status_label' => 'Guest',
                'plan'         => null,
                'expiry'       => null,
                'raw_expiry'   => null,
                'access'       => 'Standard',
                'auto_renew'   => false,
                'manage_url'   => null,
                'checkout_url' => self::getCheckoutUrl(),
            ];
        }

        // Mock test injection for active/inactive membership
        if (array_key_exists('_test_favorite_digital_active_membership', $GLOBALS)) {
            $mock = $GLOBALS['_test_favorite_digital_active_membership'];
            if ($mock === null || $mock === false) {
                return [
                    'available'    => true,
                    'has_active'   => false,
                    'is_in_grace'  => false,
                    'status'       => 'none',
                    'status_label' => 'No Active Membership',
                    'plan'         => null,
                    'expiry'       => null,
                    'raw_expiry'   => null,
                    'access'       => 'Standard',
                    'auto_renew'   => false,
                    'manage_url'   => null,
                    'checkout_url' => self::getCheckoutUrl(),
                ];
            }
            if (is_array($mock)) {
                return array_merge([
                    'available'    => true,
                    'has_active'   => true,
                    'is_in_grace'  => false,
                    'status'       => 'active',
                    'status_label' => 'Active',
                    'plan'         => 'Premium Plan',
                    'expiry'       => 'December 31, 2026',
                    'raw_expiry'   => '2026-12-31 23:59:59',
                    'access'       => 'Premium',
                    'auto_renew'   => false,
                    'manage_url'   => '/account/membership',
                    'checkout_url' => self::getCheckoutUrl(),
                ], $mock);
            }
        }

        // Query Favorite Digital server-side
        try {
            $db = Container::getInstance()->get(Database::class);
            $userId = (int)$resolvedUser->id;
            $now = date('Y-m-d H:i:s');

            $row = $db->selectOne(
                "SELECT m.*, mp.`plan_type`, mp.`duration_count`, mp.`duration_unit`, mp.`grace_period_days`, p.`title` AS `plan_title`
                 FROM `favorite_digital_memberships` m
                 LEFT JOIN `favorite_digital_membership_plans` mp ON m.`plan_id` = mp.`id`
                 LEFT JOIN `favorite_digital_products` p ON mp.`product_id` = p.`id`
                 WHERE m.`user_id` = ?
                 ORDER BY m.`id` DESC
                 LIMIT 1",
                [$userId]
            );

            if (!$row) {
                return [
                    'available'    => true,
                    'has_active'   => false,
                    'is_in_grace'  => false,
                    'status'       => 'none',
                    'status_label' => 'No Active Membership',
                    'plan'         => null,
                    'expiry'       => null,
                    'raw_expiry'   => null,
                    'access'       => 'Standard',
                    'auto_renew'   => false,
                    'manage_url'   => null,
                    'checkout_url' => self::getCheckoutUrl(),
                ];
            }

            $rawStatus = (string)($row->status ?? 'none');
            $expiresAt = $row->expires_at ?? null;
            $graceExpiresAt = $row->grace_expires_at ?? null;

            $hasActive = false;
            $isInGrace = false;
            $status = $rawStatus;

            if ($rawStatus === 'active' && ($expiresAt === null || $expiresAt > $now)) {
                $hasActive = true;
                $status = 'active';
            } elseif ($rawStatus === 'grace' && ($graceExpiresAt === null || $graceExpiresAt > $now)) {
                $hasActive = true;
                $isInGrace = true;
                $status = 'grace';
            } elseif ($expiresAt !== null && $expiresAt <= $now) {
                $status = 'expired';
            }

            $statusLabel = match ($status) {
                'active'  => 'Active',
                'grace'   => 'Grace Period',
                'expired' => 'Expired',
                default   => ucfirst($status),
            };

            $planTitle = (string)($row->plan_title ?? $row->plan_type ?? 'Premium Membership');
            $formattedExpiry = $expiresAt ? date('F j, Y', strtotime($expiresAt)) : 'Unlimited';

            return [
                'available'    => true,
                'has_active'   => $hasActive,
                'is_in_grace'  => $isInGrace,
                'status'       => $status,
                'status_label' => $statusLabel,
                'plan'         => $planTitle,
                'expiry'       => $formattedExpiry,
                'raw_expiry'   => $expiresAt,
                'access'       => $hasActive ? 'Premium' : 'Standard',
                'auto_renew'   => !empty($row->auto_renew),
                'manage_url'   => '/account/membership',
                'checkout_url' => self::getCheckoutUrl(),
            ];
        } catch (\Throwable $e) {
            return [
                'available'    => false,
                'has_active'   => false,
                'is_in_grace'  => false,
                'status'       => 'unavailable',
                'status_label' => 'Unavailable',
                'plan'         => null,
                'expiry'       => null,
                'raw_expiry'   => null,
                'access'       => 'Standard',
                'auto_renew'   => false,
                'manage_url'   => null,
                'checkout_url' => null,
                'message'      => 'Membership service is currently unavailable.',
            ];
        }
    }

    /**
     * Canonical checkout/upgrade URL using Favorite Pay via Favorite Digital.
     */
    public static function getCheckoutUrl(): ?string
    {
        if (isset($GLOBALS['_test_favorite_digital_checkout_url'])) {
            return (string)$GLOBALS['_test_favorite_digital_checkout_url'];
        }

        // If Favorite Pay is unavailable, purchases cannot be completed
        if (!FavoritePayAdapter::isAvailable()) {
            return null;
        }

        // Canonical Favorite Digital membership purchase route
        return '/store?product_type=membership';
    }

    /**
     * Canonical URL for membership hub.
     */
    public static function getSubscriptionUrl(): string
    {
        if (function_exists('apply_filters')) {
            $url = apply_filters('favorite_multimedia.subscription_url', null);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return '/multimedia/membership';
    }
}

