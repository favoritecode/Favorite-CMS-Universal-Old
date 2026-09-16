<?php
/**
 * Favorite Multimedia — Content Layout Container
 * 
 * Provides layout configuration variables and classes for single content detail views.
 *
 * @var string $contentType
 */

use FavoriteCMS\Multimedia\Theme\ThemeManager;

$themeManager = ThemeManager::getInstance();
$themeConfig = $themeManager->getActiveConfig();
$singleCfg = $themeConfig->get('single_content', null, []);

$contentType = $contentType ?? 'movie';
$fmSidebarEnabled = ($singleCfg['sidebar_enabled'] ?? true) && ($singleCfg['enabled_types'][$contentType] ?? true);
$fmSidebarPos = $singleCfg['sidebar_position'] ?? 'right';
$fmLayoutClass = 'fm-content-layout' . ($fmSidebarEnabled ? ' has-sidebar sidebar-' . htmlspecialchars($fmSidebarPos, ENT_QUOTES, 'UTF-8') : ' no-sidebar');
$fmShowBackLink = (bool)($singleCfg['show_back_link'] ?? false);
