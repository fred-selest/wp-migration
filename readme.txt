=== WP Migration ===
Contributors: fred-selest
Tags: migration, duplicate, clone, backup, move
Requires at least: 4.9
Tested up to: 7.1
Requires PHP: 5.6
Stable tag: 1.6.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Copie un site WordPress complet (fichiers + base de données) vers un nouveau domaine et/ou un nouvel hébergement, avec un installeur autonome.

== Description ==

WP Migration crée un package (archive .wpmig + installer.php) à déposer sur le serveur de destination. L'installeur extrait les fichiers, importe la base de données, remplace les URL et chemins partout (y compris dans les données sérialisées et JSON), réécrit wp-config.php et .htaccess.

* Traitement découpé en requêtes courtes et reprenable (hébergements mutualisés).
* Remplacement des URL compatible sérialisation, JSON échappé et URL encodées.
* Changement de préfixe de tables, bascule atomique des tables.
* Compatibilité MySQL / MariaDB (collations, moteurs, max_allowed_packet).
* Exclusion des caches, sauvegardes et composants propres aux hébergeurs.
* Installeur protégé par mot de passe, suppression des fichiers d'installation.
* Transfert direct de serveur à serveur, sans FTP.
* Rapport de migration vérifié (fichiers, tables, nombre de lignes, erreurs SQL) conservé dans l'administration du nouveau site.
* Commandes WP-CLI (wp migration build) et installeur en ligne de commande.
* Mises à jour automatiques depuis les releases GitHub.
* Synchronisation du contenu (commandes, clients, produits, articles, pages, médias) depuis le site d'origine vers une copie de travail.

Multisite non pris en charge.

== Installation ==

1. Copier le dossier wp-migration dans wp-content/plugins/ puis activer l'extension.
2. Menu WP Migration > Créer un package.
3. Envoyer l'archive et installer.php sur le nouveau serveur puis ouvrir installer.php dans le navigateur.

== Changelog ==

= 1.6.1 =
* Bouton « Sauvegarder la base de données » avant un remplacement ou une synchronisation du contenu.

= 1.6.0 =
* Rechercher et remplacer dans la base de données (URL, texte ou expression régulière) : compatible données sérialisées et JSON, avec analyse préalable et annulation (`wp migration replace`).

= 1.5.1 =
* Nouveau logo : une bretzel alsacienne dans une flèche de sauvegarde (menu, page de l'extension, écran des mises à jour, installeur).

= 1.5.0 =
* Synchronisation du contenu : récupérer sur une copie de travail les commandes, clients, produits (et stock), codes promo, articles, pages, médias et avis créés ou modifiés sur le site d'origine depuis la copie, avec analyse préalable et annulation.

= 1.4.0 =
* Mises à jour depuis les releases GitHub : avis dans l'administration, journal des modifications, mise à jour en un clic, automatique ou avec WP-CLI.
* En-tête Update URI : l'extension n'est plus confondue avec une extension homonyme de wordpress.org.

= 1.3.0 =
* Rapport de migration conservé dans l'administration du nouveau site : contrôles, comparaison source / destination, lignes par table, journal complet, export texte et `wp migration report`.
* Contrôle du nombre de lignes de chaque table importée par rapport à l'export du site d'origine.
* Nettoyage automatique des anciens packages.
* Tables de journaux et de cache signalées ; exclure une table conserve sa structure.
* debug.log et error_log exclus de l'archive.

= 1.2.0 =
* Transfert direct de serveur à serveur (lien secret, temporaire et révocable).
* Import d'un site depuis un WordPress déjà installé sur la destination.
* Sécurité : la sauvegarde du wp-config.php remplacé n'est plus lisible depuis le web.

= 1.1.0 =
* Mot de passe de l'installeur généré automatiquement.
* Vérification complète de l'archive avant toute modification.
* Remplacement de l'ancienne adresse avec / sans « www. ».

= 1.0.0 =
* Première version.
