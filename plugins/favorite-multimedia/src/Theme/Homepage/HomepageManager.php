<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Theme\Homepage;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Models\Setting;

/**
 * Manages homepage configuration persistence, caching, and section operations.
 */
final class HomepageManager
{
    public const SETTING_GROUP = 'multimedia';
    public const SETTING_KEY = 'homepage_config';

    private static ?self $instance = null;
    private ?HomepageConfig $cachedConfig = null;
    private ?Application $app;

    public function __construct(?Application $app = null)
    {
        $this->app = $app;
    }

    public static function getInstance(?Application $app = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($app);
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function clearCache(): void
    {
        $this->cachedConfig = null;
    }

    public function getActiveConfig(): HomepageConfig
    {
        if ($this->cachedConfig !== null) {
            return $this->cachedConfig;
        }

        try {
            $raw = Setting::get(self::SETTING_GROUP, self::SETTING_KEY);
        } catch (\Throwable $e) {
            $raw = null;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }

        if (!is_array($raw)) {
            $this->cachedConfig = new HomepageConfig();
            return $this->cachedConfig;
        }

        $this->cachedConfig = HomepageConfig::fromArray($raw);
        return $this->cachedConfig;
    }

    public function saveConfig(HomepageConfig|array $config): bool
    {
        $cfg = $config instanceof HomepageConfig ? $config : HomepageConfig::fromArray($config);
        $cfg = $cfg->sanitize();

        $errors = $cfg->validate();
        if (!empty($errors)) {
            return false;
        }

        try {
            Setting::set(self::SETTING_GROUP, self::SETTING_KEY, $cfg->toArray(), 'json');
            $this->cachedConfig = $cfg;
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function resetDefaults(): HomepageConfig
    {
        $defaultConfig = new HomepageConfig();
        $this->saveConfig($defaultConfig);
        return $defaultConfig;
    }

    public function reorderSections(array $orderedIds): HomepageConfig
    {
        $config = $this->getActiveConfig()->reorder($orderedIds);
        $this->saveConfig($config);
        return $config;
    }

    public function addSection(array $sectionData): HomepageConfig
    {
        $config = $this->getActiveConfig()->addSection($sectionData);
        $this->saveConfig($config);
        return $config;
    }

    public function updateSection(string $id, array $data): HomepageConfig
    {
        $config = $this->getActiveConfig()->withSection($id, $data);
        $this->saveConfig($config);
        return $config;
    }

    public function deleteSection(string $id): HomepageConfig
    {
        $config = $this->getActiveConfig()->removeSection($id);
        $this->saveConfig($config);
        return $config;
    }

    public function duplicateSection(string $id): HomepageConfig
    {
        $config = $this->getActiveConfig()->duplicateSection($id);
        $this->saveConfig($config);
        return $config;
    }

    public function toggleSection(string $id, bool $enabled): HomepageConfig
    {
        $config = $this->getActiveConfig()->withSection($id, ['enabled' => $enabled]);
        $this->saveConfig($config);
        return $config;
    }
}

