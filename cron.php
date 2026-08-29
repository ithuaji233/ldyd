<?php

require_once __DIR__ . '/lib/bootstrap.php';

$_GET['action'] = 'cron_sync';
require __DIR__ . '/api.php';
