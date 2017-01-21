<?php

include(__DIR__ . '/SyncLib.php');

$cloudflare_records = (SyncLib::getCloudFlareDomains('govapi.tw'));
$github_records = (SyncLib::getGitHubDomains('govapi.tw'));
$diff_records = SyncLib::checkDiff($cloudflare_records, $github_records);
SyncLib::handleDiff($diff_records, 'govapi.tw');
