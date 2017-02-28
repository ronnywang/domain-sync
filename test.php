<?php

include(__DIR__ . '/SyncLib.php');

foreach (SyncLib::getConfig() as $root => $config) {
    if ($root == '_global_') {
        continue;
    }
    error_log('checking ' . $root);

    $cloudflare_records = SyncLib::getCloudFlareDomains($root);
    $github_records = SyncLib::getGitHubDomains($root);
    $diff_records = SyncLib::checkDiff($cloudflare_records, $github_records);
    SyncLib::handleDiff($diff_records, $root);
}
