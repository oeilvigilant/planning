<?php
require_once __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ptCsrfCheck()) {
    ptAgentLogout(true);
}
ptRedirect('login.php');
