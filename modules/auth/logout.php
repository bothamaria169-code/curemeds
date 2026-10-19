<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/functions.php';

JWTHandler::clearToken();

// Keep the basket — someone signing out on a shared phone should not lose
// what they were buying — but drop everything identifying.
$cart = $_SESSION['cart'] ?? [];
$_SESSION = [];
session_regenerate_id(true);
$_SESSION['cart'] = $cart;

redirect('index.php');
