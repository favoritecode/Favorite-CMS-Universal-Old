<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Services;

use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Multimedia\Models\MediaLocalization;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\Song;

class MediaLocalizationService
{
    private static function getDb(): Database
    {
        return Container::getInstance()->get(Database::class);
    }

    /**
     * Resolve localized title, description, and tagline with deterministic fallback:
     * 1. Preferred / requested language
     * 2. Configured site locale
     * 3. Base model properties (guaranteed never blank if base model has values)
     */
    public static function resolveMetadata(
        string $contentType,
        int $contentId,
        ?string $preferredLang = null,
        ?object $baseModel = null
    ): array {
        $loc = null;

        // 1. Try preferred language
        if ($preferredLang !== null && $preferredLang !== '') {
            $loc = MediaLocalization::findForContent($contentType, $contentId, $preferredLang);
        }

        // 2. Try site locale setting if preferred not found or not specified
        if (!$loc) {
            $siteLocale = (string)Setting::get('multimedia', 'default_locale', 'en');
            if ($preferredLang === null || strtolower($preferredLang) !== strtolower($siteLocale)) {
                $loc = MediaLocalization::findForContent($contentType, $contentId, $siteLocale);
            }
        }

        // Base model resolution
        $baseTitle = $baseModel?->title ?? '';
        $baseDesc = $baseModel?->description ?? '';
        $baseTagline = $baseModel?->tagline ?? '';

        return [
            'title'         => ($loc && !empty($loc->title)) ? (string)$loc->title : (string)$baseTitle,
            'description'   => ($loc && !empty($loc->description)) ? (string)$loc->description : (string)$baseDesc,
            'tagline'       => ($loc && !empty($loc->tagline)) ? (string)$loc->tagline : (string)$baseTagline,
            'is_localized'  => ($loc !== null),
            'language_code' => $loc ? (string)$loc->language_code : 'en',
        ];
    }

    /**
     * Helper to resolve localized metadata directly from a model object.
     */
    public static function resolveLocalized(object $baseModel, string $contentType, ?string $preferredLang = null): array
    {
        return self::resolveMetadata($contentType, (int)($baseModel->id ?? 0), $preferredLang, $baseModel);
    }

    /**
     * Bulk hydrate a list of models to avoid N+1 queries.
     *
     * @param array $items Array of models or array items with 'type' and 'model'
     * @return array Hydrated items with localized_title, localized_description, localized_tagline
     */
    public static function hydrateList(array $items, ?string $preferredLang = null): array
    {
        if (empty($items)) {
            return [];
        }

        $preferredLang = $preferredLang !== null ? strtolower(trim($preferredLang)) : 'en';

        // Collect grouped IDs by content_type
        $grouped = [];
        foreach ($items as $idx => $item) {
            $model = is_array($item) ? ($item['model'] ?? null) : $item;
            $type = is_array($item) ? ($item['type'] ?? null) : null;

            if (!$type && $model) {
                if ($model instanceof Movie)   $type = 'movie';
                elseif ($model instanceof Series)  $type = 'series';
                elseif ($model instanceof Episode) $type = 'episode';
                elseif ($model instanceof Song)    $type = 'song';
            }

            if ($type && $model && isset($model->id)) {
                $grouped[$type][(int)$model->id][] = $idx;
            }
        }

        $localizations = [];
        $db = self::getDb();

        foreach ($grouped as $type => $idMap) {
            $ids = array_keys($idMap);
            if (empty($ids)) continue;

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = array_merge([$type, $preferredLang], $ids);

            try {
                $rows = $db->select(
                    "SELECT content_id, title, description, tagline, language_code 
                     FROM multimedia_localizations 
                     WHERE content_type = ? AND LOWER(language_code) = ? AND content_id IN ({$placeholders})",
                    $params
                );

                foreach ($rows as $r) {
                    $localizations[$type][(int)$r->content_id] = $r;
                }
            } catch (\Throwable) {
                // Pre-migration or table missing fallback
            }
        }

        // Apply to items
        foreach ($items as $idx => &$item) {
            $model = is_array($item) ? ($item['model'] ?? null) : $item;
            $type = is_array($item) ? ($item['type'] ?? null) : null;
            if (!$type && $model) {
                if ($model instanceof Movie)   $type = 'movie';
                elseif ($model instanceof Series)  $type = 'series';
                elseif ($model instanceof Episode) $type = 'episode';
                elseif ($model instanceof Song)    $type = 'song';
            }

            if ($model && isset($model->id) && isset($localizations[$type][(int)$model->id])) {
                $loc = $localizations[$type][(int)$model->id];
                $model->localized_title       = $loc->title ?: $model->title;
                $model->localized_description = $loc->description ?: ($model->description ?? '');
                $model->localized_tagline     = $loc->tagline ?: ($model->tagline ?? '');
                $model->display_language      = $loc->language_code;
            } else {
                if ($model) {
                    $model->localized_title       = $model->title ?? '';
                    $model->localized_description = $model->description ?? '';
                    $model->localized_tagline     = $model->tagline ?? '';
                    $model->display_language      = 'en';
                }
            }
        }
        unset($item);

        return $items;
    }

    /**
     * Search multimedia catalog through localized titles and descriptions.
     *
     * @return array Array of ['type' => string, 'model' => object]
     */
    public static function searchLocalized(
        string $query,
        ?string $contentType = null,
        ?string $preferredLang = null,
        int $limit = 10
    ): array {
        $q = trim($query);
        if ($q === '') {
            return [];
        }

        $db = self::getDb();
        $like = '%' . $q . '%';

        $where = "(title LIKE ? OR description LIKE ?)";
        $params = [$like, $like];

        if ($contentType !== null && $contentType !== '') {
            $where .= " AND content_type = ?";
            $params[] = $contentType;
        }

        if ($preferredLang !== null && $preferredLang !== '') {
            $where .= " AND LOWER(language_code) = ?";
            $params[] = strtolower(trim($preferredLang));
        }

        try {
            $rows = $db->select(
                "SELECT content_type, content_id, title, description, language_code 
                 FROM multimedia_localizations 
                 WHERE {$where} 
                 LIMIT ?",
                array_merge($params, [$limit])
            );
        } catch (\Throwable) {
            return [];
        }

        $results = [];
        $seen = [];

        foreach ($rows as $r) {
            $key = "{$r->content_type}_{$r->content_id}";
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $model = match ($r->content_type) {
                'movie'   => Movie::find((int)$r->content_id),
                'series'  => Series::find((int)$r->content_id),
                'episode' => Episode::find((int)$r->content_id),
                'song'    => Song::find((int)$r->content_id),
                default   => null,
            };

            if ($model) {
                $model->localized_title = $r->title;
                $model->localized_description = $r->description ?: ($model->description ?? '');
                $model->display_language = $r->language_code;
                $results[] = [
                    'type'  => $r->content_type,
                    'model' => $model,
                ];
            }
        }

        return $results;
    }
}
