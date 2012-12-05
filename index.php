<?php
/*
 * codename "Valen": a Wesnoth facilities status page
 * index.php: Web front-end
 *
 * Copyright (C) 2012 by Ignacio Riquelme Morelle <shadowm2006@gmail.com>
 *
 * Permission to use, copy, modify, and/or distribute this software for any
 * purpose with or without fee is hereby granted, provided that the above
 * copyright notice and this permission notice is present in all copies.
 *
 * THIS SOFTWARE IS PROVIDED BY THE AUTHOR ``AS IS'' AND ANY EXPRESS OR
 * IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR ANY DIRECT,
 * INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION)
 * HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT,
 * STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING
 * IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

/* NOTE: this must be set to the path used for valen.pl's reports. */
define('VALEN_REPORT_FILE', '/var/lib/valen/report');

define('STATUS_UNKNOWN',		-1);
define('STATUS_FAIL',			 0);
define('STATUS_GOOD',			 1);
define('STATUS_INCOMPLETE',		 2);

$status = array();

$status_timestamp = 0;

function read_report_file($file)
{
	global $status, $status_timestamp;

	$lines = @file($file, FILE_IGNORE_NEW_LINES);

	if($lines === FALSE)
	{
		return;
	}

	foreach($lines as $line)
	{
		list($key, $value) = explode('=', $line, 2);
		//list($key, $value) = array_map('trim', explode('=', $line, 2));

		if($key === 'ts')
		{
			$status_timestamp = $value;
		}
		else
		{
			$status[$key] = $value;
		}
	}
}

function get_status($status) {
	switch($status)
	{
		case STATUS_GOOD:
			$class = 'status-ok';
			$label = 'Online';
		break;
		case STATUS_FAIL:
			$class = 'status-fail';
			$label = 'Offline';
		break;
		case STATUS_INCOMPLETE:
			$class = 'status-wonky';
			$label = 'Some issues';
		break;
		default:
			$class = 'status-unknown';
			$label = 'Unknown';
		break;
	}
	return array($class, $label);
}

function get_numeric_version($version) {
	switch($version)
	{
		case 'ancientstable':
			return '1.6';
		case 'oldstable':
			return '1.8';
		case 'stable':
			return '1.10';
		case 'dev':
			return '1.11';
		case 'trunk':
			return '1.11+svn';
		default:
			return 'unknown';
	}
}

function display_status($facility_id, $display_title, $display_description, $subversions = array())
{
	global $status;


	list($class, $label) = get_status($status[$facility_id]);

	print('<li class="' . $facility_id . ' ' . $class . '"><span class="entry">' .
		'<span class="title">' . $display_title . '</span>' .
		'<span class="description">' . $display_description . '</span>' .
		'</span><span class="statuses">' .
		'<span class="status">' . $label . '</span>' .
		'<span class="substatuses">');
	foreach($subversions as $version) {
		$numeric = get_numeric_version($version);
		$full_id = "$facility_id-$version";
		list($class, $label) = get_status($status[$full_id]);
		print(' <span class="sub' . $class . '" title="' . $label . '">' . $numeric . '</span>');
	}
		print('</span></span>' .
		'<span class="clear"><span></span></span>' .
		'</li>');

	return;
}

function display_server_time()
{
	print(gmstrftime('%c'));
}

function display_status_report_age()
{
	global $status_timestamp;

	if($status_timestamp === 0)
	{
		print("Never.");
		return;
	}

	$text = '';

	$delta = time() - $status_timestamp;

	if($delta < 0)
	{
		$text = "<strong>IN THE FUTURE!</strong>";
	}
	else
	{
		$sec = $delta % 60;
		$delta = (int)(($delta - $sec) / 60);

		$min = $delta % 60;
		$delta = (int)(($delta - $min) / 60);

		$hr = $delta % 24;
		$delta = (int)(($delta - $hr) / 24);

		$days = $delta;

		$text_bits = array();

		if($days)
		{
			$text_bits[] = $days . " day" . ($days > 1 ? 's' : '');
		}

		if($hr)
		{
			$text_bits[] = $hr . " hour" . ($hr > 1 ? 's' : '');
		}

		if($min)
		{
			$text_bits[] = $min . " minute" . ($min > 1 ? 's' : '');
		}

		if($sec && $min === 0)
		{
			$text_bits[] = $sec . " second" . ($sec > 1 ? 's' : '');
		}

		$text = implode(", ", $text_bits) . " ago.";
	}

	print($text);
}

read_report_file(VALEN_REPORT_FILE);

// Begin HTML view.

?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">

<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
	<meta http-equiv="content-type" content="text/html; charset=UTF-8" />
	<meta http-equiv="content-language" content="en" />
	<meta http-equiv="content-style-type" content="text/css" />

	<title>Battle for Wesnoth &bull; Site Status</title>

	<link rel="shortcut icon" href="./glamdrol/favicon.ico" type="image/x-icon" />

	<link rel="stylesheet" type="text/css" href="./glamdrol/main.css" />
	<link rel="stylesheet" type="text/css" href="./valen/valen.css" />
</head>

<body>

<div id="global">

<div id="header">
  <div id="logo">
    <a href="http://www.wesnoth.org/"><img alt="Wesnoth logo" src="./glamdrol/wesnoth-logo.jpg" /></a>
  </div>
</div>

<div id="nav">
  <ul>
    <li><a href="http://www.wesnoth.org/">Home</a></li>
    <li><a href="http://wiki.wesnoth.org/Play">Play</a></li>
    <li><a href="http://wiki.wesnoth.org/Create">Create</a></li>
    <li><a href="http://forums.wesnoth.org/">Forums</a></li>
    <li><a href="http://wiki.wesnoth.org/Support">Support</a></li>
    <li><a href="http://wiki.wesnoth.org/Project">Project</a></li>
    <li><a href="http://wiki.wesnoth.org/Credits">Credits</a></li>
    <li><a href="http://wiki.wesnoth.org/UsefulLinks">Links</a></li>
    <li><a href="#">Status</a></li>
  </ul>
</div>

<div id="main">
<div id="content">

<h1>Site Status</h1>

<div id="contentSub"></div>

<ul class="status-table"><?php
	display_status(
		'dns',
		'DNS',
		'Resolves names such as ‘wesnoth.org’ to IP addresses'
	);

	display_status(
		'web',
		'Wesnoth.org web',
		'Wesnoth.org HTTP server and front page'
	);

	display_status(
		'wiki',
		'Wesnoth.org wiki',
		'Wesnoth.org MediaWiki instance &bull; <a href="http://wiki.wesnoth.org/Special:Statistics">Stats</a>'
	);

	display_status(
		'forums',
		'Wesnoth.org forums',
		'Wesnoth.org phpBB instance'
	);

	display_status(
		'addons',
		'Add-ons server',
		'Stable and development add-ons server instances',
		array('stable', 'dev')
	);

	display_status(
		'mp-main',
		'Primary MP server',
		'Official main MP server &bull; <a href="http://wesnothd.wesnoth.org/">Stats</a>',
		array('ancientstable', 'oldstable', 'stable', 'dev')
	);

	display_status(
		'mp-alt2',
		'Alternate MP server (server2.wesnoth.org)',
		'Official alternate MP server',
		array('ancientstable', 'oldstable', 'stable')
	);

	display_status(
		'mp-alt3',
		'Alternate MP server (server3.wesnoth.org)',
		'Official alternate MP server',
		array('ancientstable', 'oldstable', 'stable')
	);
?></ul>

<div class="visualClear"></div>

<!--
<div class="floatleft">
	<img src="./glamdrol/wesnoth-icon.png" alt="" />
</div>
-->

<div class="status-age">
	Last updated <?php display_status_report_age() ?><br />
	The time now is <?php display_server_time() ?>.
</div>


<?php /*

<h2>Report an Issue</h2>

<p>If you are experiencing availability issues with some of our services,
make sure to notify us through any of our support channels:</p>

<ul>
	<li><a href="http://forums.wesnoth.org/viewforum.php?f=17">Website forum</a></li>
	<li>#wesnoth on irc.freenode.net (<a href="http://webchat.freenode.net/?channels=wesnoth">webchat</a>)</li>
</ul>

*/ ?>

</div> <!-- end content -->

<div class="visualClear"></div>

<div id="footer">
	<div id="note">
		<p>Copyright &copy; 2003-2012 The Battle for Wesnoth</p>
		<p>Supported by <a href="http://www.jexiste.fr/">Jexiste</a>.</p>
	</div>
</div>

</div> <!-- end main -->
</div> <!-- end global -->

</body>
	
</html>