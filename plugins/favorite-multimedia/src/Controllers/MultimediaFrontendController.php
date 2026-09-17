<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Controllers;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Multimedia\Models\Album;
use FavoriteCMS\Multimedia\Models\AnalyticsEvent;
use FavoriteCMS\Multimedia\Models\Artist;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\Favorite;
use FavoriteCMS\Multimedia\Models\Genre;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\PlaybackProgress;
use FavoriteCMS\Multimedia\Models\Playlist;
use FavoriteCMS\Multimedia\Models\Season;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Models\Subtitle;
use FavoriteCMS\Multimedia\Permissions\MultimediaPermission;
use FavoriteCMS\Multimedia\Services\MediaSourcePlaybackService;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;
use FavoriteCMS\Multimedia\Services\MultimediaDiscoveryService;
use FavoriteCMS\Multimedia\Services\MultimediaEngagementService;
use FavoriteCMS\Multimedia\Services\MultimediaNotificationService;
use FavoriteCMS\Multimedia\Services\MultimediaSubscriptionService;
use FavoriteCMS\Multimedia\Services\PlaybackProgressService;
use FavoriteCMS\Multimedia\Services\UserLibraryService;
use FavoriteCMS\Multimedia\Theme\Audio\AudioAccessResolver;
use FavoriteCMS\Multimedia\Theme\Audio\AudioContextResolver;
use FavoriteCMS\Multimedia\Theme\Audio\AudioPlaybackSession;
use FavoriteCMS\Multimedia\Theme\Search\MultimediaSearchService;
use FavoriteCMS\Multimedia\Theme\Search\SearchSuggestionService;

class MultimediaFrontendController
{
    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    // 1. Multimedia Hub
    public function hub(Request $request): Response
    {
        $movies = Movie::published(8);
        $series = Series::published(8);
        $songs = Song::published(8);
        $playlists = Playlist::published(4);

        $user = current_user();
        if ($user && MultimediaPermission::isSuspendedUser($user)) {
            $user = null; // Suspended user views public content as guest equivalent
        }
        $continueWatching = $user ? UserLibraryService::getContinueWatching($user, 8) : [];
        $continueListening = $user ? UserLibraryService::getContinueListening($user, 6) : [];
        $myList = $user ? UserLibraryService::getMyList($user, 8) : [];

        // Deduplication keys across sections
        $seenKeys = [];
        foreach ($continueWatching as $cw) {
            $seenKeys[] = "{$cw['content_type']}:{$cw['content_id']}";
        }

        $becauseYouWatched = $user ? MultimediaDiscoveryService::getBecauseYouWatched($user, 6) : [];
        if (!empty($becauseYouWatched['items'])) {
            foreach ($becauseYouWatched['items'] as $it) {
                $seenKeys[] = "{$it['content_type']}:{$it['id']}";
            }
        }

        $becauseYouLiked = $user ? MultimediaDiscoveryService::getBecauseYouLiked($user, 6) : [];
        if (!empty($becauseYouLiked['items'])) {
            foreach ($becauseYouLiked['items'] as $it) {
                $seenKeys[] = "{$it['content_type']}:{$it['id']}";
            }
        }

        $personalizedFeed = $user ? MultimediaDiscoveryService::getPersonalizedFeed($user, 8, $seenKeys) : [];
        foreach ($personalizedFeed as $it) {
            $seenKeys[] = "{$it['content_type']}:{$it['id']}";
        }

        $trending = MultimediaDiscoveryService::getTrending(8, null, $user);
        $recentlyAdded = MultimediaDiscoveryService::getRecentlyAdded(8, null, $user);

        $html = $this->renderView('hub', [
            'metaTitle'          => 'Multimedia Catalog — ' . Setting::get('general', 'site_name', 'Favorite CMS'),
            'movies'             => $movies,
            'series'             => $series,
            'songs'              => $songs,
            'playlists'          => $playlists,
            'continueWatching'   => $continueWatching,
            'continueListening'  => $continueListening,
            'myList'             => $myList,
            'becauseYouWatched'  => $becauseYouWatched,
            'becauseYouLiked'    => $becauseYouLiked,
            'personalizedFeed'   => $personalizedFeed,
            'trending'           => $trending,
            'recentlyAdded'      => $recentlyAdded,
            'user'               => $user,
        ]);
        return Response::make($html, 200);
    }

    // 1b. Dedicated Music Destination (/multimedia/music)
    public function music(Request $request): Response
    {
        $user = current_user();
        $continueListening = $user ? UserLibraryService::getContinueListening($user, 6) : [];
        $popularSongs = Song::published(8, 0, null, null, null);
        $newSongs = Song::published(8, 0, null, null, null);
        $featuredAlbums = Album::published(8, 0);
        $artists = Artist::published(8, 0);
        $audioPlaylists = Playlist::published(6, 0);

        $html = $this->renderView('music', [
            'metaTitle'         => 'Music Hub — ' . Setting::get('general', 'site_name', 'Favorite CMS'),
            'user'              => $user,
            'continueListening' => $continueListening,
            'popularSongs'      => $popularSongs,
            'newSongs'          => $newSongs,
            'featuredAlbums'    => $featuredAlbums,
            'artists'           => $artists,
            'audioPlaylists'    => $audioPlaylists,
        ]);

        return Response::make($html, 200);
    }

    // 2. Movies Catalog
    public function movies(Request $request): Response
    {
        $genreSlug = $request->get('genre');
        $search = $request->get('q');
        $page = max(1, (int)$request->get('p', 1));
        $perPage = 16;
        $offset = ($page - 1) * $perPage;

        $items = Movie::published($perPage, $offset, $genreSlug, $search);
        $total = Movie::countPublished($genreSlug, $search);
        $genres = Genre::all();

        $html = $this->renderView('movies-list', [
            'metaTitle' => 'Movies — ' . Setting::get('general', 'site_name', 'Favorite CMS'),
            'items'     => $items,
            'genres'    => $genres,
            'currentGenre' => $genreSlug,
            'searchQuery'  => $search,
            'page'      => $page,
            'totalPages'=> max(1, (int)ceil($total / $perPage)),
        ]);
        return Response::make($html, 200);
    }

    // 3. Movie Detail & Player
    public function movie(Request $request, string $slug): Response
    {
        $movie = Movie::findBySlug($slug);
        if (!$movie) {
            return Response::make('<h1>404 Movie Not Found</h1>', 404);
        }

        $user = current_user();
        if ($movie->status !== 'published') {
            $isOwner = $user && ((int)($movie->user_id ?? 0) === (int)$user->id);
            $isMod = $user && ($user->hasRole('super-admin') || $user->hasRole('admin') || $user->hasRole('moderator') || \FavoriteCMS\Multimedia\Permissions\MultimediaPermission::can(\FavoriteCMS\Multimedia\Permissions\MultimediaPermission::MODERATE, $user));
            if (!$isOwner && !$isMod) {
                return Response::make('<h1>404 Movie Not Found</h1>', 404);
            }
        }
        $playbackData = MediaSourcePlaybackService::getPlayableSources($user, 'movie', $movie);
        $accessState = $playbackData['access'];
        $playableSources = $playbackData['sources'];
        $defaultSource = $playbackData['default_source'];

        // Sources and subtitles
        $sources = $movie->getSources(true);
        $subtitles = $movie->getSubtitles();
        $downloadInfo = MultimediaAccessService::checkDownloadPermission($user, 'movie', $movie, $sources[0] ?? null);

        // Progress & Favorite status
        $isFavorited = $user ? Favorite::isFavorited((int)$user->id, 'movie', (int)$movie->id) : false;
        $progress = $user ? PlaybackProgressService::getProgress($user, 'movie', (int)$movie->id) : null;

        // Related movies discovery
        $relatedMovies = MultimediaDiscoveryService::getRelatedMovies($movie, 6, $user);

        // Community Engagement data
        $ratingAggregate = MultimediaEngagementService::getRatingAggregate('movie', (int)$movie->id);
        $userRating = $user ? MultimediaEngagementService::getUserRating($user, 'movie', (int)$movie->id) : null;
        $reviewsData = MultimediaEngagementService::getReviewsForContent('movie', (int)$movie->id, 10, 0, 'newest', $user);
        $commentsData = MultimediaEngagementService::getCommentsForContent('movie', (int)$movie->id, 20, 0, $user);

        // Log view
        $movie->incrementViews();
        AnalyticsEvent::logEvent('movie', (int)$movie->id, 'view', $user ? (int)$user->id : null);

        $html = $this->renderView('movie-detail', [
            'metaTitle'       => $movie->seo_title ?: ($movie->title . ' — ' . Setting::get('general', 'site_name', 'Favorite CMS')),
            'metaDescription' => $movie->seo_description ?: $movie->description,
            'movie'           => $movie,
            'sources'         => $sources,
            'playableSources' => $playableSources,
            'defaultSource'   => $defaultSource,
            'subtitles'       => $subtitles,
            'accessState'     => $accessState,
            'downloadInfo'    => $downloadInfo,
            'isFavorited'     => $isFavorited,
            'progress'        => $progress,
            'relatedMovies'   => $relatedMovies,
            'ratingAggregate' => $ratingAggregate,
            'userRating'      => $userRating,
            'reviewsData'     => $reviewsData,
            'commentsData'    => $commentsData,
            'user'            => $user,
        ]);
        return Response::make($html, 200);
    }

    // 4. Series Catalog
    public function series(Request $request): Response
    {
        $genreSlug = $request->get('genre');
        $search = $request->get('q');
        $page = max(1, (int)$request->get('p', 1));
        $perPage = 16;
        $offset = ($page - 1) * $perPage;

        $items = Series::published($perPage, $offset, $genreSlug, $search);
        $total = Series::countPublished($genreSlug, $search);
        $genres = Genre::all();

        $html = $this->renderView('series-list', [
            'metaTitle'    => 'Web Series — ' . Setting::get('general', 'site_name', 'Favorite CMS'),
            'items'        => $items,
            'genres'       => $genres,
            'currentGenre' => $genreSlug,
            'searchQuery'  => $search,
            'page'         => $page,
            'totalPages'   => max(1, (int)ceil($total / $perPage)),
        ]);
        return Response::make($html, 200);
    }

    // 5. Series Detail (Seasons & Episodes)
    public function seriesSingle(Request $request, string $slug): Response
    {
        $series = Series::findBySlug($slug);
        if (!$series) {
            return Response::make('<h1>404 Series Not Found</h1>', 404);
        }

        $user = current_user();
        if ($series->status !== 'published') {
            $isOwner = $user && ((int)($series->user_id ?? 0) === (int)$user->id);
            $isMod = $user && ($user->hasRole('super-admin') || $user->hasRole('admin') || $user->hasRole('moderator') || \FavoriteCMS\Multimedia\Permissions\MultimediaPermission::can(\FavoriteCMS\Multimedia\Permissions\MultimediaPermission::MODERATE, $user));
            if (!$isOwner && !$isMod) {
                return Response::make('<h1>404 Series Not Found</h1>', 404);
            }
        }

        $seasons = $series->getSeasons();
        $accessState = MultimediaAccessService::checkAccess($user, 'series', $series);

        $isFavorited = $user ? Favorite::isFavorited((int)$user->id, 'series', (int)$series->id) : false;
        $seriesProgress = PlaybackProgressService::getSeriesProgress($user, (int)$series->id);

        // Related series discovery
        $relatedSeries = MultimediaDiscoveryService::getRelatedSeries($series, 6, $user);

        // Community Engagement data
        $ratingAggregate = MultimediaEngagementService::getRatingAggregate('series', (int)$series->id);
        $userRating = $user ? MultimediaEngagementService::getUserRating($user, 'series', (int)$series->id) : null;
        $reviewsData = MultimediaEngagementService::getReviewsForContent('series', (int)$series->id, 10, 0, 'newest', $user);
        $commentsData = MultimediaEngagementService::getCommentsForContent('series', (int)$series->id, 20, 0, $user);

        $series->incrementViews();
        AnalyticsEvent::logEvent('series', (int)$series->id, 'view', $user ? (int)$user->id : null);

        $isFollowing = $user ? MultimediaSubscriptionService::isFollowing($user, 'series', (int)$series->id) : false;

        $html = $this->renderView('series-detail', [
            'metaTitle'       => $series->seo_title ?: ($series->title . ' — ' . Setting::get('general', 'site_name', 'Favorite CMS')),
            'metaDescription' => $series->seo_description ?: $series->description,
            'series'          => $series,
            'seasons'         => $seasons,
            'accessState'     => $accessState,
            'isFavorited'     => $isFavorited,
            'isFollowing'     => $isFollowing,
            'seriesProgress'  => $seriesProgress,
            'relatedSeries'   => $relatedSeries,
            'ratingAggregate' => $ratingAggregate,
            'userRating'      => $userRating,
            'reviewsData'     => $reviewsData,
            'commentsData'    => $commentsData,
            'user'            => $user,
        ]);
        return Response::make($html, 200);
    }

    // 6. Episode Player
    public function episode(Request $request, string $slug): Response
    {
        $episode = Episode::findBySlug($slug);
        if (!$episode) {
            return Response::make('<h1>404 Episode Not Found</h1>', 404);
        }

        $series = $episode->getSeries();
        $season = $episode->getSeason();
        $user = current_user();

        if ($episode->status !== 'published' || ($series && ($series->status ?? 'published') !== 'published')) {
            $isOwner = $user && (((int)($episode->user_id ?? 0) === (int)$user->id) || ($series && (int)($series->user_id ?? 0) === (int)$user->id));
            $isMod = $user && ($user->hasRole('super-admin') || $user->hasRole('admin') || $user->hasRole('moderator') || \FavoriteCMS\Multimedia\Permissions\MultimediaPermission::can(\FavoriteCMS\Multimedia\Permissions\MultimediaPermission::MODERATE, $user));
            if (!$isOwner && !$isMod) {
                return Response::make('<h1>404 Episode Not Found</h1>', 404);
            }
        }
        $playbackData = MediaSourcePlaybackService::getPlayableSources($user, 'episode', $episode);
        $accessState = $playbackData['access'];
        $playableSources = $playbackData['sources'];
        $defaultSource = $playbackData['default_source'];

        $sources = $episode->getSources(true);
        $subtitles = $episode->getSubtitles();
        $downloadInfo = MultimediaAccessService::checkDownloadPermission($user, 'episode', $episode, $sources[0] ?? null);

        $progress = $user ? PlaybackProgressService::getProgress($user, 'episode', (int)$episode->id) : null;
        $nextEpisode = PlaybackProgressService::getNextEpisode($user, (int)$episode->id);

        // Community Engagement data
        $ratingAggregate = MultimediaEngagementService::getRatingAggregate('episode', (int)$episode->id);
        $userRating = $user ? MultimediaEngagementService::getUserRating($user, 'episode', (int)$episode->id) : null;
        $commentsData = MultimediaEngagementService::getCommentsForContent('episode', (int)$episode->id, 20, 0, $user);

        $episode->incrementViews();
        AnalyticsEvent::logEvent('episode', (int)$episode->id, 'view', $user ? (int)$user->id : null);

        $html = $this->renderView('episode-detail', [
            'metaTitle'       => "{$episode->title} — {$series->title}",
            'episode'         => $episode,
            'series'          => $series,
            'season'          => $season,
            'sources'         => $sources,
            'playableSources' => $playableSources,
            'defaultSource'   => $defaultSource,
            'subtitles'       => $subtitles,
            'accessState'     => $accessState,
            'downloadInfo'    => $downloadInfo,
            'progress'        => $progress,
            'nextEpisode'     => $nextEpisode,
            'ratingAggregate' => $ratingAggregate,
            'userRating'      => $userRating,
            'reviewsData'     => ['reviews' => [], 'total' => 0],
            'commentsData'    => $commentsData,
            'user'            => $user,
        ]);
        return Response::make($html, 200);
    }

    // 7. Songs Catalog
    public function songs(Request $request): Response
    {
        $genreSlug = $request->get('genre');
        $search = $request->get('q');
        $page = max(1, (int)$request->get('p', 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $items = Song::published($perPage, $offset, null, null, $genreSlug, $search);
        $total = Song::countPublished(null, null, $genreSlug, $search);
        $genres = Genre::all();

        $html = $this->renderView('songs-list', [
            'metaTitle'    => 'Songs — ' . Setting::get('general', 'site_name', 'Favorite CMS'),
            'items'        => $items,
            'genres'       => $genres,
            'currentGenre' => $genreSlug,
            'searchQuery'  => $search,
            'page'         => $page,
            'totalPages'   => max(1, (int)ceil($total / $perPage)),
        ]);
        return Response::make($html, 200);
    }

    // 8. Song Detail
    public function song(Request $request, string $slug): Response
    {
        $song = Song::findBySlug($slug);
        if (!$song) {
            return Response::make('<h1>404 Song Not Found</h1>', 404);
        }

        $user = current_user();
        if ($song->status !== 'published') {
            $isOwner = $user && ((int)($song->user_id ?? 0) === (int)$user->id);
            $isMod = $user && ($user->hasRole('super-admin') || $user->hasRole('admin') || $user->hasRole('moderator') || \FavoriteCMS\Multimedia\Permissions\MultimediaPermission::can(\FavoriteCMS\Multimedia\Permissions\MultimediaPermission::MODERATE, $user));
            if (!$isOwner && !$isMod) {
                return Response::make('<h1>404 Song Not Found</h1>', 404);
            }
        }
        $playbackData = MediaSourcePlaybackService::getPlayableSources($user, 'song', $song);
        $accessState = $playbackData['access'];
        $defaultSource = $playbackData['default_source'];
        $defaultAudioSource = $playbackData['default_audio_source'];
        $defaultVideoSource = $playbackData['default_video_source'];
        $audioSources = $playbackData['audio_sources'];
        $videoSources = $playbackData['video_sources'];
        $hasAudio = $playbackData['has_audio'];
        $hasVideo = $playbackData['has_video'];
        $playbackType = $playbackData['playback_type'];
        $defaultPlaybackMode = $playbackData['default_playback_mode'];

        $downloadSourceModel = null;
        $sourceId = (int)($defaultAudioSource['id'] ?? ($defaultSource['id'] ?? 0));
        if ($sourceId > 0) {
            $downloadSourceModel = MediaSource::find($sourceId);
        }
        if (!$downloadSourceModel && method_exists($song, 'getDefaultAudioSource')) {
            $downloadSourceModel = $song->getDefaultAudioSource() ?: $song->getDefaultSource();
        }
        $downloadInfo = MultimediaAccessService::checkDownloadPermission($user, 'song', $song, $downloadSourceModel);

        $subtitles = Subtitle::getForContent('song', (int)$song->id);
        $progress = $user ? PlaybackProgressService::getProgress($user, 'song', (int)$song->id) : null;

        $song->incrementPlays();
        AnalyticsEvent::logEvent('song', (int)$song->id, 'play', $user ? (int)$user->id : null);

        $isFavorite = $user ? Favorite::isFavorited((int)$user->id, 'song', (int)$song->id) : false;

        // Related songs and playlists discovery
        $relatedSongs = MultimediaDiscoveryService::getRelatedSongs($song, 6, $user);
        $relatedPlaylists = MultimediaDiscoveryService::getRelatedPlaylists($song, 4, $user);

        // Community Engagement data
        $ratingAggregate = MultimediaEngagementService::getRatingAggregate('song', (int)$song->id);
        $userRating = $user ? MultimediaEngagementService::getUserRating($user, 'song', (int)$song->id) : null;
        $reviewsData = MultimediaEngagementService::getReviewsForContent('song', (int)$song->id, 10, 0, 'newest', $user);
        $commentsData = MultimediaEngagementService::getCommentsForContent('song', (int)$song->id, 20, 0, $user);

        $html = $this->renderView('song-detail', [
            'metaTitle'           => $song->seo_title ?: ($song->title . ' — ' . Setting::get('general', 'site_name', 'Favorite CMS')),
            'metaDescription'     => $song->seo_description ?: $song->description,
            'song'                => $song,
            'source'              => $defaultAudioSource ?: $defaultSource,
            'playbackData'        => $playbackData,
            'audioSources'        => $audioSources,
            'videoSources'        => $videoSources,
            'defaultAudioSource'  => $defaultAudioSource,
            'defaultVideoSource'  => $defaultVideoSource,
            'hasAudio'            => $hasAudio,
            'hasVideo'            => $hasVideo,
            'playbackType'        => $playbackType,
            'defaultPlaybackMode' => $defaultPlaybackMode,
            'subtitles'           => $subtitles,
            'progress'            => $progress,
            'accessState'         => $accessState,
            'downloadInfo'        => $downloadInfo,
            'user'                => $user,
            'isFavorite'          => $isFavorite,
            'relatedSongs'        => $relatedSongs,
            'relatedPlaylists'    => $relatedPlaylists,
            'ratingAggregate'     => $ratingAggregate,
            'userRating'          => $userRating,
            'reviewsData'         => $reviewsData,
            'commentsData'        => $commentsData,
        ]);
        return Response::make($html, 200);
    }

    // 9. Playlists Catalog
    public function playlists(Request $request): Response
    {
        $items = Playlist::published(24);
        $html = $this->renderView('playlists-list', [
            'metaTitle' => 'Playlists — ' . Setting::get('general', 'site_name', 'Favorite CMS'),
            'items'     => $items,
        ]);
        return Response::make($html, 200);
    }

    // 10. Playlist Player & Tracklist
    public function playlist(Request $request, string $slug): Response
    {
        $playlist = Playlist::findBySlug($slug);
        if (!$playlist) {
            return Response::make('<h1>404 Playlist Not Found</h1>', 404);
        }

        $user = current_user();
        if ($playlist->status !== 'published' || $playlist->access_mode === 'private') {
            $isOwner = $user && ((int)($playlist->user_id ?? 0) === (int)$user->id);
            $isMod = $user && ($user->hasRole('super-admin') || $user->hasRole('admin') || $user->hasRole('moderator') || \FavoriteCMS\Multimedia\Permissions\MultimediaPermission::can(\FavoriteCMS\Multimedia\Permissions\MultimediaPermission::MODERATE, $user));
            if (!$isOwner && !$isMod) {
                return Response::make('<h1>404 Playlist Not Found</h1>', 404);
            }
        }
        $accessState = MultimediaAccessService::checkAccess($user, 'playlist', $playlist);
        $songs = $playlist->getSongs();

        $tracksData = [];
        foreach ($songs as $s) {
            $trackAccess = MultimediaAccessService::checkPlaylistTrackAccess($user, $playlist, $s);
            // In playlists, prioritize playable audio source
            $sSource = $s->getDefaultAudioSource() ?: $s->getDefaultSource();
            $sCanDownload = MultimediaAccessService::checkDownloadPermission($user, 'song', $s, $sSource)['allowed'];
            $isVideoOnly = ($s->getPlaybackType() === Song::PLAYBACK_VIDEO) || (!$s->hasAudio());

            $tracksData[] = [
                'id'            => $s->id,
                'title'         => $s->title,
                'artist'        => $s->getArtist()?->name ?: 'Various Artists',
                'cover'         => $s->cover ?: ($playlist->cover ?: ''),
                'duration'      => $s->getDurationFormatted(),
                'access'        => $trackAccess,
                'stream_url'    => ($trackAccess === MultimediaAccessService::ALLOW && $sSource && !$isVideoOnly) ? "/multimedia/stream/{$sSource->id}" : '',
                'can_download'  => $sCanDownload && !$isVideoOnly,
                'download_url'  => ($sCanDownload && $sSource && !$isVideoOnly) ? "/multimedia/download/{$sSource->id}" : '',
                'is_video_only' => $isVideoOnly,
                'playback_type' => $s->getPlaybackType(),
            ];
        }

        // Community Engagement data
        $ratingAggregate = MultimediaEngagementService::getRatingAggregate('playlist', (int)$playlist->id);
        $userRating = $user ? MultimediaEngagementService::getUserRating($user, 'playlist', (int)$playlist->id) : null;
        $reviewsData = MultimediaEngagementService::getReviewsForContent('playlist', (int)$playlist->id, 10, 0, 'newest', $user);
        $commentsData = MultimediaEngagementService::getCommentsForContent('playlist', (int)$playlist->id, 20, 0, $user);

        $isFollowing = $user ? MultimediaSubscriptionService::isFollowing($user, 'playlist', (int)$playlist->id) : false;

        $html = $this->renderView('playlist-detail', [
            'metaTitle'       => $playlist->title . ' — ' . Setting::get('general', 'site_name', 'Favorite CMS'),
            'playlist'        => $playlist,
            'tracksData'      => $tracksData,
            'accessState'     => $accessState,
            'isFollowing'     => $isFollowing,
            'ratingAggregate' => $ratingAggregate,
            'userRating'      => $userRating,
            'reviewsData'     => $reviewsData,
            'commentsData'    => $commentsData,
            'user'            => $user,
        ]);
        return Response::make($html, 200);
    }

    // 11. Search
    public function search(Request $request): Response
    {
        $q = trim((string)$request->get('q', ''));
        $filter = (string)$request->get('tab', $request->get('filter', $request->get('type', 'all')));
        $user = current_user();

        $searchService = new MultimediaSearchService();
        $searchResult = $searchService->search($q, $filter, 12, $user);

        $html = $this->renderView('search', [
            'metaTitle'     => $q !== '' ? "Search: {$q} — " . Setting::get('general', 'site_name', 'Favorite CMS') : 'Search — ' . Setting::get('general', 'site_name', 'Favorite CMS'),
            'query'         => $q,
            'filter'        => $filter,
            'searchResult'  => $searchResult,
            'groups'        => $searchResult['groups'],
            'totalMatches'  => $searchResult['total_matches'],
            'user'          => $user,
        ]);
        return Response::make($html, 200);
    }

    public function apiSearchSuggestions(Request $request): Response
    {
        $q = trim((string)$request->get('q', ''));
        $suggestionService = new SearchSuggestionService();
        $data = $suggestionService->getSuggestions($q, 8);
        return Response::json($data);
    }

    // 12. User Personal Library Dashboard
    public function library(Request $request): Response
    {
        $user = current_user();
        if (!$user) {
            return Response::redirect('/login?return=' . urlencode('/multimedia/library'));
        }
        if (MultimediaPermission::isSuspendedUser($user)) {
            return Response::make('<h1>403 Forbidden - Account Suspended</h1><p>Your account does not have permission to view personal library.</p>', 403);
        }

        $dashboard = UserLibraryService::compileLibraryDashboard($user);
        $html = $this->renderView('library', [
            'metaTitle' => 'My Library — ' . Setting::get('general', 'site_name', 'Favorite CMS'),
            'dashboard' => $dashboard,
            'user'      => $user,
        ]);
        return Response::make($html, 200);
    }

    // 13. Full Watch / Listening History View
    public function history(Request $request): Response
    {
        $user = current_user();
        if (!$user) {
            return Response::redirect('/login?return=' . urlencode('/multimedia/history'));
        }
        if (MultimediaPermission::isSuspendedUser($user)) {
            return Response::make('<h1>403 Forbidden - Account Suspended</h1><p>Your account does not have permission to view playback history.</p>', 403);
        }

        $type = $request->get('type');
        $page = max(1, (int)$request->get('p', 1));
        $perPage = 24;
        $offset = ($page - 1) * $perPage;

        $items = UserLibraryService::getWatchHistory($user, $perPage, $offset, $type);
        $total = PlaybackProgress::countHistory((int)$user->id, $type);

        $html = $this->renderView('history', [
            'metaTitle'   => 'Playback History — ' . Setting::get('general', 'site_name', 'Favorite CMS'),
            'items'       => $items,
            'currentType' => $type,
            'page'        => $page,
            'totalPages'  => max(1, (int)ceil($total / $perPage)),
            'total'       => $total,
            'user'        => $user,
        ]);
        return Response::make($html, 200);
    }

    // 14. Full My List (Favorites) View
    public function myList(Request $request): Response
    {
        $user = current_user();
        if (!$user) {
            return Response::redirect('/login?return=' . urlencode('/multimedia/my-list'));
        }
        if (MultimediaPermission::isSuspendedUser($user)) {
            return Response::make('<h1>403 Forbidden - Account Suspended</h1><p>Your account does not have permission to view My List.</p>', 403);
        }

        $type = $request->get('type');
        $page = max(1, (int)$request->get('p', 1));
        $perPage = 24;
        $offset = ($page - 1) * $perPage;

        $items = UserLibraryService::getMyList($user, $perPage, $offset, $type);
        $total = Favorite::countByUser((int)$user->id, $type);

        $html = $this->renderView('my-list', [
            'metaTitle'   => 'My List — ' . Setting::get('general', 'site_name', 'Favorite CMS'),
            'favorites'   => $items,
            'items'       => $items,
            'currentType' => $type,
            'page'        => $page,
            'totalPages'  => max(1, (int)ceil($total / $perPage)),
            'total'       => $total,
            'user'        => $user,
        ]);
        return Response::make($html, 200);
    }

    // 15. Discover Page
    public function discover(Request $request): Response
    {
        $user = current_user();
        if ($user && MultimediaPermission::isSuspendedUser($user)) {
            $user = null; // Suspended user views public content as guest equivalent
        }
        $tab = (string)$request->get('tab', 'all');
        $type = (string)$request->get('type', 'all');
        $typeFilter = ($type !== 'all') ? $type : null;

        $trending = [];
        $popular = [];
        $recentlyAdded = [];
        $personalized = [];
        $genres = [];

        if ($tab === 'trending') {
            $trending = MultimediaDiscoveryService::getTrending(24, $typeFilter, $user);
        } elseif ($tab === 'popular') {
            $popular = MultimediaDiscoveryService::getPopular(24, $typeFilter, $user);
        } elseif ($tab === 'recent') {
            $recentlyAdded = MultimediaDiscoveryService::getRecentlyAdded(24, $typeFilter, $user);
        } elseif ($tab === 'genres') {
            $genres = Genre::all();
        } else {
            // 'all'
            $trending = MultimediaDiscoveryService::getTrending(8, $typeFilter, $user);
            $popular = MultimediaDiscoveryService::getPopular(8, $typeFilter, $user);
            $recentlyAdded = MultimediaDiscoveryService::getRecentlyAdded(8, $typeFilter, $user);
            if ($user) {
                $personalized = MultimediaDiscoveryService::getPersonalizedFeed($user, 8);
            }
            $genres = Genre::all();
        }

        $html = $this->renderView('discover', [
            'metaTitle'     => 'Discover Multimedia — ' . Setting::get('general', 'site_name', 'Favorite CMS'),
            'currentTab'    => $tab,
            'currentType'   => $type,
            'trending'      => $trending,
            'popular'       => $popular,
            'recentlyAdded' => $recentlyAdded,
            'personalized'  => $personalized,
            'genres'        => $genres,
            'user'          => $user,
        ]);
        return Response::make($html, 200);
    }

    // 16. Genre Catalog & Discovery
    public function genre(Request $request, string $slug): Response
    {
        $user = current_user();
        $data = MultimediaDiscoveryService::getGenreContent($slug, 30, 0, $user);
        if (!$data['genre']) {
            return Response::make('<h1>404 Genre Not Found</h1>', 404);
        }

        $html = $this->renderView('genre', [
            'metaTitle'   => "{$data['genre']->name} — " . Setting::get('general', 'site_name', 'Favorite CMS'),
            'genre'       => $data['genre'],
            'items'       => $data['items'],
            'movies'      => $data['movies'],
            'series'      => $data['series'],
            'songs'       => $data['songs'],
            'total'       => $data['total'],
            'totalMovies' => $data['totalMovies'],
            'totalSeries' => $data['totalSeries'],
            'totalSongs'  => $data['totalSongs'],
            'user'        => $user,
        ]);
        return Response::make($html, 200);
    }

    // 17. Artist Profile & Discography
    public function artist(Request $request, string $slug): Response
    {
        $user = current_user();
        $data = MultimediaDiscoveryService::getArtistContent($slug, $user);
        if (!$data) {
            return Response::make('<h1>404 Artist Not Found</h1>', 404);
        }

        $isFollowing = ($user && isset($data['artist']->id))
            ? MultimediaSubscriptionService::isFollowing($user, 'artist', (int)$data['artist']->id)
            : false;

        $html = $this->renderView('artist', [
            'metaTitle'   => "{$data['artist']->name} — " . Setting::get('general', 'site_name', 'Favorite CMS'),
            'artist'      => $data['artist'],
            'isFollowing' => $isFollowing,
            'songs'       => $data['songs'],
            'albums'      => $data['albums'],
            'playlists'   => $data['playlists'],
            'user'        => $user,
        ]);
        return Response::make($html, 200);
    }

    // 18. Album View
    public function album(Request $request, string $slug): Response
    {
        $user = current_user();
        $data = MultimediaDiscoveryService::getAlbumContent($slug, $user);
        if (!$data) {
            return Response::make('<h1>404 Album Not Found</h1>', 404);
        }

        $album = $data['album'];
        if ($album->status !== 'published') {
            $isOwner = $user && ((int)($album->user_id ?? 0) === (int)$user->id);
            $isMod = $user && ($user->hasRole('super-admin') || $user->hasRole('admin') || $user->hasRole('moderator') || \FavoriteCMS\Multimedia\Permissions\MultimediaPermission::can(\FavoriteCMS\Multimedia\Permissions\MultimediaPermission::MODERATE, $user));
            if (!$isOwner && !$isMod) {
                return Response::make('<h1>404 Album Not Found</h1>', 404);
            }
        }

        $html = $this->renderView('album', [
            'metaTitle' => "{$data['album']->title} — " . Setting::get('general', 'site_name', 'Favorite CMS'),
            'album'     => $data['album'],
            'artist'    => $data['artist'],
            'songs'     => $data['songs'],
            'user'      => $user,
        ]);
        return Response::make($html, 200);
    }

    // 19. User Notifications Inbox
    public function notifications(Request $request): Response
    {
        $user = current_user();
        if (!$user) {
            return Response::redirect('/login');
        }
        if (MultimediaPermission::isSuspendedUser($user)) {
            return Response::make('<h1>403 Forbidden - Account Suspended</h1><p>Your account does not have permission to view notifications.</p>', 403);
        }

        $page = max(1, (int)$request->get('p', 1));
        $perPage = 25;
        $offset = ($page - 1) * $perPage;
        $unreadOnly = $request->get('filter') === 'unread';

        $data = MultimediaNotificationService::getNotifications($user, $perPage, $offset, $unreadOnly ? true : null);
        $prefs = MultimediaNotificationService::getPreferences($user);

        $html = $this->renderView('notifications', [
            'metaTitle'     => 'Notifications — ' . Setting::get('general', 'site_name', 'Favorite CMS'),
            'notifications' => $data['notifications'],
            'total'         => $data['total'],
            'unreadCount'   => $data['unread_count'],
            'unreadBadge'   => $data['unread_badge'],
            'filter'        => $unreadOnly ? 'unread' : 'all',
            'preferences'   => $prefs,
            'page'          => $page,
            'totalPages'    => max(1, (int)ceil($data['total'] / $perPage)),
            'user'          => $user,
        ]);

        return Response::make($html, 200, [
            'Cache-Control' => 'no-store, private',
        ]);
    }

    // 20. User Following Directory
    public function following(Request $request): Response
    {
        $user = current_user();
        if (!$user) {
            return Response::redirect('/login');
        }
        if (MultimediaPermission::isSuspendedUser($user)) {
            return Response::make('<h1>403 Forbidden - Account Suspended</h1><p>Your account does not have permission to view following directory.</p>', 403);
        }

        $tab = (string)$request->get('tab', 'all');
        $validTabs = ['all', 'series', 'artist', 'playlist'];
        if (!in_array($tab, $validTabs, true)) {
            $tab = 'all';
        }

        $targetFilter = ($tab === 'all') ? null : $tab;
        $data = MultimediaSubscriptionService::getSubscriptions($user, $targetFilter, 60, 0);

        $seriesSubs = MultimediaSubscriptionService::getSubscriptions($user, 'series', 60, 0);
        $artistSubs = MultimediaSubscriptionService::getSubscriptions($user, 'artist', 60, 0);
        $playlistSubs = MultimediaSubscriptionService::getSubscriptions($user, 'playlist', 60, 0);

        $html = $this->renderView('following', [
            'metaTitle'    => 'Following — ' . Setting::get('general', 'site_name', 'Favorite CMS'),
            'tab'          => $tab,
            'items'        => $data['items'],
            'total'        => $data['total'],
            'seriesCount'  => $seriesSubs['total'],
            'artistCount'  => $artistSubs['total'],
            'playlistCount'=> $playlistSubs['total'],
            'user'         => $user,
        ]);

        return Response::make($html, 200, [
            'Cache-Control' => 'no-store, private',
        ]);
    }

    // Audio Player Endpoints (Chunk 3)
    public function apiAudioResolve(Request $request, string $id): Response
    {
        $songId = (int)$id;
        if ($songId <= 0) {
            return Response::json(['success' => false, 'error' => 'Invalid song ID.'], 400);
        }

        $resolver = new AudioAccessResolver();
        $res = $resolver->resolvePlayableSong($songId, current_user());

        if (empty($res['allowed'])) {
            $status = match ($res['reason'] ?? '') {
                'login_required'   => 401,
                'premium_required' => 403,
                'forbidden'        => 403,
                'not_found', 'no_source' => 404,
                default            => 403,
            };
            return Response::json([
                'success' => false,
                'error'   => $res['message'] ?? 'Playback denied.',
                'reason'  => $res['reason'] ?? 'denied',
                'details' => $res,
            ], $status);
        }

        return Response::json([
            'success' => true,
            'audio'   => $res,
        ]);
    }

    public function apiAudioContext(Request $request, string $type, string $id): Response
    {
        $contextId = (int)$id;
        $startSongId = (int)$request->get('start_song_id', 0) ?: null;
        $resolver = new AudioContextResolver();

        $result = match ($type) {
            'album'    => $resolver->resolveAlbum($contextId, $startSongId),
            'playlist' => $resolver->resolvePlaylist($contextId, $startSongId),
            'artist'   => $resolver->resolveArtist($contextId, 50, $startSongId),
            'song'     => [
                'context'     => 'manual',
                'context_id'  => $contextId,
                'title'       => 'Single Track',
                'items'       => array_filter([$resolver->resolveSong($contextId)]),
                'start_index' => 0,
            ],
            default    => null,
        };

        if ($result === null) {
            return Response::json(['success' => false, 'error' => 'Unsupported audio context type.'], 400);
        }

        return Response::json([
            'success'     => true,
            'context'     => $result['context'],
            'context_id'  => $result['context_id'],
            'title'       => $result['title'],
            'items'       => array_values($result['items']),
            'start_index' => $result['start_index'],
            'count'       => count($result['items']),
        ]);
    }

    public function apiAudioProgress(Request $request): Response
    {
        $user = current_user();
        if ($user && MultimediaPermission::isSuspendedUser($user)) {
            return Response::json(['success' => false, 'status' => 'forbidden', 'error' => 'Account suspended.'], 403);
        }

        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        $songId = (int)($data['song_id'] ?? 0);
        $position = (float)($data['position'] ?? 0.0);
        $duration = (float)($data['duration'] ?? 0.0);
        $forceCompleted = !empty($data['is_completed']);

        if ($songId <= 0) {
            return Response::json(['success' => false, 'error' => 'Invalid track ID.'], 400);
        }

        $session = new AudioPlaybackSession();
        $result = $session->recordProgress($user, $songId, $position, $duration, $forceCompleted);

        return Response::json([
            'success' => $result['saved'],
            'data'    => $result,
        ]);
    }

    public function membership(Request $request): Response
    {
        $user = function_exists('current_user') ? current_user() : null;
        if ($user && MultimediaPermission::isSuspendedUser($user)) {
            return Response::make('<h1>403 Forbidden - Account Suspended</h1><p>Suspended accounts cannot purchase memberships.</p>', 403);
        }

        $membership = \FavoriteCMS\Multimedia\Integrations\FavoriteDigitalAdapter::getMembershipDetails($user);
        $payAvailable = \FavoriteCMS\Multimedia\Integrations\FavoritePayAdapter::isAvailable();

        $title = 'Membership — ' . Setting::get('general', 'site_name', 'Favorite CMS');
        $html = $this->renderView('membership', [
            'metaTitle'    => $title,
            'user'         => $user,
            'membership'   => $membership,
            'payAvailable' => $payAvailable,
        ]);

        $wrapped = \FavoriteCMS\Multimedia\Theme\ThemeShellService::renderPageInActiveTheme($html, $title, [
            'user'         => $user,
            'membership'   => $membership,
            'payAvailable' => $payAvailable,
        ]);

        return Response::make($wrapped, 200);
    }

    private function renderView(string $viewName, array $data = []): string
    {
        $viewPath = __DIR__ . '/../../views/frontend/' . $viewName . '.php';
        if (!file_exists($viewPath)) {
            return "<div>Frontend view not found: {$viewName}</div>";
        }

        extract($data, EXTR_SKIP);
        ob_start();
        include $viewPath;
        return (string)ob_get_clean();
    }
}

