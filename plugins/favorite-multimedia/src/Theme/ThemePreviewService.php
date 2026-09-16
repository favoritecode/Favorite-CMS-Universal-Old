<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Theme;

/**
 * Generates preview data, styles, and markup for the Theme Studio live preview canvas.
 */
final class ThemePreviewService
{
    private ThemeAssetManager $assetManager;

    public function __construct(?ThemeAssetManager $assetManager = null)
    {
        $this->assetManager = $assetManager ?? new ThemeAssetManager();
    }

    /**
     * Resolves draft configuration into CSS variables without saving to DB.
     *
     * @return array<string, string>
     */
    public function getDraftTokens(array $draftData): array
    {
        $config = new ThemeConfig($draftData);
        $resolver = new ThemeTokenResolver($config);
        return $resolver->toCssVariables();
    }

    /**
     * Returns structured mock multimedia data for preview canvas rendering.
     *
     * @return array<string, mixed>
     */
    public function getMockData(): array
    {
        return [
            'hero' => [
                'title' => 'Interstellar Echoes',
                'subtitle' => 'A groundbreaking journey across the cosmos',
                'badge' => 'Exclusive Premiere',
                'rating' => '8.9',
                'duration' => '2h 45m',
                'year' => '2026',
                'genres' => ['Sci-Fi', 'Adventure', 'Mystery'],
                'backdrop' => 'https://images.unsplash.com/photo-1518709268805-4e9042af9f23?auto=format&fit=crop&w=1400&q=80',
            ],
            'movie' => [
                'title' => 'Neon Cyberpunk: 2099',
                'type' => 'Movie',
                'year' => '2025',
                'rating' => '9.2',
                'poster' => 'https://images.unsplash.com/photo-1578632767115-351597cf2477?auto=format&fit=crop&w=600&q=80',
                'quality' => '4K UHD',
            ],
            'series' => [
                'title' => 'The Midnight Detective',
                'type' => 'Series',
                'seasons' => '3 Seasons',
                'rating' => '8.7',
                'poster' => 'https://images.unsplash.com/photo-1536440136628-849c177e76a1?auto=format&fit=crop&w=600&q=80',
                'badge' => 'New Episode',
            ],
            'album' => [
                'title' => 'Midnight Horizons',
                'artist' => 'Luna Ray',
                'tracks' => '12 Tracks',
                'year' => '2026',
                'cover' => 'https://images.unsplash.com/photo-1614613535308-eb5fbd3d2c17?auto=format&fit=crop&w=600&q=80',
            ],
            'songs' => [
                [
                    'number' => '01',
                    'title' => 'Electric Dreams',
                    'artist' => 'Luna Ray',
                    'album' => 'Midnight Horizons',
                    'duration' => '3:45',
                ],
                [
                    'number' => '02',
                    'title' => 'Starlight Serenade',
                    'artist' => 'Luna Ray ft. Nova',
                    'album' => 'Midnight Horizons',
                    'duration' => '4:12',
                ],
            ],
        ];
    }
}

