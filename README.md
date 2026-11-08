# etiquettes.copycolor.fr — site de commande (OVH)

Commande d'étiquettes prénom personnalisées en ligne pour Copy Color Service :
- **écrans clients** : accueil (formats, packs, tarifs), configurateur du carnet (design commun ou planche par planche, couleur + illustration ou fond illustré, image importée, aperçu en direct), validation, confirmation ;
- **serveur** : base de données, API, calcul des prix, emails, page de suivi client et tâche de nettoyage ;
- **page de confidentialité**.

Côté boutique, les commandes arrivent dans l'onglet **Étiquettes** du conteneur `printnest-web` du NAS (port 8082), qui regroupe les planches en plans et lance la fabrication.

PHP 7.4 minimum (testé en 8.3), MySQL 8 (base « SQL Perso » OVH), aucune dépendance externe.

## Règles de l'offre

- Retrait et paiement au magasin, pas d'expédition, aucun délai annoncé : le client reçoit un email quand c'est prêt.
- Un carnet contient **au moins la composition d'un pack**, puis le client ajoute ce qu'il veut.
- Prix = pack contenu le plus avantageux + planches en plus (thermocollante 3 €, autocollante 2 €).
- Packs : senior 19 € (3 thermo + 2 mini), rentrée 22 € (1 de chaque), colo & sport 24 € (2 thermo + mini + rondes + chaussures), crèche 29 € (3 thermo + rondes + chaussures).

Les prix et les packs sont à deux endroits qui doivent rester identiques : `app/src/Catalog.php` (le serveur recalcule toujours) et `www/assets/composer.js` (affichage), plus les textes de `www/index.html`.

## Arborescence

```
etiquettes/
├── www/               ← racine web du sous-domaine etiquettes.copycolor.fr
│   ├── index.html     (accueil)
│   ├── composer.html  (configurateur, adresse /composer)
│   ├── confidentialite.html
│   ├── index.php      (API /api/* et page de suivi /c/*)
│   ├── .htaccess
│   └── assets/        (vue.js, composer.js, logo, fonds illustrés)
├── app/               ← code PHP (hors web)
├── config/            ← config.php à créer (hors web)
├── cron/purge.php     ← tâche planifiée horaire
├── sql/schema.sql
└── tests/
etiquettes-data/       ← images importées, journaux (hors web, créé automatiquement)
```

## Installation sur l'hébergement OVH

1. **Base de données.** Espace client OVH → Hébergement → Bases de données → Créer une base : SQL Perso, MySQL 8.4. Ouvrir phpMyAdmin, sélectionner la base, puis Importer → `sql/schema.sql`.
2. **Sous-domaine.** Hébergement → Multisite → `etiquettes.copycolor.fr`, dossier racine **`etiquettes/www`**, SSL activé.
3. **Fichiers.** Envoyés tout seuls par GitHub à chaque push sur `main` (Actions → « Deploy → OVH »), avec `OVH_CUSTOM_PATH` = `/home/copycolo/etiquettes`. `config/config.php` et `etiquettes-data/` ne sont jamais touchés.
4. **Configuration.** En SSH ou FTP : copier `config/config.sample.php` en `config/config.php` et remplir :
   - les accès à la base ;
   - `data_dir` = `/home/copycolo/etiquettes-data` ;
   - `app_secret` et `nas.secret` : deux valeurs **différentes**, générées avec `php -r "echo bin2hex(random_bytes(32));"` ;
   - `mail.google.service_account` : le même fichier de compte de service Google que les autres sites.
5. **Tâche planifiée.** Hébergement → Tâches planifiées → Cron : script `etiquettes/cron/purge.php`, PHP 8.3, **toutes les heures**.
6. **NAS.** Dans le `.env` de printnest-web : `ETQ_URL=https://etiquettes.copycolor.fr` et `ETQ_NAS_SECRET=` la valeur de `nas.secret`. L'onglet Étiquettes apparaît au redémarrage.
7. **Vérification.** Ouvrir le site sur un téléphone, passer une commande test, la voir arriver dans printnest-web, cliquer « Commande prête » puis « Retirée et payée ».

## API

### Site (public)

| Méthode | Route | Rôle |
|---|---|---|
| POST | `/api/images` | Image importée (champ `image`, JPEG ou PNG, 15 Mo) → `{id, url}` |
| GET | `/api/images/{id}` | Aperçu de l'image tant qu'elle n'est pas effacée |
| POST | `/api/orders` | Crée la commande. Prix **recalculé côté serveur** ; `expected_ttc_cents` → 409 si différent ; 422 `no_pack` si le carnet ne contient aucun pack |
| GET | `/c/{ref}/{jeton}` | Page de suivi du client (lien de l'email) |
| POST | `/c/{ref}/{jeton}/annuler` | Annulation, tant que la fabrication n'a pas commencé |

### NAS (signature HMAC + IP de la boutique)

Mêmes routes et même signature que photo.copycolor.fr. Format des commandes : `docs/ETIQUETTES.md` du dépôt printnest-web.

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/api/nas/ping` | Santé, heure du serveur, commandes en attente |
| GET | `/api/nas/orders?status=envoyee` | Commandes à récupérer (`since` = statuts changés depuis une date ISO) |
| GET | `/api/nas/orders/{ref}` | JSON complet : client, planches et designs, images, prix |
| GET | `/api/nas/orders/{ref}/files/{file_id}` | Une image importée |
| POST | `/api/nas/orders/{ref}/ack` | `{files:[{file_id, sha256}]}` : si tout correspond, les images sont effacées d'OVH, statut `recuperee` |
| POST | `/api/nas/orders/{ref}/status` | `{status, note?, notify?}`. `prete` envoie l'email au client, `annulee` aussi |

Statuts : `envoyee` → `recuperee` → `en_production` → `prete` → `retiree` ; `annulee` à tout moment côté atelier, côté client tant que `envoyee` ou `recuperee`.

## Tests

```
php tests/CatalogTest.php                                   # prix des carnets (sans base)
TEST_DSN='mysql:host=127.0.0.1;port=3306;dbname=etq_test;charset=utf8mb4' php tests/E2eTest.php   # bout en bout — VIDE la base indiquée
```

Les tests de bout en bout démarrent un vrai serveur (`php -S`), importent une image, passent commande, jouent le rôle du NAS (récupération, accusé, statuts), puis l'annulation client et la purge. Ne jamais les pointer vers la base de production.
