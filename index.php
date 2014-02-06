<?php
/*
 * codename "Valen": a Wesnoth facilities status page
 * index.php: Web front-end
 *
 * Copyright (C) 2012 - 2014 by Ignacio Riquelme Morelle <shadowm2006@gmail.com>
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
			$rtime_display_text .= round($rtime, 1) . ' ms';
		}
	}
	else
	{
		$rtime_display_text .= 'N/A';
	}

	return htmlentities($rtime_display_text);
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
		$traffic_light_text = 'On';
	}
	else
	{
		$traffic_light_color = 'red';
		$traffic_light_text = 'Off';
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

		echo htmlentities($name);

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
		echo '<span class="description">' . htmlentities($desc) . '</span>';
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

			echo '<a href="' . htmlentities($l['url']) . '">' . htmlentities($l['title']) .'</a>';
		}
	}

	?></div><?php

	if (is_array($broken_dns_hostnames) && !empty($broken_dns_hostnames))
	{
		?><div class="dnsreport">
			<span class="red bold">The following domain names are unavailable, compromised, or incorrectly configured:</span>
			<ul><?php
			foreach($broken_dns_hostnames as $hostname)
			{
				echo '<li>' . htmlentities($hostname) . '</li>';
			}
			?></ul>
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

	<title>Site Status &bull; Battle for Wesnoth</title>

	<link rel="shortcut icon" href="./glamdrol/favicon.ico" type="image/x-icon" />

	<link rel="stylesheet" type="text/css" href="./glamdrol/mini.css" />
	<link rel="stylesheet" type="text/css" href="./valen/valen2.css" />

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

		function timestamp_diff_display(ts)
		{
			var delta = (new Date()).getTime()/1000 - ts;

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
			var e = document.getElementById('refresh_interval');
			if (!e)
				return;

			var mins = int(remaining / 60);
			var secs = remaining % 60;

			var text = 'Refreshing in ' + mins + ' minutes';

			if (secs)
			{
				text += ' and ' + int(secs) + ' seconds';
			}

			text += '\u2026';

			e.firstChild.nodeValue = text;
		}

		function update_report_timer()
		{
			var e = document.getElementById('report_ts');
			if (!e)
				return;

			e.firstChild.nodeValue =
				'Updated ' + timestamp_diff_display(report_ts) + ' ago';
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
	</ul>
</div>

<div id="main">

<div id="content">

<?php

// If the report TS isn't available, that's a sure sign the report is bad or
// unavailable.

if ($report_available)
{
	?>

	<h1 class="fl">Site Status</h1>

	<div id="page-description-toggle" class="fr"><a href="#" onclick="toggle_page_description(); return false;">What is this?</a></div>

	<div class="fc"></div>

	<div id="page-description" style="display:none;">
		<p>The various services provided by Wesnoth.org are regularly monitored
		for possible unexpected downtimes. If you find a problem accessing our
		site or servers, you can come here to check whether the problem is only
		at your end or affects everyone.</p>

		<p>For reporting issues and requesting help, please visit our IRC
		channel <strong>#wesnoth</strong> on the
		<a href="https://freenode.net/">freenode IRC network</a>:</p>

		<ul>
			<li><a href="https://webchat.freenode.net/?channels=%23wesnoth">Using your browser</a></li>
			<li><a href="irc://chat.freenode.net/%23wesnoth">Using a dedicated IRC client</a></li>
		</ul>

		<br />

		<hr />

		<br />
	</div>

	<div class="chronology">
		<span id="report_ts" class="updated fl">Updated on <?php echo date("Y-m-d H:i T") ?></span>
		<span id="refresh_interval" class="refreshing fr" style="display:none;">&nbsp;</span>
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
		<span id="refresh_interval" class="refreshing fr" style="display:none;">&nbsp;</span>
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

<div id="footer">
	<div id="note">
		<p>Copyright &copy; 2003&ndash;2014 The Battle for Wesnoth</p>
		<p>Supported by <a href="http://www.jexiste.fr/">Jexiste</a>.</p>
	</div>
</div>

</div> <!-- end main -->

</div> <!-- end global -->

<script type="text/javascript">
// <![CDATA[
	adjust_refresh_interval_to_clock();

	update_report_timer();
	update_refresh_timer_display(refresh_interval);

	if (refresh_interval)
	{
		var e = document.getElementById('refresh_interval');
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
