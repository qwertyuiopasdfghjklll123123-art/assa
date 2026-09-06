<?php
require_once __DIR__ . '/includes/bootstrap.php';

session_destroy();
header('Location: ' . APP_BASE_PATH . '/app');
exit;
