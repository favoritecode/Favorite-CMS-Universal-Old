<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Theme\Homepage;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Theme\ThemeManager;

/**
 * Renders homepage sections into HTML using the component system.
 */
final class SectionRenderer
{
    private SectionResolver $resolver;
    private string $viewsPath;

    public function __construct(SectionResolver|Database|null $resolverOrDb = null, ?string $viewsPath = null)
    {
        if ($resolverOrDb instanceof Database) {
            $this->resolver = new SectionResolver($resolverOrDb);
        } elseif ($resolverOrDb instanceof SectionResolver) {
            $this->resolver = $resolverOrDb;
        } else {
            $this->resolver = new SectionResolver();
        }
        $this->viewsPath = $viewsPath ?? dirname(__DIR__, 3) . '/views/frontend';
    }

    /**
     * Renders all enabled sections from HomepageConfig.
     */
    public function renderAll(HomepageConfig $config, ?User $user = null): string
    {
        $html = '';
        foreach ($config->getSections() as $section) {
            $html .= $this->renderSection($section, $user);
        }
        return $html;
    }

    /**
     * Renders a single section. Returns empty string if disabled or if resolved items are empty.
     */
    public function renderSection(array $section, ?User $user = null): string
    {
        if (!(bool)($section['enabled'] ?? true)) {
            return '';
        }

        $items = $this->resolver->resolve($section, $user);

        // Collapse empty sections
        if (empty($items)) {
            return '';
        }

        $type = (string)($section['type'] ?? '');
        if ($type === SectionRegistry::TYPE_HERO) {
            return $this->renderComponent('hero', [
                'section' => $section,
                'items' => $items,
                'user' => $user,
            ]);
        }

        return $this->renderComponent('media-row', [
            'section' => $section,
            'items' => $items,
            'cardStyle' => (string)($section['card_style'] ?? 'poster'),
            'layout' => (string)($section['layout'] ?? 'rail'),
            'user' => $user,
        ]);
    }

    public function renderComponent(string $componentName, array $data = []): string
    {
        $file = $this->viewsPath . '/components/' . $componentName . '.php';
        if (!file_exists($file)) {
            return "<!-- Component {$componentName} not found -->";
        }

        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return (string)ob_get_clean();
    }
}
