<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteMultimedia;

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__, 4));
}
require_once APP_ROOT . '/plugins/favorite-multimedia/autoload.php';

use FavoriteCMS\Multimedia\Theme\ThemePackageService;
use FavoriteCMS\Themes\ThemeManager;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class FavoriteMultimediaThemeInstallerTest extends TestCase
{
    private string $assetsRepoDir;
    private string $themeSourceDir;
    private string $zipPath;
    private string $versionedZipPath;
    private string $tempExtractBase;

    protected function setUp(): void
    {
        $this->assetsRepoDir = 'D:/Server/Shofikul/CMS Assets/Favorite-CMS-Assets/theme-assets/favorite-multimedia/release';
        $this->themeSourceDir = APP_ROOT . '/plugins/favorite-multimedia/theme/favorite-multimedia-theme';
        $this->zipPath = $this->assetsRepoDir . '/favorite-multimedia-theme.zip';
        $this->versionedZipPath = $this->assetsRepoDir . '/favorite-multimedia-theme-v1.0.2.zip';
        $this->tempExtractBase = sys_get_temp_dir() . '/fctest_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempExtractBase)) {
            $this->recursiveRmdir($this->tempExtractBase);
        }
    }

    private function recursiveRmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($dir);
    }

    public function test01_ThemeZipExistsInRelease(): void
    {
        $this->assertFileExists($this->zipPath);
        $this->assertFileExists($this->versionedZipPath);
        $this->assertGreaterThan(1000, filesize($this->zipPath));
    }

    public function test02_ThemeZipHasEnclosingRootDirectory(): void
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($this->zipPath), "Could not open canonical zip");

        $this->assertGreaterThan(0, $zip->numFiles);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->statIndex($i)['name'];
            $this->assertStringStartsWith(
                'favorite-multimedia-theme/',
                $name,
                "Zip entry '{$name}' does not start with enclosing folder 'favorite-multimedia-theme/'"
            );
        }
        $zip->close();
    }

    public function test03_ThemeManifestJsonExistsAtRootOfEnclosingDir(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->zipPath);
        $manifestStat = $zip->statName('favorite-multimedia-theme/theme.json');
        $this->assertNotFalse($manifestStat, "favorite-multimedia-theme/theme.json is missing in ZIP");
        $zip->close();

        $this->assertFileExists($this->themeSourceDir . '/theme.json');
    }

    public function test04_ManifestJsonSchemaHasRequiredKeys(): void
    {
        $manifestContent = file_get_contents($this->themeSourceDir . '/theme.json');
        $this->assertNotFalse($manifestContent);
        $manifest = json_decode($manifestContent, true);
        $this->assertIsArray($manifest);

        $requiredKeys = ['id', 'name', 'slug', 'version', 'author', 'requires_plugin', 'minimum_plugin_version', 'screenshot'];
        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $manifest);
        }

        $errors = ThemePackageService::validateManifest($manifest);
        $this->assertEmpty($errors);
    }

    public function test05_ManifestThemeIdMatchesEnclosingDirectory(): void
    {
        $manifest = json_decode(file_get_contents($this->themeSourceDir . '/theme.json'), true);
        $this->assertSame('favorite-multimedia-theme', $manifest['id']);
        $this->assertSame('favorite-multimedia-theme', $manifest['slug']);
    }

    public function test06_IndexPhpExistsAtRootOfEnclosingDir(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->zipPath);
        $this->assertNotFalse($zip->statName('favorite-multimedia-theme/index.php'));
        $zip->close();

        $this->assertFileExists($this->themeSourceDir . '/index.php');
    }

    public function test07_FunctionsPhpExistsAtRootOfEnclosingDir(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->zipPath);
        $this->assertNotFalse($zip->statName('favorite-multimedia-theme/functions.php'));
        $zip->close();

        $this->assertFileExists($this->themeSourceDir . '/functions.php');
    }

    public function test08_HeaderAndFooterPhpExist(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->zipPath);
        $this->assertNotFalse($zip->statName('favorite-multimedia-theme/header.php'));
        $this->assertNotFalse($zip->statName('favorite-multimedia-theme/footer.php'));
        $zip->close();

        $this->assertFileExists($this->themeSourceDir . '/header.php');
        $this->assertFileExists($this->themeSourceDir . '/footer.php');
    }

    public function test09_FallbackTemplatesExist(): void
    {
        $templates = ['single.php', 'page.php', 'search.php', 'archive.php', '404.php', 'sidebar.php'];
        $zip = new ZipArchive();
        $zip->open($this->zipPath);

        foreach ($templates as $tmpl) {
            $this->assertNotFalse(
                $zip->statName("favorite-multimedia-theme/{$tmpl}"),
                "Missing {$tmpl} in ZIP"
            );
            $this->assertFileExists($this->themeSourceDir . "/{$tmpl}");
        }
        $zip->close();
    }

    public function test10_ScreenshotPngExistsAndIsValidImage(): void
    {
        $screenshotFile = $this->themeSourceDir . '/screenshot.png';
        $this->assertFileExists($screenshotFile);
        $size = filesize($screenshotFile);
        $this->assertGreaterThan(500, $size);

        $imageInfo = @getimagesize($screenshotFile);
        $this->assertNotFalse($imageInfo);
        $this->assertSame('image/png', $imageInfo['mime']);
        $this->assertSame(600, $imageInfo[0]);
        $this->assertSame(400, $imageInfo[1]);
    }

    public function test11_CssAssetsExistInPackage(): void
    {
        $cssFiles = [
            'theme-tokens.css',
            'multimedia-frontend.css',
            'audio-player.css',
            'homepage-builder.css',
        ];

        $zip = new ZipArchive();
        $zip->open($this->zipPath);
        foreach ($cssFiles as $css) {
            $this->assertNotFalse(
                $zip->statName("favorite-multimedia-theme/assets/css/{$css}"),
                "Missing assets/css/{$css} in ZIP"
            );
            $this->assertFileExists($this->themeSourceDir . "/assets/css/{$css}");
        }
        $zip->close();
    }

    public function test12_JsAssetsExistInPackage(): void
    {
        $jsFiles = [
            'audio-state.js',
            'audio-queue.js',
            'audio-player.js',
            'audio-ui.js',
            'multimedia-frontend.js',
        ];

        $zip = new ZipArchive();
        $zip->open($this->zipPath);
        foreach ($jsFiles as $js) {
            $this->assertNotFalse(
                $zip->statName("favorite-multimedia-theme/assets/js/{$js}"),
                "Missing assets/js/{$js} in ZIP"
            );
            $this->assertFileExists($this->themeSourceDir . "/assets/js/{$js}");
        }
        $zip->close();
    }

    public function test13_SimulatedZipExtractionCreatesExactlyOneDirectoryInThemes(): void
    {
        mkdir($this->tempExtractBase, 0777, true);
        $zip = new ZipArchive();
        $zip->open($this->zipPath);

        // Security check & theme ID determination matching ThemeManager::installFromZip
        $themeId = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $filename = $stat['name'];
            $this->assertFalse(str_contains($filename, '..'));

            $parts = explode('/', trim($filename, '/'));
            if ($themeId === null && !empty($parts[0])) {
                $themeId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $parts[0]);
            }
        }

        $this->assertSame('favorite-multimedia-theme', $themeId);

        $zip->extractTo($this->tempExtractBase);
        $zip->close();

        $extractedDirs = glob($this->tempExtractBase . '/*', GLOB_ONLYDIR);
        $this->assertCount(1, $extractedDirs);
        $this->assertSame('favorite-multimedia-theme', basename($extractedDirs[0]));
    }

    public function test14_InstalledThemesListContainsExactlyOneMultimediaTheme(): void
    {
        mkdir($this->tempExtractBase . '/default', 0777, true);
        file_put_contents($this->tempExtractBase . '/default/theme.json', json_encode([
            'id' => 'default',
            'name' => 'Default Theme',
            'version' => '1.0.0',
        ]));
        file_put_contents($this->tempExtractBase . '/default/index.php', '<?php // default');

        $zip = new ZipArchive();
        $zip->open($this->zipPath);
        $zip->extractTo($this->tempExtractBase);
        $zip->close();

        $dirs = glob($this->tempExtractBase . '/*', GLOB_ONLYDIR);
        $installed = [];
        foreach ($dirs as $dir) {
            $id = basename($dir);
            $manifestFile = $dir . '/theme.json';
            $meta = [];
            if (file_exists($manifestFile)) {
                $meta = json_decode(file_get_contents($manifestFile), true) ?? [];
            }
            $installed[$id] = $meta;
        }

        $this->assertArrayHasKey('default', $installed);
        $this->assertArrayHasKey('favorite-multimedia-theme', $installed);
        $this->assertCount(2, $installed);
    }

    public function test15_InstalledThemesListDoesNotContainAssetsTheme(): void
    {
        mkdir($this->tempExtractBase, 0777, true);
        $zip = new ZipArchive();
        $zip->open($this->zipPath);
        $zip->extractTo($this->tempExtractBase);
        $zip->close();

        $this->assertDirectoryDoesNotExist($this->tempExtractBase . '/assets');
        $this->assertDirectoryDoesNotExist($this->tempExtractBase . '/Assets');
    }

    public function test16_InstalledThemesListDoesNotContainSrcTheme(): void
    {
        mkdir($this->tempExtractBase, 0777, true);
        $zip = new ZipArchive();
        $zip->open($this->zipPath);
        $zip->extractTo($this->tempExtractBase);
        $zip->close();

        $this->assertDirectoryDoesNotExist($this->tempExtractBase . '/src');
        $this->assertDirectoryDoesNotExist($this->tempExtractBase . '/Src');
    }

    public function test17_InstalledThemesListDoesNotContainViewsTheme(): void
    {
        mkdir($this->tempExtractBase, 0777, true);
        $zip = new ZipArchive();
        $zip->open($this->zipPath);
        $zip->extractTo($this->tempExtractBase);
        $zip->close();

        $this->assertDirectoryDoesNotExist($this->tempExtractBase . '/views');
        $this->assertDirectoryDoesNotExist($this->tempExtractBase . '/Views');
    }

    public function test18_InstalledThemesListDoesNotContainManifestjsonTheme(): void
    {
        mkdir($this->tempExtractBase, 0777, true);
        $zip = new ZipArchive();
        $zip->open($this->zipPath);
        $zip->extractTo($this->tempExtractBase);
        $zip->close();

        $this->assertDirectoryDoesNotExist($this->tempExtractBase . '/manifestjson');
        $this->assertDirectoryDoesNotExist($this->tempExtractBase . '/Manifestjson');
    }

    public function test19_ThemeMetadataMatchesNameAndVersion(): void
    {
        $manifest = json_decode(file_get_contents($this->themeSourceDir . '/theme.json'), true);
        $this->assertSame('Favorite Multimedia Theme', $manifest['name']);
        $this->assertSame('1.0.2', $manifest['version']);
    }

    public function test20_ThemeMetadataContainsAuthorAndScreenshot(): void
    {
        $manifest = json_decode(file_get_contents($this->themeSourceDir . '/theme.json'), true);
        $this->assertSame('Favorite CMS Team', $manifest['author']);
        $this->assertSame('screenshot.png', $manifest['screenshot']);
        $this->assertFileExists($this->themeSourceDir . '/' . $manifest['screenshot']);
    }

    public function test21_ActivateThemeValidationSucceedsWithIndexPhp(): void
    {
        $this->assertFileExists($this->themeSourceDir . '/index.php');
        $this->assertGreaterThan(50, filesize($this->themeSourceDir . '/index.php'));
    }

    public function test22_FunctionsPhpDeclaresCompatibilityHelpers(): void
    {
        $content = file_get_contents($this->themeSourceDir . '/functions.php');
        $this->assertStringContainsString('favorite_multimedia_theme_is_compatible', $content);
        $this->assertStringContainsString('favorite_multimedia_theme_admin_notice', $content);
    }

    public function test23_ThemeVersionMatches102(): void
    {
        $this->assertSame('1.0.2', ThemePackageService::THEME_VERSION);
        $manifest = ThemePackageService::getManifest();
        $this->assertSame('1.0.2', $manifest['version']);
    }

    public function test24_ChecksumFilesExistAndMatchSha256(): void
    {
        $checksumFile = $this->assetsRepoDir . '/favorite-multimedia-theme.zip.sha256';
        $this->assertFileExists($checksumFile);

        $expectedSha = hash_file('sha256', $this->zipPath);
        $content = file_get_contents($checksumFile);
        $this->assertStringContainsString($expectedSha, $content);

        $versionedSha = hash_file('sha256', $this->versionedZipPath);
        $this->assertSame($expectedSha, $versionedSha);
    }

    public function test25_ThemePackageServiceBuildZipPackageProducesValidZip(): void
    {
        $testZip = sys_get_temp_dir() . '/test_build_' . bin2hex(random_bytes(4)) . '.zip';
        $res = ThemePackageService::buildZipPackage($this->themeSourceDir, $testZip);
        $this->assertTrue($res);
        $this->assertFileExists($testZip);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($testZip));
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->statIndex($i)['name'];
            $this->assertStringStartsWith('favorite-multimedia-theme/', $name);
        }
        $zip->close();
        unlink($testZip);
    }
}
