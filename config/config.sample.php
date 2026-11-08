<?php
/**
 * Configuration — copier en config.php (HORS du dossier www) et remplir.
 * Ce fichier ne doit jamais être versionné avec de vraies valeurs.
 */
return [
    // Base MySQL « SQL Perso » OVH dédiée aux commandes d'étiquettes.
    // Serveur, nom de base et utilisateur : affichés dans l'espace client OVH (Hébergement → Bases de données).
    'db' => [
        'dsn'  => 'mysql:host=CHANGER.mysql.db;port=3306;dbname=CHANGER;charset=utf8mb4',
        'user' => 'CHANGER',
        'pass' => 'CHANGER',
    ],

    // Dossier des images importées et des journaux, HORS de www (ex. /home/copycolo/etiquettes-data)
    'data_dir' => '/home/CHANGER/etiquettes-data',

    // Secret du site (liens de suivi des clients) : 64 caractères hex, différent du secret NAS.
    // Générer avec  php -r "echo bin2hex(random_bytes(32));"
    'app_secret' => 'CHANGER',

    // Adresse publique du site (sans / final)
    'base_url' => 'https://etiquettes.copycolor.fr',

    // Accès du NAS (printnest-web, onglet Étiquettes : ETQ_URL et ETQ_NAS_SECRET)
    'nas' => [
        // Secret partagé : 64 caractères hex, générer avec  php -r "echo bin2hex(random_bytes(32));"
        'secret'      => 'CHANGER',
        // IP publique de la boutique (Freebox Pro). Liste vide = pas de filtrage (déconseillé).
        'allowed_ips' => ['212.114.29.82'],
        'max_skew_s'  => 300,
    ],

    // Emails
    'mail' => [
        'from'        => 'etiquettes@copycolor.fr',
        'from_name'   => 'Copy Color Service',
        'reply_to'    => 'contact@copycolor.fr',
        'atelier_to'  => 'contact@copycolor.fr',   // alerte « nouvelle commande »
        'enabled'     => true,                      // false = emails écrits dans data_dir/mail-outbox (tests)
        // 'mail' = fonction mail() d'OVH ; 'gmail_api' = Google Workspace (API Gmail, repli sur mail())
        'transport'   => 'gmail_api',
        'google'      => [
            // chemin ABSOLU du JSON du compte de service (hors du web), le même pour tous les sites
            'service_account' => '/home/CHANGER/copycolor-fr/config/google-service-account.json',
            'sender'          => 'contact@copycolor.fr',   // boîte Workspace d'envoi (devient l'expéditeur)
        ],
    ],

    // Boutique (emails, page de suivi)
    'shop' => [
        'name'    => 'Copy Color Service',
        'address' => '12, rue du Four, 79300 Bressuire',
        'phone'   => '05 49 74 31 07',
        'hours'   => '',                            // ex. « du lundi au vendredi, 9h–12h30 / 14h–18h30 »
    ],

    // Limites
    'limits' => [
        'max_image_bytes'        => 15 * 1024 * 1024,  // 15 Mo par image importée
        'images_per_hour'        => 60,                // par IP
        'orders_per_hour'        => 10,                // par IP
        'image_ttl_hours'        => 72,                // images jamais commandées : effacées après
        'orders_retention_years' => 3,                 // commandes et coordonnées supprimées après
    ],
];
