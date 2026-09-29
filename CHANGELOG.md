# Journal des modifications

Toutes les évolutions notables de WP Migration sont consignées ici.
Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet respecte le [versionnage sémantique](https://semver.org/lang/fr/).

## [1.5.1] - 2026-09-29

### Ajouté
- Logo de l'extension : une bretzel alsacienne dans une flèche circulaire de sauvegarde. Il apparaît dans le menu d'administration (version monochrome recolorée par WordPress), en tête des pages WP Migration et du rapport de migration, dans l'écran des mises à jour et la liste des extensions, et en tête de l'installeur. Fichiers SVG et PNG dans `assets/logo/`.

## [1.5.0] - 2026-09-29

### Ajouté
- **Synchronisation du contenu** : pendant qu'on travaille sur une copie du site (préproduction, développement), récupérer ce qui a été créé ou modifié sur le site d'origine depuis la copie — commandes (avec articles, notes, remboursements, droits de téléchargement), clients, produits et variations (avec leur stock), codes promo, articles et pages, médias (fichiers compris), commentaires et avis. Le site d'origine est seulement lu.
  - Sur le site d'origine : « Autoriser la synchronisation » crée un lien secret, temporaire et révocable (`wp migration sync-link`).
  - Sur la copie : « Synchroniser le contenu » analyse d'abord (ajouts, mises à jour, contenus conservés, points à connaître), puis importe après confirmation (`wp migration sync`, `--dry-run`).
  - Les numéros de commande sont conservés : une révision, un brouillon automatique ou une commande de test de la copie qui utilise le même identifiant est déplacé (ses références connues sont mises à jour).
  - Les produits, pages et médias modifiés sur la copie gardent leur version (les produits reçoivent le stock et les ventes du site d'origine), sauf option contraire ; commandes, clients, codes promo et avis viennent du site d'origine.
  - Synchronisations successives : seules les nouveautés sont reprises ; les correspondances d'identifiants sont conservées ; une réserve d'identifiants évite que le contenu créé ensuite sur la copie ne croise celui du site d'origine.
  - Annulation de la dernière synchronisation (`wp migration sync-undo`) : chaque modification est journalisée.
  - WPML : langue et liens entre traductions des contenus synchronisés. WooCommerce : stockage HPOS ou articles, statistiques recalculées.
  - Date de la copie trouvée automatiquement (rapport de migration, dernière synchronisation, ou package du site d'origine à choisir).
- Le package enregistre l'heure exacte du début de l'export de la base de données.

## [1.4.0] - 2026-09-29

### Ajouté
- **Mises à jour depuis GitHub** : WordPress détecte les nouvelles releases (avis dans Extensions et Tableau de bord → Mises à jour, journal des modifications dans « Afficher les détails »), mise à jour en un clic, avec WP-CLI ou automatique ; lien « Vérifier les mises à jour » sous l'extension. Les exigences de WordPress et de PHP de la nouvelle version sont respectées ; les pré-versions et brouillons ne sont jamais proposés.

### Sécurité
- En-tête `Update URI` : WordPress ne cherche plus l'extension sur wordpress.org, où une extension sans rapport (fermée) utilise le même identifiant `wp-migration`.

## [1.3.0] - 2026-09-28

### Ajouté
- **Rapport de migration** conservé dans l'administration du nouveau site (WP Migration → Rapport de migration), même après la suppression des fichiers d'installation : résultat global, contrôles (intégrité de l'archive, fichiers extraits, tables, lignes, requêtes SQL), comparaison source / destination (adresses, dossiers, versions de WordPress, PHP et MySQL, préfixe), détail par table, remplacements effectués, éléments exclus, avertissements et journal complet de l'installation. Téléchargeable en texte et disponible avec `wp migration report` (`--format=json`, code de sortie 1 en cas d'anomalie).
- Contrôle de la base après l'import : le nombre de lignes de chaque table est comparé à celui exporté par le site d'origine, et tout écart ou table manquante est signalé.
- Résumé des contrôles à la fin de l'installation, dans le navigateur comme en ligne de commande.
- Nettoyage automatique des anciens packages, après chaque construction et une fois par jour (WP-Cron) : conservation des 5 derniers et suppression au-delà de 30 jours (réglable), suppression des constructions abandonnées ou en échec après 24 h (leur export SQL compris) et des fichiers orphelins ; les packages ayant un lien de transfert actif ne sont jamais supprimés.
- Encart « Nettoyage automatique » : réglages, espace utilisé, nettoyage immédiat ; commande `wp migration cleanup` (`--dry-run`, `--keep=`, `--days=`).
- Les tables de journaux et de cache (Wordfence, WP Mail Logging, WP Umbrella, Redirection, WP Activity Log, sessions WooCommerce…) sont signalées dans l'analyse et dans la liste d'exclusion.

### Modifié
- Exclure une table n'en retire plus que les **données** : sa structure est conservée et elle est recréée vide sur la destination, pour que les extensions qui l'utilisent continuent de fonctionner.

### Corrigé
- L'analyse proposait d'exclure toute table de plus de 100 Mo, y compris `postmeta` (produits, commandes) : seules les tables de journaux ou de cache reconnues sont désormais signalées, jamais les registres RGPD.
- Le journal de débogage `debug.log` (souvent plusieurs centaines de Mo) était copié dans l'archive : il est désormais exclu, comme les fichiers `error_log`, et apparaît dans les éléments exclus du rapport.
- Sur un serveur très lent, une étape de l'installeur pouvait ne jamais avancer si le temps alloué était écoulé avant le premier élément traité : chaque requête traite désormais au moins un élément.

## [1.2.0] - 2026-09-28

### Ajouté
- Transfert direct de serveur à serveur : lien secret, temporaire (24 h) et révocable créé sur le site d'origine (bouton « Transfert direct », `wp migration transfer-link`) ; l'installeur y télécharge l'archive par morceaux reprenables (cURL ou flux PHP) et la contrôle avant toute modification (`--source-url` en ligne de commande).
- « Importer un site » : depuis un WordPress déjà installé sur la destination, coller le lien suffit ; l'installeur du package est placé sur le serveur et les accès à la base de données sont repris du `wp-config.php` existant.

### Corrigé
- Sécurité : la sauvegarde du `wp-config.php` remplacé (`wp-config.php.wpmig-backup-…`) pouvait être lue depuis le web avec les identifiants MySQL. Elle est désormais enregistrée sous forme de fichier `.php` inerte, et les anciennes sauvegardes sont signalées dans l'administration pour suppression.
- `wp migration list --format=ids` affichait « Array ».

## [1.1.0] - 2026-09-28

### Ajouté
- Vérification complète de l'archive (CRC32 de chaque bloc) avant toute modification sur le serveur de destination ; en cas de corruption, rien n'est touché.
- Remplacement automatique de l'ancienne adresse avec / sans `www.` (option désactivable, `--no-www-variant` en ligne de commande).
- Captures d'écran dans le README.

### Modifié
- Sécurité : le mot de passe de l'installeur est généré automatiquement (administration et WP-CLI) et affiché à la fin de la construction ; l'installeur signale lorsqu'il n'est pas protégé.
- Affichage des tailles et des nombres au format français dans l'installeur.

## [1.0.0] - 2026-09-28

### Ajouté
- Création de packages (archive `.wpmig` + `installer.php` autonome) depuis l'administration : assistant en trois étapes (configuration, analyse, construction).
- Commandes WP-CLI `wp migration build`, `wp migration list` et `wp migration delete`.
- Installeur autonome (navigateur et ligne de commande) : vérifications du serveur, extraction, import SQL, remplacement des URL et chemins, réécriture de `wp-config.php` et `.htaccess`, suppression des fichiers d'installation.
- Remplacement des URL compatible avec les données sérialisées (sans `unserialize()`), le JSON échappé et les URL encodées, en une seule passe et sur des limites de mots.
- Changement de préfixe des tables, import dans des tables temporaires et bascule atomique (`RENAME TABLE`).
- Compatibilité MySQL / MariaDB : collations, jeux de caractères, moteurs, `current_timestamp()`, découpage selon `max_allowed_packet`.
- Traitement découpé en requêtes courtes et reprenable, verrou contre les exécutions simultanées.
- Archive avec CRC32 par bloc et signature de fin (détection des transferts incomplets ou corrompus).
- Exclusion automatique des caches, journaux, sauvegardes d'autres extensions et composants propres aux hébergeurs.
- Protection de l'installeur par mot de passe.
- Compatibilité WordPress 4.9 à 7.1 et PHP 5.6 à 8.4.

[1.5.1]: https://github.com/fred-selest/wp-migration/releases/tag/v1.5.1
[1.5.0]: https://github.com/fred-selest/wp-migration/releases/tag/v1.5.0
[1.4.0]: https://github.com/fred-selest/wp-migration/releases/tag/v1.4.0
[1.3.0]: https://github.com/fred-selest/wp-migration/releases/tag/v1.3.0
[1.2.0]: https://github.com/fred-selest/wp-migration/releases/tag/v1.2.0
[1.1.0]: https://github.com/fred-selest/wp-migration/releases/tag/v1.1.0
[1.0.0]: https://github.com/fred-selest/wp-migration/releases/tag/v1.0.0
