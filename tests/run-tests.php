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
check( 'liste des constantes', array( 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'WP_HOME', 'COOKIE_DOMAIN', 'WP_SITEURL', 'WP_TEMP_DIR' ), ( new WPMIG_Config_Editor( $config ) )->define_names() );
check( 'liste des constantes : guillemets doubles et doublons', array( 'A_B', 'C' ), ( new WPMIG_Config_Editor( "<?php define(\"A_B\", 1); define( 'C', 2 ); define('A_B', 3); // define('COMMENTE'" ) )->define_names() );

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
require dirname( __DIR__ ) . '/includes/class-wpmig-s3.php';
require dirname( __DIR__ ) . '/includes/class-wpmig-schedule.php';
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
check( 'ordre d\'application : dépendances d\'abord', array( 'media', 'customers', 'products', 'coupons', 'posts', 'custom', 'orders', 'comments' ), WPMIG_Sync::KINDS );

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

echo "\nSauvegardes planifiées\n";
$utc   = new DateTimeZone( 'UTC' );
$paris = new DateTimeZone( 'Europe/Paris' );
$at    = function ( $date, $tz ) {
	$d = new DateTime( $date, $tz );
	return $d->getTimestamp();
};
$show = function ( $ts, $tz ) {
	$d = new DateTime( '@' . $ts );
	$d->setTimezone( $tz );
	return $d->format( 'Y-m-d H:i' );
};
$daily = array( 'frequency' => 'daily', 'hour' => 3, 'weekday' => 1 );
check( 'quotidienne : le jour même si l\'heure n\'est pas passée', '2026-10-08 03:00', $show( WPMIG_Schedule::next_run( $daily, $at( '2026-10-08 01:30', $utc ), $utc ), $utc ) );
check( 'quotidienne : lendemain si l\'heure est passée', '2026-10-09 03:00', $show( WPMIG_Schedule::next_run( $daily, $at( '2026-10-08 03:00', $utc ), $utc ), $utc ) );
check( 'quotidienne : fuseau du site', '2026-10-09 03:00', $show( WPMIG_Schedule::next_run( $daily, $at( '2026-10-08 12:00', $paris ), $paris ), $paris ) );
check( 'quotidienne : passage à l\'heure d\'hiver (heure locale conservée)', '2026-10-26 03:00', $show( WPMIG_Schedule::next_run( $daily, $at( '2026-10-25 12:00', $paris ), $paris ), $paris ) );
$weekly = array( 'frequency' => 'weekly', 'hour' => 2, 'weekday' => 1 );
check( 'hebdomadaire : prochain lundi (2026-10-08 est un jeudi)', '2026-10-12 02:00', $show( WPMIG_Schedule::next_run( $weekly, $at( '2026-10-08 12:00', $utc ), $utc ), $utc ) );
check( 'hebdomadaire : le jour même avant l\'heure', '2026-10-12 02:00', $show( WPMIG_Schedule::next_run( $weekly, $at( '2026-10-12 01:00', $utc ), $utc ), $utc ) );
check( 'hebdomadaire : semaine suivante après l\'heure', '2026-10-19 02:00', $show( WPMIG_Schedule::next_run( $weekly, $at( '2026-10-12 02:00', $utc ), $utc ), $utc ) );
$sunday = array( 'frequency' => 'weekly', 'hour' => 23, 'weekday' => 0 );
check( 'hebdomadaire : dimanche', '2026-10-11 23:00', $show( WPMIG_Schedule::next_run( $sunday, $at( '2026-10-08 12:00', $utc ), $utc ), $utc ) );
$monthly = array( 'frequency' => 'monthly', 'hour' => 4, 'weekday' => 1 );
check( 'mensuelle : le 1er du mois suivant', '2026-11-01 04:00', $show( WPMIG_Schedule::next_run( $monthly, $at( '2026-10-08 12:00', $utc ), $utc ), $utc ) );
check( 'mensuelle : le 1er avant l\'heure', '2026-10-01 04:00', $show( WPMIG_Schedule::next_run( $monthly, $at( '2026-10-01 01:00', $utc ), $utc ), $utc ) );
check( 'mensuelle : changement d\'année', '2027-01-01 04:00', $show( WPMIG_Schedule::next_run( $monthly, $at( '2026-12-15 10:00', $utc ), $utc ), $utc ) );
check( 'types de sauvegarde', array( 'full', 'nouploads', 'db' ), array_keys( WPMIG_Schedule::types() ) );

echo "\nCompatibilité PHP 8.5\n";
$deprecated = array();
$files      = array_merge( glob( dirname( __DIR__ ) . '/*.php' ), glob( dirname( __DIR__ ) . '/includes/*.php' ), glob( dirname( __DIR__ ) . '/includes/lib/*.php' ), array( dirname( __DIR__ ) . '/installer/installer.php.tpl' ) );
foreach ( $files as $file ) {
	foreach ( token_get_all( file_get_contents( $file ) ) as $token ) {
		// PHP 8.5 emits a deprecation as soon as the name appears in the code, even in a branch never run.
		if ( is_array( $token ) && T_VARIABLE === $token[0] && '$http_response_header' === $token[1] ) {
			$deprecated[] = basename( $file ) . ':' . $token[2];
		}
	}
}
check( 'aucune variable $http_response_header dans le code', array(), $deprecated );

echo "\nStockage S3 (signature V4)\n";
$s3_access = 'AKIAIOSFODNN7EXAMPLE';
$s3_secret = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
$s3_empty  = hash( 'sha256', '' );
// Exemples publiés dans la documentation d'AWS.
$sig = WPMIG_S3::sign( 'GET', '/test.txt', array(), array( 'host' => 'examplebucket.s3.amazonaws.com', 'range' => 'bytes=0-9', 'x-amz-content-sha256' => $s3_empty, 'x-amz-date' => '20130524T000000Z' ), $s3_empty, $s3_access, $s3_secret, 'us-east-1', '20130524T000000Z' );
check( 'AWS : signature d\'une requête (en-tête Authorization)', 'f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', $sig['signature'] );
check( 'AWS : en-têtes signés', 'host;range;x-amz-content-sha256;x-amz-date', $sig['signed_headers'] );
$q = array( 'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256', 'X-Amz-Credential' => $s3_access . '/20130524/us-east-1/s3/aws4_request', 'X-Amz-Date' => '20130524T000000Z', 'X-Amz-Expires' => '86400', 'X-Amz-SignedHeaders' => 'host' );
check( 'AWS : signature d\'une URL pré-signée', 'aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404', WPMIG_S3::sign_query( 'GET', '/test.txt', $q, 'examplebucket.s3.amazonaws.com', $s3_secret, 'us-east-1', '20130524T000000Z' ) );
// Cas calculés avec la bibliothèque officielle botocore (accents, espaces, esperluette, port, paramètres).
foreach ( array(
	array( 'stamp' => '20261008T114144Z', 'method' => 'GET', 'url' => 'http://127.0.0.1:9000/bucket', 'query' => array( 'list-type' => '2', 'max-keys' => '1', 'prefix' => 'wp migration/é' ), 'extra' => array(), 'sha' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', 'auth' => 'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20261008/eu-west-3/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=40ba8cf90f8a62f56546e7f0b8c44527a378e65642a4e2b7cbe47773c6461a52' ),
	array( 'stamp' => '20261008T114144Z', 'method' => 'PUT', 'url' => 'http://127.0.0.1:9000/bucket/wp-migration/2026/%C3%A9%20%C3%A0%20%C3%BC%20%26%20x%2B%7E.txt', 'query' => array(), 'extra' => array( 'content-md5' => '8CNolFcm1fwqFOtXb3J2wA==' ), 'sha' => '2cb4b1431b84ec15d35ed83bb927e27e8967d75f4bcd9cc4b25c8d879ae23e18', 'auth' => 'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20261008/eu-west-3/s3/aws4_request, SignedHeaders=content-md5;host;x-amz-content-sha256;x-amz-date, Signature=a043fc82492fa417c44919743906738f12f4dfd20dddf1957ba135cd772a6a5c' ),
	array( 'stamp' => '20261008T114144Z', 'method' => 'PUT', 'url' => 'https://s3.example.fr/bucket/a/b.bin', 'query' => array( 'partNumber' => '3', 'uploadId' => 'ab/cd+ef==' ), 'extra' => array( 'content-md5' => 'OYUz1IER6fZksfZMsQxLYw==' ), 'sha' => '44f8354494a5ba03ba1792a8d3e9c534c47a9181980fde7a3f44b06ef2ae7c7f', 'auth' => 'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20261008/eu-west-3/s3/aws4_request, SignedHeaders=content-md5;host;x-amz-content-sha256;x-amz-date, Signature=9ede2c9bd56f2627e56db4bdefecb006a04e4135fa15d05342a4b1c628b507ad' ),
	array( 'stamp' => '20261008T114144Z', 'method' => 'POST', 'url' => 'https://s3.example.fr/bucket/a/b.bin', 'query' => array( 'uploads' => '' ), 'extra' => array( 'content-type' => 'application/octet-stream' ), 'sha' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', 'auth' => 'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20261008/eu-west-3/s3/aws4_request, SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date, Signature=464d77acd4f5ad65c050745b40a94aefdba28835b12f64a232d607b63190c259' ),
	array( 'stamp' => '20261008T114144Z', 'method' => 'DELETE', 'url' => 'https://s3.example.fr:8443/bucket/a/b.bin', 'query' => array( 'uploadId' => 'XYZ' ), 'extra' => array(), 'sha' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', 'auth' => 'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20261008/eu-west-3/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=806f87bbf564594e0f9f6a340cd4da6866714f2e663243e2b332640f45021e1b' ),
) as $i => $c ) {
	$parts   = parse_url( $c['url'] );
	$headers = array_merge( $c['extra'], array( 'host' => $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ), 'x-amz-content-sha256' => $c['sha'], 'x-amz-date' => $c['stamp'] ) );
	$r       = WPMIG_S3::sign( $c['method'], $parts['path'], $c['query'], $headers, $c['sha'], 'AKIDEXAMPLE', 'SECRETEXAMPLEKEY123', 'eu-west-3', $c['stamp'] );
	check( 'botocore : ' . $c['method'] . ' ' . urldecode( $parts['path'] ) . ( $c['query'] ? ' ?' . http_build_query( $c['query'] ) : '' ), $c['auth'], $r['authorization'] );
}
check( 'chemin encodé (accents, espaces, esperluette, +, ~)', 'dossier/%C3%A9%20%C3%A0%20%26%20x%2B~.txt', WPMIG_S3::encode_path( 'dossier/é à & x+~.txt' ) );
check( 'requête canonique triée et encodée', 'a=1&b=%20&prefix=sites%2Fdev%2F&uploads=', WPMIG_S3::canonical_query( array( 'uploads' => '', 'prefix' => 'sites/dev/', 'b' => ' ', 'a' => '1' ) ) );

// Réglages : validation (get_option / update_option simulés).
$GLOBALS['__s3_opts'] = array();
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return isset( $GLOBALS['__s3_opts'][ $name ] ) ? $GLOBALS['__s3_opts'][ $name ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['__s3_opts'][ $name ] = $value;
		return true;
	}
}
$saved = WPMIG_S3::save_settings( array( 'endpoint' => 'https://s3.exemple.fr/', 'region' => 'fr-par', 'bucket' => 'sauvegardes', 'prefix' => '/wp-migration/', 'access_key' => 'AKIA', 'secret_key' => 'secret-1', 'keep' => 7 ) );
check( 'réglages : adresse sans barre finale', 'https://s3.exemple.fr', $saved['endpoint'] );
check( 'réglages : dossier nettoyé', 'wp-migration', $saved['prefix'] );
check( 'réglages : service configuré', true, WPMIG_S3::configured() );
$saved = WPMIG_S3::save_settings( array( 'endpoint' => 'https://s3.exemple.fr', 'bucket' => 'sauvegardes', 'access_key' => 'AKIA', 'secret_key' => '' ) );
check( 'réglages : clé secrète vide = inchangée', 'secret-1', $saved['secret_key'] );
foreach ( array(
	'adresse avec un chemin refusée' => array( 'endpoint' => 'https://s3.exemple.fr/chemin', 'bucket' => 'b' ),
	'adresse sans protocole refusée' => array( 'endpoint' => 's3.exemple.fr', 'bucket' => 'b' ),
	'bucket invalide refusé'         => array( 'endpoint' => 'https://s3.exemple.fr', 'bucket' => 'mauvais bucket!' ),
	'dossier avec .. refusé'         => array( 'endpoint' => 'https://s3.exemple.fr', 'bucket' => 'bucket', 'prefix' => '../autre' ),
) as $name => $input ) {
	try {
		WPMIG_S3::save_settings( $input );
		check( $name, 'exception', 'acceptée' );
	} catch ( WPMIG_Exception $e ) {
		check( $name, 'exception', 'exception' );
	}
}

echo "\nStockage fourni par SSM\n";
// Doubles de WordPress : filtres, erreurs et transport HTTP simulé (aucun réseau).
$GLOBALS['__filters']  = array();
$GLOBALS['__http']     = array();
$GLOBALS['__http_fn']  = null;
$GLOBALS['__sign']     = array();
if ( ! defined( 'WPMIG_VERSION' ) ) {
	define( 'WPMIG_VERSION', '0.0.0-test' );
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		if ( empty( $GLOBALS['__filters'][ $tag ] ) ) {
			return $value;
		}
		$args = func_get_args();
		array_shift( $args );
		return call_user_func_array( $GLOBALS['__filters'][ $tag ], $args );
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $url, $args = array() ) {
		$GLOBALS['__http'][] = array( 'url' => $url, 'args' => $args );
		return call_user_func( $GLOBALS['__http_fn'], $url, $args );
	}
	function wp_remote_retrieve_response_code( $res ) {
		return $res['code'];
	}
	function wp_remote_retrieve_headers( $res ) {
		return $res['headers'];
	}
	function wp_remote_retrieve_body( $res ) {
		return $res['body'];
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		unset( $GLOBALS['__s3_opts'][ $name ] );
		return true;
	}
}
require_once dirname( __DIR__ ) . '/includes/class-wpmig-package.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmig-plugin.php';

$ssm_base = 'ssm/www.exemple.fr/wp-migration/';
$secret   = 'SECRET-LOCAL-9f3a';
$sigtoken = 'SIGNATURE-DE-TEST-77';

// Connecteur simulé : il note chaque demande et rend une adresse de test pour cette seule requête.
$connector = function ( $available, $answer = null ) use ( &$ssm_base, &$sigtoken ) {
	$GLOBALS['__filters'] = array();
	$GLOBALS['__sign']    = array();
	if ( $available ) {
		$GLOBALS['__filters']['ssm_connector_storage_available'] = function () {
			return true;
		};
	}
	if ( false !== $answer ) {
		$GLOBALS['__filters']['ssm_connector_storage_sign'] = function ( $value, $args ) use ( &$ssm_base, &$sigtoken, $answer ) {
			$GLOBALS['__sign'][] = $args;
			if ( null !== $answer ) {
				return $answer;
			}
			$query = $args['query'];
			ksort( $query );
			$url = 'https://stockage.ssm.test/bucket-ssm/' . $ssm_base . $args['key'] . '?' . http_build_query( $query ) . ( $query ? '&' : '' ) . 'X-Amz-Credential=ID-JETABLE&X-Amz-Signature=' . $sigtoken;
			return array( 'url' => $url, 'method' => $args['method'], 'base' => $ssm_base, 'expires_in' => $args['expires'] );
		};
	}
};
// Stockage simulé derrière l'adresse signée : objets en mémoire, envoi par morceaux, liste.
$store = array( 'objects' => array(), 'uploads' => array() );
$fake_http = function ( $url, $args ) use ( &$store, &$ssm_base ) {
	$parts = parse_url( $url );
	parse_str( isset( $parts['query'] ) ? $parts['query'] : '', $q );
	$path   = rawurldecode( $parts['path'] );
	$method = $args['method'];
	$ok     = function ( $body = '', $headers = array() ) {
		return array( 'code' => 200, 'headers' => $headers, 'body' => $body );
	};
	if ( 0 === strpos( $path, '/bucket-ssm/' ) && isset( $q['list-type'] ) ) {
		$prefix = isset( $q['prefix'] ) ? $q['prefix'] : '';
		$xml    = '<ListBucketResult><IsTruncated>false</IsTruncated>';
		foreach ( $store['objects'] as $k => $o ) {
			if ( 0 === strpos( $k, $ssm_base . $prefix ) ) {
				$xml .= '<Contents><Key>' . htmlspecialchars( $k ) . '</Key><LastModified>2026-10-08T10:00:00.000Z</LastModified><Size>' . strlen( $o ) . '</Size></Contents>';
			}
		}
		return $ok( $xml . '</ListBucketResult>' );
	}
	$key = substr( $path, strlen( '/bucket-ssm/' ) );
	if ( 'POST' === $method && isset( $q['uploads'] ) ) {
		$store['uploads']['UP-1'] = array();
		return $ok( '<InitiateMultipartUploadResult><UploadId>UP-1</UploadId></InitiateMultipartUploadResult>' );
	}
	if ( 'PUT' === $method && isset( $q['partNumber'] ) ) {
		$store['uploads'][ $q['uploadId'] ][ (int) $q['partNumber'] ] = $args['body'];
		return $ok( '', array( 'ETag' => '"' . md5( $args['body'] ) . '"' ) );
	}
	if ( 'POST' === $method && isset( $q['uploadId'] ) ) {
		ksort( $store['uploads'][ $q['uploadId'] ] );
		$bin = '';
		$all = '';
		foreach ( $store['uploads'][ $q['uploadId'] ] as $part ) {
			$bin .= md5( $part, true );
			$all .= $part;
		}
		$store['objects'][ $key ] = $all;
		return $ok( '<CompleteMultipartUploadResult><ETag>"' . md5( $bin ) . '-' . count( $store['uploads'][ $q['uploadId'] ] ) . '"</ETag></CompleteMultipartUploadResult>' );
	}
	if ( 'PUT' === $method ) {
		$store['objects'][ $key ] = $args['body'];
		return $ok( '', array( 'ETag' => '"' . md5( $args['body'] ) . '"' ) );
	}
	if ( 'DELETE' === $method ) {
		unset( $store['objects'][ $key ] );
		return array( 'code' => 204, 'headers' => array(), 'body' => '' );
	}
	return $ok( isset( $store['objects'][ $key ] ) ? $store['objects'][ $key ] : '' );
};
$GLOBALS['__http_fn'] = $fake_http;
$reset_http           = function () {
	$GLOBALS['__http'] = array();
	$GLOBALS['__sign'] = array();
};
$set_local            = function ( $source, $complete = true ) use ( $secret ) {
	$GLOBALS['__s3_opts']['wpmig_s3'] = array(
		'source'     => $source,
		'endpoint'   => $complete ? 'https://s3.exemple.fr' : '',
		'region'     => 'fr-par',
		'bucket'     => $complete ? 'sauvegardes' : '',
		'prefix'     => 'wp-migration',
		'access_key' => $complete ? 'AKIALOCAL' : '',
		'secret_key' => $complete ? $secret : '',
		'vhost'      => false,
		'keep'       => 1,
	);
};
$message_of           = function ( $fn ) {
	try {
		$fn();
	} catch ( WPMIG_Exception $e ) {
		return $e->getMessage();
	}
	return null;
};
$ids = array( '20261001_100000_aaaaaaaaaaaa', '20261002_100000_bbbbbbbbbbbb', '20261003_100000_cccccccccccc' );

// (f) Choix de la source.
$set_local( 'auto', true );
$connector( true );
check( 'auto : réglages complets -> own (même avec le connecteur)', 'own', WPMIG_S3::source() );
$set_local( 'auto', false );
check( 'auto : réglages incomplets + connecteur -> ssm', 'ssm', WPMIG_S3::source() );
check( 'auto : ssm configuré', true, WPMIG_S3::configured() );
$connector( false, false );
check( 'auto : réglages incomplets, sans connecteur -> own non configuré', array( 'own', false ), array( WPMIG_S3::source(), WPMIG_S3::configured() ) );
$set_local( 'ssm', false );
check( 'ssm explicite sans connecteur : non configuré', array( 'ssm', false ), array( WPMIG_S3::source(), WPMIG_S3::configured() ) );
$set_local( 'own', false );
$connector( true );
check( 'own explicite, réglages incomplets : non configuré malgré le connecteur', array( 'own', false ), array( WPMIG_S3::source(), WPMIG_S3::configured() ) );
$set_local( 'ssm', true );
check( 'ssm explicite : l\'emporte sur des réglages complets', 'ssm', WPMIG_S3::source() );
$s = WPMIG_S3::settings();
check( 'ssm : dossier vide, aucune clé lue', array( '', '', '' ), array( $s['prefix'], $s['access_key'], $s['secret_key'] ) );
check( 'ssm : les réglages enregistrés restent intacts', array( 'wp-migration', $secret ), array( WPMIG_S3::local_settings()['prefix'], WPMIG_S3::local_settings()['secret_key'] ) );
$set_local( 'own', true );
$s = WPMIG_S3::settings();
check( 'own : dossier et clés comme avant', array( 'wp-migration', $secret ), array( $s['prefix'], $s['secret_key'] ) );
$GLOBALS['__s3_opts']['wpmig_s3'] = array( 'endpoint' => 'https://s3.exemple.fr', 'bucket' => 'bucket', 'access_key' => 'A', 'secret_key' => 'S' );
check( 'anciens réglages (sans source) : auto, own', array( 'auto', 'own' ), array( WPMIG_S3::settings()['source'], WPMIG_S3::source() ) );
$connector( false, false );
check( 'choisir ssm sans connecteur refusé', true, null !== $message_of( function () {
	WPMIG_S3::save_settings( array( 'source' => 'ssm' ) );
} ) );
check( 'source inconnue refusée', true, null !== $message_of( function () {
	WPMIG_S3::save_settings( array( 'source' => 'autre' ) );
} ) );
$connector( true );
$saved = WPMIG_S3::save_settings( array( 'source' => 'ssm', 'endpoint' => 'https://s3.exemple.fr', 'bucket' => 'bucket', 'access_key' => 'A', 'secret_key' => '' ) );
check( 'choisir ssm avec le connecteur : enregistré, clés locales conservées', array( 'ssm', 'S' ), array( WPMIG_S3::source(), WPMIG_S3::local_settings()['secret_key'] ) );
$saved = WPMIG_S3::save_settings( array( 'endpoint' => 'https://s3.exemple.fr', 'bucket' => 'bucket', 'access_key' => 'A' ) );
check( 'source absente de la saisie : inchangée', 'ssm', WPMIG_S3::local_settings()['source'] );
$connector( false, false );
WPMIG_S3::save_settings( array( 'endpoint' => 'https://s3.exemple.fr', 'bucket' => 'bucket', 'access_key' => 'A' ) );
check( 'ssm déjà choisi, connecteur retiré : les autres réglages restent modifiables', 'ssm', WPMIG_S3::local_settings()['source'] );

// (a) Aucune clé lue ni envoyée en mode ssm + (b) envoi par morceaux complet.
$set_local( 'ssm', true );
$connector( true );
$store = array( 'objects' => array(), 'uploads' => array() );
$reset_http();
$GLOBALS['__filters']['wpmig_s3_part_size'] = function () {
	return 5242880;
};
$tmpdir = sys_get_temp_dir() . '/wpmig-ssm-' . getmypid();
@mkdir( $tmpdir );
$big = $tmpdir . '/site_' . $ids[2] . '_archive.wpmig';
$fh  = fopen( $big, 'wb' );
for ( $i = 0; $i < 8; $i++ ) {
	fwrite( $fh, str_repeat( chr( 65 + $i ), 1048576 ) );
}
fwrite( $fh, 'fin-de-fichier' );
fclose( $fh );
$small = $tmpdir . '/site_' . $ids[2] . '_installer.php';
file_put_contents( $small, '<?php // installeur de test' );
$rel_small = $ids[2] . '/' . basename( $small );
$rel_big   = $ids[2] . '/' . basename( $big );
$files     = array();
foreach ( array( array( $small, $rel_small ), array( $big, $rel_big ) ) as $f ) {
	$files[] = array( 'path' => $f[0], 'key' => $f[1], 'size' => filesize( $f[0] ), 'sent' => 0, 'done' => false, 'upload' => '', 'parts' => array() );
}
update_option( WPMIG_S3::STATE, array( 'package' => $ids[2], 'source' => 'ssm', 'status' => 'running', 'started' => time(), 'files' => $files, 'error' => '', 'retries' => 0, 'touched' => time() ) );
$state = WPMIG_S3::run();
check( 'ssm : envoi terminé', 'done', $state['status'] );
check( 'ssm : petit fichier envoyé tel quel', '<?php // installeur de test', isset( $store['objects'][ $ssm_base . $rel_small ] ) ? $store['objects'][ $ssm_base . $rel_small ] : null );
check( 'ssm : archive reconstituée à l\'octet près sous la base de SSM', true, isset( $store['objects'][ $ssm_base . $rel_big ] ) && file_get_contents( $big ) === $store['objects'][ $ssm_base . $rel_big ] );
$kinds = array();
foreach ( $GLOBALS['__sign'] as $call ) {
	$kinds[] = $call['method'] . ' ' . ( '' === $call['key'] ? '(bucket)' : $call['key'] ) . ' ' . http_build_query( $call['query'] );
}
check(
	'ssm : demandes de signature (create -> parts -> complete)',
	array(
		'PUT ' . $rel_small . ' ',
		'POST ' . $rel_big . ' uploads=',
		'PUT ' . $rel_big . ' partNumber=1&uploadId=UP-1',
		'PUT ' . $rel_big . ' partNumber=2&uploadId=UP-1',
		'POST ' . $rel_big . ' uploadId=UP-1',
	),
	$kinds
);
$complete = null;
foreach ( $GLOBALS['__http'] as $h ) {
	if ( 'POST' === $h['args']['method'] && false !== strpos( $h['url'], 'uploadId=UP-1' ) ) {
		$complete = $h['args']['body'];
	}
}
check(
	'ssm : corps CompleteMultipartUpload avec les empreintes des morceaux',
	'<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>"' . md5( substr( file_get_contents( $big ), 0, 5242880 ) ) . '"</ETag></Part><Part><PartNumber>2</PartNumber><ETag>"' . md5( substr( file_get_contents( $big ), 5242880 ) ) . '"</ETag></Part></CompleteMultipartUpload>',
	$complete
);
$expires = array();
foreach ( $GLOBALS['__sign'] as $call ) {
	$expires[] = $call['expires'];
}
check( 'ssm : une signature fraîche par requête (5 demandes pour 5 requêtes)', array( count( $GLOBALS['__http'] ), 5 ), array( count( $GLOBALS['__sign'] ), count( $GLOBALS['__http'] ) ) );
check( 'ssm : durée demandée dans les limites (3600 s, 6 h au plus)', true, max( $expires ) <= 21600 && min( $expires ) >= 1 );
$bad = array();
foreach ( $GLOBALS['__http'] as $h ) {
	foreach ( $h['args']['headers'] as $name => $value ) {
		$lname = strtolower( $name );
		if ( 'authorization' === $lname || 0 === strpos( $lname, 'x-amz-' ) || 'host' === $lname ) {
			$bad[] = $name;
		}
	}
	if ( 0 !== $h['args']['redirection'] || false === strpos( $h['url'], 'X-Amz-Signature=' . $sigtoken ) ) {
		$bad[] = 'redirection/url';
	}
}
check( 'ssm : ni Authorization, ni x-amz-*, ni host ; adresse signée telle quelle ; pas de redirection', array(), $bad );
check( 'ssm : la clé secrète locale n\'apparaît dans aucune requête ni demande', false, false !== strpos( serialize( array( $GLOBALS['__http'], $GLOBALS['__sign'] ) ), $secret ) || false !== strpos( serialize( array( $GLOBALS['__http'], $GLOBALS['__sign'] ) ), 'AKIALOCAL' ) );
$parts_headers = array();
foreach ( $GLOBALS['__http'] as $h ) {
	if ( 'PUT' === $h['args']['method'] ) {
		$parts_headers[] = isset( $h['args']['headers']['Content-MD5'] ) && isset( $h['args']['headers']['Content-Type'] );
	}
}
check( 'ssm : Content-MD5 et Content-Type envoyés (non signés)', array( true, true, true ), $parts_headers );
$hist = WPMIG_S3::history();
check( 'ssm : historique avec la source et les clés relatives', array( 'ssm', $rel_small ), array( $hist[ $ids[2] ]['source'], $hist[ $ids[2] ]['files'][0]['key'] ) );
check( 'ssm : aucun dossier propre dans les clés', 0, preg_match( '#wp-migration/#', $rel_big ) );

// Liens de téléchargement.
$reset_http();
$links = WPMIG_S3::links( $ids[2], 24 );
check( 'ssm : liens signés par SSM', true, 0 === strpos( $links['archive'], 'https://stockage.ssm.test/' ) && false !== strpos( $links['installer'], 'X-Amz-Signature=' . $sigtoken ) );
check( 'ssm : lien GET, durée demandée 24 h', array( 'GET', 86400, array() ), array( $GLOBALS['__sign'][0]['method'], $GLOBALS['__sign'][0]['expires'], $GLOBALS['__sign'][0]['query'] ) );
check( 'ssm : un lien de 7 jours est accepté, au-delà il est ramené à 7 jours', array( 604800, 604800 ), array( ( WPMIG_S3::presign( 'a/b.txt', 604800 ) ? $GLOBALS['__sign'][ count( $GLOBALS['__sign'] ) - 1 ]['expires'] : 0 ), ( WPMIG_S3::presign( 'a/b.txt', 9999999 ) ? $GLOBALS['__sign'][ count( $GLOBALS['__sign'] ) - 1 ]['expires'] : 0 ) ) );
WPMIG_S3::delete_object( $rel_small );
check( 'ssm : suppression demandée à SSM (DELETE, 6 h au plus)', array( 'DELETE', true ), array( $GLOBALS['__sign'][ count( $GLOBALS['__sign'] ) - 1 ]['method'], $GLOBALS['__sign'][ count( $GLOBALS['__sign'] ) - 1 ]['expires'] <= 21600 ) );
$set_local( 'own', true );
check( 'liens d\'une sauvegarde envoyée via SSM refusés dans l\'autre source', true, false !== strpos( (string) $message_of( function () use ( $ids ) {
	WPMIG_S3::links( $ids[2] );
} ), 'SSM' ) );
$set_local( 'ssm', true );

// Une source qui change pendant un envoi.
update_option( WPMIG_S3::STATE, array( 'package' => $ids[2], 'source' => 'own', 'status' => 'running', 'started' => time(), 'files' => $files, 'error' => '', 'retries' => 0, 'touched' => time() ) );
$reset_http();
$state = WPMIG_S3::step( 3 );
check( 'envoi commencé avec l\'autre source : arrêté sans aucune requête', array( 'error', 0 ), array( $state['status'], count( $GLOBALS['__http'] ) ) );
update_option( WPMIG_S3::STATE, null );
WPMIG_S3::cancel();

// (c) Liste : préfixe relatif, base retirée.
$store['objects'] = array();
foreach ( $ids as $n => $id ) {
	$store['objects'][ $ssm_base . $id . '/site_' . $id . '_archive.wpmig' ] = str_repeat( 'x', 10 + $n );
	$store['objects'][ $ssm_base . $id . '/site_' . $id . '_installer.php' ] = 'y';
}
$store['objects']['ssm/autre-site/wp-migration/' . $ids[0] . '/intrus.wpmig'] = 'z';
$reset_http();
$list = WPMIG_S3::list_objects();
$keys = array();
foreach ( $list as $o ) {
	$keys[] = $o['key'];
}
check( 'ssm : liste sans base, taille conservée', array( $ids[0] . '/site_' . $ids[0] . '_archive.wpmig', 10 ), array( $keys[0], $list[0]['size'] ) );
check( 'ssm : six fichiers, aucune clé ne commence par la base', array( 6, 0 ), array( count( $keys ), count( preg_grep( '#^ssm/#', $keys ) ) ) );
check( 'ssm : liste du bucket sans clé, sans préfixe à envoyer', array( 'GET', '', array( 'list-type' => '2' ) ), array( $GLOBALS['__sign'][0]['method'], $GLOBALS['__sign'][0]['key'], $GLOBALS['__sign'][0]['query'] ) );
$reset_http();
$list = WPMIG_S3::list_objects( $ids[1] . '/' );
check( 'ssm : préfixe relatif envoyé à SSM (sans la base)', array( 'list-type' => '2', 'prefix' => $ids[1] . '/' ), $GLOBALS['__sign'][0]['query'] );
check( 'ssm : liste filtrée par le préfixe', array( $ids[1] . '/site_' . $ids[1] . '_archive.wpmig', $ids[1] . '/site_' . $ids[1] . '_installer.php' ), array( $list[0]['key'], $list[1]['key'] ) );

// Test de connexion.
$reset_http();
$lines = WPMIG_S3::test();
check( 'ssm : test de connexion par une liste, sans écriture', array( 1, 'GET' ), array( count( $GLOBALS['__http'] ), $GLOBALS['__http'][0]['args']['method'] ) );
check( 'ssm : test de connexion annonce SSM et le dossier', true, false !== strpos( $lines[0], 'SSM' ) && false !== strpos( $lines[1], $ssm_base ) );

// (g) La rétention locale ne supprime rien avec SSM.
$set_local( 'ssm', true );
$count_before = count( $store['objects'] );
$reset_http();
check( 'ssm : prune() ne supprime rien', array( 0, 0, $count_before ), array( WPMIG_S3::prune(), count( $GLOBALS['__http'] ), count( $store['objects'] ) ) );
// Rétention bien active en mode own (garde-fou du test précédent).
$set_local( 'own', true );
$connector( false, false );
$own_store = array();
foreach ( $ids as $id ) {
	$own_store[ 'wp-migration/' . $id . '/site_' . $id . '_archive.wpmig' ] = 'x';
}
$own_deleted         = array();
$GLOBALS['__http_fn'] = function ( $url, $args ) use ( &$own_store, &$own_deleted ) {
	if ( 'DELETE' === $args['method'] ) {
		$own_deleted[] = rawurldecode( parse_url( $url, PHP_URL_PATH ) );
		return array( 'code' => 204, 'headers' => array(), 'body' => '' );
	}
	$xml = '<ListBucketResult><IsTruncated>false</IsTruncated>';
	foreach ( $own_store as $k => $v ) {
		$xml .= '<Contents><Key>' . $k . '</Key><Size>1</Size></Contents>';
	}
	return array( 'code' => 200, 'headers' => array(), 'body' => $xml . '</ListBucketResult>' );
};
$reset_http();
check( 'own : prune() garde le plus récent et supprime les autres', array( 2, array( '/sauvegardes/wp-migration/' . $ids[1] . '/site_' . $ids[1] . '_archive.wpmig', '/sauvegardes/wp-migration/' . $ids[0] . '/site_' . $ids[0] . '_archive.wpmig' ) ), array( WPMIG_S3::prune(), $own_deleted ) );

// (e) Le mode own signe toujours lui-même et n'appelle jamais SSM.
$connector( true );
$GLOBALS['__http_fn'] = $fake_http;
$store['objects']     = array();
$reset_http();
WPMIG_S3::delete_object( 'wp-migration/x.txt' );
$h = $GLOBALS['__http'][0];
check( 'own : requête signée avec Authorization, hôte du bucket', array( true, 'https://s3.exemple.fr/sauvegardes/wp-migration/x.txt', 0 ), array( 0 === strpos( $h['args']['headers']['Authorization'], 'AWS4-HMAC-SHA256 Credential=AKIALOCAL/' ), $h['url'], count( $GLOBALS['__sign'] ) ) );
check( 'own : x-amz-content-sha256 et x-amz-date toujours envoyés', true, isset( $h['args']['headers']['x-amz-content-sha256'] ) && isset( $h['args']['headers']['x-amz-date'] ) );
check( 'own : lien pré-signé avec la clé locale', true, 0 === strpos( WPMIG_S3::presign( 'wp-migration/x.txt', 3600 ), 'https://s3.exemple.fr/sauvegardes/wp-migration/x.txt?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIALOCAL%2F' ) && 0 === count( $GLOBALS['__sign'] ) );
$set_local( 'own', false );
check( 'own : sans le connecteur, les erreurs d\'avant', 'Le stockage S3 n\'est pas configuré.', $message_of( function () {
	WPMIG_S3::delete_object( 'x' );
} ) );

// (d) Erreurs : message clair, jamais d'adresse signée.
$set_local( 'ssm', true );
$GLOBALS['__http_fn'] = $fake_http;
$connector( true, new WP_Error( 'ssm_no_target', 'SSM : aucun point de sauvegarde n\'est configuré pour ce site.' ) );
$reset_http();
$msg = $message_of( function () {
	WPMIG_S3::test();
} );
check( 'erreur de SSM : message repris tel quel', 'SSM : aucun point de sauvegarde n\'est configuré pour ce site.', $msg );
check( 'erreur de SSM : aucune requête vers le stockage', 0, count( $GLOBALS['__http'] ) );
$connector( true, new WP_Error( 'ssm_down', 'SSM est injoignable (délai dépassé) : https://ssm.exemple.fr/api/sign?token=JETON-SECRET-12 refusé.' ) );
$msg = $message_of( function () {
	WPMIG_S3::list_objects();
} );
check( 'erreur de SSM : une adresse dans le message est amputée de sa query', array( true, false ), array( false !== strpos( (string) $msg, 'SSM est injoignable' ), false !== strpos( (string) $msg, 'JETON-SECRET-12' ) ) );
$connector( true, null );
$GLOBALS['__filters']['ssm_connector_storage_sign'] = function ( $value ) {
	return $value; // Filtre non accroché : la valeur passée revient (null).
};
check( 'connecteur absent (null) : message explicite', true, false !== strpos( (string) $message_of( function () {
	WPMIG_S3::test();
} ), 'connecteur SSM' ) );
$connector( false, false );
$msg = $message_of( function () {
	WPMIG_S3::test();
} );
check( 'connecteur non disponible : message explicite, sans aucune requête', array( true, 0 ), array( false !== strpos( (string) $msg, 'connecteur SSM' ), count( $GLOBALS['__http'] ) ) );
$connector( true, array( 'method' => 'GET' ) );
check( 'réponse du connecteur sans adresse : erreur claire', true, false !== strpos( (string) $message_of( function () {
	WPMIG_S3::test();
} ), 'Réponse inattendue du connecteur SSM' ) );
$connector( true, array( 'url' => 'https://stockage.ssm.test/b/x?X-Amz-Signature=' . $sigtoken, 'method' => 'PUT', 'base' => $ssm_base ) );
check( 'réponse du connecteur pour une autre méthode refusée', true, false !== strpos( (string) $message_of( function () {
	WPMIG_S3::test();
} ), 'autre méthode' ) );
// Le transport échoue : l'adresse signée ne sort pas.
$connector( true );
$GLOBALS['__http_fn'] = function ( $url, $args ) {
	return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out sur ' . $url );
};
$msg = $message_of( function () {
	WPMIG_S3::test();
} );
check( 'échec réseau : message sans signature ni identifiant', array( true, false, false ), array( false !== strpos( (string) $msg, 'injoignable' ), false !== strpos( (string) $msg, $sigtoken ), false !== strpos( (string) $msg, 'ID-JETABLE' ) ) );
// Le stockage refuse (403) : conseil propre à SSM, pas de clés locales.
$GLOBALS['__http_fn'] = function ( $url, $args ) {
	return array( 'code' => 403, 'headers' => array(), 'body' => '<Error><Code>AccessDenied</Code><Message>Request has expired</Message></Error>' );
};
$msg = $message_of( function () {
	WPMIG_S3::test();
} );
check( 'refus du stockage : code, message du stockage, conseil SSM', true, false !== strpos( (string) $msg, 'AccessDenied — Request has expired' ) && false === strpos( (string) $msg, $sigtoken ) && false === strpos( (string) $msg, 'clé secrète' ) );
$GLOBALS['__http_fn'] = function ( $url, $args ) {
	return array( 'code' => 403, 'headers' => array(), 'body' => '' );
};
$msg = $message_of( function () {
	WPMIG_S3::delete_object( 'a/b.txt' );
} );
check( 'refus sans détail : conseil propre à SSM', true, false !== strpos( (string) $msg, 'Stockage fourni par SSM (HTTP 403)' ) && false !== strpos( (string) $msg, 'signée par SSM' ) && false === strpos( (string) $msg, 'clé secrète' ) );

// Clés relatives : les règles du protocole.
$GLOBALS['__http_fn'] = $fake_http;
$connector( true );
$reset_http();
foreach ( array(
	'chemin absolu'         => '/a/b.txt',
	'point-point'           => 'a/../b.txt',
	'double barre'          => 'a//b.txt',
	'caractère interdit'    => 'a/é.txt',
	'espace'                => 'a/b c.txt',
	'trop longue'           => str_repeat( 'a', 401 ),
) as $name => $bad_key ) {
	check( 'clé refusée : ' . $name, array( true, 0 ), array( null !== $message_of( function () use ( $bad_key ) {
		WPMIG_S3::delete_object( $bad_key );
	} ), count( $GLOBALS['__sign'] ) ) );
}
$reset_http();
WPMIG_S3::delete_object( str_repeat( 'a', 400 ) );
WPMIG_S3::delete_object( 'Site-2026_x.y/' . $ids[0] . '/site_' . $ids[0] . '_archive.wpmig' );
check( 'clés valides acceptées (400 caractères, identifiant de sauvegarde, tirets, points)', 2, count( $GLOBALS['__sign'] ) );
$name_ok = true;
foreach ( array( 'mon-site', 'site_2026.v2', 'a-b' ) as $slug ) {
	$name_ok = $name_ok && 1 === preg_match( '#^[A-Za-z0-9._\-][A-Za-z0-9._\-/]*$#', $slug . '_' . $ids[0] . '_archive.wpmig' );
}
check( 'noms de sauvegarde de WP Migration conformes au protocole', true, $name_ok );

// Si une constante porte une clé secrète, le mode ssm ne la lit pas non plus (à garder en dernier : une constante ne se retire pas).
if ( ! defined( 'WPMIG_S3_SECRET_KEY' ) ) {
	define( 'WPMIG_S3_SECRET_KEY', 'SECRET-CONSTANTE-wp-config' );
	define( 'WPMIG_S3_ACCESS_KEY', 'AKIACONSTANTE' );
	$set_local( 'ssm', false );
	$connector( true );
	$reset_http();
	WPMIG_S3::delete_object( 'a/b.txt' );
	check( 'ssm : clés de wp-config.php ni lues ni envoyées', array( '', '', false ), array( WPMIG_S3::settings()['secret_key'], WPMIG_S3::settings()['access_key'], false !== strpos( serialize( array( $GLOBALS['__http'], $GLOBALS['__sign'] ) ), 'CONSTANTE' ) ) );
	$set_local( 'own', false );
	check( 'own : la constante de wp-config.php reste lue comme avant', 'SECRET-CONSTANTE-wp-config', WPMIG_S3::settings()['secret_key'] );
}
array_map( 'unlink', glob( $tmpdir . '/*' ) );
@rmdir( $tmpdir );

echo "\n" . ( $count - $failures ) . '/' . $count . " tests réussis\n";
exit( $failures ? 1 : 0 );
