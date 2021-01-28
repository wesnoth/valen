<?php
/*
 * codename "Valen": a Wesnoth facilities status page
 * index.php: Web front-end
 *
 * Copyright (C) 2012 - 2021 by Iris Morelle <shadowm2006@gmail.com>
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

if(!defined('IN_VALEN')) {
	die();
}

/* NOTE: this must be set to the path used for valen.pl's reports. */
define('VALEN_REPORT_FILE', '/var/lib/valen/report.json');
/* Site notice text used to display downtime announcements. */
define('VALEN_NOTICE_FILE', '/var/lib/valen/notice.html');

define('STATUS_UNKNOWN',		-1);
define('STATUS_FAIL',			 0);
define('STATUS_GOOD',			 1);
define('STATUS_INCOMPLETE',		 2);
define('STATUS_DNS_IS_BAD',		 3);

// Valen report data, read from JSON.
$report = array();
// Valen report timestamp.
$report_ts = null;
// Valen front-end refresh interval. Must be a valid non-zero integer.
$refresh_interval = 900;
// Site notice.
$site_notice = null;

/*
 * Encode special HTML characters in $str as entities.
 */
function encode_html($str, $encode_quotes = true)
{
	return htmlspecialchars($str, ($encode_quotes ? ENT_COMPAT : ENT_NOQUOTES), 'UTF-8');
}

/*
 * Loads the valen JSON report file $file.
 */
function valen_load_json($file)
{
	global $report, $report_ts, $refresh_interval;

	$json = @file_get_contents($file);

	if ($json === false)
	{
		return;
	}

	$envelope = json_decode($json, true);

	if ($envelope === null)
	{
		return;
	}

	$report = array();

	foreach ($envelope['facilities'] as $fdata)
	{
		$fhost = $fdata['hostname'];

		if ($fhost !== null)
		{
			$report[$fhost] = $fdata;
		}
	}

	valen_make_synthetic_dns_facility();

	if (isset($envelope['refresh_interval']))
	{
		$refresh_interval = $envelope['refresh_interval'];
	}

	$report_ts = $envelope['ts'];
}

/*
 * Loads the site notice text file $file.
 */
function valen_load_notice_text($file)
{
	global $site_notice;

	$text = @file_get_contents($file);

	if ($text === false)
	{
		return;
	}

	$text = trim($text);

	if (empty($text))
	{
		return;
	}

	$site_notice = $text;
}

function valen_make_synthetic_dns_facility()
{
	global $report;

	$dns_status = STATUS_GOOD;
	$dns_good_count = 0;
	$dns_bad = array();

	foreach ($report as $fdata)
	{
		if ($fdata['dns'] != STATUS_GOOD)
		{
			$dns_bad[] = $fdata['hostname'];
		}
		else
		{
			++$dns_good_count;
		}
	}

	if ($dns_good_count == 0)
	{
		$dns_status = STATUS_FAIL;
	}
	else if (!empty($dns_bad))
	{
		$dns_status = STATUS_INCOMPLETE;
	}

	$dns_fid = 'DNS';
	$dns_fdata = array(
		'name'					=> 'Domain Name System',
		'desc'					=> 'Resolves names such as “wesnoth.org” to IP addresses',
		'broken_dns_hostnames'	=> $dns_bad,
		'status'				=> $dns_status,
		'dns'					=> ($dns_status == STATUS_GOOD ? STATUS_GOOD : STATUS_DNS_IS_BAD),
	);

	$report = array($dns_fid => $dns_fdata) + $report;
}

function valen_facility_status_overall($facility_id)
{
	global $report;

	$facility = $report[$facility_id];
	$status = STATUS_UNKNOWN;

	if ($facility)
	{
		// Start by assuming the best.
		$status = STATUS_GOOD;

		// FIXME: For some reason the 'dns' field gets serialized as a string
		//        by the backend at the moment. This is why we don't use
		//        strict equality below, for this and various other fields.
		if ($facility['dns'] != STATUS_GOOD)
		{
			// TODO: We already have a DNS pseudo-facility. It might be better
			//       to stick to that and drop the STATUS_DNS_IS_BAD option,
			//       and let real facilities be reported with their pure state
			//       regardless of any DNS situations.
			$status = STATUS_DNS_IS_BAD;
		}

		$probe_result = @$facility['status'];

		// Probes always return either STATUS_FAIL or STATUS_GOOD. This also
		// applies to individual instances below.
		if ($probe_result !== null && $probe_result == STATUS_FAIL)
		{
			$status = STATUS_FAIL;
		}

		$instances = @$facility['instances'];

		if (is_array($instances) && !empty($instances))
		{
			$at_least_one_instance_good = false;
			$at_least_one_instance_bad = false;

			foreach ($instances as $idata)
			{
				if ($idata['status'] == STATUS_GOOD)
				{
					$at_least_one_instance_good = true;
				}

				if ($idata['status'] == STATUS_FAIL)
				{
					$at_least_one_instance_bad = true;
				}
			}

			if (!$at_least_one_instance_good)
			{
				$status = STATUS_FAIL;
			}
			else if ($at_least_one_instance_bad && $status == STATUS_GOOD)
			{
				$status = STATUS_INCOMPLETE;
			}
		}
	}

	return $status;
}

valen_load_json(VALEN_REPORT_FILE);
valen_load_notice_text(VALEN_NOTICE_FILE);
