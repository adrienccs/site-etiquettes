<?php
declare(strict_types=1);

namespace CopyColor\Etiquettes;

/**
 * Contenu des emails. Chaque méthode renvoie [sujet, texte, html].
 * $order : ligne orders ; $planches : lignes order_planches (design décodé).
 */
final class Emails
{
    /** @var array */
    private $shop;
    /** @var string */
    private $baseUrl;

    public function __construct(array $shop, string $baseUrl)
    {
        $this->shop = $shop;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function confirmation(array $order, array $planches, string $trackUrl): array
    {
        $subject = "Commande {$order['ref']} bien reçue";
        $lines = $this->lines($planches);
        $total = Util::euros((int) $order['ttc_cents']);
        $text = "Bonjour {$order['customer_name']},\n\n"
            . "Nous avons bien reçu votre commande d'étiquettes {$order['ref']}.\n\n"
            . $this->linesText($lines)
            . $this->pricingText($order)
            . "Total : $total TTC, à régler au retrait\n\n"
            . "Retrait et paiement en magasin :\n{$this->shop['name']}, {$this->shop['address']}\n"
            . ($this->shop['hours'] ? "Horaires : {$this->shop['hours']}\n" : '')
            . "\nUn nouvel email vous préviendra dès que votre carnet sera prêt.\n\n"
            . "Suivre ou annuler votre commande : $trackUrl\n\n"
            . "À bientôt,\n{$this->shop['name']} — {$this->shop['phone']}\n";
        $html = $this->layout(
            'Commande bien reçue',
            '<p>Bonjour ' . Util::h($order['customer_name']) . ',</p>'
            . '<p>Nous avons bien reçu votre commande d\'étiquettes <strong>' . Util::h($order['ref']) . '</strong>.</p>'
            . $this->linesHtml($lines, $order)
            . $this->shopBlock('Retrait et paiement en magasin')
            . '<p>Un nouvel email vous préviendra dès que votre carnet sera prêt.</p>'
            . $this->button($trackUrl, 'Suivre ou annuler ma commande')
        );
        return [$subject, $text, $html];
    }

    public function atelierAlert(array $order, array $planches): array
    {
        $byFormat = [];
        foreach ($planches as $p) {
            $byFormat[$p['format']] = ($byFormat[$p['format']] ?? 0) + (int) $p['qty'];
        }
        $summary = [];
        foreach ($byFormat as $f => $n) {
            $summary[] = $n . ' × ' . Catalog::formatLabel($f);
        }
        $images = count(array_filter(array_column(array_column($planches, 'design'), 'image_file_id')));
        $total = Util::euros((int) $order['ttc_cents']);
        $n = (int) $order['planches'];
        $subject = "Nouvelle commande d'étiquettes {$order['ref']} — $n planche" . ($n > 1 ? 's' : '') . " — $total";
        $phone = Util::formatPhone($order['customer_phone']);
        $text = "Nouvelle commande d'étiquettes en ligne\n\n"
            . "Référence : {$order['ref']}\n"
            . "Client : {$order['customer_name']}\nTéléphone : $phone\nEmail : {$order['customer_email']}\n\n"
            . implode("\n", $summary) . "\n"
            . ($images ? "Avec image importée par le client : à vérifier avant de lancer.\n" : '')
            . "\nMontant : $total TTC, à régler au retrait\n\n"
            . "La commande apparaît dans l'onglet Étiquettes de printnest-web dès que le NAS l'a récupérée.\n";
        $html = $this->layout(
            'Nouvelle commande d\'étiquettes',
            '<p style="font-size:20px;font-weight:800;margin:0 0 12px">' . Util::h($order['ref']) . '</p>'
            . '<p><strong>' . Util::h($order['customer_name']) . '</strong><br>'
            . Util::h($phone) . '<br>' . Util::h($order['customer_email']) . '</p>'
            . '<ul>' . implode('', array_map(static function ($s) {
                return '<li>' . Util::h($s) . '</li>';
            }, $summary)) . '</ul>'
            . ($images ? '<p><strong>Avec image importée par le client</strong> : à vérifier avant de lancer.</p>' : '')
            . '<p>Montant : <strong>' . Util::h($total) . ' TTC</strong>, à régler au retrait.</p>'
            . '<p style="color:#66666B;font-size:13px">La commande apparaît dans l\'onglet Étiquettes de printnest-web dès que le NAS l\'a récupérée.</p>'
        );
        return [$subject, $text, $html];
    }

    public function ready(array $order, string $trackUrl): array
    {
        $subject = "Votre commande {$order['ref']} est prête";
        $total = Util::euros((int) $order['ttc_cents']);
        $text = "Bonjour {$order['customer_name']},\n\n"
            . "Votre carnet d'étiquettes (commande {$order['ref']}) est prêt.\n\n"
            . "Vous pouvez venir le chercher :\n{$this->shop['name']}, {$this->shop['address']}\n"
            . ($this->shop['hours'] ? "Horaires : {$this->shop['hours']}\n" : '')
            . "\nMontant à régler sur place : $total TTC\n\n"
            . "Détail de la commande : $trackUrl\n\n"
            . "À bientôt,\n{$this->shop['name']} — {$this->shop['phone']}\n";
        $html = $this->layout(
            'Votre carnet est prêt',
            '<p>Bonjour ' . Util::h($order['customer_name']) . ',</p>'
            . '<p>Votre carnet d\'étiquettes (commande <strong>' . Util::h($order['ref']) . '</strong>) est prêt.</p>'
            . $this->shopBlock('Venez le chercher')
            . '<p>Montant à régler sur place : <strong>' . Util::h($total) . ' TTC</strong></p>'
            . $this->button($trackUrl, 'Voir ma commande')
        );
        return [$subject, $text, $html];
    }

    public function cancelled(array $order, string $by): array
    {
        $subject = "Commande {$order['ref']} annulée";
        $why = $by === 'client'
            ? 'Votre commande a bien été annulée, comme vous l\'avez demandé.'
            : 'Votre commande a été annulée par notre atelier. N\'hésitez pas à nous appeler si vous avez une question.';
        $text = "Bonjour {$order['customer_name']},\n\n$why\n\n{$this->shop['name']} — {$this->shop['phone']}\n";
        $html = $this->layout('Commande annulée',
            '<p>Bonjour ' . Util::h($order['customer_name']) . ',</p><p>' . Util::h($why) . '</p>');
        return [$subject, $text, $html];
    }

    public function atelierCancelled(array $order): array
    {
        $subject = "Commande d'étiquettes {$order['ref']} annulée par le client";
        $text = "Le client {$order['customer_name']} a annulé la commande {$order['ref']}.\n"
            . "Si elle est déjà dans printnest-web, ne pas la lancer.\n";
        $html = $this->layout('Commande annulée par le client',
            '<p>' . Util::h($order['customer_name']) . ' a annulé la commande <strong>' . Util::h($order['ref']) . '</strong>.</p>'
            . '<p>Si elle est déjà dans printnest-web, ne pas la lancer.</p>');
        return [$subject, $text, $html];
    }

    // ─── Blocs communs ─────────────────────────────────────────────────────

    /** Une ligne par planche : « 2 × Thermocollantes — Léa Martin ». */
    private function lines(array $planches): array
    {
        $out = [];
        foreach ($planches as $p) {
            $d = $p['design'];
            $out[] = ['qty' => (int) $p['qty'], 'label' => Catalog::formatLabel($p['format']),
                      'text' => trim($d['name'] . ' ' . ($d['line2'] ?? ''))];
        }
        return $out;
    }

    private function linesText(array $lines): string
    {
        $s = '';
        foreach ($lines as $l) {
            $s .= sprintf("  %d × %s — %s\n", $l['qty'], $l['label'], $l['text']);
        }
        return $s . "\n";
    }

    private function pricingText(array $order): string
    {
        $p = json_decode($order['pricing'], true);
        $s = $p['pack']['label'] . ' : ' . Util::euros((int) $p['pack']['ttc_cents']) . "\n";
        foreach ($p['extras'] as $x) {
            $s .= sprintf("+ %d planche%s %s : %s\n", $x['qty'], $x['qty'] > 1 ? 's' : '', mb_strtolower(Catalog::formatLabel($x['format'])),
                Util::euros($x['qty'] * $x['unit_ttc_cents']));
        }
        return $s;
    }

    private function linesHtml(array $lines, array $order): string
    {
        $rows = '';
        foreach ($lines as $l) {
            $rows .= '<tr><td style="padding:6px 0">' . $l['qty'] . ' × ' . Util::h($l['label'])
                . ' <span style="color:#66666B">· ' . Util::h($l['text']) . '</span></td></tr>';
        }
        $p = json_decode($order['pricing'], true);
        $price = '<tr><td style="padding:6px 0;color:#66666B">' . Util::h($p['pack']['label']) . ' ' . Util::h(Util::euros((int) $p['pack']['ttc_cents']));
        foreach ($p['extras'] as $x) {
            $price .= ' + ' . $x['qty'] . ' planche' . ($x['qty'] > 1 ? 's' : '') . ' ' . Util::h(mb_strtolower(Catalog::formatLabel($x['format'])));
        }
        $price .= '</td></tr>';
        return '<table role="presentation" width="100%" style="border-collapse:collapse;margin:16px 0;border-top:1px solid #E2E2DD;border-bottom:1px solid #E2E2DD">'
            . $rows . $price
            . '<tr><td style="padding:10px 0;font-weight:700">Total, à régler au retrait <span style="float:right;font-size:20px;font-weight:800">'
            . Util::h(Util::euros((int) $order['ttc_cents'])) . ' TTC</span></td></tr></table>';
    }

    private function shopBlock(string $title): string
    {
        return '<div style="background:#F4F4F1;border-radius:12px;padding:14px 16px;margin:16px 0">'
            . '<strong>' . Util::h($title) . '</strong><br>'
            . Util::h($this->shop['name']) . '<br>' . Util::h($this->shop['address'])
            . ($this->shop['hours'] ? '<br>' . Util::h($this->shop['hours']) : '')
            . '<br>' . Util::h($this->shop['phone']) . '</div>';
    }

    private function button(string $url, string $label): string
    {
        return '<p style="margin:20px 0"><a href="' . Util::h($url) . '" style="display:inline-block;background:#B1D36B;color:#2D2D31;'
            . 'font-weight:800;text-decoration:none;padding:12px 20px;border-radius:10px">' . Util::h($label) . '</a></p>';
    }

    private function layout(string $title, string $content): string
    {
        return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>' . Util::h($title) . '</title>'
            . '<link href="https://fonts.googleapis.com/css2?family=Archivo:ital,wght@0,400;0,700;0,800;1,800&display=swap" rel="stylesheet"></head>'
            . '<body style="margin:0;background:#F4F4F1;font-family:\'Archivo\',Arial,Helvetica,sans-serif;color:#2D2D31">'
            . '<table role="presentation" width="100%" style="background:#F4F4F1"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="100%" style="max-width:560px;background:#FFFFFF;border-radius:14px;overflow:hidden">'
            . '<tr><td style="background:#2D2D31;color:#FFFFFF;padding:18px 24px;font-size:20px;font-weight:800;font-style:italic">'
            . 'COPY COLOR <span style="color:#B1D36B">/</span> <span style="font-weight:400;font-size:15px">étiquettes</span></td></tr>'
            . '<tr><td style="height:4px;background:linear-gradient(90deg,#EE5F90 25%,#F05A2E 25% 50%,#A8292C 50% 75%,#B03C92 75%)"></td></tr>'
            . '<tr><td style="padding:24px;font-size:15px;line-height:1.5">'
            . '<h1 style="font-size:22px;margin:0 0 12px">' . Util::h($title) . '</h1>' . $content
            . '</td></tr></table>'
            . '<p style="font-size:12px;color:#66666B;margin:16px 0 0">' . Util::h($this->shop['name'] . ' · ' . $this->shop['address'] . ' · ' . $this->shop['phone'])
            . '<br>Fabriqué dans notre atelier de Bressuire · <a href="' . Util::h($this->baseUrl) . '" style="color:#66666B">' . Util::h(preg_replace('#^https?://#', '', $this->baseUrl)) . '</a></p>'
            . '</td></tr></table></body></html>';
    }
}
