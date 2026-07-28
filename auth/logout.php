<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (is_logged_in()) {
    log_activity('logout', 'User logged out');

    // Clear remember-me token in DB and cookie
    $db = Database::getConnection();
    $db->prepare("UPDATE users SET remember_token = NULL WHERE id = ?")->execute([$_SESSION['user_id']]);
}

setcookie('rentsphere_remember', '', time() - 3600, '/', '', false, true);

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

session_start();
flash('success', 'You have been logged out securely.');
redirect(APP_URL . '/auth/login.php');
