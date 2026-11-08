<?php
declare(strict_types=1);

namespace CopyColor\Etiquettes;

final class Util
{
    public static function randomHex(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ') . ' €';
    }

    public static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Nom de fichier affichable et sûr (pas de chemin, pas de caractères de contrôle). */
    public static function cleanFilename(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F"<>|:*?]/u', '', $name) ?? '';
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'image';
        }
        return mb_substr($name, 0, 120);
    }

    /** Téléphone : chiffres (et + initial) ; 10 chiffres FR ou 8 à 15 chiffres international. */
    public static function normalizePhone(string $p): ?string
    {
        $p = trim($p);
        $plus = strpos($p, '+') === 0;
        $digits = preg_replace('/\D+/', '', $p) ?? '';
        if ($plus) {
            return (strlen($digits) >= 8 && strlen($digits) <= 15) ? '+' . $digits : null;
        }
        if (preg_match('/^0\d{9}$/', $digits)) {
            return $digits;
        }
        return null;
    }

    public static function formatPhone(string $p): string
    {
        if (preg_match('/^0\d{9}$/', $p)) {
            return trim(chunk_split($p, 2, ' '));
        }
        return $p;
    }
}
