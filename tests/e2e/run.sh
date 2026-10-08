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
	[ -n "${ORIG_PACKET:-}" ] && mysql_cmd -e "SET GLOBAL max_allowed_packet=$ORIG_PACKET" 2>/dev/null
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
step "Valeurs volumineuses (max_allowed_packet ramené à 16 Mo)"
ORIG_PACKET=$(mysql_cmd -N -e "SELECT @@global.max_allowed_packet")
mysql_cmd -e "SET GLOBAL max_allowed_packet=134217728" 2>/dev/null
mysql_cmd --max_allowed_packet=134217728 e2e_src <<'SQL'
CREATE TABLE wp_e2e_geant (id INT PRIMARY KEY, a LONGBLOB, b LONGTEXT, c LONGBLOB) ENGINE=InnoDB;
-- ligne de plus de 20 Mo dont chaque valeur tient dans un paquet de 16 Mo : insérée par morceaux
INSERT INTO wp_e2e_geant VALUES (1, REPEAT(CHAR(200), 7*1024*1024), REPEAT('texte http://127.0.0.1:8101/x ', 230000), REPEAT(CHAR(65), 7*1024*1024));
-- une valeur de 20 Mo : plus grande que le paquet, signalée et laissée vide
INSERT INTO wp_e2e_geant VALUES (2, REPEAT(CHAR(201), 20*1024*1024), 'petit', 'petit');
SQL
mysql_cmd -e "SET GLOBAL max_allowed_packet=16777216"
GEANT_A=$(mysql_cmd -N e2e_src -e "SELECT MD5(a) FROM wp_e2e_geant WHERE id=1")
GEANT_C=$(mysql_cmd -N e2e_src -e "SELECT MD5(c) FROM wp_e2e_geant WHERE id=1")
GEANT_B=$(mysql_cmd -N e2e_src -e "SELECT MD5(REPLACE(b,'8101','8102')) FROM wp_e2e_geant WHERE id=1")
[ -n "$GEANT_A" ] && ok "valeurs volumineuses créées sur la source" || ko "valeurs volumineuses créées sur la source"

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
contains "valeur plus grande que max_allowed_packet signalée par l'installeur" "$LAST" "plus grande que max_allowed_packet"
check "ligne volumineuse : colonne a intacte" "$GEANT_A" "$(mysql_cmd -N e2e_dst -e 'SELECT MD5(a) FROM wp_e2e_geant WHERE id=1')"
check "ligne volumineuse : colonne c intacte" "$GEANT_C" "$(mysql_cmd -N e2e_dst -e 'SELECT MD5(c) FROM wp_e2e_geant WHERE id=1')"
check "ligne volumineuse : texte avec adresses remplacées" "$GEANT_B" "$(mysql_cmd -N e2e_dst -e "SELECT MD5(b) FROM wp_e2e_geant WHERE id=1")"
check "valeur de 20 Mo : laissée vide, pas NULL" "0" "$(mysql_cmd -N e2e_dst -e 'SELECT IFNULL(LENGTH(a),-1) FROM wp_e2e_geant WHERE id=2')"
check "valeur de 20 Mo : le reste de la ligne est importé" "petit" "$(mysql_cmd -N e2e_dst -e 'SELECT b FROM wp_e2e_geant WHERE id=2')"

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
step "Stockage S3 (émulateur moto : signatures vérifiées)"
MOTO="${MOTO_SERVER:-$(command -v moto_server || true)}"
MOTO_PY="${MOTO_PYTHON:-python3}"
if [ -z "$MOTO" ] || ! "$MOTO_PY" -c 'import boto3, moto' 2>/dev/null; then
	echo "  (moto_server ou boto3 absent : étape ignorée ; pip install 'moto[server]' boto3)"
else
	PORT_S3=9100
	PORT_S3_OPEN=9101
	# Instance 1 : authentification SigV4 exigée (les trois premières actions servent à créer la clé).
	INITIAL_NO_AUTH_ACTION_COUNT=3 "$MOTO" -H 127.0.0.1 -p "$PORT_S3" >"$WORK/moto1.log" 2>&1 &
	PIDS+=("$!")
	# Instance 2 : sans contrôle (moto ne sait pas vérifier les URL pré-signées).
	"$MOTO" -H 127.0.0.1 -p "$PORT_S3_OPEN" >"$WORK/moto2.log" 2>&1 &
	PIDS+=("$!")
	for i in $(seq 1 40); do curl -s -o /dev/null "http://127.0.0.1:$PORT_S3/moto-api/" && curl -s -o /dev/null "http://127.0.0.1:$PORT_S3_OPEN/moto-api/" && break; sleep 0.5; done
	KEYS=$("$MOTO_PY" - "$PORT_S3" "$PORT_S3_OPEN" <<'PY'
import boto3, json, sys
strict, open_ = sys.argv[1], sys.argv[2]
iam = boto3.client('iam', endpoint_url=f'http://127.0.0.1:{strict}', region_name='us-east-1', aws_access_key_id='x', aws_secret_access_key='y')
iam.create_user(UserName='backup')
k = iam.create_access_key(UserName='backup')['AccessKey']
iam.put_user_policy(UserName='backup', PolicyName='all', PolicyDocument=json.dumps({'Version': '2012-10-17', 'Statement': [{'Effect': 'Allow', 'Action': 's3:*', 'Resource': '*'}]}))
for port, ak, sk in ((strict, k['AccessKeyId'], k['SecretAccessKey']), (open_, 'a', 'b')):
    boto3.client('s3', endpoint_url=f'http://127.0.0.1:{port}', region_name='eu-west-3', aws_access_key_id=ak, aws_secret_access_key=sk).create_bucket(Bucket='sauvegardes', CreateBucketConfiguration={'LocationConstraint': 'eu-west-3'})
print(k['AccessKeyId'], k['SecretAccessKey'])
PY
)
	S3_ACCESS="${KEYS%% *}"
	S3_SECRET="${KEYS##* }"
	[ -n "$S3_ACCESS" ] && ok "émulateur S3 démarré (authentification exigée)" || ko "émulateur S3 démarré" "$(tail -3 "$WORK/moto1.log")"
	s3py() { "$MOTO_PY" - "$@"; }

	run "réglages S3 enregistrés" wpd migration s3 --endpoint="http://127.0.0.1:$PORT_S3" --region=eu-west-3 --bucket=sauvegardes --prefix= --access-key="$S3_ACCESS" --secret-key="$S3_SECRET" --keep=2
	run "connexion S3 réussie" wpd migration s3-test
	wpd migration s3 --secret-key=cle-secrete-erronee >/dev/null
	OUT=$(wpd migration s3-test 2>&1 || true)
	contains "mauvaise clé secrète refusée (signature vérifiée par le serveur)" "$OUT" "SignatureDoesNotMatch"
	wpd migration s3 --secret-key="$S3_SECRET" --keep=0 >/dev/null
	# La clé est bien dans la base du site (positif), mais jamais dans une sauvegarde : archive sans compression, recherche directe.
	check "contrôle positif : la clé est dans la base du site" "1" "$(wpd db export - 2>/dev/null | grep -c "$S3_SECRET")"
	run "sauvegarde de la base sans compression" wpd migration build --name=s3chk --db-only --no-compress --password="$INSTALLER_PASSWORD"
	CHK_ARCHIVE=$(printf '%s\n' "$LAST" | sed -n 's/^Archive *: //p' | tail -1)
	check "clé secrète absente de la sauvegarde" "0" "$(grep -c -a "$S3_SECRET" "$CHK_ARCHIVE")"
	check "réglages S3 absents de la sauvegarde" "0" "$(grep -c -a "wpmig_s3" "$CHK_ARCHIVE")"

	IDS=$(wpd migration list | awk 'NR>1 && $3=="complete" {print $1}' | sort | tail -3)
	BIG_ID=""
	BIG_SIZE=0
	for id in $IDS; do
		run "envoi de la sauvegarde $id" wpd migration s3-send "$id"
		size=$(stat -c %s "$(ls "$DST"/wp-content/wpmig-backups/*_"$id"_archive.wpmig)")
		[ "$size" -gt "$BIG_SIZE" ] && { BIG_SIZE=$size; BIG_ID=$id; }
	done
	SENT=$(wpd migration s3-list | awk '{print $1}' | sed -E 's#^([0-9]{8}_[0-9]{6}_[a-f0-9]{12})/.*#\1#' | grep -E '^[0-9]{8}_' | sort -u | wc -l)
	check "les sauvegardes envoyées sont sur S3" "3" "$SENT"
	ARCH=$(ls "$DST"/wp-content/wpmig-backups/*_"$BIG_ID"_archive.wpmig)
	SAME=$(s3py "http://127.0.0.1:$PORT_S3" "$S3_ACCESS" "$S3_SECRET" "$BIG_ID" "$ARCH" <<'PY'
import boto3, hashlib, sys
ep, ak, sk, bid, local = sys.argv[1:6]
s3 = boto3.client('s3', endpoint_url=ep, region_name='eu-west-3', aws_access_key_id=ak, aws_secret_access_key=sk)
key = [o['Key'] for o in s3.list_objects_v2(Bucket='sauvegardes').get('Contents', []) if o['Key'].startswith(bid + '/') and o['Key'].endswith('.wpmig')][0]
etag = s3.head_object(Bucket='sauvegardes', Key=key)['ETag'].strip('"')
body = s3.get_object(Bucket='sauvegardes', Key=key)['Body']
h = hashlib.sha256()
for chunk in iter(lambda: body.read(1 << 20), b''): h.update(chunk)
same = h.hexdigest() == hashlib.sha256(open(local, 'rb').read()).hexdigest()
print(('identique' if same else 'DIFFERENT') + ('|multipart' if '-' in etag else '|simple'))
PY
)
	check "archive sur S3 identique au fichier local (sha256, lu par boto3)" "identique" "${SAME%%|*}"
	contains "archive de $((BIG_SIZE / 1048576)) Mo envoyée par morceaux (multipart)" "$SAME" "multipart"
	wpd migration s3 --keep=2 >/dev/null
	run "rétention appliquée" wpd migration s3-prune
	check "rétention : seules les 2 plus récentes restent sur S3" "2" "$(wpd migration s3-list | awk '{print $1}' | sed -E 's#^([0-9]{8}_[0-9]{6}_[a-f0-9]{12})/.*#\1#' | grep -E '^[0-9]{8}_' | sort -u | wc -l)"
	S3ID=$(echo "$IDS" | tail -1)

	# Sauvegarde planifiée envoyée sur S3.
	run "planification avec envoi S3" wpd migration schedule --enable --frequency=daily --hour=4 --type=db --password='Planif-passw0rd' --notify=never --s3
	run "sauvegarde planifiée envoyée sur S3" wpd migration schedule-run
	contains "dernière exécution : envoyée sur S3" "$(wpd migration schedule)" "envoyée sur S3"
	wpd migration schedule --no-s3 >/dev/null

	# Liens temporaires et installation depuis S3 (instance sans contrôle).
	wpd migration s3 --endpoint="http://127.0.0.1:$PORT_S3_OPEN" --access-key=a --secret-key=b >/dev/null
	run "envoi vers l'instance ouverte" wpd migration s3-send "$S3ID"
	LINKS=$(wpd migration s3-link "$S3ID" --hours=2)
	INST_URL=$(printf '%s\n' "$LINKS" | grep -o "curl -o installer.php '[^']*'" | sed "s/curl -o installer.php '//; s/'$//")
	ARCH_URL=$(printf '%s\n' "$LINKS" | grep -o "source-url='[^']*'" | sed "s/source-url='//; s/'$//")
	mkdir -p "$WORK/s3-dl"
	curl -s -o "$WORK/s3-dl/installer.php" "$INST_URL"
	cmp -s "$WORK/s3-dl/installer.php" "$(ls "$DST"/wp-content/wpmig-backups/*_"$S3ID"_installer.php)" && ok "installeur téléchargé par lien temporaire, identique" || ko "installeur téléchargé par lien temporaire"
	OUT=$(cd "$WORK/s3-dl" && php -d error_reporting=-1 -d display_errors=1 installer.php --source-url="$ARCH_URL" --check 2>&1)
	contains "installeur : archive récupérée depuis S3 et contrôlée" "$OUT" "Archive téléchargée et contrôlée"
	absent "installeur : aucun avis de dépréciation" "$OUT" "Deprecated"
fi

# --------------------------------------------------------------------------
printf '\n%d/%d vérifications réussies\n' $((COUNT - FAILURES)) "$COUNT"
[ "$FAILURES" -eq 0 ]
