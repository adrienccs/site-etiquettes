-- Copy Color Service — commandes d'étiquettes personnalisées en ligne
-- MySQL 8 (base SQL Perso OVH). À importer dans la base créée par OVH (phpMyAdmin → Importer).
-- Montants en centimes entiers. Dates en heure de Paris (fuseau fixé par l'application à chaque connexion).

SET NAMES utf8mb4;

-- Référence de commande : E26-0142 (E = étiquettes, année sur 2 chiffres, compteur annuel)
CREATE TABLE ref_counters (
    year  SMALLINT NOT NULL PRIMARY KEY,
    seq   INT      NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Images importées par les clients (logo, dessin), avant et après rattachement à une commande
CREATE TABLE images (
    id          CHAR(24)     NOT NULL PRIMARY KEY,             -- 24 hex aléatoires
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip          VARCHAR(45),
    filename    VARCHAR(160) NOT NULL,
    mime        VARCHAR(20)  NOT NULL,
    bytes       INT          NOT NULL,
    sha256      CHAR(64)     NOT NULL,
    px_w        INT          NOT NULL,
    px_h        INT          NOT NULL,
    status      VARCHAR(10)  NOT NULL DEFAULT 'pending',
    CONSTRAINT images_status_chk CHECK (status IN ('pending','attached','deleted')),
    KEY images_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
    id              INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ref             VARCHAR(16)  NOT NULL,
    status          VARCHAR(16)  NOT NULL DEFAULT 'envoyee',
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    customer_name   VARCHAR(100) NOT NULL,
    customer_email  VARCHAR(200) NOT NULL,
    customer_phone  VARCHAR(20)  NOT NULL,
    consent_at      DATETIME     NOT NULL,
    consent_ip      VARCHAR(45),
    note            VARCHAR(500),
    planches        INT          NOT NULL,                       -- nombre de planches
    pack            VARCHAR(16)  NOT NULL,                       -- pack retenu pour le prix (senior, rentree…)
    pricing         JSON         NOT NULL,                       -- détail envoyé au NAS : pack, planches en plus
    vat_rate        DECIMAL(5,4) NOT NULL,
    ttc_cents       INT          NOT NULL,
    ht_cents        INT          NOT NULL,
    files_purged_at DATETIME,                                     -- images effacées d'OVH
    fetched_at      DATETIME,
    ready_at        DATETIME,
    collected_at    DATETIME,
    cancelled_at    DATETIME,
    cancelled_by    VARCHAR(10),
    UNIQUE KEY orders_ref (ref),
    KEY orders_status (status),
    KEY orders_updated (updated_at),
    CONSTRAINT orders_status_chk CHECK (status IN ('envoyee','recuperee','en_production','prete','retiree','annulee')),
    CONSTRAINT orders_cancelled_by_chk CHECK (cancelled_by IS NULL OR cancelled_by IN ('client','atelier','systeme'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Une ligne par sorte de planche (format + design), avec sa quantité
CREATE TABLE order_planches (
    id          INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id    INT          NOT NULL,
    position    INT          NOT NULL,                           -- p1, p2… pour le NAS
    format      VARCHAR(12)  NOT NULL,
    qty         INT          NOT NULL,
    design      JSON         NOT NULL,
    image_id    CHAR(24),
    UNIQUE KEY order_planches_pos (order_id, position),
    KEY order_planches_image (image_id),
    CONSTRAINT order_planches_order_fk FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT order_planches_format_chk CHECK (format IN ('thermo','mini','chaussure','ronde','stylo')),
    CONSTRAINT order_planches_qty_chk CHECK (qty BETWEEN 1 AND 60)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_events (
    id          INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id    INT          NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status      VARCHAR(16)  NOT NULL,
    actor       VARCHAR(10)  NOT NULL,                           -- client | nas | systeme
    note        VARCHAR(500),
    KEY order_events_order (order_id),
    CONSTRAINT order_events_order_fk FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Anti-rejeu des appels signés du NAS
CREATE TABLE nas_nonces (
    nonce    VARCHAR(64)  NOT NULL PRIMARY KEY,
    seen_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY nas_nonces_seen (seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin;

-- Limites d'usage par adresse IP (routes publiques)
CREATE TABLE rate_limits (
    ip            VARCHAR(45)  NOT NULL,
    bucket        VARCHAR(16)  NOT NULL,
    window_start  DATETIME     NOT NULL,
    hits          INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (ip, bucket)
) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin;

-- Emails en échec, renvoyés par la tâche planifiée
CREATE TABLE mail_queue (
    id          INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    to_addr     VARCHAR(200)  NOT NULL,
    subject     VARCHAR(255)  NOT NULL,
    body_text   MEDIUMTEXT    NOT NULL,
    body_html   MEDIUMTEXT    NOT NULL,
    attempts    INT           NOT NULL DEFAULT 0,
    sent_at     DATETIME,
    last_error  VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Présence en direct
CREATE TABLE IF NOT EXISTS stats_salt (
    day   DATE     NOT NULL PRIMARY KEY,
    salt  CHAR(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin;

CREATE TABLE IF NOT EXISTS stats_live (
    vid      CHAR(16)     NOT NULL PRIMARY KEY,   -- empreinte du jour (IP + navigateur + sel effacé le lendemain), jamais l'IP
    step     VARCHAR(12)  NOT NULL,               -- accueil, composer, recap, commande
    items    SMALLINT     NOT NULL DEFAULT 0,     -- planches dans le carnet
    cents    INT          NOT NULL DEFAULT 0,     -- montant TTC
    device   VARCHAR(8)   NOT NULL DEFAULT '',
    started  DATETIME     NOT NULL,
    updated  DATETIME     NOT NULL,
    KEY live_updated (updated)
) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin;
