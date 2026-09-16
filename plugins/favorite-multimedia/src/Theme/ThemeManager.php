<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Theme;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Models\Setting;

/**
 * Main manager and orchestrator for Favorite Multimedia Theme System.
 */
final class ThemeManager
{
    public const SETTING_GROUP = 'multimedia';
    public const SETTING_KEY = 'theme_config';

    private static ?self $instance = null;
    private ?ThemeConfig $cachedConfig = null;
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

    public function getActiveConfig(): ThemeConfig
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
            $this->cachedConfig = new ThemeConfig();
            return $this->cachedConfig;
        }

        $this->cachedConfig = new ThemeConfig($raw);
        return $this->cachedConfig;
    }

    public function getConfig(): ThemeConfig
    {
        return $this->getActiveConfig();
    }

    public function saveConfig(ThemeConfig|array $config): bool
    {
        $cfg = $config instanceof ThemeConfig ? $config : new ThemeConfig($config);
        $cfg = $cfg->sanitize();

        try {
            Setting::set(self::SETTING_GROUP, self::SETTING_KEY, $cfg->toArray(), 'json');
            $this->cachedConfig = $cfg;
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function applyPreset(string $presetId): ThemeConfig
    {
        $current = $this->getActiveConfig();
        $updated = ThemePresetRegistry::applyTo($current, $presetId);
        $this->saveConfig($updated);
        return $updated;
    }

    public function resetSection(string $section): ThemeConfig
    {
        $defaults = ThemeConfig::getDefaults();
        if (!isset($defaults[$section])) {
            return $this->getActiveConfig();
        }

        $current = $this->getActiveConfig();
        $updated = $current->with($section, $defaults[$section]);
        $this->saveConfig($updated);
        return $updated;
    }

    public function resetAll(): ThemeConfig
    {
        $defaultConfig = new ThemeConfig();
        $this->saveConfig($defaultConfig);
        return $defaultConfig;
    }

    public function getTokenResolver(): ThemeTokenResolver
    {
        return new ThemeTokenResolver($this->getActiveConfig());
    }

    public function renderHeadTokens(): string
    {
        return $this->getTokenResolver()->renderHtmlStyleTag();
    }
}

