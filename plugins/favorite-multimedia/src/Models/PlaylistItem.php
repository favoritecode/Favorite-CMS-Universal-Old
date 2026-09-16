<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Models;

use FavoriteCMS\Models\BaseModel;

class PlaylistItem extends BaseModel
{
    protected static string $table = 'multimedia_playlist_items';

    public static function create(array $data): static
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        if (!isset($data['created_at'])) {
            $data['created_at'] = (new \DateTime())->format('Y-m-d H:i:s');
        }
        unset($data['updated_at']);
        $id = $db->insert(static::$table, $data);
        return static::find($id);
    }
}

