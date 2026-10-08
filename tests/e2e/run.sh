#!/usr/bin/env bash
#
# Test de bout en bout : un vrai WordPress est installé, rempli, sauvegardé, puis
# réinstallé ailleurs avec l'installeur ; les outils (rechercher / remplacer,
# synchronisation, réglages, comparaison, restauration, sauvegardes planifiées)
# sont ensuite exercés sur les deux sites.
#
# Variables (toutes facultatives) :
#   WP          commande WP-CLI                (défaut : wp --allow-root)
#   WP_VERSION  version de WordPress           (défaut : latest)
#   DB_HOST     hôte MySQL                     (défaut : 127.0.0.1)
#   DB_USER     utilisateur (droit de créer des bases) (défaut : root)
#   DB_PASS     mot de passe                   (défaut : root)
#   PORT_SRC / PORT_DST   ports des serveurs PHP intégrés (défaut : 8101 / 8102)
#   KEEP=1      conserve le dossier de travail
#
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WP="${WP:-wp --allow-root}"
WP_VERSION="${WP_VERSION:-latest}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-root}"
PORT_SRC="${PORT_SRC:-8101}"
PORT_DST="${PORT_DST:-8102}"
WORK="$(mktemp -d)"
SRC="$WORK/src"
DST="$WORK/dst"
URL_SRC="http://127.0.0.1:$PORT_SRC"
URL_DST="http://127.0.0.1:$PORT_DST"
INSTALLER_PASSWORD="E2e-passw0rd"
PIDS=()
FAILURES=0
COUNT=0

mysql_cmd() { mysql -h"$DB_HOST" -u"$DB_USER" ${DB_PASS:+-p"$DB_PASS"} "$@"; }
wps() { $WP --path="$SRC" "$@"; }
wpd() { $WP --path="$DST" "$@"; }

cleanup() {
	for pid in "${PIDS[@]:-}"; do [ -n "$pid" ] && kill "$pid" 2>/dev/null; done
	mysql_cmd -e "DROP DATABASE IF EXISTS e2e_src; DROP DATABASE IF EXISTS e2e_dst;" 2>/dev/null
	if [ -z "${KEEP:-}" ]; then rm -rf "$WORK"; else echo "Dossier conservé : $WORK"; fi
}
trap cleanup EXIT

step() { printf '\n== %s\n' "$*"; }
ok() { COUNT=$((COUNT + 1)); printf '  ok   %s\n' "$1"; }
ko() { COUNT=$((COUNT + 1)); FAILURES=$((FAILURES + 1)); printf '  FAIL %s\n' "$1"; [ -n "${2:-}" ] && printf '       %s\n' "$2"; }
check() { # description attendu obtenu
	if [ "$2" = "$3" ]; then ok "$1"; else ko "$1" "attendu : [$2] obtenu : [$3]"; fi
}
contains() { # description texte motif
	if printf '%s' "$2" | grep -qF -- "$3"; then ok "$1"; else ko "$1" "« $3 » absent de : $(printf '%s' "$2" | head -c 300)"; fi
}
absent() {
	if printf '%s' "$2" | grep -qF -- "$3"; then ko "$1" "« $3 » présent dans : $(printf '%s' "$2" | head -c 300)"; else ok "$1"; fi
}
# Exécute une commande dont la réussite est attendue ; affiche la sortie en cas d'échec.
run() { # description commande...
	local desc="$1"; shift
	local out
	if out="$("$@" 2>&1)"; then ok "$desc"; LAST="$out"; else ko "$desc" "$(printf '%s' "$out" | tail -5)"; LAST="$out"; fi
}

install_site() { # dossier base port
	local dir="$1" db="$2" port="$3"
	mkdir -p "$dir"
	$WP core download --path="$dir" --version="$WP_VERSION" --force >/dev/null
	mysql_cmd -e "DROP DATABASE IF EXISTS $db; CREATE DATABASE $db CHARACTER SET utf8mb4;"
	$WP --path="$dir" config create --dbname="$db" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST" --skip-check >/dev/null
	# Pas de mise à jour automatique de WordPress en plein test (elle mélangerait deux versions des fichiers).
	$WP --path="$dir" config set WP_AUTO_UPDATE_CORE false --raw >/dev/null
	$WP --path="$dir" config set AUTOMATIC_UPDATER_DISABLED true --raw >/dev/null
	$WP --path="$dir" config set DISABLE_WP_CRON true --raw >/dev/null
	$WP --path="$dir" core install --url="http://127.0.0.1:$port" --title="Site de test" --admin_user=admin --admin_password='Adm1n-passw0rd!' --admin_email=admin@example.org --skip-email >/dev/null
}

serve() { # dossier port
	PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:$2" -t "$1" >"$WORK/server-$2.log" 2>&1 &
	PIDS+=("$!")
}

install_plugin() { # site
	mkdir -p "$1/wp-content/plugins/wp-migration"
	(cd "$ROOT" && tar --exclude=.git --exclude=tests --exclude=docs -cf - .) | tar -xf - -C "$1/wp-content/plugins/wp-migration"
	$WP --path="$1" plugin activate wp-migration >/dev/null
}

# --------------------------------------------------------------------------
step "Site source : WordPress $WP_VERSION, contenu de test"
install_site "$SRC" e2e_src "$PORT_SRC"
install_plugin "$SRC"
serve "$SRC" "$PORT_SRC"
wps option update permalink_structure '/%postname%/' >/dev/null 2>&1
POST_ID=$(wps post create --post_title="Bonjour" --post_content="<a href=\"$URL_SRC/bonjour/\">lien</a> <img src=\"$URL_SRC/wp-content/uploads/x.png\">" --post_status=publish --porcelain)
wps post meta add "$POST_ID" e2e_meta "{\"u\":\"$URL_SRC/meta\"}" >/dev/null
wps option update e2e_serialized "{\"url\":\"$URL_SRC/opt\",\"liste\":[1,2,3],\"nom\":\"é à ü\"}" --format=json >/dev/null
wps option update blogdescription "Description de la source" >/dev/null
wps user create editeur editeur@example.org --role=editor --user_pass='Edit-passw0rd!' >/dev/null
php -r '$im=imagecreatetruecolor(8,8); imagepng($im,"'"$WORK"'/image.png");' 2>/dev/null || printf 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' | base64 -d >"$WORK/image.png"
wps media import "$WORK/image.png" --title="Image de test" >/dev/null
SRC_POSTS=$(wps post list --post_type=post --post_status=publish --format=count)
SRC_USERS=$(wps user list --format=count)
check "source : deux articles publiés (« Hello world » et « Bonjour »)" "2" "$SRC_POSTS"

# --------------------------------------------------------------------------
step "Sauvegarde du site source"
run "sauvegarde créée" wps migration build --name=e2e --password="$INSTALLER_PASSWORD"
ARCHIVE=$(printf '%s\n' "$LAST" | sed -n 's/^Archive *: //p' | tail -1)
INSTALLER=$(printf '%s\n' "$LAST" | sed -n 's/^Installeur *: //p' | tail -1)
[ -f "$ARCHIVE" ] && ok "archive présente" || ko "archive présente" "$ARCHIVE"
[ -f "$INSTALLER" ] && ok "installeur présent" || ko "installeur présent" "$INSTALLER"
absent "l'installeur ne contient pas le mot de passe en clair" "$(cat "$INSTALLER")" "$INSTALLER_PASSWORD"

# --------------------------------------------------------------------------
step "Installation sur le site de destination (installeur en ligne de commande)"
mkdir -p "$DST"
mysql_cmd -e "DROP DATABASE IF EXISTS e2e_dst; CREATE DATABASE e2e_dst CHARACTER SET utf8mb4;"
cp "$ARCHIVE" "$INSTALLER" "$DST/"
run "installation terminée" php "$DST/$(basename "$INSTALLER")" --url="$URL_DST" --db-host="$DB_HOST" --db-name=e2e_dst --db-user="$DB_USER" --db-pass="$DB_PASS" --db-action=replace --cleanup
contains "installation signalée terminée" "$LAST" "Terminé"
[ -z "$(ls "$DST"/*_installer.php "$DST"/*.wpmig 2>/dev/null)" ] && ok "installeur et archive supprimés" || ko "installeur et archive supprimés"
serve "$DST" "$PORT_DST"

check "adresse du site" "$URL_DST" "$(wpd option get siteurl)"
check "articles publiés" "$SRC_POSTS" "$(wpd post list --post_type=post --post_status=publish --format=count)"
check "utilisateurs" "$SRC_USERS" "$(wpd user list --format=count)"
CONTENT=$(wpd post get "$(wpd post list --post_type=post --name=bonjour --field=ID)" --field=post_content)
contains "adresse remplacée dans le contenu" "$CONTENT" "$URL_DST/bonjour/"
absent "ancienne adresse absente du contenu" "$CONTENT" "$URL_SRC"
check "réglage sérialisé : adresse remplacée" "$URL_DST/opt" "$(wpd eval 'echo get_option("e2e_serialized")["url"];')"
check "réglage sérialisé : tableau intact" "1,2,3" "$(wpd eval 'echo implode(",", get_option("e2e_serialized")["liste"]);')"
check "réglage sérialisé : accents intacts" "é à ü" "$(wpd eval 'echo get_option("e2e_serialized")["nom"];')"
check "métadonnée JSON : adresse remplacée" "{\"u\":\"$URL_DST/meta\"}" "$(wpd post meta get "$(wpd post list --post_type=post --name=bonjour --field=ID)" e2e_meta)"
UPLOAD=$(wpd eval '$a=get_posts(array("post_type"=>"attachment","numberposts"=>1)); echo get_attached_file($a[0]->ID);')
[ -f "$UPLOAD" ] && ok "fichier de la médiathèque présent" || ko "fichier de la médiathèque présent" "$UPLOAD"
check "extension active sur la destination" "active" "$(wpd plugin get wp-migration --field=status)"
HTTP=$(curl -s -o /dev/null -w '%{http_code}' "$URL_DST/")
check "page d'accueil de la destination" "200" "$HTTP"
run "contrôles de cohérence" wpd migration check --format=json
printf '%s' "$LAST" | php -r 'exit(is_array(json_decode(stream_get_contents(STDIN), true)) ? 0 : 1);' && ok "contrôles de cohérence : JSON valide" || ko "contrôles de cohérence : JSON valide"
run "rapport de migration présent" wpd option get wpmig_report --format=json

# --------------------------------------------------------------------------
step "Rechercher / remplacer"
wpd option update e2e_replace "{\"a\":\"http://ancien.example/x\",\"b\":\"http://ancien.example\"}" --format=json >/dev/null
BEFORE=$(wpd option get e2e_replace --format=json)
run "analyse sans modification" wpd migration replace 'http://ancien.example' 'https://nouveau.example' --dry-run
check "rien modifié par l'analyse" "$BEFORE" "$(wpd option get e2e_replace --format=json)"
run "remplacement appliqué" wpd migration replace 'http://ancien.example' 'https://nouveau.example' --yes
check "remplacement dans un réglage sérialisé" "https://nouveau.example/x" "$(wpd eval 'echo get_option("e2e_replace")["a"];')"
run "annulation du remplacement" wpd migration replace-undo --yes
check "valeur d'origine restaurée" "$BEFORE" "$(wpd option get e2e_replace --format=json)"

# --------------------------------------------------------------------------
step "Synchronisation, réglages et comparaison (site source → destination)"
T0=$(date -u '+%Y-%m-%d %H:%M')
sleep 2
wps post create --post_title="Nouveau sur la source" --post_content="Contenu" --post_status=publish >/dev/null
wps option update blogdescription "Nouvelle description" >/dev/null
LINK=$(wps eval 'echo WPMIG_Sync_Source::create(1)["url"];')
[ -n "$LINK" ] && ok "lien de synchronisation créé" || ko "lien de synchronisation créé"
run "synchronisation (analyse)" wpd migration sync "$LINK" --types=posts --since="$T0" --dry-run
check "analyse : rien importé" "0" "$(wpd post list --post_type=post --title='Nouveau sur la source' --format=count)"
run "synchronisation (import)" wpd migration sync "$LINK" --types=posts --since="$T0" --yes
check "synchronisation : l'article est arrivé" "1" "$(wpd post list --post_type=post --title='Nouveau sur la source' --format=count)"
run "annulation de la synchronisation" wpd migration sync-undo --yes
check "synchronisation annulée" "0" "$(wpd post list --post_type=post --title='Nouveau sur la source' --format=count)"
run "comparaison des deux sites" wpd migration compare "$LINK" --format=json
printf '%s' "$LAST" | php -r '$d=json_decode(stream_get_contents(STDIN),true); exit(isset($d["summary"]) ? 0 : 1);' && ok "comparaison : JSON valide" || ko "comparaison : JSON valide"
run "réglage : comparaison" wpd migration settings "$LINK" --names=blogdescription --dry-run
check "réglage : rien modifié par l'analyse" "Description de la source" "$(wpd option get blogdescription)"
run "réglage : copie" wpd migration settings "$LINK" --names=blogdescription --yes
check "réglage copié" "Nouvelle description" "$(wpd option get blogdescription)"
run "réglage : annulation" wpd migration settings-undo --yes
check "réglage restauré" "Description de la source" "$(wpd option get blogdescription)"
run "la source refuse un réglage protégé" wpd migration settings "$LINK" --names=siteurl,active_plugins --dry-run
contains "réglage protégé signalé" "$LAST" "protected"
wps eval 'WPMIG_Sync_Source::revoke();' >/dev/null
OUT=$(wpd migration compare "$LINK" 2>&1 || true)
contains "lien révoqué refusé" "$OUT" "invalide, expiré ou révoqué"


# --------------------------------------------------------------------------
step "Transfert direct : l'installeur télécharge l'archive (avec et sans cURL)"
SRC_ID=$(wps migration list | awk 'NR==2 {print $1}')
TLINK=$(wps migration transfer-link "$SRC_ID" 2>&1 | grep -m1 '^http')
[ -n "$TLINK" ] && ok "lien de transfert créé" || ko "lien de transfert créé"
for mode in curl fopen; do
	TDIR="$WORK/transfer-$mode"
	mkdir -p "$TDIR"
	cp "$INSTALLER" "$TDIR/installer.php"
	PHP_OPTS=""
	[ "$mode" = "fopen" ] && PHP_OPTS="-d disable_functions=curl_init,curl_exec,curl_setopt,curl_getinfo,curl_error,curl_close,curl_setopt_array"
	OUT=$(cd "$TDIR" && php $PHP_OPTS -d error_reporting=-1 -d display_errors=1 installer.php --source-url="$TLINK" --check 2>&1)
	contains "transfert ($mode) : archive téléchargée" "$OUT" "Archive téléchargée et contrôlée"
	absent "transfert ($mode) : aucun avis de dépréciation" "$OUT" "Deprecated"
done

# --------------------------------------------------------------------------
step "Restauration d'une sauvegarde sur le site de destination"
run "sauvegarde de la destination" wpd migration build --name=avant --password="$INSTALLER_PASSWORD" --exclude-uploads
ID=$(wpd migration list | awk 'NR==2 {print $1}')
wpd option update blogname "Titre modifié après la sauvegarde" >/dev/null
TMP_ID=$(wpd post create --post_title="Article temporaire" --post_status=publish --porcelain)
run "restauration préparée" wpd migration restore "$ID"
INST=$(ls "$DST"/avant_*_installer.php 2>/dev/null | head -1)
[ -f "$INST" ] && ok "installeur placé à la racine" || ko "installeur placé à la racine"
check "préparation : rien modifié" "Titre modifié après la sauvegarde" "$(wpd option get blogname)"
run "préparation annulée" wpd migration restore "$ID" --cancel
[ -z "$(ls "$DST"/avant_*_installer.php 2>/dev/null)" ] && ok "racine nettoyée par l'annulation" || ko "racine nettoyée par l'annulation"
run "restauration préparée (2)" wpd migration restore "$ID"
run "restauration exécutée" php "$(ls "$DST"/avant_*_installer.php | head -1)" --url="$URL_DST" --db-host="$DB_HOST" --db-name=e2e_dst --db-user="$DB_USER" --db-pass="$DB_PASS" --db-action=replace --cleanup
check "titre revenu à l'état de la sauvegarde" "Site de test" "$(wpd option get blogname)"
check "article temporaire disparu" "0" "$(wpd post list --post_type=post --title='Article temporaire' --format=count)"
[ -z "$(ls "$DST"/avant_*_installer.php "$DST"/avant_*.wpmig 2>/dev/null)" ] && ok "racine propre après la restauration" || ko "racine propre après la restauration"
[ -n "$(ls "$DST"/wp-content/wpmig-backups/avant_*_archive.wpmig 2>/dev/null)" ] && ok "la sauvegarde est conservée" || ko "la sauvegarde est conservée"

# --------------------------------------------------------------------------
step "Sauvegardes planifiées"
OUT=$(wpd migration schedule --enable 2>&1 || true)
contains "mot de passe obligatoire" "$OUT" "mot de passe"
run "planification enregistrée" wpd migration schedule --enable --frequency=weekly --weekday=2 --hour=4 --type=db --password='Planif-passw0rd' --notify=never
contains "prochaine exécution affichée" "$(wpd migration schedule)" "Prochaine sauvegarde"
check "mot de passe absent en clair" "0" "$(wpd option get wpmig_schedule --format=json | grep -c 'Planif-passw0rd')"
run "sauvegarde planifiée exécutée" wpd migration schedule-run
contains "dernière exécution réussie" "$(wpd migration schedule)" "réussie"
check "événement planifié" "1" "$(wpd cron event list --format=csv | grep -c '^wpmig_scheduled_backup')"

# --------------------------------------------------------------------------
printf '\n%d/%d vérifications réussies\n' $((COUNT - FAILURES)) "$COUNT"
[ "$FAILURES" -eq 0 ]
