<?php
/** @var array $cfg @var int $status @var string $message */
use CopyColor\Etiquettes\Util;

ob_start();
?>
<h1><?= $status === 404 ? 'Page introuvable' : 'Oups' ?></h1>
<p><?= Util::h($message) ?></p>
<p class="muted">Besoin d'aide ? Appelez-nous au <?= Util::h($cfg['shop']['phone']) ?>.</p>
<p><a href="/">Retour aux étiquettes</a></p>
<?php
$content = ob_get_clean();
$title = $status === 404 ? 'Introuvable' : 'Erreur';
require __DIR__ . '/_layout.php';
