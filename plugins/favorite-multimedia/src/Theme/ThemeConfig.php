<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Theme;

/**
 * Immutable and validated configuration model for Favorite Multimedia Theme System.
 */
final class ThemeConfig
{
    public const SCHEMA_VERSION = '1.0.0';

    private array $data;

    public function __construct(array $data = [])
    {
        $defaults = self::getDefaults();
        $this->data = self::deepMerge($defaults, $data);
        $this->data['theme_schema_version'] = self::SCHEMA_VERSION;
    }

    public static function getDefaults(): array
    {
        return [
            'theme_schema_version' => self::SCHEMA_VERSION,
            'general' => [
                'active' => true,
                'default_mode' => 'dark', // dark, light, system
                'enable_mode_switcher' => true,
            ],
            'branding' => [
                'logo_url' => '',
                'alt_logo_url' => '',
                'favicon_url' => '',
                'brand_title' => 'Favorite Multimedia',
                'tagline' => 'Stream movies, series, and music',
            ],
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
                'success' => '#10B981',
                'warning' => '#F59E0B',
                'danger' => '#EF4444',
            ],
            'typography' => [
                'heading_font' => "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
                'body_font' => "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
                'bangla_font' => "'Hind Siliguri', 'Noto Sans Bengali', sans-serif",
                'heading_weight' => '700',
                'body_size' => '15px',
                'title_scale' => '1.25',
                'line_height' => '1.5',
            ],
            'layout' => [
                'max_width' => '1440px',
                'page_padding' => '24px',
                'section_gap' => '48px',
                'card_gap' => '20px',
                'density' => 'comfortable', // compact, comfortable, spacious
            ],
            'surfaces' => [
                'card_radius' => '12px',
                'button_radius' => '8px',
                'modal_radius' => '16px',
                'border_strength' => '1px',
                'shadow_strength' => '0 8px 24px rgba(0, 0, 0, 0.5)',
                'glass_blur' => '12px',
            ],
            'cards' => [
                'radius' => '12px',
                'shadow' => '0 4px 12px rgba(0, 0, 0, 0.3)',
                'hover_lift' => '-6px',
                'title_lines' => 2,
                'overlay_strength' => '0.7',
                'badge_style' => 'pill', // pill, rounded, square
            ],
            'header' => [
                'style' => 'glass', // transparent, solid, glass
                'sticky' => true,
                'logo_height' => '36px',
                'height' => '68px',
                'search_style' => 'expanded', // compact, expanded, modal
            ],
            'hero' => [
                'enabled' => true,
                'height' => '520px',
                'text_align' => 'left', // left, center
                'gradient_strength' => '0.85',
                'backdrop_darkness' => '0.4',
                'slide_duration' => 6,
            ],
            'buttons' => [
                'primary_bg' => '#E50914',
                'primary_text' => '#FFFFFF',
                'radius' => '8px',
                'height' => '42px',
                'padding' => '0 20px',
                'hover_intensity' => '1.05',
            ],
            'motion' => [
                'enabled' => true,
                'intensity' => 'standard', // subtle, standard, expressive
                'transition_speed' => '250ms',
                'reduced_motion' => false,
            ],
            'responsive' => [
                'mobile_scale' => '0.88',
                'tablet_scale' => '0.94',
                'desktop_scale' => '1.0',
            ],
            'player' => [
                'bg' => 'rgba(15, 23, 42, 0.94)',
                'surface' => '#151d2c',
                'height' => '76px',
                'art_radius' => '8px',
                'progress_color' => '#E50914',
                'control_size' => '40px',
            ],
            'single_content' => [
                'show_back_link' => false,
                'sidebar_enabled' => true,
                'sidebar_position' => 'right', // right, left
                'sidebar_width' => '320px',
                'sidebar_sticky' => true,
                'sidebar_sticky_offset' => '20px',
                'sidebar_surface' => 'surface', // surface, elevated, glass, transparent
                'enabled_types' => [
                    'movie' => true,
                    'series' => true,
                    'episode' => true,
                    'song' => true,
                    'album' => true,
                    'artist' => true,
                    'playlist' => true,
                ],
                'blocks' => [
                    'poster' => true,
                    'actions' => true,
                    'metadata' => true,
                    'download' => true,
                ],
            ],
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function getSchemaVersion(): string
    {
        return (string)($this->data['theme_schema_version'] ?? self::SCHEMA_VERSION);
    }

    public function get(string $section, ?string $key = null, mixed $default = null): mixed
    {
        if (!isset($this->data[$section])) {
            return $default;
        }

        if ($key === null) {
            return $this->data[$section];
        }

        return $this->data[$section][$key] ?? $default;
    }

    public function with(string $section, array|string $keyOrData, mixed $value = null): self
    {
        $cloned = $this->data;
        if (is_array($keyOrData)) {
            $cloned[$section] = array_merge($cloned[$section] ?? [], $keyOrData);
        } elseif (is_string($keyOrData)) {
            if (!isset($cloned[$section]) || !is_array($cloned[$section])) {
                $cloned[$section] = [];
            }
            $cloned[$section][$keyOrData] = $value;
        }

        return new self($cloned);
    }

    public function merge(array $overrides): self
    {
        $merged = self::deepMerge($this->data, $overrides);
        return new self($merged);
    }

    public function sanitize(): self
    {
        $sanitized = $this->data;

        $sanitizeString = static function (mixed $val): mixed {
            if (!is_string($val)) {
                return $val;
            }
            $clean = preg_replace('#<script\b[^>]*>(.*?)</script>#is', '', $val);
            $clean = strip_tags($clean);
            $clean = preg_replace('/javascript:|expression\(|behavior:/i', '', $clean);
            return trim($clean);
        };

        $sanitizeColor = static function (mixed $val, string $fallback = '#000000'): string {
            if (!is_string($val)) {
                return $fallback;
            }
            $val = trim($val);
            if (preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6}|[A-Fa-f0-9]{8})$/', $val)) {
                return $val;
            }
            if (preg_match('/^rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(?:,\s*[\d.]+\s*)?\)$/i', $val)) {
                return $val;
            }
            return $fallback;
        };

        if (isset($sanitized['branding'])) {
            foreach ($sanitized['branding'] as $k => $v) {
                if (str_ends_with($k, '_url')) {
                    $cleaned = $sanitizeString($v);
                    if ($cleaned !== '' && !preg_match('/^(https?:\/\/|\/)/i', $cleaned)) {
                        $cleaned = '';
                    }
                    $sanitized['branding'][$k] = $cleaned;
                } else {
                    $sanitized['branding'][$k] = $sanitizeString($v);
                }
            }
        }

        if (isset($sanitized['colors']) && is_array($sanitized['colors'])) {
            $defaults = self::getDefaults()['colors'];
            foreach ($sanitized['colors'] as $ck => $cv) {
                $sanitized['colors'][$ck] = $sanitizeColor($cv, $defaults[$ck] ?? '#FFFFFF');
            }
        }

        if (isset($sanitized['general']['default_mode'])) {
            $mode = strtolower(trim((string)$sanitized['general']['default_mode']));
            $sanitized['general']['default_mode'] = in_array($mode, ['dark', 'light', 'system'], true) ? $mode : 'dark';
        }

        if (isset($sanitized['layout']['density'])) {
            $density = strtolower(trim((string)$sanitized['layout']['density']));
            $sanitized['layout']['density'] = in_array($density, ['compact', 'comfortable', 'spacious'], true) ? $density : 'comfortable';
        }

        if (isset($sanitized['cards']['badge_style'])) {
            $bstyle = strtolower(trim((string)$sanitized['cards']['badge_style']));
            $sanitized['cards']['badge_style'] = in_array($bstyle, ['pill', 'rounded', 'square'], true) ? $bstyle : 'pill';
        }

        if (isset($sanitized['header']['style'])) {
            $hstyle = strtolower(trim((string)$sanitized['header']['style']));
            $sanitized['header']['style'] = in_array($hstyle, ['transparent', 'solid', 'glass'], true) ? $hstyle : 'glass';
        }

        if (isset($sanitized['hero']['text_align'])) {
            $align = strtolower(trim((string)$sanitized['hero']['text_align']));
            $sanitized['hero']['text_align'] = in_array($align, ['left', 'center'], true) ? $align : 'left';
        }

        if (isset($sanitized['motion']['intensity'])) {
            $mIntensity = strtolower(trim((string)$sanitized['motion']['intensity']));
            $sanitized['motion']['intensity'] = in_array($mIntensity, ['subtle', 'standard', 'expressive'], true) ? $mIntensity : 'standard';
        }

        if (isset($sanitized['single_content']['sidebar_position'])) {
            $pos = strtolower(trim((string)$sanitized['single_content']['sidebar_position']));
            $sanitized['single_content']['sidebar_position'] = in_array($pos, ['left', 'right'], true) ? $pos : 'right';
        }

        if (isset($sanitized['single_content']['sidebar_surface'])) {
            $surface = strtolower(trim((string)$sanitized['single_content']['sidebar_surface']));
            $sanitized['single_content']['sidebar_surface'] = in_array($surface, ['surface', 'elevated', 'glass', 'transparent'], true) ? $surface : 'surface';
        }

        if (isset($sanitized['single_content']['show_back_link'])) {
            $sanitized['single_content']['show_back_link'] = (bool)$sanitized['single_content']['show_back_link'];
        }

        if (isset($sanitized['single_content']['sidebar_enabled'])) {
            $sanitized['single_content']['sidebar_enabled'] = (bool)$sanitized['single_content']['sidebar_enabled'];
        }

        if (isset($sanitized['single_content']['sidebar_sticky'])) {
            $sanitized['single_content']['sidebar_sticky'] = (bool)$sanitized['single_content']['sidebar_sticky'];
        }

        return new self($sanitized);
    }

    public function validate(): array
    {
        $errors = [];

        $validateColor = static function (mixed $val, string $field) use (&$errors): void {
            if (!is_string($val) || (
                !preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6}|[A-Fa-f0-9]{8})$/', $val) &&
                !preg_match('/^rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(?:,\s*[\d.]+\s*)?\)$/i', $val)
            )) {
                $errors[] = "Invalid color format for {$field}: '{$val}'";
            }
        };

        if (isset($this->data['colors']) && is_array($this->data['colors'])) {
            foreach ($this->data['colors'] as $ck => $cv) {
                $validateColor($cv, "colors.{$ck}");
            }
        }

        if (isset($this->data['buttons']['primary_bg'])) {
            $validateColor($this->data['buttons']['primary_bg'], 'buttons.primary_bg');
        }

        if (isset($this->data['general']['default_mode'])) {
            if (!in_array($this->data['general']['default_mode'], ['dark', 'light', 'system'], true)) {
                $errors[] = "Invalid default mode: '{$this->data['general']['default_mode']}'";
            }
        }

        return $errors;
    }

    private static function deepMerge(array $base, array $replacement): array
    {
        foreach ($replacement as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}
