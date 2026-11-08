<?php
/** @var array $cfg @var array $order @var array $planches @var bool $cancellable @var string $action @var ?string $notice */
use CopyColor\Etiquettes\Catalog;
use CopyColor\Etiquettes\Util;

$status = $order['status'];
$steps = ['envoyee' => 'Reçue', 'en_production' => 'En fabrication', 'prete' => 'Prête', 'retiree' => 'Retirée'];
$rank = ['envoyee' => 0, 'recuperee' => 0, 'en_production' => 1, 'prete' => 2, 'retiree' => 3, 'annulee' => -1];
$current = $rank[$status];
$sentence = [
    'envoyee' => 'Votre commande est bien reçue. Nous vous écrivons dès qu\'elle est prête.',
    'recuperee' => 'Votre commande est arrivée à l\'atelier. Nous vous écrivons dès qu\'elle est prête.',
    'en_production' => 'Vos étiquettes sont en cours de fabrication.',
    'prete' => 'Votre carnet est prêt : vous pouvez venir le chercher.',
    'retiree' => 'Commande retirée. Merci et à bientôt !',
    'annulee' => 'Cette commande est annulée.',
][$status];
$pricing = json_decode($order['pricing'], true);

ob_start();
?>
<h1>Commande <span class="nw"><?= Util::h($order['ref']) ?></span></h1>
<p class="muted">Passée le <?= Util::h(date('d/m/Y à H\hi', (int) strtotime($order['created_at']))) ?></p>

<?php if ($notice === 'annulee'): ?>
  <div class="notice">Votre commande est annulée. Un email de confirmation vous a été envoyé.</div>
<?php endif; ?>

<?php if ($status !== 'annulee'): ?>
  <div class="steps" aria-label="Avancement">
    <?php $i = 0; foreach ($steps as $label): ?>
      <div class="<?= $i <= $current ? 'done' : '' ?>"><?= Util::h($label) ?></div>
    <?php $i++; endforeach; ?>
  </div>
<?php endif; ?>
<p><strong><?= Util::h($sentence) ?></strong></p>

<section class="card">
  <?php foreach ($planches as $p): $d = $p['design']; ?>
    <div class="row"><span><?= (int) $p['qty'] ?> × <?= Util::h(Catalog::formatLabel($p['format'])) ?></span><span class="muted"><?= Util::h(trim($d['name'] . ' ' . ($d['line2'] ?? ''))) ?></span></div>
  <?php endforeach; ?>
  <div class="row muted" style="border-top:1px solid var(--line);margin-top:6px;padding-top:12px">
    <span><?= Util::h($pricing['pack']['label']) ?> <?= Util::h(Util::euros((int) $pricing['pack']['ttc_cents'])) ?><?php foreach ($pricing['extras'] as $x): ?> + <?= (int) $x['qty'] ?> planche<?= $x['qty'] > 1 ? 's' : '' ?> <?= Util::h(mb_strtolower(Catalog::formatLabel($x['format']))) ?><?php endforeach; ?></span>
  </div>
  <div class="row">
    <span class="muted"><?= Util::h(Util::euros((int) $order['ht_cents'])) ?> HT</span>
    <span class="total"><?= Util::h(Util::euros((int) $order['ttc_cents'])) ?> TTC</span>
  </div>
</section>

<?php if ($status !== 'annulee' && $status !== 'retiree'): ?>
<section class="card">
  <strong>Retrait et paiement en magasin</strong><br>
  <?= Util::h($cfg['shop']['name']) ?><br><?= Util::h($cfg['shop']['address']) ?><br>
  <?php if ($cfg['shop']['hours']): ?><?= Util::h($cfg['shop']['hours']) ?><br><?php endif; ?>
  <?= Util::h($cfg['shop']['phone']) ?>
</section>
<?php endif; ?>

<?php if ($cancellable): ?>
<section class="card">
  <strong>Annuler la commande</strong>
  <p class="muted" style="margin:6px 0 0">Possible tant que la fabrication n'a pas commencé.</p>
  <form method="post" action="<?= Util::h($action) ?>">
    <label class="check"><input type="checkbox" name="confirm" value="1" required> Je confirme vouloir annuler cette commande</label>
    <button type="submit" class="danger">Annuler ma commande</button>
  </form>
</section>
<?php elseif (in_array($status, ['en_production', 'prete'], true)): ?>
<p class="muted">Pour modifier ou annuler, appelez-nous au <?= Util::h($cfg['shop']['phone']) ?>.</p>
<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Commande ' . $order['ref'];
require __DIR__ . '/_layout.php';
