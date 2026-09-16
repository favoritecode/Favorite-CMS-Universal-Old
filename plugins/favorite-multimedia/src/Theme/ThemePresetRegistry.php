<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Theme;

/**
 * Registry of built-in theme presets for Favorite Multimedia.
 */
final class ThemePresetRegistry
{
    public const PRESET_CINEMATIC = 'cinematic';
    public const PRESET_MODERN = 'modern';
    public const PRESET_COMPACT = 'compact';
    public const PRESET_MUSIC_FOCUS = 'music_focus';
    public const PRESET_FAMILY = 'family';

    /**
     * @return array<string, array{name: string, description: string, preview_color: string, config: array}>
     */
    public static function all(): array
    {
        return [
            self::PRESET_CINEMATIC => [
                'id' => self::PRESET_CINEMATIC,
                'name' => 'Cinematic',
                'description' => 'Immersive deep blacks with bold crimson accents and theatrical contrast.',
                'preview_color' => '#E50914',
                'config' => [
                    'colors' => [
                        'primary' => '#E50914',
                        'secondary' => '#1E293B',
                        'accent' => '#FFB800',
                        'bg_page' => '#0B0E14',
                        'bg_surface' => '#121824',
                        'bg_elevated' => '#1C2436',
                        'text_primary' => '#FFFFFF',
                        'text_secondary' => '#94A3B8',
                        'text_muted' => '#64748B',
                        'border' => '#2A3548',
                    ],
                    'layout' => [
                        'density' => 'comfortable',
                        'section_gap' => '48px',
                        'card_gap' => '20px',
                    ],
                    'surfaces' => [
                        'card_radius' => '12px',
                        'button_radius' => '8px',
                    ],
                    'header' => [
                        'style' => 'glass',
                    ],
                    'hero' => [
                        'height' => '520px',
                        'gradient_strength' => '0.85',
                    ],
                    'buttons' => [
                        'primary_bg' => '#E50914',
                        'primary_text' => '#FFFFFF',
                        'radius' => '8px',
                    ],
                ],
            ],
            self::PRESET_MODERN => [
                'id' => self::PRESET_MODERN,
                'name' => 'Modern',
                'description' => 'Sleek dark slate aesthetic with vibrant indigo highlights and refined glassmorphism.',
                'preview_color' => '#6366F1',
                'config' => [
                    'colors' => [
                        'primary' => '#6366F1',
                        'secondary' => '#334155',
                        'accent' => '#0EA5E9',
                        'bg_page' => '#0F172A',
                        'bg_surface' => '#1E293B',
                        'bg_elevated' => '#334155',
                        'text_primary' => '#F8FAFC',
                        'text_secondary' => '#CBD5E1',
                        'text_muted' => '#94A3B8',
                        'border' => '#334155',
                    ],
                    'layout' => [
                        'density' => 'comfortable',
                        'section_gap' => '44px',
                        'card_gap' => '18px',
                    ],
                    'surfaces' => [
                        'card_radius' => '14px',
                        'button_radius' => '10px',
                        'glass_blur' => '16px',
                    ],
                    'header' => [
                        'style' => 'glass',
                    ],
                    'hero' => [
                        'height' => '480px',
                        'gradient_strength' => '0.75',
                    ],
                    'buttons' => [
                        'primary_bg' => '#6366F1',
                        'primary_text' => '#FFFFFF',
                        'radius' => '10px',
                    ],
                ],
            ],
            self::PRESET_COMPACT => [
                'id' => self::PRESET_COMPACT,
                'name' => 'Compact',
                'description' => 'High-density catalog layout maximizing visible items per screen with minimal padding.',
                'preview_color' => '#3B82F6',
                'config' => [
                    'colors' => [
                        'primary' => '#3B82F6',
                        'secondary' => '#1E293B',
                        'accent' => '#10B981',
                        'bg_page' => '#090D16',
                        'bg_surface' => '#0F172A',
                        'bg_elevated' => '#1E293B',
                        'text_primary' => '#F1F5F9',
                        'text_secondary' => '#94A3B8',
                        'text_muted' => '#64748B',
                        'border' => '#1E293B',
                    ],
                    'layout' => [
                        'density' => 'compact',
                        'page_padding' => '16px',
                        'section_gap' => '32px',
                        'card_gap' => '12px',
                    ],
                    'surfaces' => [
                        'card_radius' => '8px',
                        'button_radius' => '6px',
                    ],
                    'cards' => [
                        'radius' => '8px',
                        'title_lines' => 1,
                        'hover_lift' => '-3px',
                    ],
                    'header' => [
                        'style' => 'solid',
                        'height' => '56px',
                    ],
                    'hero' => [
                        'height' => '380px',
                    ],
                    'buttons' => [
                        'primary_bg' => '#3B82F6',
                        'primary_text' => '#FFFFFF',
                        'radius' => '6px',
                        'height' => '36px',
                    ],
                    'motion' => [
                        'transition_speed' => '160ms',
                    ],
                ],
            ],
            self::PRESET_MUSIC_FOCUS => [
                'id' => self::PRESET_MUSIC_FOCUS,
                'name' => 'Music Focus',
                'description' => 'Acoustic charcoal canvas with vibrant emerald rhythm accents and pill buttons.',
                'preview_color' => '#1DB954',
                'config' => [
                    'colors' => [
                        'primary' => '#1DB954',
                        'secondary' => '#282828',
                        'accent' => '#F59E0B',
                        'bg_page' => '#121212',
                        'bg_surface' => '#181818',
                        'bg_elevated' => '#282828',
                        'text_primary' => '#FFFFFF',
                        'text_secondary' => '#B3B3B3',
                        'text_muted' => '#727272',
                        'border' => '#2A2A2A',
                    ],
                    'layout' => [
                        'density' => 'comfortable',
                        'section_gap' => '40px',
                        'card_gap' => '16px',
                    ],
                    'surfaces' => [
                        'card_radius' => '10px',
                        'button_radius' => '9999px',
                    ],
                    'cards' => [
                        'radius' => '10px',
                        'badge_style' => 'pill',
                    ],
                    'header' => [
                        'style' => 'solid',
                    ],
                    'hero' => [
                        'height' => '440px',
                        'text_align' => 'left',
                    ],
                    'buttons' => [
                        'primary_bg' => '#1DB954',
                        'primary_text' => '#000000',
                        'radius' => '9999px',
                    ],
                ],
            ],
            self::PRESET_FAMILY => [
                'id' => self::PRESET_FAMILY,
                'name' => 'Family',
                'description' => 'Warm, accessible design with inviting coral tones, rounded shapes, and clear typography.',
                'preview_color' => '#FF5376',
                'config' => [
                    'colors' => [
                        'primary' => '#FF5376',
                        'secondary' => '#2D325A',
                        'accent' => '#00E5FF',
                        'bg_page' => '#16192E',
                        'bg_surface' => '#202542',
                        'bg_elevated' => '#2D325A',
                        'text_primary' => '#FFFFFF',
                        'text_secondary' => '#CBD5E1',
                        'text_muted' => '#94A3B8',
                        'border' => '#333966',
                    ],
                    'layout' => [
                        'density' => 'spacious',
                        'section_gap' => '56px',
                        'card_gap' => '24px',
                    ],
                    'surfaces' => [
                        'card_radius' => '16px',
                        'button_radius' => '12px',
                        'modal_radius' => '20px',
                    ],
                    'cards' => [
                        'radius' => '16px',
                        'badge_style' => 'rounded',
                    ],
                    'header' => [
                        'style' => 'glass',
                    ],
                    'hero' => [
                        'height' => '500px',
                    ],
                    'buttons' => [
                        'primary_bg' => '#FF5376',
                        'primary_text' => '#FFFFFF',
                        'radius' => '12px',
                        'height' => '44px',
                    ],
                ],
            ],
        ];
    }

    public static function has(string $presetId): bool
    {
        return array_key_exists($presetId, self::all());
    }

    public static function get(string $presetId): ?array
    {
        return self::all()[$presetId] ?? null;
    }

    public static function applyTo(ThemeConfig $baseConfig, string $presetId): ThemeConfig
    {
        $preset = self::get($presetId);
        if ($preset === null) {
            return $baseConfig;
        }

        return $baseConfig->merge($preset['config'])->sanitize();
    }
}

