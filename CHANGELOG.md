# Journal des modifications

Toutes les évolutions notables de WP Migration sont consignées ici.
Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet respecte le [versionnage sémantique](https://semver.org/lang/fr/).

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

[1.1.0]: https://github.com/fred-selest/wp-migration/releases/tag/v1.1.0
[1.0.0]: https://github.com/fred-selest/wp-migration/releases/tag/v1.0.0
