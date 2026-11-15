<?php
require_once __DIR__ . '/includes/bootstrap.php';

ptRedirect(ptCurrentAgent() ? 'app.php' : 'login.php');
