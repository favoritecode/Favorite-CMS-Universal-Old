<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Integrations;

use FavoriteCMS\Core\Container;
use FavoriteCMS\Pay\Contracts\PaymentServiceInterface;
use FavoriteCMS\Pay\Domain\Money;

class FavoritePayAdapter
{
    /**
     * Check if Favorite Pay plugin is active in the CMS container.
     */
    public static function isAvailable(): bool
    {
        if (isset($GLOBALS['_test_favorite_pay_available'])) {
            return (bool)$GLOBALS['_test_favorite_pay_available'];
        }

        try {
            $container = Container::getInstance();
            return class_exists('FavoriteCMS\\Pay\\FavoritePayPlugin') && $container && $container->has(PaymentServiceInterface::class);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Retrieve the active payment service instance from Favorite Pay.
     */
    public static function getPaymentService(): ?PaymentServiceInterface
    {
        try {
            $container = Container::getInstance();
            if ($container && $container->has(PaymentServiceInterface::class)) {
                return $container->get(PaymentServiceInterface::class);
            }
        } catch (\Throwable) {
            return null;
        }
        return null;
    }

    /**
     * Get available payment methods registered with Favorite Pay.
     */
    public static function getAvailablePaymentMethods(): array
    {
        $service = self::getPaymentService();
        if ($service) {
            try {
                return $service->getAvailablePaymentMethods();
            } catch (\Throwable) {
                return [];
            }
        }
        return [];
    }

    /**
     * Create a payment intent in Favorite Pay.
     */
    public static function createPaymentIntent(string $reference, int $amountInBDT, array $options = []): ?object
    {
        $service = self::getPaymentService();
        if (!$service) {
            return null;
        }

        try {
            // Amount in poisha (100 poisha = 1 BDT)
            $money = Money::fromMinor($amountInBDT * 100, 'BDT');
            return $service->createIntent('favorite-multimedia', $reference, $money, $options);
        } catch (\Throwable) {
            return null;
        }
    }
}

