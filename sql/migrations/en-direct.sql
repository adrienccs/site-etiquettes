-- Mise à jour : qui est sur le site en ce moment (bouton 📈 de PrintNest Web)
-- À importer une seule fois dans phpMyAdmin (base → Importer). Sans elle, le site marche normalement.
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
