<?php
declare(strict_types=1);

/**
 * Tests de bout en bout : vraie base MySQL, vrai serveur HTTP (php -S), vrais fichiers.
 *   TEST_DSN='mysql:host=127.0.0.1;port=3307;dbname=etq;charset=utf8mb4' php tests/E2eTest.php
 * ATTENTION : vide la base indiquée.
 */

require __DIR__ . '/lib.php';
require __DIR__ . '/../app/bootstrap.php';

use CopyColor\Etiquettes\NasAuth;

putenv('ETIQUETTES_CONFIG=' . __DIR__ . '/config.test.php');
$cfg = require __DIR__ . '/config.test.php';
$root = dirname(__DIR__);
$base = $cfg['base_url'];

// ─── Base et dossier de données neufs ─────────────────────────────────────
$pdo = new PDO($cfg['db']['dsn'], $cfg['db']['user'], $cfg['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $pdo->exec("DROP TABLE `$t`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents($root . '/sql/schema.sql'))))) as $stmt) {
    $pdo->exec($stmt);
}
exec('rm -rf ' . escapeshellarg($cfg['data_dir']));

// ─── Serveur ──────────────────────────────────────────────────────────────
$srv = proc_open(
    ['php', '-S', '127.0.0.1:8098', '-t', $root . '/www', $root . '/www/index.php'],
    [1 => ['file', '/tmp/etiquettes-test-server.log', 'a'], 2 => ['file', '/tmp/etiquettes-test-server.log', 'a']],
    $pipes, null, ['ETIQUETTES_CONFIG' => __DIR__ . '/config.test.php', 'PATH' => getenv('PATH')]
);
register_shutdown_function(function () use ($srv) { proc_terminate($srv); });
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', 8098); $i++) {
    usleep(100000);
}

function http(string $method, string $path, $body = null, array $headers = []): array
{
    global $base;
    $ch = curl_init($base . $path);
    $raw = is_array($body) ? json_encode($body) : $body;
    if (is_array($body)) {
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
                            CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false]);
    if ($raw !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $raw);
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $text = substr($resp, $hs);
    return ['code' => $code, 'head' => substr($resp, 0, $hs), 'text' => $text, 'json' => json_decode($text, true)];
}

function nas(string $method, string $path, $body = null, array $opts = []): array
{
    global $cfg;
    $raw = $body === null ? '' : json_encode($body);
    $ts = (string) ($opts['ts'] ?? time());
    $nonce = $opts['nonce'] ?? bin2hex(random_bytes(12));
    $sig = NasAuth::sign($opts['secret'] ?? $cfg['nas']['secret'], $method, $path, $ts, $nonce, $raw);
    $h = ["X-CC-Timestamp: $ts", "X-CC-Nonce: $nonce", "X-CC-Signature: $sig"];
    if ($body !== null) {
        $h[] = 'Content-Type: application/json';
    }
    return http($method, $path, $body === null ? null : $raw, $h);
}

function upload(string $file, string $name): array
{
    global $base;
    $ch = curl_init($base . '/api/images');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POSTFIELDS => ['image' => new CURLFile($file, '', $name)]]);
    $text = curl_exec($ch);
    return ['code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'json' => json_decode((string) $text, true)];
}

$design = ['name' => 'Léa', 'line2' => 'Martin', 'font' => 'fredoka', 'color' => 'lime', 'picto' => 'i-licorne',
           'auto' => true, 'pattern' => 'uni', 'textColor' => 'auto', 'imageId' => ''];
$customer = ['name' => 'Julie Martin', 'email' => 'julie@example.fr', 'phone' => '06 12 34 56 78'];
$rentree = [['format' => 'thermo', 'qty' => 2, 'design' => $design], ['format' => 'mini', 'qty' => 1, 'design' => $design],
            ['format' => 'chaussure', 'qty' => 1, 'design' => ['pattern' => 'singes'] + $design],
            ['format' => 'ronde', 'qty' => 1, 'design' => $design], ['format' => 'stylo', 'qty' => 1, 'design' => $design]];

echo "Pages\n";
t('accueil, configurateur, confidentialité', function () {
    eq(200, http('GET', '/')['code']);
    ok(strpos(http('GET', '/composer')['text'], 'id="tpl"') !== false, 'gabarit du configurateur');
    eq(200, http('GET', '/confidentialite.html')['code']);
});

echo "Images\n";
$png = tempnam(sys_get_temp_dir(), 'img');
$im = imagecreatetruecolor(400, 300);
imagefill($im, 0, 0, imagecolorallocate($im, 200, 40, 90));
imagepng($im, $png);
$imageId = null;
t('envoi d\'une image PNG', function () use ($png, &$imageId) {
    $r = upload($png, 'logo-club.png');
    eq(201, $r['code'], json_encode($r['json']));
    ok(preg_match('/^[a-f0-9]{24}$/', $r['json']['id']) === 1);
    $imageId = $r['json']['id'];
    eq(200, http('GET', $r['json']['url'])['code']);
});
t('un fichier qui n\'est pas une image est refusé', function () {
    $f = tempnam(sys_get_temp_dir(), 'txt');
    file_put_contents($f, 'bonjour');
    eq(415, upload($f, 'x.png')['code']);
});

echo "Commande\n";
t('coordonnées manquantes : 422 avec les champs', function () use ($rentree) {
    $r = http('POST', '/api/orders', ['customer' => ['name' => 'J'], 'consent' => false, 'planches' => $rentree]);
    eq(422, $r['code']);
    eq(['name', 'email', 'phone', 'consent'], array_keys($r['json']['fields']));
});
t('carnet sans pack refusé', function () use ($customer, $design) {
    $r = http('POST', '/api/orders', ['customer' => $customer, 'consent' => true,
        'planches' => [['format' => 'stylo', 'qty' => 5, 'design' => $design]]]);
    eq(422, $r['code']); eq('no_pack', $r['json']['error']);
});
t('prix changé : 409', function () use ($customer, $rentree) {
    $r = http('POST', '/api/orders', ['customer' => $customer, 'consent' => true, 'planches' => $rentree, 'expected_ttc_cents' => 2200]);
    eq(409, $r['code']); eq(2500, $r['json']['ttc_cents']);
});
$ref = null;
$track = null;
t('commande pack rentrée + 1 thermo, avec image', function () use ($customer, $rentree, &$ref, &$track, &$imageId, $design) {
    $pl = $rentree;
    $pl[1]['design'] = ['imageId' => $imageId, 'picto' => 'i-licorne'] + $design;
    $r = http('POST', '/api/orders', ['customer' => $customer, 'consent' => true, 'planches' => $pl, 'expected_ttc_cents' => 2500]);
    eq(201, $r['code'], $r['text']);
    ok(preg_match('/^E\d{2}-0001$/', $r['json']['ref']) === 1, $r['json']['ref']);
    eq(6, $r['json']['planches']);
    $ref = $r['json']['ref'];
    $track = $r['json']['track_url'];
});
t('emails client et atelier écrits', function () use ($cfg) {
    eq(2, count(glob($cfg['data_dir'] . '/mail-outbox/*.eml')));
});
t('page de suivi', function () use ($track, $base) {
    $r = http('GET', substr($track, strlen($base)));
    eq(200, $r['code']);
    ok(strpos($r['text'], 'Pack rentrée') !== false);
    eq(404, http('GET', '/c/E26-0001/' . str_repeat('0', 48))['code']);
});

echo "NAS\n";
t('signature exigée', function () {
    eq(401, http('GET', '/api/nas/ping')['code']);
    eq(401, nas('GET', '/api/nas/ping', null, ['secret' => str_repeat('ee', 32)])['code']);
    eq(200, nas('GET', '/api/nas/ping')['code']);
});
$detail = null;
t('liste et détail au format attendu par printnest-web', function () use (&$ref, &$detail, &$imageId) {
    $l = nas('GET', '/api/nas/orders?status=envoyee');
    eq([$ref], array_column($l['json']['orders'], 'ref'));
    $d = nas('GET', '/api/nas/orders/' . $ref)['json'];
    eq('p1', $d['planches'][0]['id']); eq('thermo', $d['planches'][0]['format']); eq(2, $d['planches'][0]['qty']);
    eq($imageId, $d['planches'][1]['design']['image_file_id']);
    eq('pattern', $d['planches'][2]['design']['style']);
    eq('rentree', $d['pricing']['pack']['id']); eq(2500, $d['pricing']['ttc_cents']); eq(2083, $d['pricing']['ht_cents']);
    eq(1, count($d['images']));
    $detail = $d;
});
t('téléchargement de l\'image puis accusé de réception', function () use (&$ref, &$detail, $cfg) {
    $f = nas('GET', '/api/nas/orders/' . $ref . '/files/' . $detail['images'][0]['file_id']);
    eq(200, $f['code']);
    eq($detail['images'][0]['sha256'], hash('sha256', $f['text']));
    eq(422, nas('POST', '/api/nas/orders/' . $ref . '/ack', ['files' => []])['code']);
    $a = nas('POST', '/api/nas/orders/' . $ref . '/ack', ['files' => [['file_id' => $detail['images'][0]['file_id'], 'sha256' => $detail['images'][0]['sha256']]]]);
    eq(200, $a['code']); eq('recuperee', $a['json']['status']);
    eq([], glob($cfg['data_dir'] . '/images/*.bin'), 'image effacée du site');
    eq(410, nas('GET', '/api/nas/orders/' . $ref . '/files/' . $detail['images'][0]['file_id'])['code']);
});
t('statuts : prête (email) puis retirée', function () use (&$ref, $cfg) {
    eq(409, nas('POST', '/api/nas/orders/' . $ref . '/status', ['status' => 'retiree'])['code']);
    eq(200, nas('POST', '/api/nas/orders/' . $ref . '/status', ['status' => 'prete'])['code']);
    $again = nas('POST', '/api/nas/orders/' . $ref . '/status', ['status' => 'prete']);
    eq(409, $again['code']); eq('prete', $again['json']['status']);
    eq(3, count(glob($cfg['data_dir'] . '/mail-outbox/*.eml')));
    eq(200, nas('POST', '/api/nas/orders/' . $ref . '/status', ['status' => 'retiree'])['code']);
});

echo "Annulation par le client\n";
t('annulation tant que la fabrication n\'a pas commencé', function () use ($customer, $design, $base) {
    $r = http('POST', '/api/orders', ['customer' => $customer, 'consent' => true,
        'planches' => [['format' => 'thermo', 'qty' => 3, 'design' => $design], ['format' => 'mini', 'qty' => 2, 'design' => $design]]]);
    eq(201, $r['code']); eq(1900, $r['json']['ttc_cents']);
    $path = substr($r['json']['track_url'], strlen($base));
    eq(303, http('POST', $path . '/annuler', 'confirm=1', ['Content-Type: application/x-www-form-urlencoded'])['code']);
    $s = nas('GET', '/api/nas/orders/' . $r['json']['ref'])['json'];
    eq('annulee', $s['status']);
});

echo "Tarifs publiés par l'atelier\n";
t('tarifs : défaut, modification signée, prix appliqué, retour', function () use ($customer, $design) {
    $pub = http('GET', '/api/tarifs')['json'];
    eq(1900, $pub['from_cents']); eq(300, $pub['unit']['thermo']); eq('senior', $pub['packs'][0]['id']);
    eq(401, http('POST', '/api/nas/tarifs', ['packs' => ['senior' => 100]])['code']);
    eq(422, nas('POST', '/api/nas/tarifs', ['packs' => ['senior' => 5]])['code']);
    $r = nas('POST', '/api/nas/tarifs', ['packs' => ['senior' => 1950], 'unit' => ['thermo' => 350]]);
    eq(200, $r['code']); eq(1950, $r['json']['packs']['senior']); eq(2200, $r['json']['packs']['rentree']);
    eq(1950, http('GET', '/api/tarifs')['json']['from_cents']);
    eq(1950, nas('GET', '/api/nas/ping')['json']['tarifs']['packs']['senior']);
    $o = http('POST', '/api/orders', ['customer' => $customer, 'consent' => true, 'expected_ttc_cents' => 1900 + 300,
        'planches' => [['format' => 'thermo', 'qty' => 4, 'design' => $design], ['format' => 'mini', 'qty' => 2, 'design' => $design]]]);
    eq(409, $o['code']); eq(1950 + 350, $o['json']['ttc_cents']);
    eq(200, nas('POST', '/api/nas/tarifs', ['packs' => ['senior' => 1900], 'unit' => ['thermo' => 300]])['code']);
});

echo "Tâche planifiée\n";
t('purge sans erreur', function () use ($root) {
    exec('ETIQUETTES_CONFIG=' . escapeshellarg(__DIR__ . '/config.test.php') . ' php ' . escapeshellarg($root . '/cron/purge.php') . ' 2>&1', $out, $code);
    eq(0, $code, implode("\n", $out));
});
done();
