<?php
// Configuration des tests de bout en bout (base locale, emails écrits sur disque).
return [
    'db' => ['dsn' => getenv('TEST_DSN') ?: 'mysql:unix_socket=/tmp/mysqltest/mysql.sock;dbname=etq;charset=utf8mb4',
             'user' => 'etq', 'pass' => 'etq'],
    'data_dir' => getenv('TEST_DATA') ?: '/tmp/etiquettes-test-data',
    'app_secret' => str_repeat('ab', 32),
    'base_url' => 'http://127.0.0.1:8098',
    'nas' => ['secret' => str_repeat('cd', 32), 'allowed_ips' => ['127.0.0.1'], 'max_skew_s' => 300],
    'mail' => ['from' => 'etiquettes@copycolor.fr', 'from_name' => 'Copy Color Service', 'reply_to' => 'contact@copycolor.fr',
               'atelier_to' => 'contact@copycolor.fr', 'enabled' => false],
    'shop' => ['name' => 'Copy Color Service', 'address' => '12, rue du Four, 79300 Bressuire', 'phone' => '05 49 74 31 07', 'hours' => ''],
    'limits' => ['max_image_bytes' => 15 * 1024 * 1024, 'images_per_hour' => 60, 'orders_per_hour' => 30,
                 'image_ttl_hours' => 72, 'orders_retention_years' => 3],
];
