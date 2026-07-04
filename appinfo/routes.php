<?php

return [
    'routes' => [
        ['name' => 'admin#getDryRun', 'url' => '/dry-run', 'verb' => 'GET'],
        ['name' => 'admin#setDryRun', 'url' => '/dry-run', 'verb' => 'POST'],
        ['name' => 'admin#scan', 'url' => '/scan', 'verb' => 'POST'],
        ['name' => 'admin#apply', 'url' => '/apply', 'verb' => 'POST'],
        ['name' => 'admin#getLogLevel', 'url' => '/log-level', 'verb' => 'GET'],
        ['name' => 'admin#setLogLevel', 'url' => '/log-level', 'verb' => 'POST'],
        ['name' => 'admin#debugAllinfo', 'url' => '/debug-allinfo', 'verb' => 'POST'],
    ],
];
