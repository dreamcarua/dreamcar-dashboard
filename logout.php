<?php
/**
 * logout.php
 * Вихід з системи та очищення сесії
 */

require_once 'config/app_config.php';
require_once 'core/Auth.php';
require_once 'core/Session.php';

Session::start();

// Вийти
Auth::logout();

// Редирект на логін
header('Location: login.php?logout=success');
exit;
