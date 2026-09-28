# Journal des modifications

Toutes les évolutions notables de WP Migration sont consignées ici.
Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet respecte le [versionnage sémantique](https://semver.org/lang/fr/).

## [Non publié]

### Ajouté
- Nettoyage automatique des anciens packages, après chaque construction et une fois par jour (WP-Cron) : conservation des 5 derniers et suppression au-delà de 30 jours (réglable), suppression des constructions abandonnées ou en échec après 24 h (leur export SQL compris) et des fichiers orphelins ; les packages ayant un lien de transfert actif ne sont jamais supprimés.
- Encart « Nettoyage automatique » : réglages, espace utilisé, nettoyage immédiat ; commande `wp migration cleanup` (`--dry-run`, `--keep=`, `--days=`).

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

[1.2.0]: https://github.com/fred-selest/wp-migration/releases/tag/v1.2.0
[1.1.0]: https://github.com/fred-selest/wp-migration/releases/tag/v1.1.0
[1.0.0]: https://github.com/fred-selest/wp-migration/releases/tag/v1.0.0
