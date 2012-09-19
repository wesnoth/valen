#!/usr/bin/perl
#
# codename "Valen": a Wesnoth facilities status page
# valen.pl: Web status poll script
#
# Copyright (C) 2012 by Ignacio Riquelme Morelle <shadowm2006@gmail.com>
#
# Permission to use, copy, modify, and/or distribute this software for any
# purpose with or without fee is hereby granted, provided that the above
# copyright notice and this permission notice is present in all copies.
#
# THIS SOFTWARE IS PROVIDED BY THE AUTHOR ``AS IS'' AND ANY EXPRESS OR
# IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
# WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
# DISCLAIMED. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR ANY DIRECT,
# INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
# (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
# SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION)
# HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT,
# STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING
# IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
# POSSIBILITY OF SUCH DAMAGE.
#

#
# Usage:
#   valen.pl [-d | --debug] [--addons-client=/path/to/wesnoth_addon_manager]
#            report_file_path
#

use 5.010;
use strict;
use warnings;

use LWP::UserAgent;
use Socket;

package valen;

# $| = 1;

my $VERSION = '0.0.1';

my $debug = 0;

# Path to the Wesnoth add-ons client script used to check
# the campaignd instances' status.
my $wesnoth_addon_manager = '/usr/local/bin/wesnoth_addon_manager';

my %config = (
	output_path				=> '/tmp/valen.status',

	hostname				=> 'wesnoth.org',
	# NOTE: This needs to be updated if wesnoth.org's host IP ever changes.
	host_ip					=> '65.18.193.12',

	addons_hostname			=> 'add-ons.wesnoth.org',

	addons_ports			=> [ '1.11.x', '1.10.x' ],

	mp_main_hostname		=> 'server.wesnoth.org',
	mp_alt2_hostname		=> 'server2.wesnoth.org',
	mp_alt3_hostname		=> 'server3.wesnoth.org',

	web_url					=> 'http://wesnoth.org/',
	forums_url				=> 'http://forums.wesnoth.org/',
	wiki_url				=> 'http://wiki.wesnoth.org/',
);

# A status of 0 indicates that the facility isn't working properly;
# 1 indicates that it is. A status of 2 indicates that the facility
# is not completely functional, but still working. A status of -1
# indicates that the test failed for some other reason (e.g. the
# add-ons client isn't installed on this machine).
use constant {
	STATUS_UNKNOWN			=> -1,
	STATUS_FAIL				=>  0,
	STATUS_GOOD				=>  1,
	STATUS_INCOMPLETE		=>  2,
};

my %status = ();
# Build the status table with default values.
for(qw(dns web wiki forums addons mp-main mp-alt2 mp-alt3)) {
	$status{$_} = STATUS_UNKNOWN;
}

sub dprint { print @_ if $debug }

{
	#
	# Small operation timer used to check how much time individual
	# operations took when $debug is set to 1.
	#

	package otimer;

	use Time::HiRes qw(gettimeofday tv_interval);

	sub new
	{
		my $class = shift;
		my $self = { _start_ts => [gettimeofday()] };

		bless $self, $class;

		return $self;
	}

	sub ellapsed
	{
		my ($self) = @_;

		return tv_interval($self->{_start_ts}, [gettimeofday()]);
	}

	sub DESTROY
	{
		my ($self) = @_;

		printf("<took %.2f seconds>\n", $self->ellapsed())
			if $debug;
	}
}

sub write_hash_to_file(\%$)
{
	my $hash_ref = shift;
	my $out_path = shift;

	open(my $out, '>', $out_path)
		or die("Could not open '" . $out_path . "' for writing: " . $!);

	foreach(keys %{$hash_ref}) {
		print $out $_ . '=' . $hash_ref->{$_} . "\n";
	}

	# Save a timestamp for reference when reporting in the front-end.
	print $out 'ts=' . time() . "\n";

	close $out;
}

sub check_url($)
{
	my $otimer = otimer->new();

	my $url = shift;

	my $ua = LWP::UserAgent->new();

	$ua->agent(sprintf('codename Valen/%s %s', $VERSION, $ua->_agent()));
	# 30 seconds should really be enough in the worst case.
	$ua->timeout(30);
	# Don't follow redirects, they are used by the wesnoth.org admins to
	# redirect users to status pages when things break.
	$ua->max_redirect(0);
	# A maximum of 100 KiB is a stretch, but whatever.
	$ua->max_size(102400);
	# No, we don't really want any extra processing here, just the HTTP
	# response code.
	$ua->parse_head(0);

	my $resp = $ua->head($url);

	# Really, we should only get 200 OK unless there's something unusual
	# going on with the HTTP server.
	my $ret = $resp->code() == 200;

	dprint "HTTP ($url): $ret (" . $resp->status_line() . ")\n";

	return $ret;
}

sub check_campaignd($$)
{
	unless(defined($wesnoth_addon_manager) && length($wesnoth_addon_manager) &&
	       -x $wesnoth_addon_manager) {
		return undef;
	}

	my $addr = shift;
	my $port = shift;

	return 0 == system(
		"$wesnoth_addon_manager -a $addr -p $port -l >/dev/null 2>/dev/null");
}

################################################################################
#                                                                              #
# COMMAND LINE CONFIGURATION                                                   #
#                                                                              #
################################################################################

foreach(@ARGV) {
	if($_ eq '--debug' || $_ eq '-d') {
		$debug = 1;
	} elsif(/^--addons-client=(.+)$/) {
		$wesnoth_addon_manager = $1;
	}
}

if(@ARGV && $ARGV[-1] !~ m{^-+}) {
	$config{output_path} = $ARGV[-1];
}

################################################################################
#                                                                              #
# DNS CHECK                                                                    #
#                                                                              #
################################################################################

{

#
# The add-ons server, forums, and wiki currently share the same host address
# as the web server, so we don't need to check them here.
#

my @unique_hosts = (
	$config{hostname}, $config{mp_alt2_hostname}, $config{mp_alt3_hostname});

foreach my $hostname (@unique_hosts) {
	my $otimer = otimer->new();

	my (undef, undef, undef, undef, @addr) = gethostbyname($hostname);

	my $passed = @addr > 0;
	dprint "DNS ($hostname): $passed\n";

	if(($status{dns} == STATUS_GOOD && !$passed) ||
	   ($status{dns} == STATUS_FAIL && $passed)) {
		$status{dns} = STATUS_INCOMPLETE;
	} else { # elsif($status{dns} == STATUS_UNKNOWN) {
		$status{dns} = $passed;
	}
}

dprint "*** DNS: " . $status{dns} . "\n";

}

################################################################################
#                                                                              #
# WEB/WIKI & FORUMS CHECK                                                      #
#                                                                              #
################################################################################

{

#
# For these checks we only really need to know whether we get an OK status
# from the server when fetching the relevant addresses.
#

$status{web} = check_url($config{web_url});

if($status{dns} != STATUS_GOOD && $status{web} != STATUS_GOOD) {
	dprint "*** DNS issues, retrying web URL check with a known IP\n";
	$status{web} = check_url('http://' . $config{host_ip} . '/');
	dprint "*** Skipping forum and wiki checks due to DNS issues\n";
} else {
	$status{wiki} = check_url($config{wiki_url});
	$status{forums} = check_url($config{forums_url});
}

}

################################################################################
#                                                                              #
# ADD-ONS SERVER CHECK                                                         #
#                                                                              #
################################################################################

{

my $addr = $config{addons_hostname};

foreach my $port (@{$config{addons_ports}}) {
	my $otimer = otimer->new();

	my $port_status = check_campaignd($addr, $port);

	if(!defined($port_status)) {
		# There's something wrong with our configuration, skip this test.
		dprint("There's something wrong with our configuration; skipping add-ons check for now\n");
		last;
	}

	dprint "campaignd ($addr:$port): $port_status\n";

	if(($status{addons} == STATUS_GOOD && !$port_status) ||
	   ($status{addons} == STATUS_FAIL && $port_status)) {
	   $status{addons} = STATUS_INCOMPLETE;
	} else { # elsif($status{addons} == STATUS_UNKNOWN) {
		$status{addons} = $port_status;
	}
}

dprint "*** campaignd: " . $status{addons} . "\n";

}

################################################################################
#                                                                              #
# MULTIPLAYER SERVERS CHECK                                                    #
#                                                                              #
################################################################################

################################################################################
#                                                                              #
# FINISHING                                                                    #
#                                                                              #
################################################################################

dprint "*** Writing report to " . $config{output_path} . "\n";

write_hash_to_file(%status, $config{output_path});

dprint "*** Finished\n";

# kate: indent-mode normal; encoding utf-8;
