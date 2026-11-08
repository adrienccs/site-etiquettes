<?php
declare(strict_types=1);

namespace CopyColor\Etiquettes;

/** Erreur HTTP remontée jusqu'au contrôleur frontal. */
final class HttpError extends \RuntimeException
{
    /** @var int */
    public $status;
    /** @var string */
    public $errorCode;
    /** @var array */
    public $extra;

    public function __construct(int $status, string $message, string $errorCode = 'error', array $extra = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->extra = $extra;
    }
}

final class Request
{
    /** @var string */
    public $method;
    /** @var string chemin sans query string, ex. /api/orders */
    public $path;
    /** @var string chemin + query tel que reçu (sert à la signature NAS) */
    public $pathWithQuery;
    /** @var array */
    public $query;
    /** @var string */
    public $ip;
    /** @var array paramètres extraits de la route */
    public $params = [];
    /** @var string|null */
    private $body;

    public static function fromGlobals(): self
    {
        $r = new self();
        $r->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $r->pathWithQuery = $uri;
        $path = parse_url($uri, PHP_URL_PATH);
        $r->path = is_string($path) ? rawurldecode($path) : '/';
        $r->query = $_GET;
        $r->ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        return $r;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }
        if ($name === 'Content-Type' && isset($_SERVER['CONTENT_TYPE'])) {
            return (string) $_SERVER['CONTENT_TYPE'];
        }
        if ($name === 'Content-Length' && isset($_SERVER['CONTENT_LENGTH'])) {
            return (string) $_SERVER['CONTENT_LENGTH'];
        }
        return null;
    }

    public function body(): string
    {
        if ($this->body === null) {
            $b = file_get_contents('php://input');
            $this->body = $b === false ? '' : $b;
        }
        return $this->body;
    }

    /** Corps JSON décodé ; 400 si invalide. */
    public function json(): array
    {
        $raw = $this->body();
        if ($raw === '') {
            return [];
        }
        $d = json_decode($raw, true);
        if (!is_array($d)) {
            throw new HttpError(400, 'Corps JSON invalide.', 'bad_json');
        }
        return $d;
    }

    public function param(string $k): string
    {
        return (string) ($this->params[$k] ?? '');
    }
}

final class Response
{
    public static function json(int $status, array $data): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function html(int $status, string $html): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'");
        echo $html;
    }

    public static function redirect(string $url): void
    {
        http_response_code(303);
        header('Location: ' . $url);
    }

    /** Petite image publique (logo), affichée dans la page et mise en cache par le navigateur. */
    public static function image(string $path, string $mime): void
    {
        http_response_code(200);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
    }

    /** Envoie un fichier en flux, sans le charger en mémoire. */
    public static function file(string $path, string $mime, string $downloadName, array $extraHeaders = []): void
    {
        $size = filesize($path);
        http_response_code(200);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $size);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
        header('Cache-Control: no-store');
        foreach ($extraHeaders as $k => $v) {
            header($k . ': ' . $v);
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return;
        }
        while (!feof($fh)) {
            $buf = fread($fh, 1024 * 1024);
            if ($buf === false) {
                break;
            }
            echo $buf;
            flush();
        }
        fclose($fh);
    }
}

final class Router
{
    /** @var array<int, array{0:string,1:string,2:callable}> */
    private $routes = [];

    /** @param string|string[] $methods */
    public function add($methods, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        foreach ((array) $methods as $m) {
            $this->routes[] = [strtoupper($m), $regex, $handler];
        }
    }

    public function dispatch(Request $req): void
    {
        $allowed = [];
        foreach ($this->routes as [$method, $regex, $handler]) {
            if (!preg_match($regex, $req->path, $m)) {
                continue;
            }
            if ($method !== $req->method) {
                $allowed[] = $method;
                continue;
            }
            foreach ($m as $k => $v) {
                if (is_string($k)) {
                    $req->params[$k] = $v;
                }
            }
            $handler($req);
            return;
        }
        if ($allowed) {
            header('Allow: ' . implode(', ', array_unique($allowed)));
            throw new HttpError(405, 'Méthode non autorisée.', 'method_not_allowed');
        }
        throw new HttpError(404, 'Introuvable.', 'not_found');
    }
}
