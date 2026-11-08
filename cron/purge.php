<?php
declare(strict_types=1);

/**
 * Tâche planifiée OVH (toutes les heures) :
 *   - images importées mais jamais commandées (> 72 h) : effacées
 *   - commandes annulées dont les images traînent encore : effacées
 *   - nonces NAS et compteurs de limites expirés
 *   - emails en échec : nouvel essai
 *   - commandes de plus de 3 ans : supprimées (RGPD)
 */

require __DIR__ . '/../app/bootstrap.php';

use CopyColor\Etiquettes\App;

$app = new App(etiquettes_config());
$db = $app->db;
$lim = $app->cfg['limits'];
$report = [];

// 1. Images jamais rattachées à une commande
$stale = $db->all("SELECT id FROM images WHERE status = 'pending' AND created_at < NOW() - INTERVAL :h HOUR",
    [':h' => (int) $lim['image_ttl_hours']]);
foreach ($stale as $im) {
    $app->images->delete($im['id']);
}
$report['images_abandonnees'] = count($stale);

// 2. Commandes annulées avec images encore présentes
$cancelled = $db->all("SELECT * FROM orders WHERE status = 'annulee' AND files_purged_at IS NULL");
foreach ($cancelled as $o) {
    $app->orders->purgeFiles($o);
}
$report['annulees_nettoyees'] = count($cancelled);

// 3. Nettoyage technique
$report['nonces'] = $db->run("DELETE FROM nas_nonces WHERE seen_at < NOW() - INTERVAL 15 MINUTE")->rowCount();
$report['limites'] = $db->run("DELETE FROM rate_limits WHERE window_start < NOW() - INTERVAL 2 HOUR")->rowCount();
$db->run("DELETE FROM images WHERE status = 'deleted' AND created_at < NOW() - INTERVAL 7 DAY
          AND NOT EXISTS (SELECT 1 FROM order_planches p WHERE p.image_id = images.id)");

// 4. Emails en attente
$report['emails_renvoyes'] = $app->mailer->retryQueue();

// 5. Conservation : commandes et coordonnées supprimées après N ans
$old = $db->all('SELECT * FROM orders WHERE created_at < NOW() - INTERVAL :y YEAR', [':y' => (int) $lim['orders_retention_years']]);
foreach ($old as $o) {
    $app->orders->purgeFiles($o);
    $db->run('DELETE FROM orders WHERE id = :o', [':o' => $o['id']]);
}
$report['commandes_expirees'] = count($old);

$app->storage->log('info', 'purge', $report);
if (PHP_SAPI === 'cli') {
    echo json_encode($report, JSON_UNESCAPED_UNICODE) . "\n";
}
