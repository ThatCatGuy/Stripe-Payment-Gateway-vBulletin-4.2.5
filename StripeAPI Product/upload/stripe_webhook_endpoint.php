<?php

if (!isset($_GET['is_is_webhook_request'])) {
    $_GET['is_webhook_request'] = 1;
}

if (!isset($_REQUEST['is_webhook_request'])) {
    $_REQUEST['is_webhook_request'] = 1;
}

if (!isset($_POST['is_webhook_request'])) {
    $_POST['is_webhook_request'] = 1;
}

require_once __DIR__ . '/payment_gateway.php';
