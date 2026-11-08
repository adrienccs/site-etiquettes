<?php
declare(strict_types=1);

namespace CopyColor\Etiquettes;

/** Limites d'usage par IP sur les routes publiques (fenêtre glissante d'une heure). */
final class RateLimiter
{
    /** @var Db */
    private $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    public function hit(string $ip, string $bucket, int $maxPerHour): void
    {
        // hits est recalculé avant window_start : les deux lisent l'ancienne fenêtre
        $this->db->run(
            'INSERT INTO rate_limits (ip, bucket, window_start, hits) VALUES (:ip, :b, NOW(), 1)
             ON DUPLICATE KEY UPDATE
               hits = IF(window_start < NOW() - INTERVAL 1 HOUR, 1, hits + 1),
               window_start = IF(window_start < NOW() - INTERVAL 1 HOUR, NOW(), window_start)',
            [':ip' => $ip, ':b' => $bucket]
        );
        $hits = (int) $this->db->value('SELECT hits FROM rate_limits WHERE ip = :ip AND bucket = :b', [':ip' => $ip, ':b' => $bucket]);
        if ($hits > $maxPerHour) {
            throw new HttpError(429, 'Trop de demandes, réessayez dans un moment.', 'rate_limited');
        }
    }
}

/**
 * Authentification des appels du NAS.
 *
 * En-têtes :
 *   X-CC-Timestamp : secondes Unix
 *   X-CC-Nonce     : 16 à 64 caractères [A-Za-z0-9], jamais réutilisé
 *   X-CC-Signature : hex( HMAC-SHA256( secret,
 *                      MÉTHODE \n CHEMIN?QUERY \n TIMESTAMP \n NONCE \n hex(SHA256(corps)) ) )
 */
final class NasAuth
{
    /** @var Db */
    private $db;
    /** @var array */
    private $cfg;

    public function __construct(Db $db, array $nasCfg)
    {
        $this->db = $db;
        $this->cfg = $nasCfg;
    }

    public static function sign(string $secret, string $method, string $pathWithQuery, string $ts, string $nonce, string $body): string
    {
        $base = strtoupper($method) . "\n" . $pathWithQuery . "\n" . $ts . "\n" . $nonce . "\n" . hash('sha256', $body);
        return hash_hmac('sha256', $base, $secret);
    }

    public function check(Request $req): void
    {
        $allowed = $this->cfg['allowed_ips'] ?? [];
        if ($allowed && !in_array($req->ip, $allowed, true)) {
            throw new HttpError(403, 'Accès refusé.', 'ip_denied');
        }
        $secret = (string) ($this->cfg['secret'] ?? '');
        if (strlen($secret) < 32 || $secret === 'CHANGER') {
            throw new HttpError(503, 'Secret NAS non configuré.', 'nas_not_configured');
        }
        $ts = $req->header('X-CC-Timestamp') ?? '';
        $nonce = $req->header('X-CC-Nonce') ?? '';
        $sig = strtolower($req->header('X-CC-Signature') ?? '');
        if (!ctype_digit($ts) || !preg_match('/^[A-Za-z0-9]{16,64}$/', $nonce) || !preg_match('/^[a-f0-9]{64}$/', $sig)) {
            throw new HttpError(401, 'Signature absente ou mal formée.', 'bad_signature');
        }
        $skew = (int) ($this->cfg['max_skew_s'] ?? 300);
        if (abs(time() - (int) $ts) > $skew) {
            throw new HttpError(401, 'Horloge décalée ou requête trop ancienne.', 'stale_request');
        }
        $expected = self::sign($secret, $req->method, $req->pathWithQuery, $ts, $nonce, $req->body());
        if (!hash_equals($expected, $sig)) {
            throw new HttpError(401, 'Signature invalide.', 'bad_signature');
        }
        $st = $this->db->run('INSERT IGNORE INTO nas_nonces (nonce) VALUES (:n)', [':n' => $nonce]);
        if ($st->rowCount() === 0) {
            throw new HttpError(401, 'Requête déjà reçue.', 'replay');
        }
    }
}
