<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Theme\Homepage;

/**
 * Registry of all canonical Homepage Section types and their capabilities.
 */
final class SectionRegistry
{
    public const TYPE_HERO = 'hero';
    public const TYPE_CONTINUE_WATCHING = 'continue_watching';
    public const TYPE_CONTINUE_LISTENING = 'continue_listening';
    public const TYPE_TRENDING = 'trending';
    public const TYPE_LATEST_MOVIES = 'latest_movies';
    public const TYPE_POPULAR_MOVIES = 'popular_movies';
    public const TYPE_FEATURED_MOVIES = 'featured_movies';
    public const TYPE_LATEST_SERIES = 'latest_series';
    public const TYPE_POPULAR_SERIES = 'popular_series';
    public const TYPE_FEATURED_SERIES = 'featured_series';
    public const TYPE_LATEST_EPISODES = 'latest_episodes';
    public const TYPE_MUSIC_SPOTLIGHT = 'music_spotlight';
    public const TYPE_NEW_SONGS = 'new_songs';
    public const TYPE_POPULAR_SONGS = 'popular_songs';
    public const TYPE_ALBUMS = 'albums';
    public const TYPE_FEATURED_ALBUMS = 'featured_albums';
    public const TYPE_ARTISTS = 'artists';
    public const TYPE_FEATURED_ARTISTS = 'featured_artists';
    public const TYPE_VIDEO_PLAYLISTS = 'video_playlists';
    public const TYPE_AUDIO_PLAYLISTS = 'audio_playlists';
    public const TYPE_GENRES = 'genres';
    public const TYPE_TOP_10 = 'top_10';
    public const TYPE_EDITORS_PICKS = 'editors_picks';
    public const TYPE_CUSTOM_COLLECTION = 'custom_collection';

    public static function all(): array
    {
        return [
            self::TYPE_HERO => [
                'type' => self::TYPE_HERO,
                'name' => 'Hero Billboard',
                'description' => 'Prominent rotating featured billboard with backdrops, badges, and primary play actions.',
                'category' => 'featured',
                'default_title' => 'Featured Premiere',
                'default_subtitle' => 'Watch the biggest hits this season',
                'allowed_card_styles' => ['hero'],
                'default_card_style' => 'hero',
                'allowed_layouts' => ['hero_slider'],
                'default_layout' => 'hero_slider',
                'default_limit' => 5,
                'supports_manual_ids' => true,
                'supports_sorting' => true,
                'default_sort' => 'latest',
            ],
            self::TYPE_CONTINUE_WATCHING => [
                'type' => self::TYPE_CONTINUE_WATCHING,
                'name' => 'Continue Watching',
                'description' => 'Personalized rail displaying unfinished movies and episodes with watch progress bars.',
                'category' => 'personal',
                'default_title' => 'Continue Watching',
                'default_subtitle' => 'Pick up right where you left off',
                'allowed_card_styles' => ['landscape', 'poster'],
                'default_card_style' => 'landscape',
                'allowed_layouts' => ['rail', 'grid'],
                'default_layout' => 'rail',
                'default_limit' => 8,
                'supports_manual_ids' => false,
                'supports_sorting' => false,
                'default_sort' => 'recent',
            ],
            self::TYPE_CONTINUE_LISTENING => [
                'type' => self::TYPE_CONTINUE_LISTENING,
                'name' => 'Continue Listening',
                'description' => 'Personalized row of recently played audio tracks and albums.',
                'category' => 'personal',
                'default_title' => 'Continue Listening',
                'default_subtitle' => 'Jump back into your music',
                'allowed_card_styles' => ['song_row', 'album'],
                'default_card_style' => 'song_row',
                'allowed_layouts' => ['list', 'rail', 'grid'],
                'default_layout' => 'list',
                'default_limit' => 6,
                'supports_manual_ids' => false,
                'supports_sorting' => false,
                'default_sort' => 'recent',
            ],
            self::TYPE_TRENDING => [
                'type' => self::TYPE_TRENDING,
                'name' => 'Trending Media',
                'description' => 'Highest velocity content across movies, series, and music.',
                'category' => 'discovery',
                'default_title' => 'Trending Now',
                'default_subtitle' => 'What everyone is streaming this week',
                'allowed_card_styles' => ['poster', 'landscape', 'mixed'],
                'default_card_style' => 'poster',
                'allowed_layouts' => ['rail', 'grid'],
                'default_layout' => 'rail',
                'default_limit' => 10,
                'supports_manual_ids' => false,
                'supports_sorting' => true,
                'default_sort' => 'trending',
            ],
            self::TYPE_LATEST_MOVIES => [
                'type' => self::TYPE_LATEST_MOVIES,
                'name' => 'Latest Movies',
                'description' => 'Recently released and published movies in chronological order.',
                'category' => 'video',
                'default_title' => 'Latest Movies',
                'default_subtitle' => 'Fresh releases added to the catalog',
                'allowed_card_styles' => ['poster', 'landscape'],
                'default_card_style' => 'poster',
                'allowed_layouts' => ['rail', 'grid'],
                'default_layout' => 'rail',
                'default_limit' => 10,
                'supports_manual_ids' => false,
                'supports_sorting' => true,
                'default_sort' => 'latest',
            ],
            self::TYPE_POPULAR_MOVIES => [
                'type' => self::TYPE_POPULAR_MOVIES,
                'name' => 'Popular Movies',
                'description' => 'Most viewed and highest rated movies.',
                'category' => 'video',
                'default_title' => 'Blockbuster Movies',
                'default_subtitle' => 'The most watched films by our audience',
                'allowed_card_styles' => ['poster', 'landscape'],
                'default_card_style' => 'poster',
                'allowed_layouts' => ['rail', 'grid'],
                'default_layout' => 'rail',
                'default_limit' => 10,
                'supports_manual_ids' => false,
                'supports_sorting' => true,
                'default_sort' => 'popular',
            ],
            self::TYPE_FEATURED_MOVIES => [
                'type' => self::TYPE_FEATURED_MOVIES,
                'name' => 'Featured Movies',
                'description' => 'Curated editorial selection of must-see movies.',
                'category' => 'video',
                'default_title' => 'Featured Cinema',
                'default_subtitle' => 'Handpicked cinema selections',
                'allowed_card_styles' => ['poster', 'landscape'],
                'default_card_style' => 'poster',
                'allowed_layouts' => ['rail', 'grid'],
                'default_layout' => 'rail',
                'default_limit' => 10,
                'supports_manual_ids' => true,
                'supports_sorting' => true,
                'default_sort' => 'latest',
            ],
            self::TYPE_LATEST_SERIES => [
                'type' => self::TYPE_LATEST_SERIES,
                'name' => 'Latest Web Series',
                'description' => 'New series releases and fresh show premieres.',
                'category' => 'video',
                'default_title' => 'New Web Series',
                'default_subtitle' => 'Binge-worthy shows just added',
                'allowed_card_styles' => ['poster', 'landscape'],
                'default_card_style' => 'poster',
                'allowed_layouts' => ['rail', 'grid'],
                'default_layout' => 'rail',
                'default_limit' => 10,
                'supports_manual_ids' => false,
                'supports_sorting' => true,
                'default_sort' => 'latest',
            ],
            self::TYPE_POPULAR_SERIES => [
                'type' => self::TYPE_POPULAR_SERIES,
                'name' => 'Popular Web Series',
                'description' => 'Highest rated and most watched web series.',
                'category' => 'video',
                'default_title' => 'Trending Shows',
                'default_subtitle' => 'Series captivating viewers right now',
                'allowed_card_styles' => ['poster', 'landscape'],
                'default_card_style' => 'poster',
                'allowed_layouts' => ['rail', 'grid'],
                'default_layout' => 'rail',
                'default_limit' => 10,
                'supports_manual_ids' => false,
                'supports_sorting' => true,
                'default_sort' => 'popular',
            ],
            self::TYPE_FEATURED_SERIES => [
                'type' => self::TYPE_FEATURED_SERIES,
                'name' => 'Featured Web Series',
                'description' => 'Editor-highlighted web series.',
                'category' => 'video',
                'default_title' => 'Staff Pick Series',
                'default_subtitle' => 'Acclaimed stories worth your time',
                'allowed_card_styles' => ['poster', 'landscape'],
                'default_card_style' => 'poster',
                'allowed_layouts' => ['rail', 'grid'],
                'default_layout' => 'rail',
                'default_limit' => 10,
                'supports_manual_ids' => true,
                'supports_sorting' => true,
                'default_sort' => 'latest',
            ],
            self::TYPE_LATEST_EPISODES => [
                'type' => self::TYPE_LATEST_EPISODES,
                'name' => 'Latest Episodes',
                'description' => 'Recently aired episodes from active series.',
                'category' => 'video',
                'default_title' => 'New Episodes',
                'default_subtitle' => 'Latest drops from ongoing seasons',
                'allowed_card_styles' => ['landscape'],
                'default_card_style' => 'landscape',
                'allowed_layouts' => ['rail', 'grid'],
                'default_layout' => 'rail',
                'default_limit' => 8,
                'supports_manual_ids' => false,
                'supports_sorting' => true,
                'default_sort' => 'latest',
            ],
            self::TYPE_MUSIC_SPOTLIGHT => [
                'type' => self::TYPE_MUSIC_SPOTLIGHT,
                'name' => 'Music Spotlight',
                'description' => 'Highlighted album or track release with prominent play action.',
                'category' => 'music',
                'default_title' => 'Music Spotlight',
                'default_subtitle' => 'Featured soundscapes & chart toppers',
                'allowed_card_styles' => ['album', 'song_row'],
                'default_card_style' => 'album',
                'allowed_layouts' => ['rail', 'grid'],
                'default_layout' => 'rail',
                'default_limit' => 8,
                'supports_manual_ids' => true,
                'supports_sorting' => true,
                'default_sort' => 'popular',
            ],
            self::TYPE_NEW_SONGS => [
                'type' => self::TYPE_NEW_SONGS,
                'name' => 'New Songs',
                'description' => 'Recently added single tracks and audio releases.',
                'category' => 'music',
                'default_title' => 'New Releases',
                'default_subtitle' => 'Fresh beats and singles',
                'allowed_card_styles' => ['song_row', 'album'],
                'default_card_style' => 'song_row',
                'allowed_layouts' => ['list', 'rail', 'grid'],
                'default_layout' => 'list',
                'default_limit' => 8,
                'supports_manual_ids' => false,
                'supports_sorting' => true,
                'default_sort' => 'latest',
            ],
            self::TYPE_POPULAR_SONGS => [
                'type' => self::TYPE_POPULAR_SONGS,
                'name' => 'Popular Songs',
                'description' => 'Most streamed audio tracks.',
                'category' => 'music',
                'default_title' => 'Top Tracks',
                'default_subtitle' => 'The most played songs right now',
                'allowed_card_styles' => ['song_row', 'album'],
                'default_card_style' => 'song_row',
                'allowed_layouts' => ['list', 'rail', 'grid'],
                'default_layout' => 'list',
                'default_limit' => 8,
                'supports_manual_ids' => false,
                'supports_sorting' => true,
                'default_sort' => 'popular',
            ],
            self::TYPE_ALBUMS => [
                'type' => self::TYPE_ALBUMS,
                'name' => 'Albums',
                'description' => 'Browse full albums and EPs.',
                'category' => 'music',
                'default_title' => 'Albums & EPs',
                'default_subtitle' => 'Complete studio works and collections',
                'allowed_card_styles' => ['album'],
                'default_card_style' => 'album',
                'allowed_layouts' => ['rail', 'grid'],
                'default_layout' => 'rail',
                'default_limit' => 8,
                'supports_manual_ids' => false,
                'supports_sorting' => true,
                'default_sort' => 'latest',
            ],
            self::TYPE_FEATURED_ALBUMS => [
                'type' => self::TYPE_FEATURED_ALBUMS,
                'name' => 'Featured Albums',
                'description' => 'Curated collection of featured music albums.',
                'category' => 'music',
                'default_title' => 'Essential Albums',
                'default_subtitle' => 'Timeless records curated by our editors',
                'allowed_card_styles' => ['album'],
                'default_card_style' => 'album',
                'allowed_layouts' => ['rail', 'grid'],
                'default_layout' => 'rail',
                'default_limit' => 8,
                'supports_manual_ids' => true,
                'supports_sorting' => true,
                'default_sort' => 'latest',
            ],
            self::TYPE_ARTISTS => [
                'type' => self::TYPE_ARTISTS,
                'name' => 'Artists',
                'description' => 'Musicians, bands, and featured creators.',
                'category' => 'music',
                'default_title' => 'Popular Artists',
                'default_subtitle' => 'Explore music by your favorite performers',
                'allowed_card_styles' => ['artist'],
                'default_card_style' => 'artist',
                'allowed_layouts' => ['rail', 'grid'],
                'default_layout' => 'rail',
                'default_limit' => 8,
                'supports_manual_ids' => false,
                'supports_sorting' => true,
                'default_sort' => 'popular',
            ],
            self::TYPE_FEATURED_ARTISTS => [
                'type' => self::TYPE_FEATURED_ARTISTS,
                'name' => 'Featured Artists',
                'description' => 'Editorially highlighted musicians and bands.',
                'category' => 'music',
                'default_title' => 'Artists in the Spotlight',
                'default_subtitle' => 'Creators shaping today’s sound',
                'allowed_card_styles' => ['artist'],
                'default_card_style' => 'artist',
                'allowed_layouts' => ['rail', 'grid'],
                'default_layout' => 'rail',
                'default_limit' => 8,
                'supports_manual_ids' => true,
                'supports_sorting' => true,
                'default_sort' => 'latest',
            ],
            self::TYPE_VIDEO_PLAYLISTS => [
                'type' => self::TYPE_VIDEO_PLAYLISTS,
                'name' => 'Video Playlists',
                'description' => 'Curated video collections, movie franchises, and series marathons.',
                'category' => 'video',
                'default_title' => 'Curated Video Playlists',
                'default_subtitle' => 'Handcrafted queues for continuous viewing',
                'allowed_card_styles' => ['playlist', 'landscape'],
                'default_card_style' => 'playlist',
                'allowed_layouts' => ['rail', 'grid'],
                'default_layout' => 'rail',
                'default_limit' => 6,
                'supports_manual_ids' => false,
                'supports_sorting' => true,
                'default_sort' => 'latest',
            ],
            self::TYPE_AUDIO_PLAYLISTS => [
                'type' => self::TYPE_AUDIO_PLAYLISTS,
                'name' => 'Audio Playlists',
                'description' => 'Themed music playlists, mood mixes, and genre journeys.',
                'category' => 'music',
                'default_title' => 'Vibe Playlists',
                'default_subtitle' => 'Soundtracks for every mood and occasion',
                'allowed_card_styles' => ['playlist', 'album'],
                'default_card_style' => 'playlist',
                'allowed_layouts' => ['rail', 'grid'],
                'default_layout' => 'rail',
                'default_limit' => 6,
                'supports_manual_ids' => false,
                'supports_sorting' => true,
                'default_sort' => 'latest',
            ],
            self::TYPE_GENRES => [
                'type' => self::TYPE_GENRES,
                'name' => 'Genres & Categories',
                'description' => 'Visual discovery cards for multimedia genres.',
                'category' => 'discovery',
                'default_title' => 'Explore by Genre',
                'default_subtitle' => 'Find exactly what fits your mood',
                'allowed_card_styles' => ['landscape', 'poster'],
                'default_card_style' => 'landscape',
                'allowed_layouts' => ['rail', 'grid'],
                'default_layout' => 'rail',
                'default_limit' => 8,
                'supports_manual_ids' => false,
                'supports_sorting' => true,
                'default_sort' => 'popular',
            ],
            self::TYPE_TOP_10 => [
                'type' => self::TYPE_TOP_10,
                'name' => 'Top 10 Rail',
                'description' => 'Prominent numbered ranking cards (1 through 10) for leading releases.',
                'category' => 'discovery',
                'default_title' => 'Top 10 Today',
                'default_subtitle' => 'The ten most streamed titles across the platform',
                'allowed_card_styles' => ['poster'],
                'default_card_style' => 'poster',
                'allowed_layouts' => ['rail'],
                'default_layout' => 'rail',
                'default_limit' => 10,
                'supports_manual_ids' => true,
                'supports_sorting' => true,
                'default_sort' => 'popular',
            ],
            self::TYPE_EDITORS_PICKS => [
                'type' => self::TYPE_EDITORS_PICKS,
                'name' => 'Editor’s Picks',
                'description' => 'Staff selected cinematic and musical gems.',
                'category' => 'discovery',
                'default_title' => 'Editor’s Picks',
                'default_subtitle' => 'Stories and sounds recommended by our team',
                'allowed_card_styles' => ['poster', 'landscape', 'mixed'],
                'default_card_style' => 'poster',
                'allowed_layouts' => ['rail', 'grid'],
                'default_layout' => 'rail',
                'default_limit' => 8,
                'supports_manual_ids' => true,
                'supports_sorting' => true,
                'default_sort' => 'latest',
            ],
            self::TYPE_CUSTOM_COLLECTION => [
                'type' => self::TYPE_CUSTOM_COLLECTION,
                'name' => 'Custom Collection',
                'description' => 'Custom administrator-configured section selecting specific items manually.',
                'category' => 'discovery',
                'default_title' => 'Special Collection',
                'default_subtitle' => 'Exclusively assembled for you',
                'allowed_card_styles' => ['poster', 'landscape', 'album', 'mixed'],
                'default_card_style' => 'poster',
                'allowed_layouts' => ['rail', 'grid'],
                'default_layout' => 'rail',
                'default_limit' => 12,
                'supports_manual_ids' => true,
                'supports_sorting' => false,
                'default_sort' => 'manual',
            ],
        ];
    }

    public static function has(string $type): bool
    {
        return array_key_exists($type, self::all());
    }

    public static function isValidType(string $type): bool
    {
        return self::has($type);
    }

    public static function canonicalDefinitions(): array
    {
        return self::all();
    }

    public static function categories(): array
    {
        return [
            'featured'  => 'Hero & Featured Banner',
            'video'     => 'Movies & Series',
            'music'     => 'Music & Audio',
            'discovery' => 'Discovery & Curated',
            'personal'  => 'Personalized',
        ];
    }

    public static function get(string $type): ?array
    {
        return self::all()[$type] ?? null;
    }
}

