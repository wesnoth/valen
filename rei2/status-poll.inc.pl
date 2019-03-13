#!/bin/true
#
# Copyright (C) 2012 - 2019 by Iris Morelle <shadowm2006@gmail.com>
# Rights to this code are as documented in COPYING.
#

use JSON;

# Constants

use constant {
	STATUS_UNKNOWN			=> -1,
	STATUS_FAIL				=>  0,
	STATUS_GOOD				=>  1,
	STATUS_INCOMPLETE		=>  2,
	STATUS_DNS_IS_BAD		=>  3,
};

my $valen_report_file = '/var/lib/valen/report.json';

my %facility_aliases = (
	'DNS'								=> 'DNS',

	'www.wesnoth.org'					=> 'Web',
	'forums.wesnoth.org'				=> 'Forums',
	'wiki.wesnoth.org'					=> 'Wiki',

	'add-ons.wesnoth.org'				=> 'Add-ons',

	'server.wesnoth.org'				=> 'MP #1',
	'server2.wesnoth.org'				=> 'MP #2',
	'server3.wesnoth.org'				=> 'MP #3',
);

my @status_display = (
	$color->{lightred} . 'Offline',
	$color->{lightgreen} . 'Online',
	$color->{orange} . 'Issues',
	$color->{orange} . 'DNS Issues', # STATUS_DNS_IS_BAD
);

# State variables

my @facilities;
my $report_ts;
my $refresh_interval;
my $last_refresh;

# Utility subs

sub vrei2_need_reload()
{
	!defined $last_refresh ||
	!defined $refresh_interval ||
	time >= $last_refresh + $refresh_interval
}

sub vrei2_load_json($)
{
	my $fname = shift;

	my $text;

	{
		local $/;
		open my $fh, '<', $fname or return 0;
		$text = <$fh>;
		close $fh;
	}

	defined $text or return 0;

	my $envelope;
	eval { $envelope = decode_json $text };
	return 0 if $@; # Probably invalid json.

	$report_ts = $envelope->{ts};

	if(!defined $report_ts || !$report_ts) {
		return 0;
	}

	$refresh_interval = $envelope->{refresh_interval};
	$last_refresh = time();

	@facilities = @{ $envelope->{facilities} };

	return 1;
}

# From common.php
sub vrei2_make_synthetic_dns_facility()
{
	@facilities or return;

	my $dns_status = STATUS_GOOD;
	my $dns_good_count = 0;
	my @dns_bad = ();

	foreach my $fdata (@facilities)
	{
		if($fdata->{dns} != STATUS_GOOD) {
			push @dns_bad, $fdata->{hostname};
		} else {
			++$dns_good_count;
		}
	}

	if(!$dns_good_count) {
		$dns_status = STATUS_FAIL;
	} elsif(@dns_bad) {
		$dns_status = STATUS_INCOMPLETE;
	}

	my $dns_fdata = {
		name							=> 'DNS',
		hostname						=> 'DNS',
		broken_dns_hostnames			=> [ @dns_bad ],
		status							=> $dns_status,
		dns								=> ($dns_status == STATUS_GOOD ? STATUS_GOOD : STATUS_DNS_IS_BAD),
	};

	unshift @facilities, $dns_fdata;
}

# From common.php
sub vrei2_facility_status_overall($)
{
	my $facility = shift;

	# Start by assuming the best.
	my $status = STATUS_GOOD;

	# FIXME: For some reason the 'dns' field gets serialized as a string
	#        by the backend at the moment.
	if($facility->{dns} != STATUS_GOOD) {
		# TODO: We already have a DNS pseudo-facility. It might be better
		#       to stick to that and drop the STATUS_DNS_IS_BAD option,
		#       and let real facilities be reported with their pure state
		#       regardless of any DNS situations.
		$status = STATUS_DNS_IS_BAD;
	}

	my $probe_result = exists $facility->{status} ? $facility->{status} : undef;

	# Probes always return either STATUS_FAIL or STATUS_GOOD. This also
	# applies to individual instances below.
	if(defined $probe_result && $probe_result == STATUS_FAIL) {
		$status = STATUS_FAIL;
	}

	my $instances = exists $facility->{instances}
		? $facility->{instances}
		: undef;

	if(defined $instances && @$instances) {
		my $at_least_one_instance_good = 0;
		my $at_least_one_instance_bad = 0;

		foreach my $idata (@$instances)
		{
			if($idata->{status} == STATUS_GOOD) {
				$at_least_one_instance_good = 1;
			}

			if($idata->{status} == STATUS_FAIL) {
				$at_least_one_instance_bad = 1;
			}
		}

		if(!$at_least_one_instance_good) {
			$status = STATUS_FAIL;
		} elsif($at_least_one_instance_bad && $status == STATUS_GOOD) {
			$status = STATUS_INCOMPLETE;
		}
	}

	return $status;
}

sub vrei2_format_age($)
{
	my $age = shift;
	my @age_bits = ();

	if($age > 0) {
		my $sec = $age % 60;
		$age = int(($age - $sec) / 60);
		my $min = $age % 60;
		$age = int(($age - $min) / 60);
		my $hr = $age % 24;
		$age = int(($age - $hr) / 24);
		my $days = $age;

		push @age_bits, $days . " day" . ($days > 1 ? 's' : '')
			if $days;

		push @age_bits, $hr . " hour" . ($hr > 1 ? 's' : '')
			if $hr;

		push @age_bits, $min . " minute" . ($min > 1 ? 's' : '')
			if $min;

		push @age_bits, $sec . " second" . ($sec > 1 ? 's' : '')
			if($sec && !$min);
	} else {
		return 'Just now';
	}

	return join(', ', @age_bits) . ' ago';
}

BOT_COMMAND_REGEX_HANDLER {
	my $event = shift;

	if(vrei2_need_reload()) {
		if(!vrei2_load_json($valen_report_file)) {
			$event->reply_msg("Could not open status report file.");
			return TRUE;
		} else {
			vrei2_make_synthetic_dns_facility();
		}
	}

	my $report_age = $last_refresh - $report_ts;

	my @irc_lines = ();

	my @broken_dns = ();
	my @display_bits = ();

	foreach my $fdata (@facilities)
	{
		!exists $fdata->{hidden} || !$fdata->{hidden}
			or next;

		my $status = vrei2_facility_status_overall($fdata);
		my $hostname = $fdata->{hostname};

		if($hostname eq 'DNS') {
			@broken_dns = @{ $fdata->{broken_dns_hostnames} };
		}

		# We need the shortest name possible for IRC.
		my $dispname = exists $facility_aliases{$hostname}
			? $facility_aliases{$hostname}
			: $hostname;

		my $dispstatus = $status >= 0 && $status < @status_display
			? $status_display[$status].$normal
			: 'STATUS='.$status;

		if($status == STATUS_INCOMPLETE && exists $fdata->{instances}) {
			my @instance_bits = ();
			# Show what went wrong.
			foreach my $idata (@{ $fdata->{instances} }) {
				$idata->{status} == STATUS_FAIL or next;
				push @instance_bits, $color->{lightred} . $idata->{id} . $normal;
			}

			$dispstatus .= ' (' . join(', ', @instance_bits) . ')'
				if @instance_bits;
		}

		push @display_bits, sprintf("\002%s\002: %s", $dispname, $dispstatus);
	}

	push @irc_lines, join('  ', @display_bits);

	if(@broken_dns) {
		push @irc_lines, "\002Broken DNS hostnames\002: " . join(', ', @broken_dns);
	}

	push @irc_lines, "\002Last updated\002: " . ($report_age >= 0
		? vrei2_format_age($report_age)
		: "\002IN THE FUTURE!\002") . '  ' . $color->{gray} . '<http://status.wesnoth.org/>' . $normal;

	foreach(@irc_lines) {
		$event->reply_msg($_);
	}

	TRUE;
} qr{^WSS(?:\s+.*)?$}i;

$help->add_entry('WSS', {
	summary     => q(Displays Wesnoth.org's status.),
	syntax      => q(WSS),
	description => q(WSS displays Wesnoth.org's status as presented in http://status.wesnoth.org/.),
	examples    => [ q(WSS) ],
});

# kate: indent-mode normal; encoding utf-8;
