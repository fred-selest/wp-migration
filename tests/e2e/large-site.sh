#!/usr/bin/env bash
#
# Montée en charge (manuelle, hors CI) : un site d'environ 900 Mo de base de données
# (250 000 articles, 2,5 millions de métadonnées, une table sans clé primaire, une
# ligne de 35 Mo, une option de 5 Mo) et 2,2 Go de médias (25 000 fichiers dont trois
# gros) est sauvegardé puis installé ailleurs avec 128 Mo de mémoire PHP et un
# max_allowed_packet MySQL de 16 Mo, comme chez un hébergeur mutualisé.
# Compter environ 10 Go de disque et 6 minutes.
#
# Variables : WP (commande WP-CLI), DB_HOST, DB_USER, DB_PASS (droit de créer des bases
# et de changer SET GLOBAL), WORK (dossier de travail, défaut : dossier temporaire).
#
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WP="${WP:-wp --allow-root}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-root}"
WORK="${WORK:-$(mktemp -d)}"
SRC="$WORK/src"
DST="$WORK/dst"
FAILURES=0

mysql_cmd() { mysql -h"$DB_HOST" -u"$DB_USER" ${DB_PASS:+-p"$DB_PASS"} "$@"; }
ok() { printf '  ok   %s\n' "$1"; }
ko() { FAILURES=$((FAILURES + 1)); printf '  FAIL %s\n' "$1"; [ -n "${2:-}" ] && printf '       %s\n' "$2"; }
check() { if [ "$2" = "$3" ]; then ok "$1"; else ko "$1" "attendu : [$2] obtenu : [$3]"; fi; }
ORIG_PACKET=$(mysql_cmd -N -e "SELECT @@global.max_allowed_packet")
cleanup() {
	mysql_cmd -e "SET GLOBAL max_allowed_packet=$ORIG_PACKET; DROP DATABASE IF EXISTS big_src; DROP DATABASE IF EXISTS big_dst;" 2>/dev/null
	[ -z "${KEEP:-}" ] && rm -rf "$WORK"
}
trap cleanup EXIT

echo "== Site source"
mkdir -p "$SRC" "$DST"
$WP core download --path="$SRC" --force >/dev/null
mysql_cmd -e "DROP DATABASE IF EXISTS big_src; CREATE DATABASE big_src CHARACTER SET utf8mb4; DROP DATABASE IF EXISTS big_dst; CREATE DATABASE big_dst CHARACTER SET utf8mb4;"
$WP --path="$SRC" config create --dbname=big_src --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST" --skip-check >/dev/null
for c in "WP_AUTO_UPDATE_CORE false" "AUTOMATIC_UPDATER_DISABLED true" "DISABLE_WP_CRON true"; do $WP --path="$SRC" config set $c --raw >/dev/null; done
$WP --path="$SRC" core install --url=http://127.0.0.1:8110 --title="Gros site" --admin_user=admin --admin_password='Adm1n-passw0rd!' --admin_email=a@example.org --skip-email >/dev/null
mkdir -p "$SRC/wp-content/plugins/wp-migration"
(cd "$ROOT" && tar --exclude=.git --exclude=tests --exclude=docs -cf - .) | tar -xf - -C "$SRC/wp-content/plugins/wp-migration"
$WP --path="$SRC" plugin activate wp-migration >/dev/null

mysql_cmd -e "SET GLOBAL max_allowed_packet=134217728"
mysql_cmd --max_allowed_packet=134217728 big_src <<'SQL'
INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count)
SELECT 1, DATE_ADD('2020-01-01', INTERVAL seq MINUTE), DATE_ADD('2020-01-01', INTERVAL seq MINUTE),
 CONCAT('<p>Contenu ', seq, ' éàùç « » 😀 ', REPEAT('lorem ipsum http://127.0.0.1:8110/page-', 20), '</p>'),
 CONCAT('Article ', seq), '', 'publish', 'closed', 'closed', '', CONCAT('article-', seq), '', '', NOW(), UTC_TIMESTAMP(), '', 0,
 CONCAT('http://127.0.0.1:8110/?p=', 100000 + seq), 0, 'post', '', 0
FROM seq_1_to_250000;
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT p.ID, CONCAT('meta_', s.seq), IF(s.seq = 1, CONCAT('a:1:{s:3:"url";s:', LENGTH(CONCAT('http://127.0.0.1:8110/m/', p.ID)), ':"http://127.0.0.1:8110/m/', p.ID, '";}'), CONCAT('valeur ', p.ID, '-', s.seq))
FROM wp_posts p JOIN seq_1_to_10 s WHERE p.post_type = 'post';
CREATE TABLE wp_sans_cle (a INT, b VARCHAR(100), c TEXT) ENGINE=InnoDB;
INSERT INTO wp_sans_cle SELECT seq, CONCAT('ligne ', seq), REPEAT('données ', 40) FROM seq_1_to_200000;
CREATE TABLE wp_geante (id INT PRIMARY KEY, blob_data LONGBLOB, texte LONGTEXT) ENGINE=InnoDB;
INSERT INTO wp_geante VALUES (1, REPEAT(CHAR(200), 9*1024*1024), REPEAT('texte géant http://127.0.0.1:8110/x ', 400000));
INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('gros_reglage', REPEAT('y', 5000000), 'no');
SQL
python3 - "$SRC/wp-content/uploads" <<'PY'
import os, random, sys
random.seed(1)
base = sys.argv[1]
for y in (2022, 2023, 2024, 2025):
    for m in range(1, 13):
        d = f'{base}/{y}/{m:02d}'
        os.makedirs(d, exist_ok=True)
        for i in range(520):
            with open(f'{d}/image-{y}{m:02d}-{i}.jpg', 'wb') as f:
                f.write(os.urandom(random.randint(6000, 26000)))
os.makedirs(base + '/divers', exist_ok=True)
for name in ['é à ü.jpg', 'nom avec espaces.png', 'a' * 180 + '.txt', 'sans-extension']:
    open(f'{base}/divers/{name}', 'wb').write(os.urandom(5000))
PY
for f in video-1.mp4:800 video-2.mp4:600 export.zip:400; do dd if=/dev/urandom of="$SRC/wp-content/uploads/divers/${f%%:*}" bs=1M count="${f##*:}" status=none; done
mysql_cmd -e "SET GLOBAL max_allowed_packet=16777216"
GEANT_BLOB=$(mysql_cmd -N big_src -e "SELECT MD5(blob_data) FROM wp_geante")
GEANT_TEXT=$(mysql_cmd -N big_src -e "SELECT MD5(REPLACE(texte,'8110','8111')) FROM wp_geante")
echo "  base : $(mysql_cmd -N -e "SELECT ROUND(SUM(data_length+index_length)/1048576) FROM information_schema.tables WHERE table_schema='big_src'") Mo, fichiers : $(find "$SRC/wp-content/uploads" -type f | wc -l)"

echo "== Sauvegarde (memory_limit=128M)"
printf "memory_limit=128M\n" > "$WORK/php128.ini"
OUT=$(PHPRC="$WORK/php128.ini" $WP --path="$SRC" migration build --name=gros --password=Demo2026 2>&1)
ARCHIVE=$(printf '%s\n' "$OUT" | sed -n 's/^Archive *: //p' | tail -1)
INSTALLER=$(printf '%s\n' "$OUT" | sed -n 's/^Installeur *: //p' | tail -1)
[ -f "$ARCHIVE" ] && ok "archive créée ($(du -h "$ARCHIVE" | cut -f1))" || ko "archive créée" "$(printf '%s' "$OUT" | tail -3)"

echo "== Installation (memory_limit=128M, max_allowed_packet=16 Mo)"
cp "$ARCHIVE" "$INSTALLER" "$DST/"
OUT=$(cd "$DST" && php -d memory_limit=128M "$(basename "$INSTALLER")" --url=http://127.0.0.1:8111 --db-host="$DB_HOST" --db-name=big_dst --db-user="$DB_USER" --db-pass="$DB_PASS" --db-action=replace --cleanup 2>&1)
printf '%s' "$OUT" | grep -q "CONTRÔLES : OK" && ok "contrôles de l'installeur : copie complète" || ko "contrôles de l'installeur" "$(printf '%s' "$OUT" | tail -6)"
check "lignes importées" "$(mysql_cmd -N big_src -e 'SELECT COUNT(*) FROM wp_posts')" "$(mysql_cmd -N big_dst -e 'SELECT COUNT(*) FROM wp_posts')"
check "métadonnées importées" "$(mysql_cmd -N big_src -e 'SELECT COUNT(*) FROM wp_postmeta')" "$(mysql_cmd -N big_dst -e 'SELECT COUNT(*) FROM wp_postmeta')"
check "table sans clé primaire" "$(mysql_cmd -N big_src -e 'SELECT COUNT(*), MD5(GROUP_CONCAT(c ORDER BY a)) FROM wp_sans_cle')" "$(mysql_cmd -N big_dst -e 'SELECT COUNT(*), MD5(GROUP_CONCAT(c ORDER BY a)) FROM wp_sans_cle')"
check "ligne géante : binaire intact" "$GEANT_BLOB" "$(mysql_cmd -N big_dst -e 'SELECT MD5(blob_data) FROM wp_geante')"
check "ligne géante : texte avec adresses remplacées" "$GEANT_TEXT" "$(mysql_cmd -N big_dst -e 'SELECT MD5(texte) FROM wp_geante')"
check "option de 5 Mo" "5000000" "$(mysql_cmd -N big_dst -e "SELECT LENGTH(option_value) FROM wp_options WHERE option_name='gros_reglage'")"
check "médias identiques" "" "$(diff -rq "$SRC/wp-content/uploads" "$DST/wp-content/uploads" | head -3)"

printf '\n%s\n' "$([ "$FAILURES" -eq 0 ] && echo 'Montée en charge réussie' || echo "$FAILURES échec(s)")"
[ "$FAILURES" -eq 0 ]
