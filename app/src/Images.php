<?php
declare(strict_types=1);

namespace CopyColor\Etiquettes;

/**
 * Images importées par les clients (logo du club, dessin de l'enfant…), en un seul envoi.
 * Gardées hors de www ; servies pour l'aperçu tant qu'elles ne sont pas effacées
 * (identifiant aléatoire de 24 caractères, impossible à deviner).
 */
final class Images
{
    private const MIME = ['jpeg' => 'image/jpeg', 'png' => 'image/png'];

    /** @var Db */
    private $db;
    /** @var Storage */
    private $storage;
    /** @var array */
    private $cfg;

    public function __construct(Db $db, Storage $storage, array $cfg)
    {
        $this->db = $db;
        $this->storage = $storage;
        $this->cfg = $cfg;
    }

    /** Reçoit le champ « image » d'un envoi multipart. */
    public function upload(string $ip): array
    {
        $f = $_FILES['image'] ?? null;
        $max = (int) $this->cfg['limits']['max_image_bytes'];
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            $big = is_array($f) && in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            throw new HttpError($big ? 413 : 400, $big ? sprintf('Image trop lourde (%d Mo au maximum).', intdiv($max, 1048576))
                : 'Image mal reçue, réessayez.', 'bad_image');
        }
        $bytes = (int) filesize($f['tmp_name']);
        if ($bytes < 1 || $bytes > $max) {
            throw new HttpError(413, sprintf('Image trop lourde (%d Mo au maximum).', intdiv($max, 1048576)), 'too_large');
        }
        $kind = self::sniff($f['tmp_name']);
        $size = @getimagesize($f['tmp_name']);
        if ($kind === null || !is_array($size) || $size[0] < 1) {
            throw new HttpError(415, 'Choisissez une image JPEG ou PNG.', 'bad_mime');
        }
        if ($size[0] > 12000 || $size[1] > 12000) {
            throw new HttpError(422, 'Image trop grande (12 000 pixels de côté au maximum).', 'bad_px');
        }
        $id = Util::randomHex(12);
        $dest = $this->storage->imagePath($id);
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            throw new \RuntimeException('Enregistrement de l\'image impossible');
        }
        @chmod($dest, 0600);
        $this->db->run(
            'INSERT INTO images (id, ip, filename, mime, bytes, sha256, px_w, px_h) VALUES (:id, :ip, :fn, :m, :b, :sha, :w, :h)',
            [':id' => $id, ':ip' => $ip, ':fn' => Util::cleanFilename((string) ($f['name'] ?? 'image')), ':m' => self::MIME[$kind],
             ':b' => $bytes, ':sha' => hash_file('sha256', $dest), ':w' => (int) $size[0], ':h' => (int) $size[1]]
        );
        return ['id' => $id, 'url' => '/api/images/' . $id, 'px_w' => (int) $size[0], 'px_h' => (int) $size[1],
                'low' => min($size[0], $size[1]) < 300];
    }

    /** Image encore présente (aperçu dans le configurateur). */
    public function get(string $id): array
    {
        Storage::checkId($id);
        $im = $this->db->one("SELECT * FROM images WHERE id = :i AND status <> 'deleted'", [':i' => $id]);
        $path = $this->storage->imagePath($id);
        if ($im === null || !is_file($path)) {
            throw new HttpError(404, 'Image introuvable ou effacée.', 'image_not_found');
        }
        return $im + ['path' => $path];
    }

    public function delete(string $id): void
    {
        $this->storage->deleteImage($id);
        $this->db->run("UPDATE images SET status = 'deleted' WHERE id = :i", [':i' => $id]);
    }

    public static function sniff(string $path): ?string
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        $h = (string) fread($fh, 8);
        fclose($fh);
        if (strncmp($h, "\xFF\xD8\xFF", 3) === 0) {
            return 'jpeg';
        }
        if (strncmp($h, "\x89PNG\r\n\x1A\n", 8) === 0) {
            return 'png';
        }
        return null;
    }
}
