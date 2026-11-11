<?php
declare(strict_types=1);

namespace CopyColor\Etiquettes;

/**
 * Présence en direct (bouton 📈 de PrintNest Web) : qui compose un carnet en ce moment.
 *   - aucun cookie ; le navigateur envoie un signal par minute tant que la page est affichée
 *     (étape, nombre de planches, montant) ;
 *   - un visiteur = empreinte du jour (IP + navigateur + sel aléatoire effacé le lendemain), jamais l'IP ;
 *   - une ligne par visiteur, effacée après 30 minutes sans nouvelles.
 */
final class Live
{
    /** Étapes, de la moins à la plus avancée. */
    private const STEPS = ['suivi', 'accueil', 'composer', 'recap', 'commande'];
    private const BOTS = '/bot|crawl|spider|slurp|preview|facebookexternalhit|embedly|headless|lighthouse|pagespeed|curl|wget|python|java\/|go-http|monitor|uptime|scan/i';

    /** @var Db */
    private $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /** Signal du navigateur (POST /api/stats/live). Ne lève jamais d'erreur. */
    public function ping(Request $q): void
    {
        try {
            $raw = $q->body();
            $ua = (string) $q->header('User-Agent');
            if (strlen($raw) > 1024 || $ua === '' || preg_match(self::BOTS, $ua)) {
                return;
            }
            $in = json_decode($raw, true);
            $step = is_array($in) ? (string) ($in['step'] ?? '') : '';
            if (!in_array($step, self::STEPS, true)) {
                return;
            }
            $day = date('Y-m-d');
            $this->db->run('INSERT IGNORE INTO stats_salt (day, salt) VALUES (:d, :s)', [':d' => $day, ':s' => bin2hex(random_bytes(32))]);
            $salt = (string) $this->db->value('SELECT salt FROM stats_salt WHERE day = :d', [':d' => $day]);
            $vid = substr(hash_hmac('sha256', $q->ip . '|' . $ua, $salt), 0, 16);
            $this->db->run(
                'INSERT INTO stats_live (vid, step, items, cents, device, started, updated)
                 VALUES (:v, :s, :n, :c, :d, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE step = VALUES(step), items = VALUES(items), cents = VALUES(cents),
                   device = VALUES(device), updated = NOW()',
                [':v' => $vid, ':s' => $step, ':n' => max(0, min(9999, (int) ($in['items'] ?? 0))),
                 ':c' => max(0, min(99999999, (int) ($in['cents'] ?? 0))), ':d' => self::device($ua)]
            );
            if (random_int(1, 20) === 1) {
                $this->purge();
            }
        } catch (\Throwable $e) {
            // présence perdue, sans conséquence
        }
    }

    /** Qui est sur le site en ce moment (signal reçu depuis moins de 3 minutes). */
    public function report(): array
    {
        $rows = $this->db->all(
            "SELECT step, items, cents, device, TIMESTAMPDIFF(SECOND, started, NOW()) AS since,
                    TIMESTAMPDIFF(SECOND, updated, NOW()) AS idle
             FROM stats_live WHERE updated >= NOW() - INTERVAL 3 MINUTE ORDER BY updated DESC LIMIT 50"
        );
        $order = array_flip(self::STEPS);
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['step' => $r['step'], 'items' => (int) $r['items'], 'cents' => (int) $r['cents'], 'device' => $r['device'],
                      'since' => (int) $r['since'], 'idle' => (int) $r['idle']];
        }
        usort($out, function ($a, $b) use ($order) { return [$order[$b['step']], $b['items']] <=> [$order[$a['step']], $a['items']]; });
        return ['count' => count($out), 'visitors' => $out];
    }

    /** Tâche planifiée : présences de plus de 30 min et sels des jours passés. */
    public function purge(): array
    {
        return [
            'presence' => $this->db->run('DELETE FROM stats_live WHERE updated < NOW() - INTERVAL 30 MINUTE')->rowCount(),
            'sels' => $this->db->run('DELETE FROM stats_salt WHERE day < CURDATE()')->rowCount(),
        ];
    }

    private static function device(string $ua): string
    {
        if (preg_match('/iPad|Tablet|PlayBook|Silk/i', $ua) || (stripos($ua, 'Android') !== false && stripos($ua, 'Mobile') === false)) {
            return 'tablet';
        }
        return preg_match('/Mobi|iPhone|iPod|Android/i', $ua) ? 'mobile' : 'desktop';
    }
}
