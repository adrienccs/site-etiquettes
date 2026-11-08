<?php
declare(strict_types=1);

namespace CopyColor\Etiquettes;

/**
 * Envoi par la fonction mail() de l'hébergement OVH.
 * En cas d'échec, le message part dans mail_queue et la tâche planifiée le renvoie.
 * Avec mail.enabled = false, les messages sont écrits dans data_dir/mail-outbox (tests).
 */
final class Mailer
{
    /** @var array */
    private $cfg;
    /** @var Db */
    private $db;
    /** @var Storage */
    private $storage;

    public function __construct(array $mailCfg, Db $db, Storage $storage)
    {
        $this->cfg = $mailCfg;
        $this->db = $db;
        $this->storage = $storage;
    }

    /** @param array{0:string,1:string,2:string} $msg [sujet, texte, html] */
    public function send(string $to, array $msg): bool
    {
        [$subject, $text, $html] = $msg;
        try {
            $ok = $this->deliver($to, $subject, $text, $html);
        } catch (\Throwable $e) {
            $ok = false;
            $this->storage->log('error', 'mail: ' . $e->getMessage(), ['to' => $to]);
        }
        if (!$ok) {
            $this->db->run(
                'INSERT INTO mail_queue (to_addr, subject, body_text, body_html, attempts, last_error) VALUES (:t,:s,:x,:h,1,:e)',
                [':t' => $to, ':s' => $subject, ':x' => $text, ':h' => $html, ':e' => 'mail() a échoué']
            );
        }
        return $ok;
    }

    /** Renvoie les messages en attente (tâche planifiée). */
    public function retryQueue(int $maxAttempts = 5): int
    {
        $sent = 0;
        foreach ($this->db->all('SELECT * FROM mail_queue WHERE sent_at IS NULL AND attempts < :m ORDER BY id LIMIT 50', [':m' => $maxAttempts]) as $m) {
            $ok = false;
            try {
                $ok = $this->deliver($m['to_addr'], $m['subject'], $m['body_text'], $m['body_html']);
            } catch (\Throwable $e) {
                $ok = false;
            }
            if ($ok) {
                $this->db->run('UPDATE mail_queue SET sent_at = NOW(), attempts = attempts + 1 WHERE id = :i', [':i' => $m['id']]);
                $sent++;
            } else {
                $this->db->run('UPDATE mail_queue SET attempts = attempts + 1 WHERE id = :i', [':i' => $m['id']]);
            }
        }
        return $sent;
    }

    private function deliver(string $to, string $subject, string $text, string $html): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $boundary = 'cc_' . Util::randomHex(12);
        $gmail = $this->gmail();
        // Avec l'API Gmail, l'expéditeur doit être la boîte Google Workspace utilisée
        $from = $gmail ? $gmail->sender() : $this->cfg['from'];
        $fromName = mb_encode_mimeheader($this->cfg['from_name'], 'UTF-8', 'Q');
        $headers = [
            'From: ' . $fromName . ' <' . $from . '>',
            'Reply-To: ' . $this->cfg['reply_to'],
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: CopyColor-Etiquettes',
        ];
        $body = "--$boundary\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text))
            . "--$boundary\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html))
            . "--$boundary--\r\n";
        $encSubject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");

        if (empty($this->cfg['enabled'])) {
            $file = $this->storage->outboxDir() . '/' . (new \DateTime())->format('Ymd-His-u') . '-' . Util::randomHex(3) . '.eml';
            file_put_contents($file, "To: $to\r\nSubject: $encSubject\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $body);
            return true;
        }
        if ($gmail) {
            if ($gmail->send("To: $to\r\nSubject: $encSubject\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $body)) {
                return true;
            }
            $this->storage->log('error', 'gmail_api: ' . $gmail->lastError, ['to' => $to]);
            // repli : envoi par l'hébergement
        }
        return mail($to, $encSubject, $body, implode("\r\n", $headers), '-f' . $from);
    }

    /** Transport Google Workspace si configuré (mail.transport = 'gmail_api'). */
    private function gmail(): ?GmailApi
    {
        if (($this->cfg['transport'] ?? 'mail') !== 'gmail_api') {
            return null;
        }
        $g = $this->cfg['google'] ?? [];
        return new GmailApi((string) ($g['service_account'] ?? ''), (string) ($g['sender'] ?? 'contact@copycolor.fr'));
    }
}
