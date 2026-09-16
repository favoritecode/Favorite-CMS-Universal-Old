<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Models;

use FavoriteCMS\Models\BaseModel;

class Season extends BaseModel
{
    protected static string $table = 'multimedia_seasons';

    public function getSeries(): ?Series
    {
        return Series::find((int)$this->series_id);
    }

    public function getEpisodes(bool $publishedOnly = false): array
    {
        $statusClause = $publishedOnly ? " AND status = 'published'" : "";
        $rows = $this->db->select(
            "SELECT * FROM multimedia_episodes WHERE season_id = ?{$statusClause} ORDER BY sort_order ASC, episode_number ASC, id ASC",
            [(int)$this->id]
        );
        return array_map(fn($r) => new Episode((array)$r), $rows);
    }
}

