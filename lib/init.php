<?php
if (!defined('SITE_ROOT')) {
	exit(1);
}

error_reporting(E_ALL ^ E_NOTICE);
ini_set("display_errors", "off");

if (!extension_loaded('kl')) {
	require_once SITE_ROOT.'/lib/ext_kl.php';
}

function __autoload($class)
{
	require_once(SITE_ROOT . '/lib/' . $class . '.php');
}

date_default_timezone_set("UTC");

/* Directory relative to server root
 * No leading or trailing slash.
 * Example: http://www.example.com/applications/app1/
 */

define('REL_DIR', 'alpha');

if (!defined('URL_ROOT')) {
	$init_port = "";
	$init_ssl = strlen($_SERVER["HTTPS"]) > 0 ? true:false;
	define('USE_SSL', $init_ssl);

	$init_url = $init_ssl ? "https://" : "http://";

	if ($init_ssl && $_SERVER['PORT']!=443) {
		$init_port = $_SERVER['PORT'];
	}

	if (!$init_ssl && $_SERVER['PORT']!=80) {
		$init_port = $_SERVER['PORT'];
	}

	$init_url .= $_SERVER['HTTP_HOST'];

	if ($init_port) {
		$init_url .= ":" . $init_port;
	}

	if (defined('REL_DIR') && strlen(REL_DIR)) {
		$init_url .= '/' . REL_DIR;
	}

	define ('URL_ROOT', $init_url);
}

if (!defined('IMG_ROOT')) {
	define('IMG_ROOT', URL_ROOT . '/images');
}


// $CHANS = array("SingularityAlpha" => "HEAD", "SingularityMultiWearable" => "refs/remotes/shyotl/V2MultiWear");
$CHANS = array("SingularityAlpha" => "refs/remotes/origin/master");//,  "SingularityFMODStudio" => "refs/remotes/shyotl/FMODStudio");
define("KEEP_BUILDS", 50);

$DB = new DBH();

$DB_NAME = SITE_ROOT . '/lib/singularity_revisions.db';
/* $DB_USER = 'gigaprims';
   $DB_PASS = 'secrit';
   $DB_HOST = 'localhost';
*/


if (!$DB->connect($DB_NAME, $DB_HOST, $DB_USER, $DB_PASS)) {
	echo "System is down for mantainence. Please try again later.";
	die();
}


/*

  $S = new Session();
  if (!defined('NO_SESSION') && PHP_SAPI != "cli") {
  $S->check();
  }

*/

if (PHP_SAPI != "cli") {
	header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + 2 * 3600));
	header('Cache-Control:  max-age=' . (4 * 3600));
 }


/* Prevent XSS attacks via PHP_SELF */
$_SERVER['PHP_SELF'] = htmlspecialchars($_SERVER['PHP_SELF']);
/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: sw=4 ts=4 fdm=marker
 * vim<600: sw=4 ts=4
 */


?>