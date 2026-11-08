<?php
declare(strict_types=1);

// Contrôleur frontal de etiquettes.copycolor.fr : /api/* et /c/* (page de suivi).
// Le reste (accueil, configurateur) est servi en fichiers statiques par Apache.

// Serveur de développement (php -S) : laisser passer les fichiers statiques
if (PHP_SAPI === 'cli-server') {
    $p = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $f = __DIR__ . $p;
    if (is_file($f) && basename($f) !== 'index.php') {
        return false;
    }
    if (preg_match('#^/composer/?$#', $p)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/composer.html');
        return true;
    }
    if ($p === '/') {
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/index.html');
        return true;
    }
}

require __DIR__ . '/../app/bootstrap.php';

use CopyColor\Etiquettes\App;
use CopyColor\Etiquettes\Request;

try {
    $app = new App(etiquettes_config());
} catch (Throwable $e) {
    error_log('etiquettes: démarrage impossible : ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"error":"unavailable","message":"Service momentanément indisponible."}';
    exit;
}
$app->handle(Request::fromGlobals());
