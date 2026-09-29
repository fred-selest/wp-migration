<?php
/**
 * Unit tests of the shared library (no WordPress needed).
 *
 * Usage: php tests/run-tests.php
 *
 * @package WPMigration
 */

// The installer template defines WPMIG_INSTALLER and the wp-config editor.
ob_start();
require dirname( __DIR__ ) . '/installer/installer.php.tpl';
ob_end_clean();

require dirname( __DIR__ ) . '/includes/lib/class-wpmig-archive.php';
require dirname( __DIR__ ) . '/includes/lib/class-wpmig-replacer.php';
require dirname( __DIR__ ) . '/includes/lib/class-wpmig-sql.php';
require dirname( __DIR__ ) . '/includes/lib/class-wpmig-db-importer.php';

$failures = 0;
$count    = 0;

/**
 * Assertion helper.
 *
 * @param string $name     Test name.
 * @param mixed  $expected Expected.
 * @param mixed  $actual   Actual.
 */
function check( $name, $expected, $actual ) {
	global $failures, $count;
	$count++;
	if ( $expected === $actual ) {
		echo "  ok   $name\n";
		return;
	}
	$failures++;
	echo "  FAIL $name\n    attendu : " . var_export( $expected, true ) . "\n    obtenu  : " . var_export( $actual, true ) . "\n";
}

echo "Remplacements\n";
$pairs = WPMIG_Replacer::build_url_pairs( 'http://old.com', 'https://new.fr/site' )
	+ WPMIG_Replacer::build_path_pairs( '/home/old/public_html', '/var/www/new' );
$r     = new WPMIG_Replacer( $pairs );

check( 'URL simple', '<a href="https://new.fr/site/page">', $r->replace( '<a href="http://old.com/page">' ) );
check( 'https -> nouvelle URL', 'https://new.fr/site/x', $r->replace( 'https://old.com/x' ) );
check( 'protocole relatif', '<img src="//new.fr/site/a.png">', $r->replace( '<img src="//old.com/a.png">' ) );
check( 'JSON échappé', '{"u":"https:\/\/new.fr\/site\/a"}', $r->replace( '{"u":"http:\/\/old.com\/a"}' ) );
check( 'URL encodée', 'u=https%3A%2F%2Fnew.fr%2Fsite%2Fa', $r->replace( 'u=http%3A%2F%2Fold.com%2Fa' ) );
check( 'domaine plus long non touché', 'http://old.com.au/ http://old.company.fr', $r->replace( 'http://old.com.au/ http://old.company.fr' ) );
check( 'fin de phrase', 'Voir https://new.fr/site.', $r->replace( 'Voir http://old.com.' ) );
check( 'e-mail non touché', 'contact@old.com', $r->replace( 'contact@old.com' ) );
check( 'chemin', '/var/www/new/wp-content/uploads', $r->replace( '/home/old/public_html/wp-content/uploads' ) );
check( 'chemin plus long non touché', '/home/old/public_html_backup', $r->replace( '/home/old/public_html_backup' ) );
check( 'chemin inclus non touché', '/x/home/old/public_html', $r->replace( '/x/home/old/public_html' ) );

$data = array(
	'url'    => 'http://old.com/wp-content/uploads/a.jpg',
	'nested' => array( 'html' => '<a href="http://old.com">lien</a> é' ),
	'num'    => 42,
	'float'  => 1.5,
	'bool'   => true,
	'null'   => null,
	'path'   => '/home/old/public_html/wp-content',
);
$ser = serialize( $data );
$out = $r->replace( $ser );
$un  = unserialize( $out );
check( 'sérialisé : unserialize OK', true, is_array( $un ) );
check( 'sérialisé : URL', 'https://new.fr/site/wp-content/uploads/a.jpg', $un['url'] );
check( 'sérialisé : imbriqué', '<a href="https://new.fr/site">lien</a> é', $un['nested']['html'] );
check( 'sérialisé : types conservés', array( 42, 1.5, true, null ), array( $un['num'], $un['float'], $un['bool'], $un['null'] ) );
check( 'sérialisé : chemin', '/var/www/new/wp-content', $un['path'] );

$double = serialize( array( 'inner' => serialize( array( 'u' => 'http://old.com/x' ) ) ) );
$un     = unserialize( $r->replace( $double ) );
$inner  = unserialize( $un['inner'] );
check( 'double sérialisation', 'https://new.fr/site/x', $inner['u'] );

$obj        = new stdClass();
$obj->url   = 'http://old.com/obj';
$obj->list  = array( 'http://old.com/1', 'http://old.com/2' );
$ser        = serialize( array( 'o' => $obj ) );
$un         = unserialize( $r->replace( $ser ) );
check( 'objet sérialisé', 'https://new.fr/site/obj', $un['o']->url );
check( 'objet sérialisé : tableau', 'https://new.fr/site/2', $un['o']->list[1] );

// Object of a class that does not exist here (the plugin never unserializes).
$foreign = 'a:1:{s:1:"w";O:12:"Some_Widget_":2:{s:5:"title";s:3:"Hey";s:3:"url";s:18:"http://old.com/abc";}}';
$res     = $r->replace( $foreign );
check( 'objet de classe inconnue', 'a:1:{s:1:"w";O:12:"Some_Widget_":2:{s:5:"title";s:3:"Hey";s:3:"url";s:23:"https://new.fr/site/abc";}}', $res );

$ser = serialize( array( 'k' => 'http://old.com', 'x' => "multi\nligne \"quote\" ; } http://old.com/y" ) );
$un  = unserialize( $r->replace( $ser ) );
check( 'caractères spéciaux dans sérialisé', "multi\nligne \"quote\" ; } https://new.fr/site/y", $un['x'] );

$broken = 'a:1:{s:3:"url";s:99:"http://old.com/x";}';
check( 'sérialisé corrompu : remplacement simple', 'a:1:{s:3:"url";s:99:"https://new.fr/site/x";}', $r->replace( $broken ) );

$r2 = new WPMIG_Replacer( WPMIG_Replacer::build_url_pairs( 'http://a.com', 'http://a.com/sub' ) );
check( 'pas de double remplacement', 'http://a.com/sub/page //a.com/sub/x', $r2->replace( 'http://a.com/page //a.com/x' ) );

$r3 = new WPMIG_Replacer( WPMIG_Replacer::build_url_pairs( 'https://www.site.fr/', 'http://localhost:8080' ) );
check( 'https -> http localhost', 'http://localhost:8080/?p=1', $r3->replace( 'https://www.site.fr/?p=1' ) );

check( 'variante www ajoutée', 'https://www.site.fr/blog', WPMIG_Replacer::www_variant( 'https://site.fr/blog' ) );
check( 'variante www retirée', 'http://site.fr:8080', WPMIG_Replacer::www_variant( 'http://www.site.fr:8080' ) );
check( 'pas de variante pour localhost / IP', array( null, null ), array( WPMIG_Replacer::www_variant( 'http://localhost:8081' ), WPMIG_Replacer::www_variant( 'http://192.168.1.10' ) ) );
$r4 = new WPMIG_Replacer( WPMIG_Replacer::build_url_pairs( 'https://www.site.fr', 'https://neuf.fr' ) + WPMIG_Replacer::build_url_pairs( WPMIG_Replacer::www_variant( 'https://www.site.fr' ), 'https://neuf.fr' ) );
check( 'remplacement des deux variantes', 'https://neuf.fr/a https://neuf.fr/b', $r4->replace( 'https://www.site.fr/a http://site.fr/b' ) );

echo "\nSQL\n";
$samples = array( '', 'simple', "l'apostrophe", 'back\\slash', "nul\0byte", "ligne\nnouvelle\r\n", "ctrl\x1a", '"guillemets"', "émoji 😀", "\\'", "''" );
foreach ( $samples as $i => $s ) {
	check( 'échappement #' . $i, $s, WPMIG_SQL::unescape( WPMIG_SQL::escape( $s ) ) );
}
$bin = '';
for ( $i = 0; $i < 256; $i++ ) {
	$bin .= chr( $i );
}
check( 'échappement binaire', $bin, WPMIG_SQL::unescape( WPMIG_SQL::escape( $bin ) ) );

$rows = array(
	array( array( 'r', '1' ), array( 's', "a,b)(c'd\\e\nf" ), array( 'r', 'NULL' ), array( 'r', '0xDEADBEEF' ) ),
	array( array( 'r', '2' ), array( 's', '' ), array( 'r', '-3.5' ), array( 's', ';' ) ),
);
$sql    = 'INSERT INTO `wp_t` (`id`,`txt`,`n`,`b`) VALUES ' . WPMIG_SQL::build_row( $rows[0] ) . ',' . WPMIG_SQL::build_row( $rows[1] ) . ';';
$parsed = WPMIG_SQL::parse_insert( $sql );
check( 'analyse INSERT : table', 'wp_t', $parsed['table'] );
check( 'analyse INSERT : colonnes', array( 'id', 'txt', 'n', 'b' ), $parsed['columns'] );
check( 'analyse INSERT : lignes', $rows, $parsed['rows'] );
check( 'analyse INSERT invalide', null, WPMIG_SQL::parse_insert( "INSERT INTO `t` VALUES ('abc" ) );

echo "\nArchive\n";
$tmp = sys_get_temp_dir() . '/wpmig-test-' . getmypid();
@mkdir( $tmp );
$file   = $tmp . '/a.wpmig';
$big    = str_repeat( 'Lorem ipsum dolor sit amet ' . mt_rand(), 100000 ); // ~3 Mo : plusieurs blocs.
$random = function_exists( 'random_bytes' ) ? random_bytes( 1500000 ) : openssl_random_pseudo_bytes( 1500000 );
$w      = new WPMIG_Archive_Writer( $file, 0 );
$w->add_string( '__wpmig__/manifest.json', '{"a":1}' );
$w->begin_entry( 'dir/sub', 'd', 1700000000, 0755 );
$w->begin_entry( 'dir/sub/big.txt', 'f', 1700000000, 0644 );
for ( $i = 0; $i < strlen( $big ); $i += WPMIG_Archive::BLOCK_SIZE ) {
	$w->write_block( substr( $big, $i, WPMIG_Archive::BLOCK_SIZE ), true );
}
$w->end_entry();
$committed = $w->tell();
// Simulate an interrupted request: garbage after the committed offset is truncated on resume.
$w->write_block( 'garbage', false );
$w->close();
$w = new WPMIG_Archive_Writer( $file, $committed );
$w->begin_entry( 'random.bin', 'f', 1700000000, 0644 );
$w->write_block( substr( $random, 0, WPMIG_Archive::BLOCK_SIZE ), true );
$w->write_block( substr( $random, WPMIG_Archive::BLOCK_SIZE ), true );
$w->end_entry();
$w->add_string( 'empty.txt', '' );
check( 'archive incomplète détectée', null, WPMIG_Archive::read_trailer( $file ) );
$w->finish( array( 'files' => 3 ) );
$w->close();
check( 'fin d\'archive', array( 'files' => 3 ), WPMIG_Archive::read_trailer( $file ) );

$reader  = new WPMIG_Archive_Reader( $file );
$entries = array();
while ( null !== ( $e = $reader->next_entry() ) ) {
	$entries[ $e['path'] ] = 'd' === $e['type'] ? 'DIR' : $reader->read_all_blocks();
}
check( 'entrées', array( '__wpmig__/manifest.json', 'dir/sub', 'dir/sub/big.txt', 'random.bin', 'empty.txt' ), array_keys( $entries ) );
check( 'contenu texte', true, $big === $entries['dir/sub/big.txt'] );
check( 'contenu binaire', true, $random === $entries['random.bin'] );
check( 'fichier vide', '', $entries['empty.txt'] );
check( 'compression effective', true, filesize( $file ) < strlen( $big ) / 5 + strlen( $random ) + 10000 );

// Corruption.
$raw = file_get_contents( $file );
$pos = strpos( $raw, 'random.bin' ) + 200;
$raw[ $pos ] = chr( ord( $raw[ $pos ] ) ^ 0xFF );
file_put_contents( $tmp . '/bad.wpmig', $raw );
$error = '';
try {
	$reader = new WPMIG_Archive_Reader( $tmp . '/bad.wpmig' );
	while ( null !== ( $e = $reader->next_entry() ) ) {
		if ( 'f' === $e['type'] ) {
			$reader->read_all_blocks();
		}
	}
} catch ( WPMIG_Exception $ex ) {
	$error = $ex->getMessage();
}
check( 'corruption détectée', true, false !== strpos( $error, 'corrompue' ) );
file_put_contents( $tmp . '/cut.wpmig', substr( file_get_contents( $file ), 0, 5000 ) );
check( 'troncature détectée', null, WPMIG_Archive::read_trailer( $tmp . '/cut.wpmig' ) );
check( 'chemins sûrs', array( true, false, false, false, false ), array(
	WPMIG_Archive::is_safe_path( 'wp-content/a.php' ),
	WPMIG_Archive::is_safe_path( '../etc/passwd' ),
	WPMIG_Archive::is_safe_path( '/etc/passwd' ),
	WPMIG_Archive::is_safe_path( 'a/../../b' ),
	WPMIG_Archive::is_safe_path( 'C:/x' ),
) );
array_map( 'unlink', glob( $tmp . '/*' ) );
rmdir( $tmp );

echo "\nwp-config.php\n";
$config = <<<'PHP'
<?php
define( 'DB_NAME', 'old_db' );
define('DB_USER', "old_user");
define( 'DB_PASSWORD', 'p@ss;w)o\'rd' );
define( 'DB_HOST', 'localhost' );
define( 'WP_HOME', 'http://old.com' );
define( 'COOKIE_DOMAIN', 'old.com' );
define( 'WP_SITEURL', 'http://' . $_SERVER['HTTP_HOST'] );
$table_prefix = 'wp_';
define( 'WP_TEMP_DIR', '/home/old/public_html/tmp' );
/* That's all, stop editing! Happy publishing. */
require_once ABSPATH . 'wp-settings.php';
PHP;
$ed = new WPMIG_Config_Editor( $config );
check( 'lecture DB_PASSWORD', "p@ss;w)o'rd", $ed->get_define( 'DB_PASSWORD' ) );
check( 'lecture DB_USER (guillemets doubles)', 'old_user', $ed->get_define( 'DB_USER' ) );
check( 'valeur dynamique ignorée', null, $ed->get_define( 'WP_SITEURL' ) );
check( 'préfixe', 'wp_', $ed->get_prefix() );
$ed->transform( array( $r, 'replace_plain' ) );
$ed->set_define( 'DB_NAME', var_export( 'new_db', true ) );
$ed->set_define( 'DB_PASSWORD', var_export( "n'ew$\\", true ) );
$ed->set_define( 'WP_HOME', var_export( 'https://new.fr/site', true ) );
$ed->set_define( 'FS_METHOD', "'direct'" );
$ed->remove_define( 'COOKIE_DOMAIN' );
$ed->set_prefix( 'abc_' );
check( 'syntaxe valide', true, $ed->lint() );
$ed2 = new WPMIG_Config_Editor( $ed->code() );
check( 'DB_NAME modifié', 'new_db', $ed2->get_define( 'DB_NAME' ) );
check( 'DB_PASSWORD modifié', "n'ew$\\", $ed2->get_define( 'DB_PASSWORD' ) );
check( 'WP_HOME modifié', 'https://new.fr/site', $ed2->get_define( 'WP_HOME' ) );
check( 'constante ajoutée', 'direct', $ed2->get_define( 'FS_METHOD' ) );
check( 'constante ajoutée avant le marqueur', true, strpos( $ed->code(), 'FS_METHOD' ) < strpos( $ed->code(), "That's all" ) );
check( 'COOKIE_DOMAIN supprimé', null, $ed2->get_define( 'COOKIE_DOMAIN' ) );
check( 'chemin remplacé', '/var/www/new/tmp', $ed2->get_define( 'WP_TEMP_DIR' ) );
check( 'nouveau préfixe', 'abc_', $ed2->get_prefix() );
$skeleton = new WPMIG_Config_Editor( WPMIG_Config_Editor::skeleton() );
check( 'squelette valide', true, $skeleton->lint() );

echo "\nRapport de migration\n";
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
// Minimal stand-ins for the WordPress functions used by the report.
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $n ) {
		return number_format( (float) $n, 0, ',', ' ' );
	}
}
if ( ! function_exists( 'wpmig_size' ) ) {
	function wpmig_size( $bytes ) {
		return round( $bytes / 1048576, 1 ) . ' Mo';
	}
}
if ( ! function_exists( 'wpmig_date' ) ) {
	function wpmig_date( $ts ) {
		return gmdate( 'd/m/Y H:i', (int) $ts );
	}
}
require dirname( __DIR__ ) . '/includes/class-wpmig-report.php';
$report = array(
	'package'      => array( 'id' => '20260101_000000_abcdef012345', 'name' => 'site', 'created' => '2026-01-01 00:00:00', 'archive_size' => 1048576 ),
	'source'       => array( 'home' => 'https://old.fr', 'prefix' => 'wp_1514363_', 'db' => '10.11' ),
	'destination'  => array( 'home' => 'https://new.fr', 'prefix' => 'wp_', 'db' => '11.4', 'db_name' => 'base', 'db_host' => 'localhost' ),
	'options'      => array( 'db_action' => 'replace' ),
	'started'      => 1000,
	'finished'     => 1125,
	'mode'         => 'cli',
	'transfer'     => array( 'size' => 1048576, 'seconds' => 3 ),
	'checks'       => array( 'ok' => false, 'issues' => array( '1 table(s) absente(s) ou avec un nombre de lignes différent de la source : wp_1514363_posts.' ), 'verified' => true, 'files_expected' => 10, 'files' => 10, 'files_failed' => 0, 'bytes' => 2048, 'tables' => 5, 'tables_bad' => 2, 'rows_exported' => 20, 'rows_imported' => 18, 'sql_queries' => 9, 'sql_errors' => 0, 'broken' => 0 ),
	'tables'       => array(
		array( 'wp_1514363_options', 10, 10, 0 ),
		array( 'wp_1514363_posts', 8, 6, 0 ),
		array( 'wp_1514363_umbrella_log', 0, 0, 1 ),
		array( 'wp_1514363_old', null, 4, 0 ),
		array( 'wp_1514363_lost', 2, null, 0 ),
	),
	'replacements' => array( array( 'https://old.fr', 'https://new.fr' ) ),
	'excluded'     => array( 'wp-content/debug.log (journal de débogage, 587,8 Mo)' ),
	'warnings'     => array(),
	'notices'      => array(),
	'log'          => "ligne 1\nligne 2\n",
);
$tables   = WPMIG_Report::tables( $report );
$statuses = array();
foreach ( $tables as $t ) {
	$statuses[ $t['name'] ] = $t['status'];
}
check( 'statuts des tables (préfixe renommé)', array( 'wp_options' => 'ok', 'wp_posts' => 'diff', 'wp_umbrella_log' => 'empty', 'wp_old' => 'unknown', 'wp_lost' => 'missing' ), $statuses );
check( 'libellé de l\'écart', 'Écart de -2 ligne(s)', $tables[1]['label'] );
$rows = array();
foreach ( WPMIG_Report::checks( $report ) as $row ) {
	$rows[ $row[0] ] = $row[2];
}
check( 'contrôles', array( 'Intégrité de l\'archive' => true, 'Fichiers' => true, 'Tables' => false, 'Lignes' => false, 'Requêtes SQL' => true ), $rows );
check( 'durée', '2 min 05 s', WPMIG_Report::duration( 125 ) );
$text = WPMIG_Report::to_text( $report );
check( 'texte : résultat', true, false !== strpos( $text, 'RÉSULTAT : 1 point(s) à vérifier.' ) );
check( 'texte : table en écart', true, (bool) preg_match( '/^wp_posts\s+8\s+6  Écart de -2 ligne\(s\)$/mu', $text ) );
check( 'texte : colonnes alignées malgré les accents', true, (bool) preg_match( '/^\[OK\] Intégrité de l\'archive {4}Sommes/mu', $text ) );
check( 'texte : exclusions et journal', true, false !== strpos( $text, 'debug.log' ) && false !== strpos( $text, "ligne 1\nligne 2" ) );

echo "\nMises à jour\n";
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	}
}
require dirname( __DIR__ ) . '/includes/class-wpmig-updater.php';
check(
	'notes de version en HTML',
	'<h5>Ajouté</h5><ul><li><strong>Rapport</strong> et <code>wp migration report</code></li><li>a &lt;b&gt;</li></ul><p>Fin.</p>',
	WPMIG_Updater::markdown( "### Ajouté\n- **Rapport** et `wp migration report`\n- a <b>\n\nFin." )
);
check( 'liste en fin de texte fermée', '<ul><li>x</li></ul>', WPMIG_Updater::markdown( '* x' ) );
$header = file_get_contents( dirname( __DIR__ ) . '/wp-migration.php' );
check( 'en-tête Update URI', 1, preg_match( '/^ \* Update URI:\s+https:\/\/github\.com\/' . preg_quote( WPMIG_Updater::REPO, '/' ) . '$/m', $header ) );

echo "\n" . ( $count - $failures ) . '/' . $count . " tests réussis\n";
exit( $failures ? 1 : 0 );
