<?php
declare(strict_types=1);

namespace CopyColor\Etiquettes;

final class Orders
{
    /** Statuts que le NAS peut donner, depuis chaque statut. */
    private const NAS_TRANSITIONS = [
        'envoyee'       => ['annulee'],
        'recuperee'     => ['en_production', 'prete', 'annulee'],
        'en_production' => ['prete', 'annulee'],
        'prete'         => ['retiree', 'annulee'],
        'retiree'       => [],
        'annulee'       => [],
    ];
    /** Le client peut annuler tant que la fabrication n'a pas commencé. */
    private const CLIENT_CANCELLABLE = ['envoyee', 'recuperee'];

    /** @var Db */
    private $db;
    /** @var Storage */
    private $storage;
    /** @var Images */
    private $images;
    /** @var Mailer */
    private $mailer;
    /** @var Emails */
    private $emails;
    /** @var array */
    private $cfg;

    public function __construct(Db $db, Storage $storage, Images $images, Mailer $mailer, Emails $emails, array $cfg)
    {
        $this->db = $db;
        $this->storage = $storage;
        $this->images = $images;
        $this->mailer = $mailer;
        $this->emails = $emails;
        $this->cfg = $cfg;
    }

    // ─── Création par le client ────────────────────────────────────────────

    public function create(array $in, string $ip): array
    {
        $c = is_array($in['customer'] ?? null) ? $in['customer'] : [];
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($c['name'] ?? '')) ?? '');
        $email = trim((string) ($c['email'] ?? ''));
        $phone = Util::normalizePhone((string) ($c['phone'] ?? ''));
        $fields = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $fields['name'] = 'Indiquez votre nom et prénom.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 200) {
            $fields['email'] = 'Adresse email invalide.';
        }
        if ($phone === null) {
            $fields['phone'] = 'Numéro de téléphone invalide.';
        }
        if (($in['consent'] ?? null) !== true) {
            $fields['consent'] = 'Merci de cocher la case d\'accord.';
        }
        if ($fields) {
            throw new HttpError(422, 'Merci de vérifier vos coordonnées.', 'bad_customer', ['fields' => $fields]);
        }

        // Planches : format connu, quantité raisonnable, design propre
        $in_ = $in['planches'] ?? null;
        if (!is_array($in_) || count($in_) === 0) {
            throw new HttpError(422, 'Votre carnet est vide.', 'no_planches');
        }
        if (count($in_) > 30) {
            throw new HttpError(422, 'Trop de planches différentes dans un seul carnet.', 'too_many');
        }
        $lines = [];
        $counts = [];
        $images = [];
        foreach (array_values($in_) as $i => $p) {
            $f = (string) ($p['format'] ?? '');
            $q = $p['qty'] ?? null;
            if (!isset(Catalog::FORMATS[$f])) {
                throw new HttpError(422, 'Planche ' . ($i + 1) . ' : format inconnu.', 'bad_format');
            }
            if (!is_int($q) || $q < 1 || $q > Catalog::MAX_PLANCHES) {
                throw new HttpError(422, 'Planche ' . ($i + 1) . ' : quantité invalide.', 'bad_qty');
            }
            $d = Catalog::cleanDesign($p['design'] ?? null, $i + 1);
            if ($d['image_file_id']) {
                $im = $this->db->one('SELECT * FROM images WHERE id = :i', [':i' => $d['image_file_id']]);
                if ($im === null || $im['status'] === 'deleted') {
                    throw new HttpError(422, 'Planche ' . ($i + 1) . ' : votre image a expiré, importez-la à nouveau.', 'image_gone');
                }
                $images[$d['image_file_id']] = true;
            }
            $lines[] = ['format' => $f, 'qty' => $q, 'design' => $d];
            $counts[$f] = ($counts[$f] ?? 0) + $q;
        }
        if (array_sum($counts) > Catalog::MAX_PLANCHES) {
            throw new HttpError(422, sprintf('%d planches au maximum par carnet : appelez-nous pour une plus grosse commande.', Catalog::MAX_PLANCHES), 'too_many');
        }

        // Mode atelier (code saisi à la boutique) : composition libre, planches au prix unitaire,
        // ou prix total fixé à la main
        $staff = isset($in['staff_code']) && is_string($in['staff_code']) && $in['staff_code'] !== '';
        if ($staff && !Catalog::staffCheck($in['staff_code'])) {
            throw new HttpError(403, 'Code atelier incorrect.', 'bad_staff_code');
        }
        // Prix recalculé ici, jamais repris du navigateur
        $quote = $staff ? Catalog::staffQuote($counts) : Catalog::quote($counts);
        if (!$quote['ok']) {
            throw new HttpError(422, Catalog::missingMessage($quote), 'no_pack');
        }
        if ($staff && isset($in['staff_ttc_cents']) && is_int($in['staff_ttc_cents'])
            && $in['staff_ttc_cents'] >= 0 && $in['staff_ttc_cents'] <= 100000) {
            $quote['ttc_cents'] = $in['staff_ttc_cents'];
            $quote['manual'] = true;
        }
        $ttc = $quote['ttc_cents'];
        $expected = $in['expected_ttc_cents'] ?? null;
        if (is_int($expected) && $expected !== $ttc) {
            throw new HttpError(409, 'Le prix a changé : ' . Util::euros($ttc) . '. Merci de vérifier avant de valider.', 'price_changed',
                ['ttc_cents' => $ttc]);
        }
        $pack = $staff ? ['label' => empty($quote['manual']) ? 'Commande atelier' : 'Commande atelier (prix fixé)', 'cents' => 0]
                       : Catalog::packs()[$quote['pack']];
        $pricing = [
            'pack' => ['id' => $quote['pack'], 'label' => $pack['label'], 'ttc_cents' => $pack['cents']],
            'extras' => $quote['extras'],
            'ttc_cents' => $ttc,
            'ht_cents' => Catalog::htCents($ttc),
        ];

        $order = $this->db->tx(function (Db $db) use ($name, $email, $phone, $ip, $lines, $counts, $pricing, $images, $in) {
            $yy = (int) date('y');
            // Compteur annuel atomique (verrou de ligne InnoDB jusqu'à la fin de la transaction)
            $db->run(
                'INSERT INTO ref_counters (year, seq) VALUES (:y, LAST_INSERT_ID(1))
                 ON DUPLICATE KEY UPDATE seq = LAST_INSERT_ID(seq + 1)',
                [':y' => $yy]
            );
            $n = (int) $db->value('SELECT LAST_INSERT_ID()');
            $ref = sprintf('E%02d-%04d', $yy, $n);
            $note = trim((string) ($in['note'] ?? ''));
            if ($pricing['pack']['id'] === 'atelier') {
                $note = trim('[Commande atelier] ' . $note);
            }
            $db->run(
                'INSERT INTO orders (ref, customer_name, customer_email, customer_phone, consent_at, consent_ip, note,
                                     planches, pack, pricing, vat_rate, ttc_cents, ht_cents)
                 VALUES (:ref, :n, :e, :p, NOW(), :ip, :note, :pl, :pack, :pricing, :vat, :ttc, :ht)',
                [':ref' => $ref, ':n' => $name, ':e' => $email, ':p' => $phone, ':ip' => $ip,
                 ':note' => $note === '' ? null : mb_substr($note, 0, 500),
                 ':pl' => array_sum($counts), ':pack' => $pricing['pack']['id'],
                 ':pricing' => json_encode($pricing, JSON_UNESCAPED_UNICODE),
                 ':vat' => Catalog::VAT_RATE, ':ttc' => $pricing['ttc_cents'], ':ht' => $pricing['ht_cents']]
            );
            $orderId = $db->lastId();
            foreach ($lines as $i => $l) {
                $db->run(
                    'INSERT INTO order_planches (order_id, position, format, qty, design, image_id) VALUES (:o, :pos, :f, :q, :d, :img)',
                    [':o' => $orderId, ':pos' => $i + 1, ':f' => $l['format'], ':q' => $l['qty'],
                     ':d' => json_encode($l['design'], JSON_UNESCAPED_UNICODE), ':img' => $l['design']['image_file_id']]
                );
            }
            foreach (array_keys($images) as $imgId) {
                $db->run("UPDATE images SET status = 'attached' WHERE id = :i", [':i' => $imgId]);
            }
            $db->run("INSERT INTO order_events (order_id, status, actor) VALUES (:o, 'envoyee', 'client')", [':o' => $orderId]);
            return $db->one('SELECT * FROM orders WHERE id = :id', [':id' => $orderId]);
        });

        $planches = $this->planches((int) $order['id']);
        $trackUrl = $this->trackUrl($order['ref']);
        $this->mailer->send($order['customer_email'], $this->emails->confirmation($order, $planches, $trackUrl));
        $this->mailer->send($this->cfg['mail']['atelier_to'], $this->emails->atelierAlert($order, $planches));
        $this->storage->log('info', 'commande créée', ['ref' => $order['ref'], 'planches' => $order['planches'], 'ttc' => $order['ttc_cents']]);

        return [
            'ref' => $order['ref'],
            'planches' => (int) $order['planches'],
            'ttc_cents' => (int) $order['ttc_cents'],
            'track_url' => $trackUrl,
        ];
    }

    // ─── Suivi et annulation par le client ─────────────────────────────────

    /** Jeton du lien client : dérivé de la référence et du secret du site (HMAC), jamais stocké. */
    public function trackToken(string $ref): string
    {
        return substr(hash_hmac('sha256', 'track:' . $ref, (string) $this->cfg['app_secret']), 0, 48);
    }

    public function trackUrl(string $ref): string
    {
        return rtrim($this->cfg['base_url'], '/') . '/c/' . rawurlencode($ref) . '/' . $this->trackToken($ref);
    }

    public function byToken(string $ref, string $token): array
    {
        $o = $this->db->one('SELECT * FROM orders WHERE ref = :r', [':r' => $ref]);
        if ($o === null || !preg_match('/^[a-f0-9]{48}$/', $token) || !hash_equals($this->trackToken($ref), $token)) {
            throw new HttpError(404, 'Commande introuvable.', 'order_not_found');
        }
        return $o;
    }

    public function isClientCancellable(array $o): bool
    {
        return in_array($o['status'], self::CLIENT_CANCELLABLE, true);
    }

    public function cancelByClient(string $ref, string $token): array
    {
        $o = $this->byToken($ref, $token);
        if ($o['status'] === 'annulee') {
            return $o;
        }
        if (!$this->isClientCancellable($o)) {
            throw new HttpError(409, 'La fabrication a commencé : appelez-nous pour annuler.', 'not_cancellable');
        }
        $o = $this->applyStatus($o, 'annulee', 'client', null);
        $this->mailer->send($o['customer_email'], $this->emails->cancelled($o, 'client'));
        $this->mailer->send($this->cfg['mail']['atelier_to'], $this->emails->atelierCancelled($o));
        return $o;
    }

    // ─── NAS ───────────────────────────────────────────────────────────────

    public function nasList(?string $status, ?string $since): array
    {
        $where = [];
        $args = [];
        if ($status !== null && $status !== '') {
            $ph = [];
            foreach (array_values(array_filter(explode(',', $status))) as $i => $s) {
                if (!isset(self::NAS_TRANSITIONS[$s])) {
                    throw new HttpError(400, "Statut inconnu : $s", 'bad_status');
                }
                $ph[] = ":s$i";
                $args[":s$i"] = $s;
            }
            $where[] = 'status IN (' . implode(',', $ph) . ')';
        }
        if ($since !== null && $since !== '') {
            if (strtotime($since) === false) {
                throw new HttpError(400, 'Paramètre since invalide.', 'bad_since');
            }
            $where[] = 'updated_at > :since';
            $args[':since'] = date('Y-m-d H:i:s', (int) strtotime($since));
        }
        $sql = 'SELECT ref, status, planches, ttc_cents, created_at, updated_at FROM orders'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY created_at LIMIT 500';
        return array_map(static function ($r) {
            return ['ref' => $r['ref'], 'status' => $r['status'], 'planches' => (int) $r['planches'],
                    'ttc_cents' => (int) $r['ttc_cents'], 'created_at' => self::iso($r['created_at']),
                    'updated_at' => self::iso($r['updated_at'])];
        }, $this->db->all($sql, $args));
    }

    public function byRef(string $ref): array
    {
        $o = $this->db->one('SELECT * FROM orders WHERE ref = :r', [':r' => $ref]);
        if ($o === null) {
            throw new HttpError(404, 'Commande introuvable.', 'order_not_found');
        }
        return $o;
    }

    public function planches(int $orderId): array
    {
        $rows = $this->db->all('SELECT * FROM order_planches WHERE order_id = :o ORDER BY position', [':o' => $orderId]);
        foreach ($rows as &$r) {
            $r['design'] = json_decode($r['design'], true);
        }
        return $rows;
    }

    /** Images de la commande (une seule fois chacune, même si plusieurs planches l'utilisent). */
    private function orderImages(int $orderId): array
    {
        return $this->db->all(
            'SELECT DISTINCT i.* FROM images i JOIN order_planches p ON p.image_id = i.id WHERE p.order_id = :o ORDER BY i.id',
            [':o' => $orderId]
        );
    }

    /** Le JSON complet reçu par le NAS (contrat : docs/ETIQUETTES.md de printnest-web). */
    public function nasDetail(string $ref): array
    {
        $o = $this->byRef($ref);
        $planches = array_map(static function ($p) {
            return ['id' => 'p' . $p['position'], 'format' => $p['format'], 'label' => Catalog::formatLabel($p['format']),
                    'qty' => (int) $p['qty'], 'design' => $p['design']];
        }, $this->planches((int) $o['id']));
        $images = array_map(static function ($im) {
            return ['file_id' => $im['id'], 'filename' => $im['filename'], 'sha256' => $im['sha256'], 'mime' => $im['mime'],
                    'bytes' => (int) $im['bytes'], 'px' => ['w' => (int) $im['px_w'], 'h' => (int) $im['px_h']]];
        }, $this->orderImages((int) $o['id']));
        return [
            'ref' => $o['ref'],
            'status' => $o['status'],
            'created_at' => self::iso($o['created_at']),
            'updated_at' => self::iso($o['updated_at']),
            'customer' => ['name' => $o['customer_name'], 'email' => $o['customer_email'], 'phone' => $o['customer_phone']],
            'note' => $o['note'] ?? '',
            'planches' => $planches,
            'images' => $images,
            'pricing' => json_decode($o['pricing'], true) + ['vat_rate' => (float) $o['vat_rate']],
            'files_available' => $o['files_purged_at'] === null,
        ];
    }

    /** Chemin d'une image pour le NAS (404 si inconnue, 410 si déjà effacée). */
    public function nasFile(string $ref, string $fileId): array
    {
        $o = $this->byRef($ref);
        $im = $this->db->one(
            'SELECT i.* FROM images i JOIN order_planches p ON p.image_id = i.id WHERE p.order_id = :o AND i.id = :i LIMIT 1',
            [':o' => $o['id'], ':i' => $fileId]
        );
        if ($im === null) {
            throw new HttpError(404, 'Fichier inconnu pour cette commande.', 'file_not_found');
        }
        $path = $this->storage->imagePath($fileId);
        if ($o['files_purged_at'] !== null || !is_file($path)) {
            throw new HttpError(410, 'Fichier déjà effacé.', 'file_gone');
        }
        return ['path' => $path, 'image' => $im];
    }

    /** Le NAS a tout reçu : on vérifie les empreintes, on efface les images, statut recuperee. */
    public function ack(string $ref, array $in): array
    {
        $o = $this->byRef($ref);
        if ($o['status'] === 'recuperee' && $o['fetched_at'] !== null) {
            return ['ref' => $ref, 'status' => 'recuperee', 'already' => true];
        }
        if ($o['status'] !== 'envoyee') {
            throw new HttpError(409, 'Commande au statut ' . $o['status'] . ', accusé de réception impossible.', 'bad_state',
                ['status' => $o['status']]);
        }
        $got = [];
        foreach ((array) ($in['files'] ?? []) as $f) {
            if (is_array($f) && isset($f['file_id'], $f['sha256'])) {
                $got[(string) $f['file_id']] = strtolower((string) $f['sha256']);
            }
        }
        $images = $this->orderImages((int) $o['id']);
        $bad = [];
        foreach ($images as $im) {
            if (!isset($got[$im['id']]) || !hash_equals($im['sha256'], $got[$im['id']])) {
                $bad[] = $im['id'];
            }
        }
        if ($bad) {
            throw new HttpError(422, 'Empreintes manquantes ou différentes : rien n\'a été effacé.', 'ack_mismatch', ['files' => $bad]);
        }
        $this->db->tx(function (Db $db) use ($o) {
            $db->run("UPDATE orders SET status = 'recuperee', fetched_at = NOW(), files_purged_at = NOW(), updated_at = NOW() WHERE id = :i",
                [':i' => $o['id']]);
            $db->run("INSERT INTO order_events (order_id, status, actor) VALUES (:o, 'recuperee', 'nas')", [':o' => $o['id']]);
        });
        foreach ($images as $im) {
            $this->images->delete($im['id']);
        }
        $this->storage->log('info', 'commande récupérée par le NAS', ['ref' => $ref]);
        return ['ref' => $ref, 'status' => 'recuperee', 'already' => false];
    }

    public function nasSetStatus(string $ref, array $in): array
    {
        $o = $this->byRef($ref);
        $to = (string) ($in['status'] ?? '');
        $note = isset($in['note']) ? mb_substr((string) $in['note'], 0, 500) : null;
        $notify = !array_key_exists('notify', $in) || $in['notify'] !== false;
        if (!isset(self::NAS_TRANSITIONS[$to])) {
            throw new HttpError(400, 'Statut inconnu.', 'bad_status');
        }
        if ($o['status'] === $to) {
            throw new HttpError(409, "Déjà au statut $to.", 'bad_transition', ['status' => $to]);
        }
        if (!in_array($to, self::NAS_TRANSITIONS[$o['status']], true)) {
            throw new HttpError(409, "Passage de {$o['status']} à $to impossible.", 'bad_transition', ['status' => $o['status']]);
        }
        $o = $this->applyStatus($o, $to, 'nas', $note);
        if ($notify && $to === 'prete') {
            $this->mailer->send($o['customer_email'], $this->emails->ready($o, $this->trackUrl($o['ref'])));
        }
        if ($notify && $to === 'annulee') {
            $this->mailer->send($o['customer_email'], $this->emails->cancelled($o, 'atelier'));
        }
        return ['ref' => $ref, 'status' => $to, 'changed' => true];
    }

    // ─── Commun ────────────────────────────────────────────────────────────

    private function applyStatus(array $o, string $to, string $actor, ?string $note): array
    {
        $o = $this->db->tx(function (Db $db) use ($o, $to, $actor, $note) {
            $set = 'status = :s, updated_at = NOW()';
            $args = [':s' => $to, ':i' => $o['id']];
            if ($to === 'prete') {
                $set .= ', ready_at = NOW()';
            } elseif ($to === 'retiree') {
                $set .= ', collected_at = NOW()';
            } elseif ($to === 'annulee') {
                $set .= ', cancelled_at = NOW(), cancelled_by = :by';
                $args[':by'] = $actor === 'client' ? 'client' : ($actor === 'nas' ? 'atelier' : 'systeme');
            }
            $db->run("UPDATE orders SET $set WHERE id = :i", $args);
            $db->run('INSERT INTO order_events (order_id, status, actor, note) VALUES (:o, :s, :a, :n)',
                [':o' => $o['id'], ':s' => $to, ':a' => $actor, ':n' => $note]);
            return $db->one('SELECT * FROM orders WHERE id = :i', [':i' => $o['id']]);
        });
        if ($to === 'annulee' && $o['files_purged_at'] === null) {
            $this->purgeFiles($o);
            $o['files_purged_at'] = date('c');
        }
        return $o;
    }

    public function purgeFiles(array $o): void
    {
        foreach ($this->orderImages((int) $o['id']) as $im) {
            $this->images->delete($im['id']);
        }
        $this->db->run('UPDATE orders SET files_purged_at = NOW() WHERE id = :i AND files_purged_at IS NULL', [':i' => $o['id']]);
    }

    private static function iso(string $ts): string
    {
        return date('c', (int) strtotime($ts));
    }
}
