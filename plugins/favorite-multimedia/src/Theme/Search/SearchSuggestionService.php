<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Theme\Search;

use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;

/**
 * Provides instant search suggestions for autocomplete dropdowns.
 *
 * Debounced, lightweight, and strictly based on real published entities.
 * Never fabricates fake trending queries.
 */
class SearchSuggestionService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Container::getInstance()->get(Database::class);
    }

    /**
     * Get suggestions across published entities.
     *
     * @return array{
     *     query: string,
     *     suggestions: array<int, array{
     *         id: int,
     *         type: string,
     *         title: string,
     *         subtitle: string,
     *         url: string,
     *         poster: string,
     *         type_label: string
     *     }>
     * }
     */
    public function getSuggestions(string $query, int $limit = 8): array
    {
        $clean = trim($query);
        if (mb_strlen($clean) < 2) {
            return [
                'query'       => $clean,
                'suggestions' => [],
            ];
        }

        $like = '%' . $clean . '%';
        $suggestions = [];

        // 1. Movies (up to 3)
        try {
            $movies = $this->db->select(
                "SELECT id, title, slug, poster, release_year FROM multimedia_movies WHERE status = 'published' AND title LIKE ? ORDER BY id DESC LIMIT 3",
                [$like]
            );
            foreach ($movies as $m) {
                $m = (array)$m;
                $suggestions[] = [
                    'id'         => (int)$m['id'],
                    'type'       => 'movie',
                    'title'      => (string)$m['title'],
                    'subtitle'   => !empty($m['release_year']) ? (string)$m['release_year'] : 'Movie',
                    'url'        => '/movie/' . $m['slug'],
                    'poster'     => (string)($m['poster'] ?? ''),
                    'type_label' => 'Movie',
                ];
            }
        } catch (\Throwable) {}

        // 2. Series (up to 3)
        try {
            $series = $this->db->select(
                "SELECT id, title, slug, poster, release_year FROM multimedia_series WHERE status = 'published' AND title LIKE ? ORDER BY id DESC LIMIT 3",
                [$like]
            );
            foreach ($series as $s) {
                $s = (array)$s;
                $suggestions[] = [
                    'id'         => (int)$s['id'],
                    'type'       => 'series',
                    'title'      => (string)$s['title'],
                    'subtitle'   => !empty($s['release_year']) ? (string)$s['release_year'] : 'Series',
                    'url'        => '/series/' . $s['slug'],
                    'poster'     => (string)($s['poster'] ?? ''),
                    'type_label' => 'Series',
                ];
            }
        } catch (\Throwable) {}

        // 3. Songs (up to 3)
        try {
            $songs = $this->db->select(
                "SELECT s.id, s.title, s.slug, s.cover, a.name as artist_name 
                 FROM multimedia_songs s 
                 LEFT JOIN multimedia_artists a ON a.id = s.artist_id 
                 WHERE s.status = 'published' AND s.title LIKE ? 
                 ORDER BY s.id DESC LIMIT 3",
                [$like]
            );
            foreach ($songs as $sg) {
                $sg = (array)$sg;
                $suggestions[] = [
                    'id'         => (int)$sg['id'],
                    'type'       => 'song',
                    'title'      => (string)$sg['title'],
                    'subtitle'   => !empty($sg['artist_name']) ? (string)$sg['artist_name'] : 'Song',
                    'url'        => '/song/' . $sg['slug'],
                    'poster'     => (string)($sg['cover'] ?? ''),
                    'type_label' => 'Song',
                ];
            }
        } catch (\Throwable) {}

        // 4. Artists (up to 2)
        try {
            $artists = $this->db->select(
                "SELECT id, name, slug, avatar FROM multimedia_artists WHERE status = 'active' AND name LIKE ? ORDER BY id DESC LIMIT 2",
                [$like]
            );
            foreach ($artists as $ar) {
                $ar = (array)$ar;
                $suggestions[] = [
                    'id'         => (int)$ar['id'],
                    'type'       => 'artist',
                    'title'      => (string)$ar['name'],
                    'subtitle'   => 'Artist',
                    'url'        => '/multimedia/artist/' . $ar['slug'],
                    'poster'     => (string)($ar['avatar'] ?? ''),
                    'type_label' => 'Artist',
                ];
            }
        } catch (\Throwable) {}

        return [
            'query'       => $clean,
            'suggestions' => array_slice($suggestions, 0, $limit),
        ];
    }
}
