<?php
declare(strict_types=1);

namespace CopyColor\Etiquettes;

/**
 * Fichiers sur disque, hors de www :
 *   <data_dir>/images/<id>.bin      images importées par les clients (logo, dessin…)
 *   <data_dir>/logs/                journaux
 *   <data_dir>/mail-outbox/         emails (mode test)
 */
final class Storage
{
    /** @var string */
    private $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
        foreach (['images', 'logs', 'mail-outbox'] as $d) {
            $p = $this->root . '/' . $d;
            if (!is_dir($p) && !@mkdir($p, 0700, true) && !is_dir($p)) {
                throw new \RuntimeException("Impossible de créer $p");
            }
        }
    }

    public static function checkId(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $id)) {
            throw new HttpError(404, 'Image introuvable.', 'image_not_found');
        }
    }

    public function imagePath(string $id): string
    {
        self::checkId($id);
        return $this->root . '/images/' . $id . '.bin';
    }

    public function deleteImage(string $id): void
    {
        @unlink($this->imagePath($id));
    }

    public function log(string $level, string $msg, array $ctx = []): void
    {
        $line = sprintf("%s [%s] %s %s\n", date('c'), $level, $msg,
            $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '');
        @file_put_contents($this->root . '/logs/app-' . date('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    public function outboxDir(): string
    {
        return $this->root . '/mail-outbox';
    }
}
