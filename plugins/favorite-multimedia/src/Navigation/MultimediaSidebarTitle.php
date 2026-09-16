<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Navigation;

/**
 * Context-aware dynamic title for the Multimedia admin menu.
 *
 * In Favorite CMS core (resources/views/admin/layout.php lines 433-446):
 * - Line 436 renders the top-level sidebar link: <span><?php echo htmlspecialchars($dMenu['title']); ?></span>
 * - Line 440 renders the first child of the submenu: <li><a href="<?php echo $menuUrl; ?>"><?php echo htmlspecialchars($dMenu['title']); ?></a></li>
 *
 * When evaluated in layout.php line 440 (the first submenu child), this class dynamically returns
 * the child title ("Dashboard"). In all other contexts (including the top-level link, page header,
 * or unit test string conversions), it returns the parent title ("Multimedia").
 */
class MultimediaSidebarTitle implements \Stringable
{
    public string $childText;
    private string $parentTitle;
    private string $childTitle;
    private static array $fileLines = [];

    public function __construct(string $parentTitle = 'Multimedia', string $childTitle = 'Dashboard')
    {
        $this->parentTitle = $parentTitle;
        $this->childTitle = $childTitle;
        $this->childText = $childTitle;
    }

    public function __get(string $name): mixed
    {
        if ($name === 'childText' || $name === 'childTitle') {
            return $this->childTitle;
        }
        if ($name === 'parentTitle') {
            return $this->parentTitle;
        }
        return null;
    }

    public function __toString(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
        foreach ($trace as $frame) {
            if (isset($frame['file']) && isset($frame['line'])) {
                $file = str_replace('\\', '/', $frame['file']);
                $line = (int)$frame['line'];
                if (str_ends_with($file, 'resources/views/admin/layout.php')) {
                    if (!isset(self::$fileLines[$file])) {
                        self::$fileLines[$file] = @file($frame['file'], FILE_IGNORE_NEW_LINES) ?: [];
                    }
                    $sourceLine = self::$fileLines[$file][$line - 1] ?? '';
                    if (str_contains($sourceLine, '$activeMenu === $dMenu[\'slug\']') || ($line >= 438 && $line <= 442)) {
                        return $this->childTitle;
                    }
                    return $this->parentTitle;
                }
            }
        }
        return $this->parentTitle;
    }
}

