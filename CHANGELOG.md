# Journal des modifications

Toutes les évolutions notables de WP Migration sont consignées ici.
Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet respecte le [versionnage sémantique](https://semver.org/lang/fr/).

## [1.13.0] - 2026-10-08

### Ajouté
- **Types de contenus personnalisés** dans la synchronisation : événements, portfolio, FAQ, modèles de blocs et autres types déclarés par des extensions ou le thème. La case « Autres contenus (types personnalisés) » propose, après collage du lien, les types du site d'origine avec leur nombre de contenus. Repris comme les articles et pages (métadonnées, taxonomies, traductions WPML, image à la une). Alerte si le type n'est pas enregistré sur la copie. `--custom-types=` en WP-CLI.
- **Contenus disparus de l'origine** : l'analyse signale (sans rien supprimer) les articles, pages, produits, codes promo et contenus personnalisés qui existent sur la copie, ont été créés avant la copie et n'existent plus sur le site d'origine. Nouvelle colonne « absents de l'origine » et remarques détaillées (50 par type).

### Modifié
- Le tableau de l'analyse n'affiche que les colonnes utiles.
- Côté site d'origine : nouvelles opérations de lecture seule (liste des types personnalisés, empreintes des contenus). Les types réservés (produits, commandes, codes promo, médias, menus, modèles techniques) sont refusés et ne peuvent pas être lus par ce biais.

## [1.12.0] - 2026-10-08

### Ajouté
- **Sauvegardes planifiées** (Réglages → Sauvegardes planifiées) : une sauvegarde automatique chaque jour, chaque semaine ou chaque mois, à l'heure du site choisie ; contenu complet, sans la médiathèque, ou base de données seule. Exécutée par WP-Cron en étapes courtes enchaînées, reprise après interruption, exécution bloquée abandonnée au bout de 6 heures. Bouton « Lancer une sauvegarde maintenant », prochain passage et dernier résultat affichés, échec signalé sur l'accueil.
- Le mot de passe de l'installeur des sauvegardes planifiées (8 caractères au moins, obligatoire) n'est conservé que sous forme d'empreinte salée.
- E-mail en cas d'échec, à chaque sauvegarde ou jamais, sans mot de passe ni lien d'accès dans le message.
- Les anciennes sauvegardes sont supprimées par le nettoyage automatique existant.
- `wp migration schedule` et `wp migration schedule-run`.

## [1.11.0] - 2026-10-08

### Ajouté
- **Restaurer une sauvegarde de ce site** depuis **Sauvegardes → Restaurer** : l'installeur de la sauvegarde et son archive sont placés à la racine du site (archive liée plutôt que dupliquée quand le serveur le permet, sinon copiée après contrôle de l'espace disque), puis l'installeur, qui reprend les accès à la base de données et l'adresse de ce site, remplace les fichiers, la base de données et les comptes. Rien n'est modifié tant que l'installeur n'est pas lancé ; « Annuler la préparation » retire les fichiers. La préparation reste visible dans la liste après rechargement de la page, et la sauvegarde n'est jamais supprimée par le nettoyage de l'installeur.
- `wp migration restore <id>` et `wp migration restore <id> --cancel`.

## [1.10.0] - 2026-10-08

### Ajouté
- **Comparer ce site avec un autre** (onglet Synchronisation), en lecture seule sur les deux sites, avec le lien de synchronisation de l'autre site : versions de WordPress, de PHP et de la base de données, thème, extensions (version, active ou non), réglages usuels, moyens de paiement activés, langues (WPML ou Polylang), menus et nombre de contenus. Chaque ligne est identique, différente, présente d'un seul côté ou indicative ; les lignes identiques sont repliées. Ni mot de passe ni clé ne sont transmis.
- Le bouton **Reprendre** d'un réglage différent ouvre « Reprendre des réglages d'un autre site » sur ce réglage.
- `wp migration compare` (`--all`, `--format=json`).
- Côté autre site : nouvelle opération de lecture `profile` du lien de synchronisation.

## [1.9.0] - 2026-10-08

### Ajouté
- **Reprendre des réglages d'un autre site** (onglet Synchronisation) : avec le lien de synchronisation du site d'origine, on cherche des réglages par leur nom (moyen de paiement, TVA, Polylang, WPML, widgets, thème…), on les **compare avec ceux du site** (nouveau, différent, identique, différences détaillées, valeurs secrètes masquées), puis on les copie. Les valeurs sont copiées telles que stockées (jamais désérialisées) et les adresses du site d'origine sont remplacées par celles du site. La copie est **annulable** (un réglage modifié depuis est conservé) et peut être précédée d'une sauvegarde de la base de données.
- Ne sont jamais copiés : adresse du site, thème, extensions actives, numéros de version, tâches planifiées, sessions et réglages de WP Migration. Les réglages qui désignent des contenus par leur numéro sont signalés ; au-delà de 1 Mo, un réglage n'est pas repris.
- `wp migration settings` (liste, `--names`, `--filter --all`, `--dry-run`, `--no-adapt`) et `wp migration settings-undo`.
- Côté site d'origine : nouvelle opération de lecture `options` du lien de synchronisation (lecture seule, sans les réglages protégés).

## [1.8.0] - 2026-09-30

### Ajouté
- **Contrôles de cohérence après l'installation** : à la fin de l'installation (navigateur et ligne de commande), puis dans **WP Migration → Rapport de migration → Cohérence du site**, avec un bouton « Relancer les contrôles » pour vérifier une correction. Ils ne modifient rien et ne font jamais échouer l'installation.
  - **Menus** : chaque emplacement du thème actif (et, avec Polylang, chaque menu par langue) doit pointer vers un menu existant.
  - **Permaliens** : rappel d'enregistrer les réglages si les règles de réécriture n'ont pas encore été régénérées (pages traduites en 404).
  - **WPML** : liens de traduction comparés à ceux du site d'origine (lignes sans contenu associé, lignes vers un contenu absent), langue par défaut, domaines de langue restés sur l'ancien site, clé de site liée au domaine.
  - **Polylang** : langue par défaut vide ou inconnue, domaines de langue restés sur l'ancien site, présence simultanée de données WPML.
- Le package embarque quelques chiffres du site d'origine (liens de traduction WPML) pour comparer avec la copie : une ligne `element_id` vide déjà présente sur l'origine n'est pas signalée comme une anomalie.
- `wp migration check` (`--format=json`, `--save`) ; code de sortie 1 en cas de point à vérifier. La section figure aussi dans l'export texte du rapport, et l'accueil signale les points à vérifier.
- Lecture des réglages sérialisés sans instancier d'objet (même principe que le remplacement des adresses).

## [1.7.0] - 2026-09-29

### Modifié
- **Nouvelle interface** plus simple pour l'utilisateur non technicien. La page unique de neuf blocs devient une **page d'accueil « Que voulez-vous faire ? »** avec quatre cartes (déménager ou sauvegarder, recevoir un site, récupérer les commandes et contenus, changer une adresse ou un texte) et une bande d'état, puis des **onglets** : Accueil, Sauvegardes, Recevoir un site, Synchronisation, Rechercher / Remplacer, Réglages, Aide. Chaque onglet a son adresse (`&tab=`).
- **« Package » devient « sauvegarde »** dans l'administration, l'installeur, les messages de WP-CLI et le rapport de migration. Les commandes (`wp migration build`, `list`, `delete`…) et le format des archives ne changent pas.
- **Sauvegarde en un clic** : « Sauvegarde complète » (fichiers et base de données) ou « Base de données seulement », avec mot de passe d'installeur généré, téléchargement et transfert direct dans la foulée ; l'assistant en trois étapes reste disponible sous « Personnaliser ». Les vérifications bloquantes du serveur sont signalées sans rien construire.
- **Rechercher / Remplacer en mode simple** : deux champs, le type de recherche est détecté automatiquement (adresse, domaine, adresse IP ou chemin en mots entiers ; sinon texte) et les options avancées sont repliées. Sur un site migré depuis une autre adresse, un lien propose de remplacer l'ancienne adresse (lue dans le rapport de migration) par l'adresse actuelle. `--mode=auto` en ligne de commande.
- **Aide** réécrite en trois scénarios pas à pas (changer d'hébergeur, travailler sur une copie, changer une adresse). Onglet Réglages : nettoyage automatique et version installée.

## [1.6.1] - 2026-09-29

### Ajouté
- Bouton **« Sauvegarder la base de données »** dans l'analyse de « Rechercher et remplacer » et dans celle de « Synchroniser le contenu », à côté du bouton d'application : un clic crée un package de la base de données seule (sans rien exclure : transitoires, indésirables et révisions compris), à télécharger avec son installeur et son mot de passe (affiché une seule fois). Il figure aussi dans la liste des packages et se restaure avec l'installeur.

## [1.6.0] - 2026-09-29

### Ajouté
- **Rechercher et remplacer dans la base de données** (administration et `wp migration replace`) : change une adresse, un domaine, un chemin ou n'importe quel texte dans toutes les colonnes texte du site (articles, réglages, métadonnées, commandes, tables des extensions…), y compris dans les données sérialisées (longueurs recalculées, jamais de `unserialize()`), les données doublement sérialisées et le JSON.
  - Trois types de recherche : **URL, domaine ou chemin** (mots entiers : `http://a.fr` ne touche pas `http://a.frite.com` ; variantes `https`, `//`, avec ou sans `www.`, JSON `\/` et URL encodée), **texte** (toutes les occurrences) et **expression régulière** (`/motif/i`, groupes `$1`…). Casse ignorable, accents compris.
  - Une **analyse** précède toute modification : occurrences, lignes et colonnes concernées par table, exemples avant / après. Un avertissement signale un remplacement qui changerait l'adresse du site (déconnexion). Option `--dry-run` en ligne de commande.
  - **Annulation** : la valeur d'origine de chaque colonne modifiée est journalisée avant le changement ; une valeur modifiée depuis est laissée telle quelle (`wp migration replace-undo`, historique des 10 derniers remplacements).
  - Garde-fous : jamais les noms de réglages ni de métadonnées, les mots de passe, les `guid` des articles (sauf option), ni les réglages de WP Migration ; les tables sans clé primaire sont ignorées et signalées. Traitement par lots reprenables (aucun souci de `max_execution_time`), sur une table choisie ou sur toutes.

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

[1.13.0]: https://github.com/fred-selest/wp-migration/releases/tag/v1.13.0
[1.12.0]: https://github.com/fred-selest/wp-migration/releases/tag/v1.12.0
[1.11.0]: https://github.com/fred-selest/wp-migration/releases/tag/v1.11.0
[1.10.0]: https://github.com/fred-selest/wp-migration/releases/tag/v1.10.0
[1.9.0]: https://github.com/fred-selest/wp-migration/releases/tag/v1.9.0
[1.8.0]: https://github.com/fred-selest/wp-migration/releases/tag/v1.8.0
[1.7.0]: https://github.com/fred-selest/wp-migration/releases/tag/v1.7.0
[1.6.1]: https://github.com/fred-selest/wp-migration/releases/tag/v1.6.1
[1.6.0]: https://github.com/fred-selest/wp-migration/releases/tag/v1.6.0
[1.5.1]: https://github.com/fred-selest/wp-migration/releases/tag/v1.5.1
[1.5.0]: https://github.com/fred-selest/wp-migration/releases/tag/v1.5.0
[1.4.0]: https://github.com/fred-selest/wp-migration/releases/tag/v1.4.0
[1.3.0]: https://github.com/fred-selest/wp-migration/releases/tag/v1.3.0
[1.2.0]: https://github.com/fred-selest/wp-migration/releases/tag/v1.2.0
[1.1.0]: https://github.com/fred-selest/wp-migration/releases/tag/v1.1.0
[1.0.0]: https://github.com/fred-selest/wp-migration/releases/tag/v1.0.0
