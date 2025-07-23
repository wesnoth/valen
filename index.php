<?php
/*
 * codename "Valen": a Wesnoth facilities status page
 * index.php: Web front-end
 *
 * Copyright (C) 2012 - 2025 by Iris Morelle <shadowm@wesnoth.org>
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

define('IN_VALEN', true);
include('./common.php');

function vweb_format_response_time($rtime)
{
	$rtime_display_text = 'Last response time: ';

	if ($rtime !== null)
	{
		if ($rtime > 1000)
		{
			$rtime_display_text .= round($rtime / 1000, 1) . ' s';
		}
		else
		{
			$rtime_display_text .= round($rtime, 0) . ' ms';
		}
	}
	else
	{
		$rtime_display_text .= 'N/A';
	}

	return encode_html($rtime_display_text);
}

/*
 * Print subreport for a single facility instance.
 */
function vweb_process_instance($idata)
{
	$iid = $idata['id'];
	$status = $idata['status'];

	$rtime = @$idata['response_time'];

	$traffic_light_color = $traffic_light_text = '';

	if ($status == STATUS_GOOD)
	{
		$traffic_light_color = 'green';
		$traffic_light_text = 'Up';
	}
	else
	{
		$traffic_light_color = 'red';
		$traffic_light_text = 'Down';
	}

	echo '<li>' .
		'<span class="instatus" title="' . vweb_format_response_time($rtime) . '">' .
		'<span class="inname">' . $iid . '</span>' .
		'<span class="' . $traffic_light_color . '">' .
			'<span class="status-label '. $traffic_light_color . '">' . $traffic_light_text . '</span>' .
		'</span>' .
		'</span>' .
		'</li>';
}

/*
 * Print report for a single facility item and its instances.
 */
function vweb_process_facility($fid, $fdata)
{
	if (isset($fdata['hidden']) && $fdata['hidden'])
	{
		return;
	}

	echo '<li>';

	$name = (isset($fdata['name']) ? $fdata['name'] : $fid);

	$hostname = @$fdata['hostname'];
	$desc = @$fdata['desc'];
	$links = @$fdata['links'];
	$broken_dns_hostnames = @$fdata['broken_dns_hostnames'];
	$instances = @$fdata['instances'];
	$rtime = @$fdata['response_time'];

	$status = valen_facility_status_overall($fid);

	$traffic_light_color = $traffic_light_text = '';

	if ($status == STATUS_GOOD)
	{
		$traffic_light_color = 'green';
		$traffic_light_text = 'Online';
	}
	else if ($status == STATUS_FAIL)
	{
		$traffic_light_color = 'red';
		$traffic_light_text = 'Offline';
	}
	else
	{
		$traffic_light_color = 'yellow';
		$traffic_light_text = 'Issues';
	}

	?>
	<dl class="header">
		<dt><?php

		echo encode_html($name, false);

		if ($hostname !== null)
		{
			// Leading space required.
			echo ' <span class="hostname">' . $hostname . '</span>';
		}

		?></dt>
		<dd class="<?php echo $traffic_light_color ?>">
			<span class="status-label <?php echo $traffic_light_color ?>" title="<?php echo vweb_format_response_time($rtime) ?>"><?php echo $traffic_light_text ?></span>
		</dd>
	</dl>

	<div class="details"><?php

	if ($desc !== null)
	{
		echo '<span class="description">' . encode_html($desc, false) . '</span>';
	}

	if (is_array($links) && !empty($links))
	{
		$is_first_link = true;

		foreach ($links as $l)
		{
			if (!$is_first_link)
			{
				echo ' &#8226; ';
			}
			else
			{
				$is_first_link = false;
			}

			echo '<a href="' . encode_html($l['url']) . '" rel="nofollow">' . encode_html($l['title'], false) .'</a>';
		}
	}

	?></div><?php

	if (is_array($broken_dns_hostnames) && !empty($broken_dns_hostnames))
	{
		?><div class="dnsreport">
			<span class="red bold">The following domain names are unavailable, compromised, or incorrectly configured:</span>
			<div class="dnslist">
				<ul><?php
				foreach($broken_dns_hostnames as $hostname)
				{
					echo '<li>' . encode_html($hostname, false) . '</li>';
				}
				?></ul>
			</div>
		</div><?php
	}

	if (is_array($instances) && !empty($instances))
	{
		?><div class="inreport">
			<ul><?php
			foreach ($instances as $idata)
			{
				vweb_process_instance($idata);
			}
			?></ul>
		</div>
		<div class="fc"></div><?php
	}

	echo '</li>';
}

//
// Set up JS front-end variables.
//

function vweb_int_or_null($value)
{
	echo ($value === null ? 'null' : (int)($value));
}

// If the report TS isn't available, that's a sure sign the report is bad or
// unavailable.

$report_available = $report_ts !== null;

//
// Enable gzip compression.
//

if (function_exists('ob_gzhandler') && extension_loaded('zlib'))
{
	ob_start('ob_gzhandler');
}

//
// Begin HTML view.
//

?><!doctype html>

<html lang="en">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width,initial-scale=1" />

	<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Montaga%7COpen+Sans:400,400i,700,700i" type="text/css" />
	<link rel="icon" type="image/png" href="wesmere/favicon-32.png" sizes="32x32" />
	<link rel="icon" type="image/png" href="wesmere/favicon-16.png" sizes="16x16" />
	<link rel="stylesheet" type="text/css" href="wesmere/mini.css" />
	<link rel="stylesheet" type="text/css" href="valen/valen2.css" />

	<title>Wesnoth.org Site Status</title>

	<script src="wesmere/modernizr.js"></script>

	<script type="text/javascript">
	// <![CDATA[
		var refresh_interval = <?php vweb_int_or_null($refresh_interval) ?>;
		var report_ts = <?php vweb_int_or_null($report_ts) ?>;

		var page_description_shown = false;

		function int(value)
		{
			return parseInt(value, 10);
		}

		function adjust_refresh_interval_to_clock()
		{
			var cur_ts, upd_ts;

			cur_ts = upd_ts = (new Date()).getTime() / 1000;

			upd_ts = refresh_interval * (1 + int(upd_ts / refresh_interval));

			var new_interval = upd_ts - cur_ts;

			if (new_interval > 0)
			{
				refresh_interval = new_interval;
			}
		}

		function unit_display(value, unit)
		{
			var intval = int(value);
			return intval + ' ' + unit + (intval > 1 ? 's' : '');
		}

		function timestamp_diff_display(delta)
		{
			var secs = delta % 60;
			delta = /*int*/((delta - secs) / 60);

			var mins = delta % 60;
			delta = /*int*/((delta - mins) / 60);

			var hours = delta % 24;
			delta = /*int*/((delta - hours) / 24);

			var text_bits = new Array();

			if (delta)
				text_bits.push(unit_display(delta, 'day'));

			if (hours)
				text_bits.push(unit_display(hours, 'hour'));

			if (mins)
				text_bits.push(unit_display(mins, 'minute'));

			if (secs && mins == 0)
				text_bits.push(unit_display(secs, 'second'));

			return text_bits.join(', ');
		}

		function update_refresh_timer_display(remaining)
		{
			var e = document.getElementById('refresh-interval');
			if (!e)
				return;

			var mins = int(remaining / 60);
			var secs = Math.max(int(remaining % 60), 0);

			var text = 'Refreshing in ';

			if (mins)
				text += mins + ' minute' + (mins != 1 ? 's' : '') + ' and ';

			text += secs + ' seconds';

			text += '\u2026';

			e.firstChild.nodeValue = text;
		}

		function update_report_timer()
		{
			var e = document.getElementById('report-ts');
			if (!e)
				return;

			var age = int((new Date()).getTime()/1000 - report_ts);

			if (age < 60)
				e.firstChild.nodeValue = 'Updated less than a minute ago';
			else if (age >= 60 && age < 120)
				e.firstChild.nodeValue = 'Updated a minute ago';
			else
				e.firstChild.nodeValue = 'Updated ' +
					timestamp_diff_display(age) + ' ago';
		}

		function toggle_page_description()
		{
			page_description_shown = !page_description_shown;
			document.getElementById('page-description').style.display =
				page_description_shown ? '' : 'none';
		}
	// ]]>
	</script>
</head>

<body>

<div id="main">

<div id="nav" role="banner">
<div class="centerbox">

	<div id="logo">
		<a href="https://www.wesnoth.org/" aria-label="Wesnoth logo"></a>
	</div>

	<ul id="navlinks">
		<li><a href="https://status.wesnoth.org/">Status</a></li>
		<li><a href="https://www.wesnoth.org/">Home</a></li>
		<li><a href="https://forums.wesnoth.org/viewforum.php?f=62">News</a></li>
		<li><a href="https://wiki.wesnoth.org/Play">Play</a></li>
		<li><a href="https://wiki.wesnoth.org/Create">Create</a></li>
		<li><a href="https://forums.wesnoth.org/">Forums</a></li>
		<li><a href="https://wiki.wesnoth.org/Project">About</a></li>
	</ul>

	<div class="reset"></div>
</div>
</div>

<div id="content" role="main">

<?php

// If the report TS isn't available, that's a sure sign the report is bad or
// unavailable.

if ($report_available)
{
	?>

	<?php if (!empty($site_notice)): ?>

	<div class="status-site-notice">
		<div class="notice-header">Status Notice</div>
		<div><?php echo $site_notice ?></div>
	</div>
	<?php endif ?>

	<h1 class="fl">Site Status</h1>

	<div id="page-description-toggle" class="fr" style="display:none;"><a href="#" onclick="toggle_page_description(); return false;">What is this?</a></div>

	<div class="fc"></div>

	<div id="page-description">
		<script type="text/javascript">
			document.getElementById('page-description').style.display = 'none';
			document.getElementById('page-description-toggle').style.display = '';
		</script>

		<p>The various services provided by Wesnoth.org are regularly monitored
		for possible unexpected downtimes. If you find a problem accessing our
		site or servers, you can come here to check whether the problem is only
		at your end or affects everyone.</p>

		<p>For reporting issues or requesting help, you can use our <a href="https://discord.gg/battleforwesnoth"><b>official Discord server</b></a>, or our official <abbr title="Internet Relay Chat">IRC</abbr> channel <a href="https://web.libera.chat/#wesnoth"><b>#wesnoth</b></a> on <code class="noframe">irc.libera.chat</code>.</p>

		<p>Alternatively, you may use our forums:</p>

		<ul>
			<li><a href="https://forums.wesnoth.org/viewforum.php?f=4">Technical Support</a> — For assistance with the add-ons and multiplayer client functionality of the game</li>
			<li><a href="https://forums.wesnoth.org/viewforum.php?f=17">Website</a> — For reporting problems or suggesting ideas for the Wesnoth.org website in general</li>
		</ul>
	</div>

	<div class="chronology">
		<span id="report-ts" class="updated fl">Updated on <?php echo date("Y-m-d H:i T") ?></span>
		<span id="refresh-interval" class="refreshing fr" style="display:none;">&nbsp;</span>
	</div>

	<div class="fc"></div>

	<ul class="facilities"><?php

	foreach ($report as $facility_id => $data)
	{
		vweb_process_facility($facility_id, $data);
	}

	?></ul>

	<?php
}
else
{
	?>

	<h1>Site Status</h1>

	<div class="chronology">
		<span id="refresh-interval" class="refreshing fr" style="display:none;">&nbsp;</span>
	</div>

	<div class="fc"></div>

	<div class="status-report-unavailable">
		<p>The Wesnoth.org Site Status service is currently unavailable. Please try again at a later time.</p>
	</div>

	<?php
}

?>

<div class="fc"></div>

</div> <!-- end content -->

</div> <!-- end main -->

<div id="footer-sep"></div>

<div id="footer"><div id="footer-content"><div>
	<a href="https://wiki.wesnoth.org/StartingPoints">Site Map</a> &#8226; <a href="https://status.wesnoth.org/">Site Status</a><br />
	Copyright &copy; 2003&ndash;2025 by <a rel="author" href="https://wiki.wesnoth.org/Project">The Battle for Wesnoth Project</a><br />
	Site design Copyright &copy; 2017&ndash;2025 by Iris Morelle
</div></div></div>

<script type="text/javascript">
// <![CDATA[
	adjust_refresh_interval_to_clock();

	update_report_timer();
	update_refresh_timer_display(refresh_interval);

	if (refresh_interval)
	{
		var e = document.getElementById('refresh-interval');
		if (e)
			e.style.display = '';

		setTimeout(function() {
			window.location.reload(1);
		}, refresh_interval * 1000);
	}

	var refresh_timer_value = refresh_interval;

	setInterval(function() {
		update_report_timer();
		update_refresh_timer_display(--refresh_timer_value);
	}, 1000);
// ]]>
</script>

</body>

</html>
