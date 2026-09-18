<?php

use XcVm\Core\Auth\SessionManager;
use XcVm\Core\Localization\Translator;
use XcVm\Infrastructure\Bootstrap\AdminScopeBootstrap;

global $db, $rSettings, $rMobile, $rServers, $rProxyServers, $rDetect,
	$rTimeout, $rProtocol, $allServers, $rPermissions, $allowedLangs,
	$rServerError, $allServersHealthy, $updateRequired, $rUserInfo,
	$_STATUS, $customScript, $language;
$language = Translator::class;
AdminScopeBootstrap::hydrateAdminContext();
SessionManager::clearContext('admin');
header('Location: ./login');

exit();
