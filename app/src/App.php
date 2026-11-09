<?php
declare(strict_types=1);

namespace CopyColor\Etiquettes;

/** Assemble les services et déclare les routes. */
final class App
{
    /** @var array */
    public $cfg;
    /** @var Db */
    public $db;
    /** @var Storage */
    public $storage;
    /** @var Images */
    public $images;
    /** @var Orders */
    public $orders;
    /** @var Mailer */
    public $mailer;
    /** @var NasAuth */
    public $nasAuth;
    /** @var RateLimiter */
    public $limiter;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
        $this->db = new Db($cfg['db']);
        $this->storage = new Storage($cfg['data_dir']);
        Catalog::setPricesFile(rtrim($cfg['data_dir'], '/') . '/tarifs.json');
        Catalog::setStaffFile(rtrim($cfg['data_dir'], '/') . '/atelier.json');
        $this->mailer = new Mailer($cfg['mail'], $this->db, $this->storage);
        $this->images = new Images($this->db, $this->storage, $cfg);
        $this->orders = new Orders($this->db, $this->storage, $this->images, $this->mailer,
            new Emails($cfg['shop'], $cfg['base_url']), $cfg);
        $this->nasAuth = new NasAuth($this->db, $cfg['nas']);
        $this->limiter = new RateLimiter($this->db);
    }

    public function handle(Request $req): void
    {
        $isApi = strpos($req->path, '/api/') === 0;
        try {
            $this->router()->dispatch($req);
        } catch (HttpError $e) {
            if ($isApi) {
                Response::json($e->status, ['error' => $e->errorCode, 'message' => $e->getMessage()] + $e->extra);
            } else {
                Response::html($e->status, View::render('error', ['cfg' => $this->cfg, 'status' => $e->status, 'message' => $e->getMessage()]));
            }
        } catch (\Throwable $e) {
            $this->storage->log('error', get_class($e) . ': ' . $e->getMessage(), ['at' => $e->getFile() . ':' . $e->getLine(), 'path' => $req->path]);
            $msg = 'Erreur interne, réessayez dans un instant.';
            if ($isApi) {
                Response::json(500, ['error' => 'internal', 'message' => $msg]);
            } else {
                Response::html(500, View::render('error', ['cfg' => $this->cfg, 'status' => 500, 'message' => $msg]));
            }
        }
    }

    private function router(): Router
    {
        $r = new Router();
        $lim = $this->cfg['limits'];

        // ─── Public ───────────────────────────────────────────────────────
        $r->add('POST', '/api/images', function (Request $q) use ($lim) {
            $this->limiter->hit($q->ip, 'image', (int) $lim['images_per_hour']);
            Response::json(201, $this->images->upload($q->ip));
        });
        $r->add('GET', '/api/images/{id}', function (Request $q) {
            $im = $this->images->get($q->param('id'));
            Response::image($im['path'], $im['mime']);
        });
        $r->add('POST', '/api/orders', function (Request $q) use ($lim) {
            if (strpos((string) $q->header('Content-Type'), 'application/json') !== 0) {
                throw new HttpError(415, 'Format de requête refusé.', 'bad_content_type');
            }
            $this->limiter->hit($q->ip, 'order', (int) $lim['orders_per_hour']);
            Response::json(201, $this->orders->create($q->json(), $q->ip));
        });

        // Mode atelier : vérification du code (essais limités), sans rien révéler d'autre
        $r->add('POST', '/api/atelier', function (Request $q) {
            $this->limiter->hit($q->ip, 'atelier', 8);
            $code = (string) ($q->json()['code'] ?? '');
            if (!Catalog::staffCheck($code)) {
                throw new HttpError(403, 'Code incorrect.', 'bad_staff_code');
            }
            Response::json(200, ['ok' => true]);
        });

        $r->add('GET', '/api/tarifs', function (Request $q) {
            header('Cache-Control: public, max-age=60');
            Response::json(200, Catalog::publicPrices());
        });

        // ─── Page de suivi du client ──────────────────────────────────────
        $r->add('GET', '/c/{ref}/{token}', function (Request $q) {
            $o = $this->orders->byToken($q->param('ref'), $q->param('token'));
            Response::html(200, View::render('track', [
                'cfg' => $this->cfg, 'order' => $o, 'planches' => $this->orders->planches((int) $o['id']),
                'cancellable' => $this->orders->isClientCancellable($o),
                'action' => $this->orders->trackUrl($o['ref']) . '/annuler',
                'notice' => $_GET['ok'] ?? null,
            ]));
        });
        $r->add('POST', '/c/{ref}/{token}/annuler', function (Request $q) {
            if (($_POST['confirm'] ?? '') !== '1') {
                throw new HttpError(400, 'Cochez la case pour confirmer l\'annulation.', 'confirm_required');
            }
            $o = $this->orders->cancelByClient($q->param('ref'), $q->param('token'));
            Response::redirect($this->orders->trackUrl($o['ref']) . '?ok=annulee');
        });

        // ─── NAS (signé HMAC + IP de la boutique) ─────────────────────────
        $nas = function (callable $h) {
            return function (Request $q) use ($h) {
                $this->nasAuth->check($q);
                $h($q);
            };
        };
        $r->add('GET', '/api/nas/ping', $nas(function (Request $q) {
            Response::json(200, [
                'ok' => true,
                'server_time' => date('c'),
                'pending' => (int) $this->db->value("SELECT count(*) FROM orders WHERE status = 'envoyee'"),
                'schema' => [],
                'tarifs' => Catalog::prices(),
                'atelier' => Catalog::staffEnabled(),
            ]);
        }));
        $r->add('GET', '/api/nas/tarifs', $nas(function (Request $q) {
            Response::json(200, Catalog::prices());
        }));
        $r->add('POST', '/api/nas/atelier', $nas(function (Request $q) {
            Response::json(200, ['atelier' => Catalog::saveStaff((string) ($q->json()['code'] ?? ''))]);
        }));
        $r->add('POST', '/api/nas/tarifs', $nas(function (Request $q) {
            Response::json(200, Catalog::savePrices($q->json()));
        }));
        $r->add('GET', '/api/nas/orders', $nas(function (Request $q) {
            Response::json(200, ['orders' => $this->orders->nasList(
                isset($q->query['status']) ? (string) $q->query['status'] : null,
                isset($q->query['since']) ? (string) $q->query['since'] : null
            )]);
        }));
        $r->add('GET', '/api/nas/orders/{ref}', $nas(function (Request $q) {
            Response::json(200, $this->orders->nasDetail($q->param('ref')));
        }));
        $r->add('GET', '/api/nas/orders/{ref}/files/{file_id}', $nas(function (Request $q) {
            $f = $this->orders->nasFile($q->param('ref'), $q->param('file_id'));
            Response::file($f['path'], $f['image']['mime'], $f['image']['filename'], ['X-CC-Sha256' => $f['image']['sha256']]);
        }));
        $r->add('POST', '/api/nas/orders/{ref}/ack', $nas(function (Request $q) {
            Response::json(200, $this->orders->ack($q->param('ref'), $q->json()));
        }));
        $r->add('POST', '/api/nas/orders/{ref}/status', $nas(function (Request $q) {
            Response::json(200, $this->orders->nasSetStatus($q->param('ref'), $q->json()));
        }));

        return $r;
    }
}

/** Gabarits PHP simples, dans app/views. */
final class View
{
    public static function render(string $name, array $vars): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        require __DIR__ . '/../views/' . $name . '.php';
        return (string) ob_get_clean();
    }
}
