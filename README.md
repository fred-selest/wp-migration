<p align="center"><img src="assets/logo/logo.svg" width="128" height="128" alt="Logo WP Migration : une bretzel alsacienne dans une flèche de sauvegarde"></p>

# WP Migration

Extension WordPress pour **copier un site complet (fichiers + base de données) vers un nouveau domaine et/ou un nouvel hébergement** :

1. sur le site d'origine, l'extension crée une **sauvegarde** : une archive `.wpmig` + un fichier `installer.php` autonome ;
2. on dépose ces deux fichiers sur le nouveau serveur (dossier vide ou WordPress existant) ;
3. on ouvre `https://nouveau-domaine.fr/installer.php` : l'installeur extrait les fichiers, importe la base, remplace les URL et les chemins partout (y compris dans les données sérialisées), réécrit `wp-config.php` et `.htaccess`.

Et si le site d'origine reste en ligne pendant que vous travaillez sur la copie, la [synchronisation du contenu](#synchroniser-le-contenu-travail-sur-une-copie) y rapatrie ensuite les commandes, clients, produits, articles et pages créés entre-temps.

WordPress n'a **pas** besoin d'être installé sur la destination. S'il l'est déjà (installation « en un clic » de l'hébergeur), l'extension peut aussi **importer le site d'origine directement, sans FTP** : voir [Transfert direct](#transfert-direct-sans-ftp).

![Installeur : base de données et nouvelle adresse](docs/screenshots/7-installeur-base-de-donnees.png)

## Captures d'écran

### Page d'accueil : que voulez-vous faire ?

![Page d'accueil](docs/screenshots/23-accueil.png)

### Sur le site d'origine : créer une sauvegarde

En un clic (sauvegarde complète ou base de données seule), ou avec l'assistant en 3 étapes (« Personnaliser ») :

![Sauvegarde en un clic](docs/screenshots/24-sauvegarde-en-un-clic.png)

| 1. Configuration | 2. Analyse |
|---|---|
| ![Configuration de la sauvegarde](docs/screenshots/1-creation-configuration.png) | ![Analyse du site](docs/screenshots/2-creation-analyse.png) |
| **3. Sauvegarde prête** (archive, installeur et mot de passe) | **Liste des sauvegardes** |
| ![Sauvegarde prête](docs/screenshots/3-creation-sauvegarde-prete.png) | ![Liste des sauvegardes](docs/screenshots/4-liste-des-sauvegardes.png) |

### Restaurer une sauvegarde de ce site

![Restauration préparée](docs/screenshots/30-restauration-preparee.png)

### Transfert direct de serveur à serveur

| Lien secret sur le site d'origine | Import depuis un WordPress déjà installé |
|---|---|
| ![Lien de transfert direct](docs/screenshots/11-transfert-direct-lien.png) | ![Importer un site](docs/screenshots/12-importer-un-site.png) ![Installeur : récupération de l'archive](docs/screenshots/13-installeur-transfert-direct.png) |

### Sur le nouveau serveur : installeur

| Mot de passe | Vérifications du serveur |
|---|---|
| ![Mot de passe de l'installeur](docs/screenshots/5-installeur-mot-de-passe.png) | ![Vérifications](docs/screenshots/6-installeur-verifications.png) |
| **Installation en cours** | **Installation terminée** |
| ![Progression](docs/screenshots/8-installeur-progression.png) | ![Terminé](docs/screenshots/9-installeur-termine.png) |

### Synchronisation du contenu (copie de travail)

| Site d'origine : autoriser | Copie de travail : analyse |
|---|---|
| ![Lien de synchronisation](docs/screenshots/17-synchronisation-lien.png) | ![Analyse de la synchronisation](docs/screenshots/18-synchronisation-analyse.png) |

![Synchronisation terminée](docs/screenshots/19-synchronisation-terminee.png)

### Reprendre des réglages d'un autre site (onglet Synchronisation)

| Choisir les réglages | Comparaison avant la copie |
|---|---|
| ![Choisir les réglages](docs/screenshots/27-reglages-selection.png) | ![Comparaison des réglages](docs/screenshots/28-reglages-comparaison.png) |

### Comparer deux sites (onglet Synchronisation)

![Comparaison de deux sites](docs/screenshots/29-comparaison-sites.png)

### Rechercher et remplacer dans la base de données

| Formulaire (deux champs, options avancées repliées) | Analyse avant remplacement |
|---|---|
| ![Rechercher et remplacer](docs/screenshots/20-rechercher-remplacer-formulaire.png) | ![Analyse du remplacement](docs/screenshots/21-rechercher-remplacer-analyse.png) |

![Sauvegarde de la base de données avant le remplacement](docs/screenshots/22-rechercher-remplacer-sauvegarde.png)

### Sauvegardes planifiées (onglet Réglages)

![Sauvegardes planifiées](docs/screenshots/31-sauvegardes-planifiees.png)

### Nettoyage automatique des anciennes sauvegardes (onglet Réglages)

![Nettoyage automatique](docs/screenshots/14-nettoyage-automatique.png)

Après la migration, l'administration du nouveau site confirme l'opération avec le résultat des contrôles et rappelle, le cas échéant, de supprimer les fichiers d'installation restants :

![Notification après migration](docs/screenshots/10-notification-apres-migration.png)

### Rapport de migration

Conservé dans l'administration du nouveau site, même après la suppression des fichiers d'installation :

![Rapport de migration](docs/screenshots/15-rapport-de-migration.png)

### Cohérence du site (contrôles après l'installation)

| Fin de l'installation | Rapport de migration |
|---|---|
| ![Installeur : cohérence du site](docs/screenshots/26-installeur-coherence.png) | ![Cohérence du site](docs/screenshots/25-coherence-du-site.png) |

## Compatibilité

| | Pris en charge | Réellement testé |
|---|---|---|
| WordPress | 4.9 → 7.1 (dernière version) | 4.9.26 sous PHP 5.6, 7.1.2 sous PHP 8.4 |
| PHP | 5.6 → 8.4 | 5.6, 7.4 et 8.4 (+ analyse PHPCompatibility 5.6+) |
| Base de données | MySQL 5.5+ / 8.x, MariaDB 10.x / 11.x (collations et moteurs adaptés automatiquement) | MariaDB 10.11 |
| Serveurs web | Apache, LiteSpeed, nginx, IIS | serveur intégré de PHP |

Non pris en charge : WordPress **multisite** (refusé explicitement plutôt que migré à moitié).

## Installation

Télécharger `wp-migration-x.y.z.zip` depuis la [dernière release](https://github.com/fred-selest/wp-migration/releases/latest), puis **Extensions → Ajouter → Téléverser une extension** et activer. Le menu **WP Migration** apparaît dans l'administration.

### Mises à jour

Depuis la version 1.4.0, WordPress détecte les nouvelles versions publiées sur GitHub comme pour une extension de wordpress.org :

- avis « Une nouvelle version est disponible » dans **Extensions** et **Tableau de bord → Mises à jour**, avec le journal des modifications dans « Afficher les détails » ;
- mise à jour en un clic, avec WP-CLI (`wp plugin update wp-migration`) ou **automatique** (lien « Activer les mises à jour auto ») ;
- lien **Vérifier les mises à jour** sous l'extension pour interroger GitHub immédiatement (sinon, toutes les 12 heures).

![Mise à jour disponible](docs/screenshots/16-mise-a-jour.png)

L'en-tête `Update URI` empêche WordPress de chercher l'extension sur wordpress.org, où une autre extension (fermée) utilise le même identifiant `wp-migration`. Les exigences de la nouvelle version (WordPress, PHP) sont lues dans son `readme.txt` : une mise à jour incompatible avec le serveur est signalée comme telle. Si l'extension a été installée dans un dossier au nom différent (par exemple `wp-migration-main`), il est conservé lors de la mise à jour.

Pour une installation en 1.3.0 ou antérieure, la mise à jour vers la 1.4.0 se fait une dernière fois manuellement : téléverser le zip et choisir « Remplacer la version installée ».

## Prise en main

Le menu **WP Migration** s'ouvre sur une **page d'accueil** qui pose la question « Que voulez-vous faire ? » avec quatre cartes, et une bande d'état (dernière sauvegarde, lien de synchronisation actif, opération à reprendre). Chaque carte mène à un onglet :

| Onglet | Pour… |
|---|---|
| **Accueil** | choisir ce que l'on veut faire, voir l'état et le rapport de la dernière migration |
| **Sauvegardes** | créer une sauvegarde en un clic, la personnaliser, télécharger, **restaurer**, lancer un transfert direct, supprimer |
| **Recevoir un site** | remplacer ce site par une sauvegarde (FTP ou installeur) ou par un autre site, sans FTP, avec un lien de transfert |
| **Synchronisation** | 1. autoriser la synchronisation (site en ligne), 2. récupérer les commandes et contenus (copie de travail), 3. reprendre des réglages précis (moyen de paiement, langues, widgets…), 4. comparer deux sites |
| **Rechercher / Remplacer** | changer une adresse ou un texte dans toute la base de données |
| **Réglages** | sauvegardes planifiées, nettoyage automatique des anciennes sauvegardes, version installée |
| **Aide** | les trois scénarios pas à pas |

> Une **sauvegarde** est le couple archive `.wpmig` + `installer.php` : elle sert aussi bien à déménager le site qu'à en garder une copie pour revenir en arrière. (Dans les versions antérieures à 1.7.0, on parlait de « package » ; les commandes WP-CLI `wp migration build`, `list` et `delete` restent inchangées.)

## Migrer un site

### 1. Créer la sauvegarde (site d'origine)

**WP Migration → Sauvegardes** : deux boutons créent une sauvegarde en un clic, avec les réglages habituels et un mot de passe d'installeur généré (**Sauvegarde complète** : fichiers et base de données ; **Base de données seulement**). **Personnaliser** ouvre un assistant en 3 étapes :

1. **Configuration** : nom, site complet ou base de données seule, exclusions (dossiers ou fichiers, extensions, médias), données des tables de journaux et de cache à laisser de côté (la table est recréée **vide**, jamais supprimée), filtres (transients, spam, révisions), mot de passe de l'installeur (**généré automatiquement**, à noter).
2. **Analyse** : vérifications du serveur, nombre et taille des fichiers, fichiers volumineux, fichiers illisibles, tables et tailles ; les tables de journaux ou de cache volumineuses sont signalées (jamais `postmeta`, les commandes ou les registres RGPD).
3. **Création** : export SQL puis archive, avec barre de progression. Téléchargez ensuite l'**archive** et **installer.php**.

En SSH : `wp migration build --dir=/chemin/export` (un mot de passe est généré et affiché ; `--password=…` pour le choisir, voir `wp help migration build`).

### 2. Installer (serveur de destination)

1. Créer une base de données MySQL vide depuis le panneau de l'hébergeur.
2. Envoyer l'archive et `installer.php` par FTP/SFTP **en mode binaire** dans le dossier du site.
3. Ouvrir `https://nouveau-domaine.fr/installer.php` :
   - **Vérifications** : version de PHP compatible avec la version de WordPress, extensions, droits d'écriture, espace disque, intégrité de l'archive ;
   - **Base de données & URL** : identifiants (pré-remplis si un `wp-config.php` existe déjà), préfixe des tables (modifiable), nouvelle URL (détectée automatiquement), options avancées, création facultative d'un compte administrateur ;
   - **Installation** : vérification complète de l'archive (CRC de chaque bloc) **avant toute modification**, puis extraction et import, avec progression et reprise automatique en cas de coupure ;
   - **Terminé** : résumé des contrôles, bouton pour supprimer l'installeur, l'archive et les fichiers temporaires, puis connexion.

En SSH :

```sh
php installer.php --url=https://nouveau-domaine.fr --db-name=base --db-user=utilisateur --db-pass=secret --cleanup
php installer.php --help
```

### Restaurer une sauvegarde de ce site

Pour revenir à un état antérieur (après une mise à jour qui tourne mal, une fausse manœuvre, un remplacement massif) : **Sauvegardes → Restaurer** sur la ligne de la sauvegarde.

1. **Conseil** : créez d'abord une sauvegarde de l'état actuel (rien ne sera conservé de ce qui a été fait depuis la sauvegarde choisie).
2. **Restaurer** prépare la restauration : l'installeur de la sauvegarde est placé à la racine du site, avec son archive (liée au fichier d'origine plutôt que dupliquée quand le serveur le permet, sinon copiée si l'espace disque suffit). **Rien n'est modifié à ce stade** ; **Annuler la préparation** retire ces fichiers.
3. **Ouvrir l'installeur**, saisir le mot de passe de la sauvegarde (celui choisi à sa création), puis suivre les étapes habituelles : les accès à la base de données et l'adresse de ce site sont déjà remplis. Tous les fichiers, la base de données et les comptes du site sont remplacés par ceux de la sauvegarde. À la fin, « Supprimer les fichiers d'installation » retire l'installeur et l'archive de la racine ; la sauvegarde reste dans la liste.

Il faut pouvoir installer des extensions (`install_plugins`) et que les modifications de fichiers soient autorisées (`DISALLOW_FILE_MODS`). En SSH : `wp migration restore <id>` prépare et affiche l'adresse de l'installeur, `wp migration restore <id> --cancel` annule ; l'installeur se lance aussi en ligne de commande (`php <installeur> --help`).

### Transfert direct (sans FTP)

L'archive peut aller **directement du site d'origine au nouveau serveur**, sans passer par votre ordinateur ni par le FTP. Sur le site d'origine, **WP Migration → Sauvegardes → Transfert direct** crée un lien secret, valable 24 h et révocable. Ensuite, trois façons de l'utiliser :

- **WordPress déjà installé sur la destination** (le cas le plus simple) : installez et activez WP Migration sur ce WordPress, collez le lien dans **WP Migration → Recevoir un site**. L'installeur de la sauvegarde est placé sur le serveur, récupère l'archive et reprend les accès à la base de données du `wp-config.php` existant. Le WordPress de destination est entièrement remplacé par le site d'origine : connectez-vous ensuite avec les identifiants du site d'origine.
- **Dossier vide** : déposez seulement `installer.php` (quelques centaines de Ko), ouvrez-le et collez le lien quand il signale l'archive absente.
- **En SSH** :

```sh
curl -o installer.php 'LIEN&file=installer'
php installer.php --source-url='LIEN' --url=https://nouveau-domaine.fr --db-name=base --db-user=utilisateur --db-pass=secret
```

Le téléchargement se fait par morceaux de 8 Mo (requêtes HTTP `Range`) : il reprend après une coupure, fonctionne avec cURL ou, à défaut, les flux PHP, puis l'archive est contrôlée (sauvegarde attendue, signature de fin, CRC de chaque bloc) avant toute modification. En ligne de commande sur le site d'origine : `wp migration transfer-link <id>` (`--hours=`, `--revoke`).

### Rapport de migration

À la fin de l'installation, l'installeur **contrôle la copie** puis enregistre un rapport dans la base du nouveau site. Il reste consultable dans **WP Migration → Rapport de migration** après la suppression des fichiers d'installation :

- **résultat global** : « Migration vérifiée : la copie est complète » ou la liste des points à vérifier ;
- **contrôles** : sommes de contrôle de l'archive, fichiers extraits sur le nombre contenu dans l'archive, tables présentes, **nombre de lignes de chaque table comparé à celui exporté par le site d'origine** (compté juste après l'import, avant toute modification), requêtes SQL en erreur ;
- **source / destination** : adresses, dossiers, versions de WordPress, PHP et MySQL / MariaDB, préfixe des tables, base de données ;
- détail **par table** (lignes exportées / importées, tables de journaux recréées vides), remplacements effectués, éléments exclus par le site d'origine (caches, `debug.log`…), avertissements et **journal complet** de l'installation.

Le rapport se télécharge en texte (bouton « Télécharger le rapport ») ; en ligne de commande : `wp migration report` (`--format=json` ; code de sortie 1 si une anomalie a été détectée, pratique dans un script). L'installeur en ligne de commande affiche aussi le résumé des contrôles à la fin.

### Cohérence du site (contrôles après l'installation)

Une copie peut être « complète » sans être **cohérente** : un réglage qui pointe vers un élément qui n'existe plus ne fait échouer aucune requête. À la fin de l'installation, puis à tout moment dans **WP Migration → Rapport de migration → Cohérence du site** (bouton **Relancer les contrôles** après une correction), l'extension vérifie sans rien modifier :

- **Menus** : chaque emplacement de menu du thème actif (et, avec Polylang, chaque menu par langue) pointe vers un menu qui existe ;
- **Permaliens** : si les règles de réécriture ne sont pas encore régénérées, rappel d'enregistrer **Réglages → Permaliens** (pages traduites `/fr/…` en 404) ;
- **WPML** : liens de traduction (`icl_translations`) **comparés à ceux du site d'origine** — une ligne à `element_id` vide qui existait déjà sur l'origine (traduction en attente ou supprimée dans WPML) est signalée comme telle, pas comme une anomalie de la migration ; une ligne apparue depuis, ou un lien vers un contenu absent, est signalé. Langue par défaut, domaines de langue restés sur l'ancien site, clé de site liée au domaine ;
- **Polylang** : langue par défaut vide ou inconnue, domaines de langue restés sur l'ancien site, données WPML encore présentes (passage de WPML à Polylang : les tables `*_icl_*` sont alors obsolètes).

Chaque point est **OK**, **À vérifier** ou **Info**. Rien n'est jamais corrigé automatiquement, et un point à vérifier ne fait pas échouer l'installation. En SSH : `wp migration check` (`--format=json`, `--save` pour l'enregistrer dans le rapport ; code de sortie 1 s'il y a un point à vérifier). La section figure aussi dans l'export texte du rapport.

### Synchroniser le contenu (travail sur une copie)

Scénario type : le site est copié sur un serveur de développement ou de préproduction, on y travaille plusieurs jours (thème, extensions, pages…), pendant que **le site d'origine reste en ligne** et reçoit des commandes, des clients, des avis, de nouveaux produits. Avant la mise en ligne de la copie, la synchronisation y rapatrie tout ce qui a été créé ou modifié sur le site d'origine depuis la copie.

1. **Sur le site d'origine** (WP Migration 1.5.0 ou plus récent) : **WP Migration → Synchronisation → 1. Sur le site en ligne**, **Créer un lien** (valable 24 h, 3 ou 7 jours, révocable). Ce site est seulement lu.
2. **Sur la copie** : **WP Migration → Synchronisation → 2. Sur la copie de travail**, coller le lien, choisir les contenus, puis **Analyser**. La date de la copie est trouvée automatiquement (rapport de migration, synchronisation précédente) ou choisie parmi les sauvegardes du site d'origine.
3. Vérifier l'analyse (ajouts, mises à jour, contenus conservés, points à connaître), éventuellement **Sauvegarder la base de données** (sauvegarde de la base seule), puis **Importer ces contenus**. Rien n'est modifié avant cette confirmation, et la dernière synchronisation peut être **annulée**.
4. Recommencer autant que nécessaire : seules les nouveautés sont reprises. Faire une dernière synchronisation juste avant la mise en ligne, idéalement avec la boutique d'origine en maintenance pour ne perdre aucune commande entre les deux.

En SSH : `wp migration sync-link` sur le site d'origine, puis `wp migration sync '<lien>' --dry-run`, `wp migration sync '<lien>' --yes`, `wp migration sync-undo` sur la copie (options `--types=orders,customers,products,coupons,posts,media,comments`, `--since="AAAA-MM-JJ HH:MM"`, `--force`).

| Contenu | Repris | En cas de modification des deux côtés |
|---|---|---|
| Commandes et remboursements | articles, notes, adresses, métadonnées (paiement, expédition…), droits de téléchargement ; stockage HPOS ou articles | la version du site d'origine l'emporte |
| Clients | compte (mot de passe compris), adresses et données WooCommerce | la version du site d'origine l'emporte ; les administrateurs de la copie ne sont jamais modifiés |
| Produits et variations | fiche, prix, images, catégories, attributs, **stock et ventes** (y compris quand seul le stock a changé à cause des commandes) | la version de la copie est conservée, **mais le stock et les ventes viennent du site d'origine** |
| Codes promo | réglages et utilisations | la version du site d'origine l'emporte |
| Articles et pages | contenu, image à la une, catégories, étiquettes | la version de la copie est conservée |
| Médias | fiche et fichiers (toutes les tailles), téléchargés s'ils manquent | la version de la copie est conservée |
| Commentaires et avis | avec leurs notes | la version du site d'origine l'emporte |

L'option « Remplacer aussi les produits, pages et médias modifiés sur ce site » donne la priorité au site d'origine pour tout.

**Identifiants** : chaque contenu garde son identifiant chaque fois que possible — en particulier **les numéros de commande**, connus des clients et des services de paiement. Si la copie utilise déjà le numéro pour une révision, un brouillon automatique ou une commande de test, celle-ci est déplacée ; si c'est un contenu créé sur la copie (une page, un menu…) qui gêne une commande, il est déplacé et ses références connues sont mises à jour (menus, page d'accueil, pages WooCommerce, blocs, images à la une) ; pour les autres contenus, c'est le contenu entrant qui reçoit un nouvel identifiant, et ses liens (image à la une, galerie, variations, client d'une commande…) suivent. Les correspondances sont conservées pour les synchronisations suivantes, et une réserve de 1 000 identifiants au-dessus de ceux du site d'origine éloigne le contenu créé ensuite sur la copie (les numéros de commande peuvent donc sauter d'environ 1 000 après la mise en ligne de la copie).

**Limites** : une suppression définitive sur le site d'origine n'est pas reprise (une mise à la corbeille l'est) ; les réglages, extensions, thèmes, menus et widgets ne sont pas synchronisés (la copie fait référence ; des réglages précis se reprennent à part, voir ci-dessous) ; les données propres à d'autres extensions dans leurs propres tables (abonnements, réservations, fidélité, formulaires…) et les types de contenus personnalisés ne sont pas repris ; avec WPML, les liens entre traductions des contenus sont repris, pas les traductions de catégories créées entre-temps. Après une synchronisation, les statistiques WooCommerce des commandes concernées sont recalculées et les tables de recherche des produits régénérées.

### Reprendre des réglages d'un autre site

Un réglage a disparu après une migration, ou diffère de celui du site d'origine (un moyen de paiement, la TVA, les langues, des widgets…) ? Plutôt que de tout refaire à la main :

1. **Sur le site d'origine** (WP Migration 1.9.0 ou plus récent) : **Synchronisation → 1.** créer le lien de synchronisation (le même que pour le contenu).
2. **Sur le site à corriger** : **Synchronisation → Reprendre des réglages d'un autre site**, coller le lien et chercher un nom de réglage (`monetico`, `woocommerce_`, `polylang`…) ; des filtres rapides proposent WooCommerce, Polylang, WPML, les widgets et le thème.
3. Cocher les réglages à reprendre puis **Comparer avec ce site** : chaque réglage est marqué *Nouveau ici*, *Différent* ou *Identique*, avec la liste des différences (les valeurs qui ressemblent à des identifiants, clés ou mots de passe sont masquées). Rien n'est écrit à ce stade.
4. Éventuellement **Sauvegarder la base de données**, puis **Copier**. La copie peut être **annulée** : les réglages d'origine sont remis, sauf ceux modifiés depuis.

Les valeurs sont copiées telles qu'elles sont stockées (jamais désérialisées), et **les adresses du site d'origine sont remplacées par celles de ce site** (variantes encodées et formes sérialisées comprises ; option décochable). Ne sont jamais copiés : l'adresse du site (`siteurl`, `home`), le thème, les extensions actives, les numéros de version, les tâches planifiées, les données de session et les réglages de WP Migration. Les réglages qui désignent des pages, menus ou termes par leur numéro sont signalés : vérifiez qu'ils existent sur le site à corriger. Un réglage de plus de 1 Mo n'est pas repris.

En SSH : `wp migration settings '<lien>' --filter=monetico` liste, `--names=…` ou `--filter=… --all` choisit, `--dry-run` compare seulement, `wp migration settings-undo` annule.

### Comparer deux sites

Après une migration, ou avant une mise en ligne : qu'est-ce qui diffère entre ce site et l'autre ? **Synchronisation → Comparer ce site avec un autre**, coller le lien de synchronisation de l'autre site (WP Migration 1.10.0 ou plus récent), **Comparer**. La comparaison est **en lecture seule** sur les deux sites et ne transmet ni mot de passe ni clé.

Elle couvre : versions de WordPress, de PHP et de la base de données ; thème actif ; **extensions** (version, active ou non, extensions obligatoires) ; réglages usuels (permaliens, page d'accueil, fuseau horaire, devise et taxes WooCommerce…) ; **moyens de paiement activés** ; extension de langues, langue par défaut et langues actives ; menus ; nombre de contenus. Chaque ligne est *Identique*, *Différent*, *Seulement ici*, *Seulement là-bas* ou *À titre indicatif* (adresse, préfixe des tables et nombre de contenus diffèrent normalement entre un site et sa copie). Le bouton **Reprendre** d'un réglage ouvre l'outil « Reprendre des réglages d'un autre site » sur ce réglage.

En SSH : `wp migration compare '<lien>'` (`--all` pour les lignes identiques, `--format=json`).

### Rechercher et remplacer dans la base de données

Après une migration (ou à tout moment), pour changer une adresse oubliée, un domaine, un chemin serveur, un nom de société… dans **tout le contenu du site**. Un « rechercher / remplacer » SQL classique casse les données sérialisées (réglages de thème, constructeurs de pages, widgets) parce que la longueur des textes y est mémorisée ; ici chaque valeur est analysée et ses longueurs recalculées, y compris quand la valeur est sérialisée dans une autre valeur sérialisée ou dans du JSON.

**WP Migration → Rechercher / Remplacer**, puis :

1. Saisir le texte à **rechercher** et son **remplacement** (vide pour supprimer). Le type de recherche est **détecté automatiquement** (adresse, domaine, adresse IP ou chemin → mots entiers ; sinon texte) ; sous **Options avancées**, on peut l'imposer :
   - **URL, domaine ou chemin** (par défaut) : mots entiers — `http://a.fr` ne touche pas `http://a.frite.com` ; les variantes `https`, `//`, avec / sans `www.`, JSON (`http:\/\/`) et URL encodée (`http%3A%2F%2F`) sont traitées ensemble. Un chemin commence par `/` (`/home/ancien/public_html` → `/var/www/site`).
   - **Texte** : toutes les occurrences, où qu'elles soient (avec, en option, les formes JSON et URL encodée).
   - **Expression régulière** : `/motif/i`, avec `$1`, `$2`… pour les groupes capturés (ajouter `u` pour les caractères accentués).
2. Options avancées : ignorer la casse, ne traiter qu'une partie des tables, modifier aussi les `guid` des articles (déconseillé : ce ne sont pas des liens, les lecteurs RSS s'en servent pour reconnaître les articles déjà lus).
3. **Analyser** : rien n'est modifié. Le résultat donne, par table, le nombre de lignes et d'occurrences ainsi que des exemples avant / après (la partie modifiée est surlignée).
4. **Sauvegarder la base de données** (bouton à côté de « Remplacer ») : une sauvegarde de la base seule, sans rien exclure, à télécharger avec son installeur et son mot de passe (affiché une seule fois) ; en SSH : `wp migration build --db-only`.
5. **Remplacer** après vérification, puis vider les caches (extension de cache, CSS générés par le constructeur de pages).

**Annulation** : la valeur d'origine de chaque colonne modifiée est enregistrée avant le changement ; « Annuler ce remplacement » (ou depuis l'historique des 10 derniers) la remet, sauf si elle a été modifiée depuis (elle est alors laissée telle quelle et comptée). Les journaux des 5 derniers remplacements sont conservés dans le dossier de stockage.

Ne sont **jamais** modifiés : les noms des réglages et des métadonnées (`option_name`, `meta_key`), les mots de passe et clés d'activation des comptes, les réglages de WP Migration et, sauf option, les `guid` des articles. Les colonnes binaires et les tables sans clé primaire sont ignorées (et signalées). Remplacer l'adresse du site elle-même (`siteurl` / `home`) est possible mais déconnecte l'utilisateur : l'analyse le signale, et les réglages sont traités en dernier pour que la session tienne le plus longtemps possible.

Sur un site migré depuis une autre adresse, un lien propose de remplacer l'ancienne adresse (lue dans le rapport de migration) par l'adresse actuelle.

En SSH :

```
wp migration replace 'https://www.ancien.fr' 'https://www.nouveau.fr' --dry-run
wp migration replace 'https://www.ancien.fr' 'https://www.nouveau.fr' --yes
wp migration replace 'Ancienne société' 'Nouvelle société' --mode=text --ignore-case --tables=wp_posts,wp_postmeta
wp migration replace '/produit-(\d+)/' 'article-$1' --regex
wp migration replace-undo            # dernier remplacement (ou son identifiant)
```

Contrairement à `wp search-replace`, l'analyse détaillée, l'annulation et le traitement des variantes d'URL sont intégrés.

### Sauvegardes planifiées

**Réglages → Sauvegardes planifiées** : une sauvegarde est créée automatiquement chaque jour, chaque semaine (jour au choix) ou le 1<sup>er</sup> de chaque mois, à l'heure du site que vous choisissez (préférez une heure creuse).

- **Contenu** : complète, fichiers sans la médiathèque (et base de données), ou base de données seulement.
- **Mot de passe de l'installeur** (8 caractères au moins, obligatoire) : il protège l'installeur de chaque sauvegarde. **Notez-le** : seule son empreinte salée est conservée dans la base, il ne peut pas être retrouvé. Laissé vide à l'enregistrement, il reste inchangé.
- **E-mail** : prévenu en cas d'échec (par défaut), à chaque sauvegarde, ou jamais ; à l'adresse de l'administrateur ou à celle de votre choix. Le message ne contient ni mot de passe ni lien d'accès.
- **Conservation** : les anciennes sauvegardes sont supprimées par le nettoyage automatique (nombre de sauvegardes et âge maximum). Les sauvegardes planifiées sont des sauvegardes comme les autres : on peut les télécharger, les transférer ou les restaurer depuis l'onglet Sauvegardes.
- **Exécution** : par WP-Cron, en étapes courtes enchaînées (comme la création manuelle) ; elle reprend toute seule après une interruption, et une exécution restée bloquée plus de 6 heures est abandonnée et signalée. « Lancer une sauvegarde maintenant » la déroule dans le navigateur. Le prochain passage et le résultat de la dernière exécution sont affichés, et l'accueil signale un échec.
- **WP-Cron désactivé** (`DISABLE_WP_CRON`) : appelez `wp-cron.php` ou `wp cron event run --due-now` depuis une tâche cron du serveur, ou lancez `wp migration schedule-run` à l'heure voulue.

En SSH : `wp migration schedule` affiche l'état et accepte `--enable`, `--disable`, `--frequency=daily|weekly|monthly`, `--hour=3`, `--weekday=0..6`, `--type=full|nouploads|db`, `--password=…`, `--notify=failure|always|never`, `--email=…` ; `wp migration schedule-run` exécute une sauvegarde planifiée jusqu'à son terme.

### Nettoyage automatique des anciennes sauvegardes

Les sauvegardes occupent de l'espace sur l'hébergement et contiennent une copie complète du site (base de données comprise). L'extension les nettoie automatiquement **après chaque création et une fois par jour** (WP-Cron) :

- conserve les **5 dernières** sauvegardes terminées et supprime celles de plus de **30 jours** (réglable dans **WP Migration → Réglages**, encart « Nettoyage automatique », 0 désactive une règle) ;
- supprime les créations **abandonnées ou en échec** au bout de 24 h : leur dossier de travail contient un export SQL complet ;
- supprime les fichiers orphelins du dossier de stockage ;
- ne supprime **jamais** une sauvegarde dont le lien de transfert direct est actif, pour ne pas interrompre une migration en cours.

L'encart affiche l'espace utilisé, le prochain et le dernier nettoyage, et propose « Enregistrer et nettoyer maintenant ». En ligne de commande : `wp migration cleanup --dry-run` pour voir ce qui serait supprimé, `--keep=` et `--days=` pour d'autres règles ponctuelles.

## Ce qui est géré automatiquement

**Remplacement des URL et des chemins**
- toutes les variantes de l'ancienne adresse : `http://`, `https://`, `//` (relatif au protocole), avec ou sans `www.`, JSON échappé (`http:\/\/…`, constructeurs de pages comme Elementor), URL encodée (`http%3A%2F%2F…`) ;
- données **sérialisées** PHP : analysées sans `unserialize()` (aucun risque d'injection d'objet, aucune dépendance aux classes des extensions), longueurs recalculées, y compris pour les données doublement sérialisées ;
- remplacement en une seule passe (pas de remplacement en chaîne) et **sur des limites de mots** : `http://ancien.fr` ne touche pas `http://ancien.fr.example.org` ;
- chemins absolus du serveur (`/home/ancien/public_html` → nouveau chemin), aussi dans `wp-config.php` (`WP_TEMP_DIR`, `WPCACHEHOME`…) ;
- remplacements supplémentaires libres (`ancien => nouveau`), colonne `guid` optionnelle.

**Base de données**
- changement de **préfixe de tables** (clés `wp_user_roles`, `wp_capabilities`, `wp_user_level`… renommées) ;
- import dans des tables temporaires puis bascule **atomique** (`RENAME TABLE`) : en cas d'échec, la base existante n'est pas touchée ; les tables d'autres applications partageant la base sont conservées (sauf option « vider la base ») ;
- compatibilité MySQL ↔ MariaDB : collations inconnues (`utf8mb4_0900_ai_ci`, `uca1400`…), `utf8mb3`, moteur Aria, `current_timestamp()`, `ROW_FORMAT` ;
- requêtes redécoupées automatiquement selon le `max_allowed_packet` du serveur de destination ;
- colonnes binaires (hexadécimal), `BIT`, colonnes générées, dates `0000-00-00`, émojis (utf8mb4), tables sans clé primaire.

**Fichiers et serveur**
- `.htaccess`, `.user.ini` et `php.ini` de l'ancien hébergeur mis de côté (`*.wpmig-source`) : ils sont une cause classique d'« Erreur 500 » (gestionnaire PHP, `auto_prepend_file` de Wordfence…). Un `.htaccess` WordPress propre est écrit (avec le bon `RewriteBase`), puis les permaliens sont régénérés à la première connexion ;
- exclusion des caches, journaux (dont `debug.log`), sauvegardes d'autres extensions, extensions « must-use » propres aux hébergeurs (WP Engine, Kinsta, GoDaddy, Bluehost…) et des drop-ins `object-cache.php` / `advanced-cache.php` ;
- `wp-config.php` réécrit en conservant vos constantes et vos clés de sécurité (option pour les régénérer) ; `COOKIE_DOMAIN` retiré, `FORCE_SSL_ADMIN` désactivé si le nouveau site est en http, sauvegarde de l'éventuel `wp-config.php` existant ;
- `wp-content` déplacé hors de WordPress (`WP_CONTENT_DIR`) replacé à l'emplacement standard.

**Robustesse (hébergements mutualisés)**
- tout le traitement (analyse, export, archive, extraction, import) est découpé en requêtes courtes et **reprenable** : aucun souci de `max_execution_time`, les gros fichiers sont coupés entre plusieurs requêtes ;
- verrou contre l'exécution simultanée de deux étapes (timeout d'un proxy suivi d'une nouvelle tentative) ;
- archive avec une somme de contrôle CRC32 par bloc de 1 Mo et une signature de fin, **entièrement vérifiée avant l'installation** : un transfert FTP incomplet ou en mode ASCII est détecté sans que rien n'ait été modifié sur le serveur.

## Sécurité

- Dossier de stockage `wp-content/wpmig-backups` protégé (`.htaccess`, `web.config`, `index.php`) et noms de fichiers contenant un identifiant aléatoire (pour nginx) ; téléchargements servis par PHP après vérification des droits et d'un nonce.
- Installeur protégé par un mot de passe généré automatiquement (seul un hachage salé est stocké), session liée à un jeton ; une installation lancée ne peut pas être reprise depuis un autre navigateur sans le mot de passe. Sans mot de passe, n'importe qui trouvant `installer.php` pourrait lancer l'installation avec sa propre base de données : c'est possible mais déconseillé, et signalé par l'installeur.
- Lien de transfert direct : jeton aléatoire de 128 bits (seul son hachage est conservé), valable 24 h, révocable, un seul lien actif par sauvegarde, chaque téléchargement est journalisé (adresse IP) ; la même erreur 403 est renvoyée pour une sauvegarde inconnue ou une clé fausse. L'import depuis l'administration exige le droit d'installer des extensions et respecte `DISALLOW_FILE_MODS`.
- La sauvegarde du `wp-config.php` remplacé est un fichier `.php` qui s'arrête immédiatement : elle n'est jamais lisible depuis le web.
- Lien de synchronisation : jeton aléatoire de 128 bits (seul son hachage est conservé), valable 24 h à 7 jours, révocable, dernière utilisation affichée ; il donne accès en lecture aux contenus et comptes clients du site d'origine : utilisez HTTPS et révoquez-le après usage. Les jetons de session des clients ne sont jamais transmis, et seuls les fichiers de la médiathèque (hors PHP) peuvent être téléchargés.
- L'installeur propose de se supprimer avec l'archive à la fin ; l'administration du nouveau site affiche un avertissement tant que des fichiers d'installation subsistent.

## Dépannage

| Problème | Solution |
|---|---|
| « Archive incomplète » | Renvoyer l'archive, en mode **binaire** si FTP. |
| « Accès refusé » MySQL | Vérifier utilisateur / mot de passe et que l'utilisateur est associé à la base dans le panneau de l'hébergeur. |
| Pages 404 sur nginx | Ajouter `try_files $uri $uri/ /index.php?$args;` dans la configuration du site. |
| « Installation déjà lancée depuis un autre navigateur » | Supprimer le dossier `wpmig-installer-data-…` sur le serveur puis recharger l'installeur. |
| Autres adresses restantes (ancien sous-domaine, CDN…) | Ajouter un remplacement supplémentaire `https://cdn.ancien.fr => https://cdn.nouveau.fr`. |
| « Archive corrompue » pendant la vérification | Rien n'a été modifié : renvoyer l'archive en mode binaire et relancer. |
| Journal détaillé | **WP Migration → Rapport de migration** sur le nouveau site (journal complet inclus), ou `wpmig-installer-data-…/install.log` avant le nettoyage. |

## Développement

```
wp-migration.php                 amorçage de l'extension
includes/lib/                    bibliothèque sans dépendance à WordPress, embarquée dans installer.php
  class-wpmig-archive.php          format d'archive .wpmig (écriture / lecture reprenables)
  class-wpmig-replacer.php         remplacement compatible sérialisation
  class-wpmig-sql.php              échappement et analyse des INSERT
  class-wpmig-db-importer.php      import SQL (mysqli), compatibilité serveurs
includes/                        extension : sauvegarde, analyse, export SQL, archivage, admin, WP-CLI
installer/installer.php.tpl      modèle de l'installeur autonome
assets/                          interface d'administration
tests/run-tests.php              tests unitaires (sans WordPress)
```

Tests : `php tests/run-tests.php`. La CI GitHub les exécute sur PHP 5.6 à 8.4.

Format d'archive `.wpmig` : suite d'entrées `WMF1` (type, chemin, date, permissions) dont le contenu est découpé en blocs de 1 Mo (longueur, compression deflate facultative, CRC32), terminée par une signature `WMFE … WMFZ` contenant un résumé JSON. La première entrée est `__wpmig__/manifest.json` (description du site d'origine), la deuxième `__wpmig__/database.sql`.

## Publier une version

1. Mettre à jour le numéro de version dans `wp-migration.php` (`Version`, `WPMIG_VERSION`), `readme.txt` (`Stable tag`) et l'installeur (`WPMIG_INSTALLER` dans `installer/installer.php.tpl`).
2. Ajouter une section `## [x.y.z]` dans `CHANGELOG.md`.
3. Pousser un tag : `git tag vx.y.z && git push origin vx.y.z` (ou lancer le workflow « Release » avec l'option de publication).

Le workflow `.github/workflows/release.yml` vérifie la cohérence des versions, lance les tests, construit `wp-migration-x.y.z.zip` et publie la release GitHub avec les notes du changelog. En local : `bin/make-zip.sh`.

Les sites équipés de l'extension détectent la release dans les 12 heures : le zip joint `wp-migration-x.y.z.zip` (nom à conserver) est le paquet de mise à jour, et les notes de la release forment le journal affiché dans WordPress. Une release marquée « pre-release » ou brouillon n'est jamais proposée.

## Licence

GPL-2.0-or-later
