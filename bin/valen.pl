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

package valen;

use IO::Socket;
use IO::Uncompress::Gunzip qw(gunzip $GunzipError);
use LWP::UserAgent;
use Socket;

$| = 1;

my $VERSION = '0.0.1';

my $debug = 0;

# Path to the Wesnoth add-ons client script used to check
# the campaignd instances' status.
my $wesnoth_addon_manager = '/usr/local/bin/wesnoth_addon_manager';

my %config = (
	output_path				=> '/var/lib/valen/report',

	hostname				=> 'wesnoth.org',
	# NOTE: This needs to be updated if wesnoth.org's host IP ever changes.
	host_ip					=> '65.18.193.12',

	addons_hostname			=> 'add-ons.wesnoth.org',

	addons_ports			=> [ '1.11.x', '1.10.x' ],

	mp_main_hostname		=> 'server.wesnoth.org',
	mp_alt2_hostname		=> 'server2.wesnoth.org',
	mp_alt3_hostname		=> 'server3.wesnoth.org',

	mp_port					=> 15000,

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

# Various timeouts (in seconds).
use constant {
	HTTP_TIMEOUT			=> 10,
	WESNOTHD_TIMEOUT		=> 10,
};

sub dprint { print @_ if $debug }

sub dwarn { warn @_ if $debug }

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

	# In order to avoid someone else reading $out_path mid-write,
	# write the intermediate results to a new file that will later
	# clobber $out_path.
	my $int_path = $out_path . '.new';

	open(my $out, '>', $int_path)
		or die("Could not open '" . $int_path . "' for writing: " . $!);

	foreach(keys %{$hash_ref}) {
		print $out $_ . '=' . $hash_ref->{$_} . "\n";
	}

	# Save a timestamp for reference when reporting in the front-end.
	print $out 'ts=' . time() . "\n";

	close $out;

	rename($int_path, $out_path)
		or die("Could not replace '" . $out_path . "': " . $!);
}

sub check_url($)
{
	my $otimer = otimer->new();

	my $url = shift;

	my $ua = LWP::UserAgent->new();

	$ua->agent(sprintf('codename Valen/%s %s', $VERSION, $ua->_agent()));
	# 30 seconds should really be enough in the worst case.
	$ua->timeout(HTTP_TIMEOUT);
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
	my $ret = int($resp->code() == 200);

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

	return int(0 == system(
		"$wesnoth_addon_manager -a $addr -p $port -l >/dev/null 2>/dev/null"));
}

sub check_wesnothd($$)
{
	my $otimer = otimer->new();

	my $addr = shift;
	my $port = shift;

	my $sock = new IO::Socket::INET(
		PeerAddr => $addr, PeerPort => $port, Proto => 'tcp', Timeout => WESNOTHD_TIMEOUT);

	if(!$sock) {
		dwarn "wesnothd ($addr:$port): could not establish connection\n";
		return 0;
	}

	# The Timeout option in the IO::Socket::INET constructor
	# is only good when establishing the connection, so we
	# must set read/send timeouts ourselves.

	my $timeout_timeval = pack('L!L!', WESNOTHD_TIMEOUT, 0);

	unless($sock->sockopt(SO_SNDTIMEO, $timeout_timeval) &&
		$sock->sockopt(SO_RCVTIMEO, $timeout_timeval)) {
		dwarn "wesnothd ($addr:$port): socket layer smells funny\n";
		return 0;
	}

	#
	# Initial handshake.
	#

	if(!print $sock pack('N', 0)) {
		dwarn "wesnothd ($addr:$port): could not send to server\n";
		return 0;
	}

	my $buf = '';

	read $sock, $buf, 4;
	my $conn_num = unpack('N', $buf);

	if(!defined $conn_num) {
		dwarn "wesnothd ($addr:$port): connection broke or timed out during handshake\n";
		return 0;
	}

	if(length $conn_num != 4) {
		dwarn "wesnothd ($addr:$port): malformed connection number\n";
		return 0;
	}

	dprint "wesnothd ($addr:$port): handshake succeeded ($conn_num)\n";

	#
	# Get the compressed packet size.
	#

	$buf = '';

	my $nread = read($sock, $buf, 4);

	if(!defined $nread || !$nread) {
		dwarn "wesnothd ($addr:$port): connection broke during read\n";
		return 0;
	}

	if($nread != 4) {
		dwarn "wesnothd ($addr:$port): read error\n";
		return 0;
	}

	#
	# Read the compressed packet.
	#

	$nread = 0;

	my $zpacket = '';
	my $zpacket_length = unpack('N', $buf);
	my $zread_length = 0;

	while($zread_length < $zpacket_length) {
		my $zremaining_length = $zpacket_length - $nread;

		$buf = '';

		$nread = read($sock, $buf, $zremaining_length);
		$zread_length += $nread
			if $nread == length($buf);

		$zpacket .= $buf;
	}

	my $text = '';

	unless(gunzip(\$zpacket => \$text)) {
		dwarn "wesnothd ($addr:$port): gunzip failed: $GunzipError\n";
		return 0;
	}

	# We now have the WML. According to Gambit and the MultiplayerServerWML
	# wiki page, we should get an optional empty [version] WML node (asking
	# us to send our own [version] response) after the handshake. It is not
	# currently known what the response would be when the server doesn't
	# care about the client version, but since the normal next step is the
	# [mustlogin] request, that's probably the alternative in this case
	# too.
	if($text !~ m.^\[(version|mustlogin)\]\n\[/\1\]\n.) {
		dwarn "wesnothd ($addr:$port): got something other than [version] or [mustlogin]\n";
		dwarn "--cut here--\n" . $text . "--cut here--\n";
		return 0;
	}

	dprint "wesnothd ($addr:$port): OK ($1)\n";

	return 1;
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

	my $passed = int(@addr > 0);
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

{

$status{'mp-main'} = check_wesnothd($config{mp_main_hostname}, $config{mp_port});
dprint "*** wesnothd 1: " . $status{'mp-main'} . "\n";

$status{'mp-alt2'} = check_wesnothd($config{mp_alt2_hostname}, $config{mp_port});
dprint "*** wesnothd 2: " . $status{'mp-alt2'} . "\n";

$status{'mp-alt3'} = check_wesnothd($config{mp_alt3_hostname}, $config{mp_port});
dprint "*** wesnothd 3: " . $status{'mp-alt3'} . "\n";

}

################################################################################
#                                                                              #
# FINISHING                                                                    #
#                                                                              #
################################################################################

dprint "*** Writing report to " . $config{output_path} . "\n";

write_hash_to_file(%status, $config{output_path});

dprint "*** Finished\n";

# kate: indent-mode normal; encoding utf-8;
