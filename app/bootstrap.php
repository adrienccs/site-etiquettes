<?php
declare(strict_types=1);

// Chargement commun au site et aux tâches planifiées.
// La configuration est cherchée dans (par ordre) : $ETIQUETTES_CONFIG, ../config/config.php

if (PHP_VERSION_ID < 70400) {
    http_response_code(500);
    exit('PHP 7.4 minimum requis');
}

date_default_timezone_set('Europe/Paris');
mb_internal_encoding('UTF-8');

foreach (['Http', 'Util', 'Db', 'Storage', 'Security', 'GmailApi', 'Mailer', 'Catalog', 'Emails', 'Images', 'Orders', 'Live', 'App'] as $f) {
    require_once __DIR__ . '/src/' . $f . '.php';
}

function etiquettes_config(): array
{
    $path = getenv('ETIQUETTES_CONFIG') ?: __DIR__ . '/../config/config.php';
    if (!is_file($path)) {
        throw new RuntimeException('config.php introuvable : ' . $path);
    }
    $cfg = require $path;
    if (!is_array($cfg)) {
        throw new RuntimeException('config.php doit renvoyer un tableau');
    }
    return $cfg;
}
