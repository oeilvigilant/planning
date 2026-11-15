<?php
require_once __DIR__ . '/_init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ptCsrfCheck()) {
    unset($_SESSION['pt_user']);
}
ptRedirect('admin/login.php');
