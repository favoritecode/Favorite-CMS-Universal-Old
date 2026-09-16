<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Theme;

/**
 * Resolves ThemeConfig into CSS custom properties (--fm-*) and formatted style blocks.
 */
final class ThemeTokenResolver
{
    private ThemeConfig $config;

    public function __construct(ThemeConfig $config)
    {
        $this->config = $config->sanitize();
    }

    /**
     * @return array<string, string>
     */
    public function toCssVariables(): array
    {
        $cfg = $this->config;

        $vars = [
            // Colors
            '--fm-color-primary' => (string)$cfg->get('colors', 'primary', '#E50914'),
            '--fm-color-secondary' => (string)$cfg->get('colors', 'secondary', '#1E293B'),
            '--fm-color-accent' => (string)$cfg->get('colors', 'accent', '#FFB800'),
            '--fm-color-bg-page' => (string)$cfg->get('colors', 'bg_page', '#0B0E14'),
            '--fm-color-bg-surface' => (string)$cfg->get('colors', 'bg_surface', '#121824'),
            '--fm-color-bg-elevated' => (string)$cfg->get('colors', 'bg_elevated', '#1C2436'),
            '--fm-color-text-primary' => (string)$cfg->get('colors', 'text_primary', '#FFFFFF'),
            '--fm-color-text-secondary' => (string)$cfg->get('colors', 'text_secondary', '#94A3B8'),
            '--fm-color-text-muted' => (string)$cfg->get('colors', 'text_muted', '#64748B'),
            '--fm-color-border' => (string)$cfg->get('colors', 'border', '#2A3548'),
            '--fm-color-success' => (string)$cfg->get('colors', 'success', '#10B981'),
            '--fm-color-warning' => (string)$cfg->get('colors', 'warning', '#F59E0B'),
            '--fm-color-danger' => (string)$cfg->get('colors', 'danger', '#EF4444'),

            // Typography
            '--fm-font-heading' => (string)$cfg->get('typography', 'heading_font'),
            '--fm-font-body' => (string)$cfg->get('typography', 'body_font'),
            '--fm-font-bangla' => (string)$cfg->get('typography', 'bangla_font'),
            '--fm-font-weight-heading' => (string)$cfg->get('typography', 'heading_weight', '700'),
            '--fm-font-size-body' => (string)$cfg->get('typography', 'body_size', '15px'),
            '--fm-title-scale' => (string)$cfg->get('typography', 'title_scale', '1.25'),
            '--fm-line-height' => (string)$cfg->get('typography', 'line_height', '1.5'),

            // Layout
            '--fm-layout-max-width' => (string)$cfg->get('layout', 'max_width', '1440px'),
            '--fm-layout-page-padding' => (string)$cfg->get('layout', 'page_padding', '24px'),
            '--fm-layout-section-gap' => (string)$cfg->get('layout', 'section_gap', '48px'),
            '--fm-layout-card-gap' => (string)$cfg->get('layout', 'card_gap', '20px'),
            '--fm-layout-density' => (string)$cfg->get('layout', 'density', 'comfortable'),

            // Surfaces
            '--fm-radius-card' => (string)$cfg->get('surfaces', 'card_radius', '12px'),
            '--fm-radius-button' => (string)$cfg->get('surfaces', 'button_radius', '8px'),
            '--fm-radius-modal' => (string)$cfg->get('surfaces', 'modal_radius', '16px'),
            '--fm-border-strength' => (string)$cfg->get('surfaces', 'border_strength', '1px'),
            '--fm-shadow-card' => (string)$cfg->get('surfaces', 'shadow_strength', '0 8px 24px rgba(0, 0, 0, 0.5)'),
            '--fm-glass-blur' => (string)$cfg->get('surfaces', 'glass_blur', '12px'),

            // Cards
            '--fm-card-radius' => (string)$cfg->get('cards', 'radius', '12px'),
            '--fm-card-shadow' => (string)$cfg->get('cards', 'shadow', '0 4px 12px rgba(0, 0, 0, 0.3)'),
            '--fm-card-hover-lift' => (string)$cfg->get('cards', 'hover_lift', '-6px'),
            '--fm-card-title-lines' => (string)$cfg->get('cards', 'title_lines', '2'),
            '--fm-card-overlay-strength' => (string)$cfg->get('cards', 'overlay_strength', '0.7'),

            // Header
            '--fm-header-height' => (string)$cfg->get('header', 'height', '68px'),
            '--fm-header-logo-height' => (string)$cfg->get('header', 'logo_height', '36px'),

            // Hero
            '--fm-hero-height' => (string)$cfg->get('hero', 'height', '520px'),
            '--fm-hero-gradient-strength' => (string)$cfg->get('hero', 'gradient_strength', '0.85'),
            '--fm-hero-backdrop-darkness' => (string)$cfg->get('hero', 'backdrop_darkness', '0.4'),

            // Buttons
            '--fm-btn-primary-bg' => (string)$cfg->get('buttons', 'primary_bg', '#E50914'),
            '--fm-btn-primary-text' => (string)$cfg->get('buttons', 'primary_text', '#FFFFFF'),
            '--fm-btn-radius' => (string)$cfg->get('buttons', 'radius', '8px'),
            '--fm-btn-height' => (string)$cfg->get('buttons', 'height', '42px'),
            '--fm-btn-padding' => (string)$cfg->get('buttons', 'padding', '0 20px'),
            '--fm-btn-hover-intensity' => (string)$cfg->get('buttons', 'hover_intensity', '1.05'),

            // Motion
            '--fm-motion-speed' => (string)$cfg->get('motion', 'transition_speed', '250ms'),
            '--fm-motion-easing' => 'cubic-bezier(0.4, 0, 0.2, 1)',

            // Responsive scale
            '--fm-scale-mobile' => (string)$cfg->get('responsive', 'mobile_scale', '0.88'),
            '--fm-scale-tablet' => (string)$cfg->get('responsive', 'tablet_scale', '0.94'),
            '--fm-scale-desktop' => (string)$cfg->get('responsive', 'desktop_scale', '1.0'),

            // Audio Player (Chunk 3)
            '--fm-player-bg' => (string)$cfg->get('player', 'bg', 'rgba(15, 23, 42, 0.94)'),
            '--fm-player-surface' => (string)$cfg->get('player', 'surface', '#151d2c'),
            '--fm-player-height' => (string)$cfg->get('player', 'height', '76px'),
            '--fm-player-art-radius' => (string)$cfg->get('player', 'art_radius', '8px'),
            '--fm-player-progress' => (string)$cfg->get('player', 'progress_color', '#E50914'),
            '--fm-player-control-size' => (string)$cfg->get('player', 'control_size', '40px'),

            // Single Content & Sidebar
            '--fm-sidebar-width' => (string)$cfg->get('single_content', 'sidebar_width', '320px'),
            '--fm-sidebar-sticky-offset' => (string)$cfg->get('single_content', 'sidebar_sticky_offset', '20px'),
        ];

        return $vars;
    }

    /**
     * Generates a CSS style declaration string.
     */
    public function toInlineCss(): string
    {
        $vars = $this->toCssVariables();
        $mode = (string)$this->config->get('general', 'default_mode', 'dark');

        $lines = [":root {"];
        foreach ($vars as $prop => $val) {
            $lines[] = "  {$prop}: {$val};";
        }
        $lines[] = "}";

        // Light mode overrides
        $lightOverrides = [
            '--fm-color-bg-page' => '#F8FAFC',
            '--fm-color-bg-surface' => '#FFFFFF',
            '--fm-color-bg-elevated' => '#F1F5F9',
            '--fm-color-text-primary' => '#0F172A',
            '--fm-color-text-secondary' => '#475569',
            '--fm-color-text-muted' => '#94A3B8',
            '--fm-color-border' => '#E2E8F0',
            '--fm-shadow-card' => '0 8px 24px rgba(0, 0, 0, 0.08)',
            '--fm-card-shadow' => '0 4px 12px rgba(0, 0, 0, 0.06)',
            '--fm-player-bg' => 'rgba(255, 255, 255, 0.96)',
            '--fm-player-surface' => '#FFFFFF',
        ];

        if ($mode === 'light') {
            $lines[] = ":root {";
            foreach ($lightOverrides as $prop => $val) {
                $lines[] = "  {$prop}: {$val};";
            }
            $lines[] = "}";
        } elseif ($mode === 'system') {
            $lines[] = "@media (prefers-color-scheme: light) {";
            $lines[] = "  :root {";
            foreach ($lightOverrides as $prop => $val) {
                $lines[] = "    {$prop}: {$val};";
            }
            $lines[] = "  }";
            $lines[] = "}";
        }

        // Support manual light/dark class overrides on html or body
        $lines[] = "html.fm-theme-light, [data-fm-theme='light'] {";
        foreach ($lightOverrides as $prop => $val) {
            $lines[] = "  {$prop}: {$val};";
        }
        $lines[] = "}";

        $lines[] = "html.fm-theme-dark, [data-fm-theme='dark'] {";
        foreach ($vars as $prop => $val) {
            if (str_starts_with($prop, '--fm-color-') || str_starts_with($prop, '--fm-shadow-')) {
                $lines[] = "  {$prop}: {$val};";
            }
        }
        $lines[] = "}";

        return implode("\n", $lines);
    }

    public function renderHtmlStyleTag(): string
    {
        if (!(bool)$this->config->get('general', 'active', true)) {
            return '';
        }

        $css = $this->toInlineCss();
        return "<style id=\"fm-theme-tokens\">\n{$css}\n</style>";
    }
}

