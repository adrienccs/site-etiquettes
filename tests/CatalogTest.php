<?php
declare(strict_types=1);

// Prix des carnets (sans base) : php tests/CatalogTest.php
require __DIR__ . '/lib.php';
require __DIR__ . '/../app/bootstrap.php';

use CopyColor\Etiquettes\Catalog;

echo "Prix des carnets\n";
t('pack senior seul', function () {
    $q = Catalog::quote(['thermo' => 3, 'mini' => 2]);
    eq(true, $q['ok']); eq('senior', $q['pack']); eq(1900, $q['ttc_cents']); eq([], $q['extras']);
});
t('pack rentrée + 1 thermo = 25 €', function () {
    $q = Catalog::quote(['thermo' => 2, 'mini' => 1, 'chaussure' => 1, 'ronde' => 1, 'stylo' => 1]);
    eq('rentree', $q['pack']); eq(2500, $q['ttc_cents']);
    eq([['format' => 'thermo', 'qty' => 1, 'unit_ttc_cents' => 300]], $q['extras']);
});
t('le pack le plus avantageux est retenu', function () {
    // contient senior (19 + 1 rondes + 1 chaussures = 23) et crèche (29 + 2 mini = 33) → senior
    $q = Catalog::quote(['thermo' => 3, 'mini' => 2, 'ronde' => 1, 'chaussure' => 1]);
    eq('senior', $q['pack']); eq(2300, $q['ttc_cents']);
});
t('5 planches stylo : aucun pack', function () {
    $q = Catalog::quote(['stylo' => 5]);
    eq(false, $q['ok']); eq('rentree', $q['closest']);
    ok(strpos(Catalog::missingMessage($q), 'pack rentrée') !== false);
});
t('design : prénom obligatoire, champs inconnus remplacés', function () {
    throws(function () { Catalog::cleanDesign(['name' => '  '], 1); });
    $d = Catalog::cleanDesign(['name' => "Léa\u{0007}  Rose", 'font' => 'comic', 'color' => 'or', 'picto' => 'i-x', 'textColor' => 'red',
                               'pattern' => 'abeilles', 'imageId' => str_repeat('a', 24)], 1);
    eq('Léa Rose', $d['name']); eq('fredoka', $d['font']); eq('lime', $d['color']); eq('auto', $d['text_color']);
    eq('pattern', $d['style']); eq('none', $d['picto']); eq(null, $d['image_file_id']);
});
t('design : prénom tronqué à 14 caractères', function () {
    eq('Marie-Charlott', Catalog::cleanDesign(['name' => 'Marie-Charlotte'], 1)['name']);
});
done();
