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
require dirname( __DIR__ ) . '/includes/lib/class-wpmig-consistency.php';
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

echo "\nRechercher / remplacer\n";
$t = new WPMIG_Replacer( array( 'foo' => 'barbaz' ), array( 'boundary' => false, 'min' => 1 ) );
check( 'texte : sans limite de mot', 'xbarbazy barbaz', $t->replace( 'xfooy foo' ) );
check( 'texte : nombre de remplacements', 2, $t->count );
$ser = serialize( array( 'a' => 'un foo', 'foo' => 'clé', 'b' => array( 'c' => 'foofoo' ) ) );
$un  = unserialize( $t->replace( $ser ) );
check( 'texte : sérialisé recalculé, clés intactes', array( 'a' => 'un barbaz', 'foo' => 'clé', 'b' => array( 'c' => 'barbazbarbaz' ) ), $un );
$t = new WPMIG_Replacer( array( 'a' => 'b' ), array( 'boundary' => false, 'min' => 1 ) );
check( 'texte : un caractère', 'bbb', $t->replace( 'aab' ) );
$t = new WPMIG_Replacer( array( 'Société' => 'Entreprise' ), array( 'boundary' => false, 'min' => 1, 'ignore_case' => true ) );
check( 'casse ignorée, lettres accentuées comprises', 'Entreprise et Entreprise, ENTREPRISE ok', $t->replace( 'société et SOCIÉTÉ, ENTREPRISE ok' ) );
$t = new WPMIG_Replacer( array( 'abc' => 'Z' ), array( 'boundary' => false, 'min' => 1, 'ignore_case' => true ) );
check( 'casse ignorée (ASCII)', 'xZZZ', $t->replace( 'xabcABCAbc' ) );
$t = new WPMIG_Replacer( array( 'abc' => 'Z' ), array( 'boundary' => false, 'min' => 1 ) );
check( 'casse respectée par défaut', 'ABC Z', $t->replace( 'ABC abc' ) );
$t = WPMIG_Replacer::from_regex( '/(\d+)-(\w+)/', '$2:$1 \1 ${2} $$ \$' );
check( 'regex : groupes capturés', 'ab:12 12 ab $ $ et x:7 7 x $ $', $t->replace( '12-ab et 7-x' ) );
check( 'regex : nombre de remplacements', 2, $t->count );
check( 'regex : dans un sérialisé', array( 'k' => 'v:1 1 v $ $' ), unserialize( $t->replace( serialize( array( 'k' => '1-v' ) ) ) ) );
check( 'regex invalide refusée', null, WPMIG_Replacer::from_regex( '/(/', 'x' ) );
check( 'regex vide refusée', null, WPMIG_Replacer::from_regex( '', 'x' ) );
$t = new WPMIG_Replacer( WPMIG_Replacer::build_url_pairs( 'http://old.com', 'https://new.fr' ), array( 'ignore_case' => true ) );
check( 'URL : casse ignorée, mots entiers', 'https://new.fr/a HTTP://OLD.COMPANY.FR', $t->replace( 'HTTP://OLD.COM/a HTTP://OLD.COMPANY.FR' ) );

echo "\nContrôles de cohérence\n";
$base = array(
	'old_host'           => 'www.exemple.fr',
	'new_host'           => 'dev.exemple.fr',
	'permalinks'         => '/%postname%/',
	'rewrite_rules'      => false,
	'stylesheet'         => 'montheme',
	'theme_mods'         => serialize( array( 'nav_menu_locations' => array( 'primary' => 5, 'footer' => 0 ) ) ),
	'menus'              => array( 5, 9 ),
	'polylang'           => null,
	'polylang_languages' => array(),
	'wpml_settings'      => null,
	'icl'                => null,
	'source'             => null,
);
$find = function ( array $items, $id ) {
	foreach ( $items as $item ) {
		if ( $item['id'] === $id ) {
			return $item['status'];
		}
	}
	return null;
};
$items = WPMIG_Consistency::evaluate( $base );
check( 'menus : emplacement associé à un menu existant', 'ok', $find( $items, 'menus' ) );
check( 'permaliens : règles à régénérer signalées', 'info', $find( $items, 'permalinks' ) );
check( 'permaliens : règles présentes', 'ok', $find( WPMIG_Consistency::evaluate( array_merge( $base, array( 'rewrite_rules' => true ) ) ), 'permalinks' ) );
check( 'aucun contrôle de langue sans extension', null, $find( $items, 'wpml_links' ) );
$lost = WPMIG_Consistency::evaluate( array_merge( $base, array( 'menus' => array( 9 ) ) ) );
check( 'menus : menu absent signalé', 'warning', $find( $lost, 'menus' ) );
$pll = array(
	'default_lang' => 'fr',
	'force_lang'   => 3,
	'domains'      => array( 'fr' => 'https://dev.exemple.fr', 'en' => 'https://en.exemple.fr' ),
	'nav_menus'    => array( 'montheme' => array( 'primary' => array( 'fr' => 5, 'en' => 77 ) ) ),
);
$data = array_merge( $base, array( 'polylang' => serialize( $pll ), 'polylang_languages' => array( 'fr', 'en' ) ) );
$items = WPMIG_Consistency::evaluate( $data );
check( 'Polylang : menu d\'une langue absent', 'warning', $find( $items, 'menus' ) );
check( 'Polylang : domaine de langue resté sur l\'ancien site', 'warning', $find( $items, 'domains' ) );
check( 'Polylang : langue par défaut valide', 'ok', $find( $items, 'pll_default' ) );
$pll['default_lang'] = '';
check( 'Polylang : langue par défaut vide', 'warning', $find( WPMIG_Consistency::evaluate( array_merge( $data, array( 'polylang' => serialize( $pll ) ) ) ), 'pll_default' ) );
$pll['default_lang'] = 'de';
check( 'Polylang : langue par défaut inconnue', 'warning', $find( WPMIG_Consistency::evaluate( array_merge( $data, array( 'polylang' => serialize( $pll ) ) ) ), 'pll_default' ) );
$pll['domains'] = array( 'fr' => 'https://dev.exemple.fr', 'en' => 'https://autre-domaine.com' );
check( 'Polylang : domaine sans rapport avec l\'ancien site laissé tranquille', 'ok', $find( WPMIG_Consistency::evaluate( array_merge( $data, array( 'polylang' => serialize( $pll ) ) ) ), 'domains' ) );
$wpml = array(
	'default_language'          => 'fr',
	'language_negotiation_type' => 2,
	'language_domains'          => array( 'en' => 'en.exemple.fr', 'de' => 'www.exemple.fr' ),
	'site_key'                  => 'abc123',
);
$w = array_merge( $base, array( 'wpml_settings' => serialize( $wpml ), 'icl' => array( 'total' => 8, 'null' => 3, 'orphan' => 0 ) ) );
check( 'WPML : liens vides sans référence de l\'origine', 'info', $find( WPMIG_Consistency::evaluate( $w ), 'wpml_links' ) );
$same = array_merge( $w, array( 'source' => array( 'icl_total' => 8, 'icl_null' => 3, 'icl_orphan' => 0 ) ) );
check( 'WPML : liens vides déjà présents sur l\'origine', 'ok', $find( WPMIG_Consistency::evaluate( $same ), 'wpml_links' ) );
$diff = array_merge( $w, array( 'source' => array( 'icl_total' => 8, 'icl_null' => 0, 'icl_orphan' => 0 ) ) );
check( 'WPML : liens vides apparus depuis l\'origine', 'warning', $find( WPMIG_Consistency::evaluate( $diff ), 'wpml_links' ) );
check( 'WPML : domaines de langue restés sur l\'ancien site', 'warning', $find( WPMIG_Consistency::evaluate( $w ), 'domains' ) );
check( 'WPML : clé de site liée au domaine', 'info', $find( WPMIG_Consistency::evaluate( $w ), 'wpml_key' ) );
$wpml['default_language'] = '';
check( 'WPML : langue par défaut vide', 'warning', $find( WPMIG_Consistency::evaluate( array_merge( $w, array( 'wpml_settings' => serialize( $wpml ) ) ) ), 'wpml_default' ) );
check( 'profil de l\'origine sans WPML', null, WPMIG_Consistency::profile( $base ) );
check( 'profil de l\'origine avec WPML', array( 'icl_total' => 8, 'icl_null' => 3, 'icl_orphan' => 0 ), WPMIG_Consistency::profile( $w ) );
check( 'décompte des statuts', array( 'ok' => 1, 'warning' => 0, 'info' => 1 ), WPMIG_Consistency::counts( WPMIG_Consistency::evaluate( $base ) ) );
check( 'lecture sérialisée : booléen faux et null conservés', array( 'a' => false, 'b' => null, 'c' => array( 1 ) ), WPMIG_Consistency::parse( serialize( array( 'a' => false, 'b' => null, 'c' => array( 1 ) ) ) ) );
check( 'lecture sérialisée : objet refusé', null, WPMIG_Consistency::parse( 'O:8:"stdClass":1:{s:1:"a";i:1;}' ) );

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

echo "\nSynchronisation\n";
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'esc_sql' ) ) {
	function esc_sql( $s ) {
		return addslashes( $s );
	}
}
require dirname( __DIR__ ) . '/includes/class-wpmig-sync-source.php';
require dirname( __DIR__ ) . '/includes/class-wpmig-sync-db.php';
require dirname( __DIR__ ) . '/includes/class-wpmig-sync.php';
require dirname( __DIR__ ) . '/includes/class-wpmig-settings.php';
require dirname( __DIR__ ) . '/includes/class-wpmig-compare.php';
check( 'empreinte d\'un contenu', 'shop_order|2026-09-29 05:25:04', WPMIG_Sync_Source::fingerprint( 'orders', array( 'post_type' => 'shop_order', 'post_date_gmt' => '2026-09-29 05:25:04' ) ) );
check( 'empreinte d\'un client', 'client-1', WPMIG_Sync_Source::fingerprint( 'customers', array( 'user_login' => 'client-1' ) ) );
check( 'empreinte d\'un commentaire', '2026-09-29 05:25:07|marie@example.org', WPMIG_Sync_Source::fingerprint( 'comments', array( 'post_date_gmt' => '2026-09-29 05:25:07', 'comment_author_email' => 'Marie@Example.org' ) ) );
$binary  = array( 'a' => 'texte é', 'b' => "\xff\x00\xfe", 'c' => null, 'd' => '12' );
$encoded = WPMIG_Sync_Source::encode_row( $binary );
check( 'valeur binaire encodée', true, is_array( $encoded['b'] ) && isset( $encoded['b']['b64'] ) && 'texte é' === $encoded['a'] );
check( 'valeur binaire restituée', $binary, WPMIG_Sync_DB::decode_row( json_decode( json_encode( $encoded ), true ) ) );
check( 'condition SQL avec NULL', "`order_id` = '5' AND `parent` IS NULL", WPMIG_Sync_DB::where( array( 'order_id' => 5, 'parent' => null ) ) );
$valid = 'https://www.exemple.fr/wp-admin/admin-ajax.php?action=wpmig_sync&key=0123456789abcdef0123456789abcdef';
check( 'lien de synchronisation valide', $valid, WPMIG_Sync::check_link( ' ' . $valid . ' ' ) );
foreach ( array(
	'lien de transfert refusé' => 'https://www.exemple.fr/wp-admin/admin-ajax.php?action=wpmig_transfer&id=x&key=0123456789abcdef0123456789abcdef',
	'clé invalide refusée'     => 'https://www.exemple.fr/wp-admin/admin-ajax.php?action=wpmig_sync&key=zz',
	'protocole refusé'         => 'ftp://www.exemple.fr/wp-admin/admin-ajax.php?action=wpmig_sync&key=0123456789abcdef0123456789abcdef',
) as $name => $link ) {
	try {
		WPMIG_Sync::check_link( $link );
		check( $name, 'exception', 'acceptée' );
	} catch ( WPMIG_Exception $e ) {
		check( $name, 'exception', 'exception' );
	}
}
check( 'ordre d\'application : dépendances d\'abord', array( 'media', 'customers', 'products', 'coupons', 'posts', 'orders', 'comments' ), WPMIG_Sync::KINDS );

echo "\nRéglages repris d'un autre site\n";
if ( ! function_exists( 'size_format' ) ) {
	function size_format( $bytes ) {
		return $bytes . ' o';
	}
}
foreach ( array( 'siteurl', 'home', 'active_plugins', 'template', 'wpmig_report', 'wpmig_sync_link', '_transient_x', '_site_transient_timeout_y', 'wp_user_roles', 'wp_1514363_user_roles', 'woocommerce_db_version', 'db_version', 'woocommerce_version', "bad\nname" ) as $name ) {
	check( 'réglage protégé : ' . trim( $name ), true, '' !== WPMIG_Settings::protected_reason( $name ) );
}
foreach ( array( 'woocommerce_monetico_settings', 'polylang', 'icl_sitepress_settings', 'theme_mods_astra', 'widget_text', 'blogname', 'permalink_structure', 'woocommerce_currency' ) as $name ) {
	check( 'réglage copiable : ' . $name, '', WPMIG_Settings::protected_reason( $name ) );
}
check( 'autoload yes', 'yes', WPMIG_Settings::autoload( 'auto' ) );
check( 'autoload on', 'yes', WPMIG_Settings::autoload( 'on' ) );
check( 'autoload off', 'no', WPMIG_Settings::autoload( 'off' ) );
check( 'autoload auto-off', 'no', WPMIG_Settings::autoload( 'auto-off' ) );
check( 'nom de réglage ordinaire', false, WPMIG_Settings::secret_name( 'woocommerce_monetico_settings' ) );
check( 'clé secrète détectée', true, WPMIG_Settings::secret_name( 'api_key' ) );
check( 'clé banale', false, WPMIG_Settings::secret_name( 'title' ) );
check( 'renvoi par numéro signalé', true, '' !== WPMIG_Settings::references_note( 'woocommerce_shop_page_id' ) );
check( 'réglage sans renvoi', '', WPMIG_Settings::references_note( 'woocommerce_currency' ) );
$old = serialize( array( 'enabled' => 'no', 'title' => 'CB', 'api_key' => 'ancien', 'nested' => array( 'a' => 1, 'b' => 2 ) ) );
$new = serialize( array( 'enabled' => 'yes', 'title' => 'CB', 'api_key' => 'nouveau', 'nested' => array( 'a' => 1, 'b' => 3, 'c' => true ) ) );
$diff = WPMIG_Settings::changes( 'woocommerce_monetico_settings', $old, $new );
check( 'différences : nombre', 4, count( $diff ) );
check( 'différences : valeur lisible', 'enabled : « no » → « yes »', $diff[0] );
check( 'différences : secret masqué', 'api_key : •••••• → ••••••', implode( '|', preg_grep( '/^api_key/', $diff ) ) );
check( 'différences : clé ajoutée', 'nested.c : (absent) → true', implode( '|', preg_grep( '/^nested\.c/', $diff ) ) );
check( 'différence de texte simple', array( '« a » → « b »' ), WPMIG_Settings::changes( 'blogname', 'a', 'b' ) );
check( 'texte secret masqué', array( '•••••• → ••••••' ), WPMIG_Settings::changes( 'my_token', 'a', 'b' ) );
check( 'objet sérialisé non lu', array( '« O:8:"stdClass":0:{} » → « b »' ), WPMIG_Settings::changes( 'x', 'O:8:"stdClass":0:{}', 'b' ) );
check( 'valeurs longues résumées', array( '300 o → 400 o' ), WPMIG_Settings::changes( 'x', str_repeat( 'a', 300 ), str_repeat( 'b', 400 ) ) );

echo "\nComparaison de deux sites\n";
$here  = array(
	'site'      => array( 'Adresse' => 'http://dev.local', 'WordPress' => '6.8', 'PHP' => '8.2.1', 'Préfixe des tables' => 'wp_' ),
	'theme'     => array( 'stylesheet' => 'astra-child', 'template' => 'astra', 'name' => 'Astra enfant', 'version' => '1.0' ),
	'plugins'   => array(
		'a/a.php' => array( 'name' => 'Alpha', 'version' => '1.0', 'active' => 1 ),
		'b/b.php' => array( 'name' => 'Beta', 'version' => '2.0', 'active' => 1 ),
		'c/c.php' => array( 'name' => 'Gamma', 'version' => '1.0', 'active' => 0 ),
		'd/d.php' => array( 'name' => 'Delta', 'version' => '3.0', 'active' => 1 ),
	),
	'settings'  => array( 'woocommerce_currency' => array( 'label' => 'WooCommerce : devise', 'value' => 'EUR' ), 'posts_per_page' => array( 'label' => 'Articles par page', 'value' => '10' ) ),
	'gateways'  => array( 'woocommerce_cheque_settings' ),
	'languages' => array( 'plugin' => 'Polylang', 'default' => 'fr', 'active' => array( 'de', 'fr' ) ),
	'menus'     => array( 'count' => 2, 'locations' => 1 ),
	'counts'    => array( 'Articles publiés' => 10, 'Commandes' => 5 ),
);
$there = array(
	'site'      => array( 'Adresse' => 'https://www.prod.fr', 'WordPress' => '6.8', 'PHP' => '7.4.33', 'Préfixe des tables' => 'wp_prod_' ),
	'theme'     => array( 'stylesheet' => 'astra-child', 'template' => 'astra', 'name' => 'Astra enfant', 'version' => '1.0' ),
	'plugins'   => array(
		'a/a.php' => array( 'name' => 'Alpha', 'version' => '1.0', 'active' => 1 ),
		'b/b.php' => array( 'name' => 'Beta', 'version' => '2.1', 'active' => 1 ),
		'c/c.php' => array( 'name' => 'Gamma', 'version' => '1.0', 'active' => 1 ),
		'e/e.php' => array( 'name' => 'Epsilon', 'version' => '1.0', 'active' => 1 ),
	),
	'settings'  => array( 'woocommerce_currency' => array( 'label' => 'WooCommerce : devise', 'value' => 'EUR' ), 'posts_per_page' => array( 'label' => 'Articles par page', 'value' => '12' ) ),
	'gateways'  => array( 'woocommerce_cheque_settings', 'woocommerce_monetico_settings' ),
	'languages' => array( 'plugin' => 'Polylang', 'default' => 'fr', 'active' => array( 'de', 'en', 'fr' ) ),
	'menus'     => array( 'count' => 2, 'locations' => 2 ),
	'counts'    => array( 'Articles publiés' => 14, 'Commandes' => 5 ),
);
$cmp  = WPMIG_Compare::diff( $here, $there );
$rows = array();
foreach ( $cmp['sections'] as $section ) {
	foreach ( $section['rows'] as $row ) {
		$rows[ $section['title'] . '|' . $row['label'] ] = $row;
	}
}
check( 'origine lue', 'https://www.prod.fr', $cmp['source'] );
check( 'adresse différente : indicatif', 'info', $rows['Environnement|Adresse']['status'] );
check( 'préfixe différent : indicatif', 'info', $rows['Environnement|Préfixe des tables']['status'] );
check( 'PHP différent', 'diff', $rows['Environnement|PHP']['status'] );
check( 'WordPress identique', 'same', $rows['Environnement|WordPress']['status'] );
check( 'thème identique', 'same', $rows['Thème|Thème actif']['status'] );
check( 'extension identique', 'same', $rows['Extensions|Alpha']['status'] );
check( 'extension : version différente', 'diff', $rows['Extensions|Beta']['status'] );
check( 'extension : active ici, inactive là-bas', 'diff', $rows['Extensions|Gamma']['status'] );
check( 'extension absente de l\'autre site', 'only_here', $rows['Extensions|Delta']['status'] );
check( 'extension absente de ce site', 'only_there', $rows['Extensions|Epsilon']['status'] );
check( 'réglage identique', 'same', $rows['Réglages|WooCommerce : devise']['status'] );
check( 'réglage différent', 'diff', $rows['Réglages|Articles par page']['status'] );
check( 'réglage : nom d\'option conservé', 'posts_per_page', $rows['Réglages|Articles par page']['option'] );
$pay = array();
foreach ( $cmp['sections'][3]['rows'] as $row ) {
	if ( 'Moyen de paiement actif' === $row['label'] ) {
		$pay[ $row['option'] ] = $row['status'];
	}
}
check( 'moyens de paiement', array( 'woocommerce_cheque_settings' => 'same', 'woocommerce_monetico_settings' => 'only_there' ), $pay );
check( 'langues actives différentes', 'diff', $rows['Langues et menus|Langues actives']['status'] );
check( 'emplacements de menu différents', 'diff', $rows['Langues et menus|Emplacements de menu utilisés']['status'] );
check( 'compteurs différents : indicatif', 'info', $rows['Contenus|Articles publiés']['status'] );
check( 'compteurs identiques', 'same', $rows['Contenus|Commandes']['status'] );
check( 'total des différences à examiner', 9, $cmp['summary']['diff'] + $cmp['summary']['only_here'] + $cmp['summary']['only_there'] );
check( 'profil vide sans erreur', true, is_array( WPMIG_Compare::diff( array(), array() ) ) );

echo "\n" . ( $count - $failures ) . '/' . $count . " tests réussis\n";
exit( $failures ? 1 : 0 );
