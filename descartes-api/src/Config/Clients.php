<?php

declare(strict_types=1);

return [
  'default' => [
    'server' => $_ENV['DB_SERVER'] ?? 'localhost',
    'database' => $_ENV['DB_NAME'] ?? 'larasa',
    'user' => $_ENV['DB_USER'] ?? '',
    'password' => $_ENV['DB_PASSWORD'] ?? '',
    'trust_cert' => $_ENV['DB_TRUST_CERT'] ?? 'true',
  ],
];