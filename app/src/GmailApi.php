<?php
declare(strict_types=1);

namespace CopyColor\Etiquettes;

/**
 * Envoi par l'API Gmail de Google Workspace (HTTPS) : OVH bloque le SMTP sortant.
 * Compte de service Google Cloud avec délégation au niveau du domaine, portée gmail.send ;
 * il envoie au nom de `sender` (une vraie boîte Google Workspace, ex. contact@copycolor.fr).
 */
final class GmailApi
{
    /** @var string */
    private $saFile;
    /** @var string */
    private $sender;
    /** @var string */
    public $lastError = '';

    public function __construct(string $saFile, string $sender)
    {
        $this->saFile = $saFile;
        $this->sender = $sender;
    }

    public function sender(): string
    {
        return $this->sender;
    }

    /** $raw : message complet (en-têtes To/Subject/From… puis corps). */
    public function send(string $raw): bool
    {
        $sa = is_file($this->saFile) ? json_decode((string) file_get_contents($this->saFile), true) : null;
        if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key']) || $this->sender === '') {
            $this->lastError = 'compte de service ou expéditeur manquant';
            return false;
        }
        $now = time();
        $jwt = self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . self::b64(json_encode([
            'iss' => $sa['client_email'],
            'sub' => $this->sender,
            'scope' => 'https://www.googleapis.com/auth/gmail.send',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 600,
        ]));
        $sig = '';
        if (!openssl_sign($jwt, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
            $this->lastError = 'signature impossible';
            return false;
        }
        $jwt .= '.' . self::b64($sig);

        $tok = self::post('https://oauth2.googleapis.com/token',
            http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]),
            ['Content-Type: application/x-www-form-urlencoded']);
        $access = $tok['json']['access_token'] ?? null;
        if (!$access) {
            $this->lastError = 'jeton refusé (' . $tok['code'] . ') ' . substr($tok['body'], 0, 200);
            return false;
        }
        $res = self::post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
            (string) json_encode(['raw' => self::b64($raw)]),
            ['Content-Type: application/json', 'Authorization: Bearer ' . $access]);
        if ($res['code'] !== 200) {
            $this->lastError = 'envoi refusé (' . $res['code'] . ') ' . substr($res['body'], 0, 200);
            return false;
        }
        return true;
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    /** @return array{code:int, body:string, json:mixed} */
    private static function post(string $url, string $data, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false) {
            $body = 'curl: ' . curl_error($ch);
        }
        curl_close($ch);
        return ['code' => $code, 'body' => (string) $body, 'json' => json_decode((string) $body, true)];
    }
}
