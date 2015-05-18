#!/usr/bin/php
<?php

if (PHP_SAPI != "cli") {
	print "Utility script 0x55424523.";
	die();
}

define("SITE_ROOT", realpath(dirname(__file__) . "/.."));
require_once SITE_ROOT . "/lib/init.php";

function import_rev(&$existing_revs, $raw, $chan)
{
	global $DB;

	$log = explode("\n", rtrim($raw));

	$hash = $log[0];
	if(preg_match('/^[A-Za-z0-9]{40}$/i', $hash) == false) return false;
	if (isset($existing_revs["$hash$chan"])) return true;
	$author = "";
	$date = "";
	$msg = "";
	$inMsg = false;
	$nrLog = count($log);

	for ($i=0; $i<$nrLog; $i++) {
		if ($inMsg) {
			$msg .=  substr($log[$i], 4);
			if ($i<$nrLog-1) {
				$msg .= "\n";
			}
		} else {
			if (preg_match("|^author\\s*([^>]*>)\\s*([\\d]+)\\s*(.*)|i", $log[$i], $m)) {
				$author = $m[1];
				$date = (int)$m[2];
			} else if  (!trim($log[$i])) {
				$inMsg = true;
			}
		}
	}
	
	$DB->query(
	   kl_str_sql(
		  "insert into revs (hash, chan, author, time, message) values (!s, !s, !s, !t, !s)",
		  $hash, $chan, $author, $date - date("Z"), $msg));
	return true;
}

function import_rev_array(&$existing_revs, &$revs, $chan)
{
	global $DB;
	
	$nrRevs = count($revs);
	$count = 0;
	
	$DB->query("begin");
	for ($i = 0; $i < $nrRevs; $i++)
	{
		if(import_rev($existing_revs, $revs[$i], $chan))
			$count++;
	}
	$DB->query("commit");
	return $count;
}

function import_remote(&$existing_revs, $chan, $remote)
{
	global $DB;
	
	$DB->query("begin");
	$DB->query("create table if not exists remotes (chan varchar)");
	$DB->query("commit");
	
	$res = $DB->query(kl_str_sql("select count(*) as c from remotes where chan=!s", $chan));
	$row = $DB->fetchRow($res);
	if ($row["c"] == 0)
	{
		print "Attempting import of new remote '$remote'\n";
		
		$DB->query("begin");
		$DB->query(kl_str_sql("insert into remotes (chan) values (!s)", $chan));
		$DB->query("commit");
		exec("git fetch $remote 2>&1");
		$revs = array_reverse(explode(chr(0), rtrim(`git rev-list $remote/master --header --max-count=100`)));
		$count = import_rev_array($existing_revs, $revs, $chan);
		print "$count revisions successfully imported for '$remote'\n";
		if($count == 0) {throw new Exception('Invalid remote');}
		return true;
	}
	return false;
}

function import_revs()
{
	global $DB, $CHANS;

	$DB->query("begin "); 
	$DB->query("create table if not exists revs(hash varchar, chan varchar, author varchar, time timestamp, message text, diff text, primary key(chan, hash))");
	$DB->query("commit"); 
	
	$res = $DB->query("select chan,hash from revs");
	$existing_revs = array();
	while ($row = $DB->fetchRow($res)) {
		$existing_revs["${row['hash']}${row['chan']}"] = 1;
	}
	
	$new_remotes = array();
	
	foreach ($CHANS as $chan => $branch) {
		$ref_components = array_reverse(explode('/',$branch));
		$remote = $ref_components[1];
		$branch_short = "$remote/${ref_components[0]}";
		
		if(!in_array($remote,$new_remotes,true))
		{
			try{
				$new_remotes[$remote] = import_remote($existing_revs, $chan, $remote);
			}catch(Exception $e){
				print "Caught an exception: " + $e->getMessage() + "\n";
				continue;
			}
		}
					
		$old_rev;
		if($new_remotes[$remote])
		{
			if($branch_short == "$remote/master")
				continue;
			$old_rev = rtrim(`zsh -c 'diff -u <(git rev-list --first-parent $branch_short) <(git rev-list --first-parent $remote/master)' | sed -ne 's/^ //p' | head -1`);
		}
		else
		{
			$old_rev = rtrim(`git rev-parse $branch`);
			exec("git fetch $remote 2>&1");
		}
		$new_rev = rtrim(`git rev-parse $branch`);
		
		if(preg_match('/^[A-Za-z0-9]{40}$/i', $old_rev) == false)
		{
			print "Failed to parse \$old_rev ($old_rev) for '$branch_short'\n";
			continue;
		}
		if(preg_match('/^[A-Za-z0-9]{40}$/i', $new_rev) == false)
		{
			print "Failed to parse \$new_rev ($new_rev) for '$branch_short'\n";
			continue;
		}
		if($old_rev == $new_rev)
		{
			print "'$branch_short' already up to date'\n";
			continue;
		}
		
		$revs = array_reverse(explode(chr(0), rtrim(`git rev-list $old_rev..$new_rev --header`)));
		
		$count = import_rev_array($existing_revs, $revs, $chan);
		print "$count revisions successfully imported for channel '$chan'\n";
	}
}

function save_build_changes($changes, $chan)
{
	global $DB;


	$DB->query("begin");
	foreach ($changes as $buildNr => $revs) {
		$DB->query(kl_str_sql("delete from  changes where build=!i and chan=!s", $buildNr, $chan));
		$DB->query(kl_str_sql("insert into changes (build, chan, revisions) values (!i, !s, !s)", $buildNr, $chan, implode(",", $revs)));
	}
	$DB->query("commit");

}


function set_changes($build, $chan)
{
	global $DB, $CHANS;

	$DB->query("begin");
	$DB->query("create table if not exists changes (build integer, chan varchar, revisions text, primary key(build, chan))");
	$DB->query("commit");

	if (!($res = $DB->query(kl_str_sql("select * from builds where chan=!s and nr<=!i order by nr desc", $chan, $build)))) {
		return;
	}

	if (!($current = $DB->fetchRow($res))) return;
	if (!($previous = $DB->fetchRow($res)))
	{
		if(!array_key_exists($chan,$CHANS))
			return;
		if($CHANS[$chan] == "refs/remotes/origin/master")
			return;
			
		$ref_components = array_reverse(explode('/',$CHANS[$chan]));
		$remote = $ref_components[1];
		$branch_short = "$remote/${ref_components[0]}";
		$previous["hash"] = rtrim(`zsh -c 'diff -u <(git rev-list --first-parent $branch_short) <(git rev-list --first-parent $remote/master)' | sed -ne 's/^ //p' | head -1`);
	}
	
	if(preg_match('/^[A-Za-z0-9]{40}$/i', $current["hash"]) == false) return;
	if(preg_match('/^[A-Za-z0-9]{40}$/i', $previous["hash"]) == false) return;
	
	chdir(SITE_ROOT . "/lib/source");
	
	$revs = explode("\n", rtrim(`git rev-list {$previous["hash"]}..{$current["hash"]}`));
	
	$changes = array();
	$changes[$build] = $revs;
	save_build_changes($changes, $chan);
}


function add_build($build, $chan, $version, $hash)
{
	global $DB;

	// check if table exists
	$DB->query("begin"); 
	$DB->query("create table if not exists builds(nr integer, chan varchar, version varchar, hash varchar,  file varchar, modified timestamp, primary key(nr, chan))");
	$DB->query("commit"); 
	
	$res = $DB->query(kl_str_sql("select count(*) as c from builds where nr=!i and chan=!s", $build, $chan));
	$row = $DB->fetchRow($res);

	if ($row["c"] == 0) {
		$DB->query("begin"); 
		$DB->query(kl_str_sql("insert into builds (nr, chan, version, hash, modified) ".
							  "values (!i, !s, !s, !s, !t)",
							  $build, $chan, $version, $hash, time() - date("Z")));
		$DB->query(kl_str_sql("insert into builds_all (nr, chan, version, hash, modified) ".
							  "values (!i, !s, !s, !s, !t)",
							  $build, $chan, $version, $hash, time() - date("Z")));
		$DB->query("commit"); 
	}
}

function find_files($chan, $version, $hash)
{
	global $DB;

	$files = array(
		"{$chan}_" . str_replace(".", "-", $version) . "_Setup.exe",
		"{$chan}_" . str_replace(".", "-", $version) . "_Setup.exe.log",
		"{$chan}_" . str_replace(".", "-", $version) . "_x86-64_Setup.exe",
		"{$chan}_" . str_replace(".", "-", $version) . "_x86-64_Setup.exe.log",
		"{$chan}-i686-{$version}.tar.bz2",
		"{$chan}-i686-{$version}.tar.bz2.log",
		"{$chan}-x86_64-{$version}.tar.bz2",
		"{$chan}-x86_64-{$version}.tar.bz2.log",
		"{$chan}_" . str_replace(".", "_", $version) . ".dmg",
		"{$chan}_" . str_replace(".", "_", $version) . ".dmg.log");
	
	$DB->query("begin"); 
	$DB->query("create table if not exists changes (build integer, chan varchar, revisions text, primary key(build, chan))");
	$DB->query("commit"); 
	
	foreach($files as $file)
	{
		if(file_exists("/home/frs/project/singularityview/alphas/$file"))
		{
			$DB->query("begin");
			$DB->query("create table if not exists downloads (file_name varchar)");
			$DB->query("commit");
			
			$res = $DB->query(kl_str_sql("select count(*) as c from downloads where file_name=!s", $file));
			$row = $DB->fetchRow($res);
			if ($row["c"] == 0) {
				$DB->query("begin");
				$DB->query(kl_str_sql("insert into downloads (file_name) values (!s)", $file));
				$DB->query("commit");
				print "$file added to db\n";
			}
			else
				print "$file already in db\n";
		}
		else
			print "Failed to find $file\n";
	}
}

if($_SERVER['argv'][1] == 'fix')
{
  chdir(SITE_ROOT . "/lib/source");
  $DB->query("begin");
  $DB->query("drop table if exists revs_alt");
  $DB->query("create table if not exists revs_alt(hash varchar, chan varchar, author varchar, time timestamp, message text, diff text, primary key(chan, hash))");
  $DB->query("insert into revs_alt select * from revs");
  $DB->query("drop table revs");
  $DB->query("alter table revs_alt rename to revs");
  $DB->query("commit");
  import_revs();
  exit(1);
}

/* main */
if ($_SERVER['argc'] < 4) {
	print "Too few arguments.\nUsage: import_revs.php <channel> <version> <hash>\n";
	exit(1);
}

$CHAN = $_SERVER['argv'][1];
$VERSION = $_SERVER['argv'][2];
$HASH = $_SERVER['argv'][3];
$build_parts = explode(".", $VERSION);
if(preg_match('/^[A-Za-z0-9]{40}$/i', $HASH) == false)
{
	print "Invalid commit hash.\n";
	die();
}

if (count($build_parts) != 4) {
	print "Wrong version format, expected x.y.z.build\n";
	die();
}

if(!array_key_exists($CHAN,$CHANS))
{
	print "Unknwon channel.\n";
	die();
}
			
$BUILD = $build_parts[3];

$DB->query("PRAGMA synchronous = OFF");
chdir(SITE_ROOT . "/lib/source");

//Fill out our database with git revisions
import_revs();

add_build($BUILD, $CHAN, $VERSION, $HASH);
set_changes($BUILD, $CHAN);
find_files($CHAN, $VERSION, $HASH);

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: noet sw=4 ts=4 fdm=marker
 * vim<600: noet sw=4 ts=4
 */
