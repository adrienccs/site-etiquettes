<?php
declare(strict_types=1);

namespace CopyColor\Etiquettes;

/**
 * Formats, packs, prix et contrôle des designs.
 * Mêmes règles que le configurateur (www/assets/composer.js) : le serveur recalcule toujours le prix.
 *
 * Règle du carnet : il contient au moins la composition d'un pack ; prix = pack contenu le plus
 * avantageux + les planches en plus au prix unitaire (thermocollante 3 €, autocollante 2 €).
 */
final class Catalog
{
    public const VAT_RATE = 0.20;

    /** format => libellé, étiquettes par planche, famille */
    public const FORMATS = [
        'thermo'    => ['label' => 'Thermocollantes', 'per' => 24, 'kind' => 'thermo'],
        'mini'      => ['label' => 'Mini', 'per' => 42, 'kind' => 'adhesive'],
        'chaussure' => ['label' => 'Chaussures', 'per' => 12, 'kind' => 'adhesive'],
        'ronde'     => ['label' => 'Rondes', 'per' => 10, 'kind' => 'adhesive'],
        'stylo'     => ['label' => 'Stylo', 'per' => 32, 'kind' => 'adhesive'],
    ];

    /** Prix par défaut d'une planche en plus, en centimes TTC (modifiables depuis l'atelier : tarifs.json) */
    public const UNIT_CENTS = ['thermo' => 300, 'mini' => 200, 'chaussure' => 200, 'ronde' => 200, 'stylo' => 200];

    /** Packs de 5 planches, du moins cher au plus cher (prix par défaut, modifiables depuis l'atelier) */
    public const PACKS = [
        'senior'  => ['label' => 'Pack senior', 'cents' => 1900, 'content' => ['thermo' => 3, 'mini' => 2]],
        'rentree' => ['label' => 'Pack rentrée', 'cents' => 2200, 'content' => ['thermo' => 1, 'mini' => 1, 'chaussure' => 1, 'ronde' => 1, 'stylo' => 1]],
        'colo'    => ['label' => 'Pack colo & sport', 'cents' => 2400, 'content' => ['thermo' => 2, 'mini' => 1, 'ronde' => 1, 'chaussure' => 1]],
        'creche'  => ['label' => 'Pack crèche', 'cents' => 2900, 'content' => ['thermo' => 3, 'ronde' => 1, 'chaussure' => 1]],
    ];

    public const FONTS = ['fredoka', 'pacifico', 'archivo', 'patrick'];
    public const COLORS = [
        'lime' => '#B1D36B', 'pink' => '#EE5F90', 'teal' => '#3E9C8C', 'orange' => '#F2994A',
        'red' => '#C62F35', 'violet' => '#6E52C2', 'blue' => '#2F6FB5', 'ink' => '#2D2D31',
    ];
    public const PICTOS = ['none', 'i-licorne', 'i-pompier', 'i-fleur', 'i-dino', 'i-fusee', 'i-chat', 'i-arc', 'i-papillon'];
    public const PATTERNS = ['cactus', 'feuilles', 'chats', 'abeilles', 'marguerites', 'mer', 'noel', 'herbier', 'automne',
                             'tortues', 'monstres', 'baleines', 'poissons', 'nuages', 'singes'];

    public const MAX_NAME = 14;
    public const MAX_LINE2 = 20;
    public const MAX_PLANCHES = 60;

    /**
     * Prix d'un carnet.
     * @param array<string,int> $counts format => nombre de planches
     * @return array{ok:bool, pack?:string, ttc_cents?:int, extras?:array, missing?:array, closest?:string}
     */
    /** @var string|null fichier des tarifs publiés par l'atelier (data_dir/tarifs.json) */
    private static $pricesFile = null;
    /** @var array|null */
    private static $prices = null;

    public static function setPricesFile(string $file): void
    {
        self::$pricesFile = $file;
        self::$prices = null;
    }

    /** Tarifs en vigueur : {packs: {id: centimes}, unit: {thermo, adhesive}, updated_at} */
    public static function prices(): array
    {
        if (self::$prices !== null) {
            return self::$prices;
        }
        $p = ['packs' => [], 'unit' => ['thermo' => self::UNIT_CENTS['thermo'], 'adhesive' => self::UNIT_CENTS['mini']], 'updated_at' => null];
        foreach (self::PACKS as $id => $pk) {
            $p['packs'][$id] = $pk['cents'];
        }
        if (self::$pricesFile && is_file(self::$pricesFile)) {
            $d = json_decode((string) file_get_contents(self::$pricesFile), true);
            if (is_array($d)) {
                foreach ($p['packs'] as $id => $_) {
                    if (isset($d['packs'][$id]) && is_int($d['packs'][$id])) {
                        $p['packs'][$id] = $d['packs'][$id];
                    }
                }
                foreach (['thermo', 'adhesive'] as $k) {
                    if (isset($d['unit'][$k]) && is_int($d['unit'][$k])) {
                        $p['unit'][$k] = $d['unit'][$k];
                    }
                }
                $p['updated_at'] = $d['updated_at'] ?? null;
            }
        }
        return self::$prices = $p;
    }

    /** Enregistre les tarifs envoyés par l'atelier (centimes TTC, entre 0,50 € et 500 €). */
    public static function savePrices(array $in): array
    {
        $cur = self::prices();
        $ok = static function ($v): bool {
            return is_int($v) && $v >= 50 && $v <= 50000;
        };
        $out = ['packs' => [], 'unit' => [], 'updated_at' => date('c')];
        foreach ($cur['packs'] as $id => $c) {
            $v = $in['packs'][$id] ?? $c;
            if (!$ok($v)) {
                throw new HttpError(422, 'Prix invalide pour ' . self::PACKS[$id]['label'] . '.', 'bad_price');
            }
            $out['packs'][$id] = $v;
        }
        foreach (['thermo', 'adhesive'] as $k) {
            $v = $in['unit'][$k] ?? $cur['unit'][$k];
            if (!$ok($v)) {
                throw new HttpError(422, 'Prix invalide pour la planche ' . $k . '.', 'bad_price');
            }
            $out['unit'][$k] = $v;
        }
        if (!self::$pricesFile) {
            throw new \RuntimeException('Fichier des tarifs non configuré');
        }
        $tmp = self::$pricesFile . '.tmp';
        if (file_put_contents($tmp, json_encode($out, JSON_PRETTY_PRINT)) === false || !rename($tmp, self::$pricesFile)) {
            throw new \RuntimeException('Écriture des tarifs impossible');
        }
        self::$prices = null;
        return self::prices();
    }

    /** Packs avec leur prix en vigueur. */
    public static function packs(): array
    {
        $p = self::prices();
        $out = [];
        foreach (self::PACKS as $id => $pk) {
            $out[$id] = ['cents' => $p['packs'][$id]] + $pk;
        }
        uasort($out, static function ($a, $b) {
            return $a['cents'] <=> $b['cents'];
        });
        return $out;
    }

    /** Prix d'une planche en plus, par format, en vigueur. */
    public static function unitCents(): array
    {
        $u = self::prices()['unit'];
        $out = [];
        foreach (self::FORMATS as $f => $fm) {
            $out[$f] = $fm['kind'] === 'thermo' ? $u['thermo'] : $u['adhesive'];
        }
        return $out;
    }

    /** Pour l'affichage public (accueil et configurateur). */
    public static function publicPrices(): array
    {
        $packs = [];
        foreach (self::packs() as $id => $pk) {
            $packs[] = ['id' => $id, 'label' => $pk['label'], 'cents' => $pk['cents'], 'content' => $pk['content']];
        }
        $u = self::prices()['unit'];
        return ['packs' => $packs, 'unit' => $u, 'from_cents' => $packs[0]['cents'], 'updated_at' => self::prices()['updated_at']];
    }

    public static function quote(array $counts): array
    {
        $best = null;
        $closest = null;
        $unit = self::unitCents();
        foreach (self::packs() as $id => $p) {
            $missing = [];
            foreach ($p['content'] as $f => $n) {
                $lack = $n - ($counts[$f] ?? 0);
                if ($lack > 0) {
                    $missing[$f] = $lack;
                }
            }
            if ($missing) {
                if ($closest === null || array_sum($missing) < array_sum($closest['missing'])) {
                    $closest = ['id' => $id, 'missing' => $missing];
                }
                continue;
            }
            $extras = [];
            $total = $p['cents'];
            foreach ($counts as $f => $n) {
                $more = $n - ($p['content'][$f] ?? 0);
                if ($more > 0) {
                    $extras[] = ['format' => $f, 'qty' => $more, 'unit_ttc_cents' => $unit[$f]];
                    $total += $more * $unit[$f];
                }
            }
            if ($best === null || $total < $best['ttc_cents']) {
                $best = ['ok' => true, 'pack' => $id, 'ttc_cents' => $total, 'extras' => $extras];
            }
        }
        if ($best !== null) {
            return $best;
        }
        return ['ok' => false, 'closest' => $closest['id'], 'missing' => $closest['missing']];
    }

    public static function htCents(int $ttc): int
    {
        return (int) round($ttc / (1 + self::VAT_RATE));
    }

    /** Message lisible quand le carnet ne contient aucun pack. */
    public static function missingMessage(array $q): string
    {
        $parts = [];
        foreach ($q['missing'] as $f => $n) {
            $parts[] = $n . ' planche' . ($n > 1 ? 's' : '') . ' ' . mb_strtolower(self::FORMATS[$f]['label']);
        }
        return 'Votre carnet doit contenir au moins la composition d\'un pack. Pour le '
            . mb_strtolower(self::PACKS[$q['closest']]['label']) . ', ajoutez : ' . implode(', ', $parts) . '.';
    }

    /**
     * Design reçu du navigateur → design propre, au format attendu par le NAS (docs/ETIQUETTES.md de printnest-web).
     * @throws HttpError
     */
    public static function cleanDesign($d, int $pos): array
    {
        $where = 'Planche ' . $pos . ' : ';
        if (!is_array($d)) {
            throw new HttpError(422, $where . 'design manquant.', 'bad_design');
        }
        $text = static function ($v, int $max): string {
            $v = preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $v) ?? '';
            $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
            return mb_substr($v, 0, $max);
        };
        $name = $text($d['name'] ?? '', self::MAX_NAME);
        if ($name === '') {
            throw new HttpError(422, $where . 'indiquez le prénom à imprimer.', 'bad_design');
        }
        $font = in_array($d['font'] ?? '', self::FONTS, true) ? $d['font'] : 'fredoka';
        $color = isset(self::COLORS[$d['color'] ?? '']) ? $d['color'] : 'lime';
        $pattern = in_array($d['pattern'] ?? '', self::PATTERNS, true) ? $d['pattern'] : 'uni';
        $picto = in_array($d['picto'] ?? '', self::PICTOS, true) ? $d['picto'] : 'none';
        $tc = (string) ($d['textColor'] ?? 'auto');
        $tc = preg_match('/^#[0-9A-Fa-f]{6}$/', $tc) ? strtoupper($tc) : 'auto';
        $img = (string) ($d['imageId'] ?? '');
        $img = preg_match('/^[a-f0-9]{24}$/', $img) ? $img : null;
        if ($pattern !== 'uni') {          // fond illustré : jamais d'illustration ni d'image
            $picto = 'none';
            $img = null;
        }
        return [
            'name' => $name,
            'line2' => $text($d['line2'] ?? '', self::MAX_LINE2),
            'font' => $font,
            'style' => $pattern === 'uni' ? 'uni' : 'pattern',
            'color' => $color,
            'bg' => self::COLORS[$color],
            'text_color' => $tc,
            'picto' => $img ? 'none' : $picto,
            'pattern' => $pattern,
            'auto_colors' => !empty($d['auto']),
            'image_file_id' => $img,
        ];
    }

    public static function formatLabel(string $f): string
    {
        return self::FORMATS[$f]['label'] ?? $f;
    }
}
