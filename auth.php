<?php
require_once __DIR__ . '/config.php';

function wajibLogin(): void {
    if (empty($_SESSION['user'])) {
        header('Location: login.php');
        exit;
    }
}