<?php
session_start();
$_SESSION = [];
session_destroy();

$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'], 3), '/\\');
header('Location: ' . $baseUrl . '/modules/auth/login.php');
exit;