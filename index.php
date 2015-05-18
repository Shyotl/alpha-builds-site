<?php
define("TARGET", ' target="_blank" ');
define("SITE_ROOT", realpath(dirname(__file__)));
require_once SITE_ROOT . "/lib/init.php";

function get_downloads()
{
	global $DB;
	
	$ret = array();

	if (!$res = $DB->query("select file_name from downloads")) return;

	while ($row = $DB->fetchRow($res)) {
		$ret[] = $row["file_name"];
	}

	return $ret;
}

$all_downloads = get_downloads();

function download_exists($file_name)
{
	global $all_downloads;
	return in_array($file_name, $all_downloads);
}

function download_link($file_name)
{
	// return "http://master.dl.sourceforge.net/projects/singularityview/alphas/{$file_name}";
	return "http://sourceforge.net/projects/singularityview/files/alphas/{$file_name}/download";
}

function parse_email($email) 
{
	$ret = array("name" => $email, "email" => "");
	if (preg_match("|([^\<]*)<([^>]*)>|", $email, $m)) {
		$ret["name"] = trim($m[1]);
		$ret["email"] = trim($m[2]);
	}
	return $ret;
}

function print_changeset($row)
{
	$author = parse_email($row["author"]);
	$gid = md5(strtolower($author["email"]));
	$avatar = (USE_SSL ? "https://secure.gravatar.com" : "http://www.gravatar.com") .
		"/avatar/$gid?r=x&amp;d=mm&amp;s=48";
	print '
            <tr>
              <td rowspan="2" style="text-align: center;"><img src="' . $avatar . '" alt="Avatar"/><br />' .
				htmlspecialchars($author["name"]) . '</td>
              <td><a href="https://github.com/singularity-viewer/SingularityViewer/commit/' . htmlspecialchars($row["hash"]) . '">' . htmlspecialchars($row["hash"]) . '</a></td>
              <td>' . htmlspecialchars($row["time"]). 
              ' (' . Layout::since(strtotime($row["time"])) . ' ago)</td>
           </tr>
           <tr>
             <td colspan="2" width="99%"><pre>' . htmlspecialchars($row["message"]) . '</pre></td>
           </tr>';
}

function sort_by_date($a, $b) 
{
	if ($a["time"] < $b["time"]) {
		return 1;
	} else if ($a["time"] > $b["time"]) {
		return -1;
	}
	return 0;
}

function gather_revisions($current, $next, $chan)
{
	global $DB;
	$revs = array();
	if($next)
	{
		if (!($res = $DB->query(kl_str_sql("select revisions from changes where chan=!s and build<=!i and build>!i order by build desc", $chan, $current->nr, $next->nr))))
			return;
	}
	else if (!($res = $DB->query(kl_str_sql("select revisions from changes where chan=!s and build=!i order by build desc", $chan, $current->nr))))
		return;
	
	while ($row = $DB->fetchRow($res))
	{
		$revs = array_merge($revs, explode(",", $row["revisions"]));
	}
	return $revs;
}

function print_changes($chan, &$revs)
{
	global $DB;

	if ($res = $DB->query(kl_str_sql("select * from revs where chan=!s and hash in ('" . implode("','", $revs) . "')", $chan))) {

		$changesets = array();

		while ($row = $DB->fetchRow($res)) {
			$changesets[] = $row;
		}

		if (count($changesets) == 0) return;

		print '<table style="width: 99%;">';

		usort($changesets, "sort_by_date");
		
		foreach ($changesets as $change) {
			print_changeset($change);
		}

		print '</table>';
	}
}

Function print_build($current, $next, $buildNr, $chan)
{
	print "
		<tr style=\"background-color: #303030;\">
		  <th><a href=\"" . URL_ROOT ."?build_id={$current->nr}\">Build " . htmlspecialchars($current->nr). "</a><br/>";


	$vspace = "";
	$github = "https://github.com/singularity-viewer/SingularityViewer/";

	$revs = gather_revisions($current, $next, $chan);	
	$next_hash;
	if($next)
		$next_hash = $next->hash;
	else
	{
		$next_hash = end($revs);
		reset($revs);
	}
	
	{
		if (($current->linux_file && $current->osx_file && $current->linux64_file)) {
			$vspace = "<br/><br/>";
		}
		elseif (($current->linux_file && $current->osx_file)) {
			$vspace = "<br/>";
		}

		print $vspace .
            '<a class="dimmer" href="javascript:void(0)" id="toggle_link_'. $current->nr . '" onclick="javascript:toggleChanges('. $current->nr . ')">' .
	        ($buildNr ? 'Hide changes &lt;&lt;' : 'Show changes &gt;&gt;') . '</a>';
	}

 	print "</th><th>" . htmlspecialchars($current->modified). " (" . Layout::since(strtotime($current->modified)) . " ago)<br/>";

	if ($next_hash != $current->hash) {
		$gh_link = $github . "compare/" . substr($next_hash, 0, 12) . "..." . substr($current->hash, 0, 12);
	} else {
		$gh_link = $github . "commits/" . $current->hash;
	}

	print $vspace . '<a class="dimmer" href="' . $gh_link . '">';
	print substr($current->hash, 0, 10) . "</a>";  

	Print "</th>
		  <th>" . htmlspecialchars($current->chan). "</th>
		  <th>";

	if ($current->file) {
		print "<a href='" . download_link($current->file) . "' " . TARGET . "><img src=\"" . IMG_ROOT . "/dl.gif\" alt=\"Download Windows Build\"/>&nbsp;Windows</a>";
		if (download_exists($current->file . ".log")) {
			print "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a class='dimmer' href='" . download_link($current->file . ".log") . "' " . TARGET . ">Build Log</a>";
		}
	}


	if ($current->win64_file) {
		print "<br/><a href='" . download_link($current->win64_file) . "' " . TARGET . "><img src=\"" . IMG_ROOT . "/dl.gif\" alt=\"Download Windows Build\"/>&nbsp;Windows (64 bit)</a>";
		if (download_exists($current->win64_file . ".log")) {
              print "&thinsp;<a class='dimmer' href='" . download_link($current->win64_file . ".log") . "' " . TARGET . ">Build Log</a>";
		}
	}

	if ($current->linux_file) {
		print "<br/><a href='" . download_link($current->linux_file) . "' " . TARGET . "><img src=\"" . IMG_ROOT . "/dl.gif\" alt=\"Download Linux Build (32 bit)\"/>&nbsp;Linux (32 bit)</a>";
		if (download_exists($current->linux_file . ".log")) {
			print "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a class='dimmer' href='" . download_link($current->linux_file . ".log") . "' " . TARGET . ">Build Log</a>";
		}

	}


	if ($current->linux64_file) {
		print "<br/><a href='" . download_link($current->linux64_file) . "' " . TARGET . "><img src=\"" . IMG_ROOT . "/dl.gif\" alt=\"Download Linux Build (64 bit)\"/>&nbsp;Linux (64 bit)</a>";
		if (download_exists($current->linux64_file . ".log")) {
			print "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a class='dimmer' href='" . download_link($current->linux64_file . ".log") . "' " . TARGET . ">Build Log</a>";
		}
	}

	if ($current->osx_file) {
		print "<br/><a href='" . download_link($current->osx_file) . "' " . TARGET . "><img src=\"" . IMG_ROOT . "/dl.gif\" alt=\"Download Mac OS X Build\"/>&nbsp;Mac OS X</a>";
		if (download_exists($current->osx_file . ".log")) {
			print "&thinsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a class='dimmer' href='" . download_link($current->osx_file . ".log") . "' " . TARGET . ">Build Log</a>";
		}
	}

	print "</th></tr>";

	{
		print '<tr' . ($buildNr ? '' : ' style="display: none;"') . ' id="changes_' . $current->nr . '"><td colspan="4">';
		print_changes($chan, $revs);
		print "</td></tr>";
	}

}

function chan_selector($current_chan)
{
	//	return;
	global $CHANS;
	print '<form method="get" action="index.php">';
	print 'Select channel&nbsp;<select name="chan" onchange="this.form.submit()">';
	foreach($CHANS as $chan => $ref) {
		print "<option value=\"$chan\"" . ($current_chan == $chan ? " selected=\"selected\"" : "") . ">$chan</option>";
	}
	print '</select><noscript><input type="submit" value="Change"/></noscript></form><br />';

}

Layout::header();

if (isset($_GET["chan"]) && isset($CHANS[$_GET["chan"]])) {
	$chan = $_GET["chan"];
} else {
	// $chan = "SingularityMultiWearable";
	$chan = "SingularityAlpha";
}

$page = 0;
if (isset($_GET["page"])) {
	$page = (int)$_GET["page"];
 }

$pageSize = 25;

$builds = array();

$buildNr = 0;
$where = "";

if (isset($_GET["build_id"])) {
	$buildNr = (int)$_GET["build_id"];
	$pageSize = 1;
	$where = kl_str_sql(" and nr <= !i ", $buildNr); 
} else {
	chan_selector($chan);
}

if ($res = $DB->query(kl_str_sql("select * from builds_all where chan=!s $where order by nr desc limit !i offset !i", $chan, $pageSize + 1, $page * $pageSize))) {
	while ($row = $DB->fetchRow($res)) {
		
		$build = new stdClass;
		$DB->loadFromDbRow($build, $res, $row);

		$file = "{$chan}_" . str_replace(".", "-", $build->version) . "_Setup.exe";
		$build->file = download_exists($file) ? $file : false;

		$win64_file = "{$chan}_" . str_replace(".", "-", $build->version) . "_x86-64_Setup.exe";
		$build->win64_file = download_exists($win64_file) ? $win64_file : false;

		$linux_file = "{$chan}-i686-{$build->version}.tar.bz2";
		$build->linux_file = download_exists($linux_file) ? $linux_file : false;

		$linux64_file = "{$chan}-x86_64-{$build->version}.tar.bz2";
		$build->linux64_file = download_exists($linux64_file) ? $linux64_file : false;

		$osx_file = "{$chan}_" . str_replace(".", "_", $build->version) . ".dmg";
		$build->osx_file = download_exists($osx_file) ? $osx_file : false;

		$builds[] = $build;
	}
}

$nrBuilds = count($builds);

if ($res = $DB->query(kl_str_sql("select count(*) from builds_all where chan=!s", $chan))) {
	if ($row =  $DB->fetchRow($res)) {
		$total = (int)$row[0];
	}
}


$nextLink = "#";
$prevLink = "#";

if ($page > 0) $prevLink = "?page=" . ($page - 1);
if ($page < (int)($total / $pageSize)) $nextLink = "?page=" . ($page + 1);

$paginator .= '<a href="' . $prevLink . '">&lt;Previous</a>';

$paginator .= "&nbsp;&nbsp;Page " . ($page + 1) . " of " . ceil($total / $pageSize) . "&nbsp;&nbsp;&nbsp;";

$paginator .= '<a href="' . $nextLink . '">Next&gt;</a>';

if ($pageSize == 1) $paginator = "";

print $paginator;

if ($nrBuilds) {
	print '<table class="build-list">';


	for ($i = 0; $i < $pageSize; $i++) {
		if (!isset($builds[$i])) continue;
		print_build($builds[$i], $builds[$i + 1], $buildNr, $chan);
	}

	print '</table>';

}

print "<br/>\n" . $paginator;


Layout::footer();

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: noet sw=4 ts=4 fdm=marker
 * vim<600: noet sw=4 ts=4
 */
