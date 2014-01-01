#!/bin/true
#
# Copyright (C) 2012 - 2014 by Ignacio Riquelme Morelle <shadowm2006@gmail.com>
# Rights to this code are as documented in COPYING.
#

my $valen_report_file = '/var/lib/valen/report';

my @status_map = (
    [ 'dns', 'DNS' ],
    [ 'web', 'Web' ],
    [ 'wiki', 'Wiki' ],
    [ 'forums', 'Forums' ],
    [ 'addons', 'Add-ons' ],
    [ 'mp-main', 'MP 1' ],
    [ 'mp-alt2', 'MP 2' ],
    [ 'mp-alt3', 'MP 3' ],
);

BOT_COMMAND_REGEX_HANDLER {
    my $event = shift;

    my $fh;

    unless(open $fh, '<', $valen_report_file) {
        msg("Could not open status report file.", @_);
        return TRUE;
    }

    my %report = ();

    foreach(<$fh>) {
        my ($key, $value) = split(/=/, $_, 2);
        $report{$key} = $value;
    }

    close $fh;

    my $delta = time() - $report{ts};
    my (@status_bits, @age_bits);

    foreach my $ary (@status_map) {
        my ($key, $label) = @{$ary};

        my $value = int($report{$key});
        my $text = '';

        if($value == 0) {
            $text = $color->{lightred} . 'Offline';
        } elsif($value == 1) {
            $text = $color->{lightgreen} . 'Online';
        } elsif($value == 2) {
            $text = $color->{orange} . 'Wonky';
        } else {
            $text = $color->{gray} . 'Unknown';
        }

        push @status_bits, "\002$label\002: $text" . $normal;
    }

    my $original_delta = $delta;

    if($delta > 0) {
        my $sec = $delta % 60;
        $delta = int(($delta - $sec) / 60);

        my $min = $delta % 60;
        $delta = int(($delta - $min) / 60);

        my $hr = $delta % 24;
        $delta = int(($delta - $hr) / 24);

        my $days = $delta;

        push @age_bits, $days . " day" . ($days > 1 ? 's' : '')
            if $days;

        push @age_bits, $hr . " hour" . ($hr > 1 ? 's' : '')
            if $hr;

        push @age_bits, $min . " minute" . ($min > 1 ? 's' : '')
            if $min;

        push @age_bits, $sec . " second" . ($sec > 1 ? 's' : '')
            if($sec && !$min);
    }

    $event->reply_msg(join(' ', @status_bits));

    if($original_delta == 0) {
        $event->reply_msg("\002Last updated\002: just now.");
    } elsif($original_delta > 0) {
        $event->reply_msg("\002Last updated\002: " . join(', ', @age_bits) . " ago.");
    } else {
        $event->reply_msg("\002Last updated\002: \002IN THE FUTURE!\002");
    }

    TRUE;
} qr{^WSS(?:\s+.*)?$}i;

$help->add_entry('WSS', {
    summary     => q(Displays Wesnoth.org's status.),
    syntax      => q(WSS),
    description => q(WSS displays Wesnoth.org's status as presented in http://status.wesnoth.org/.),
    examples    => [ q(WSS) ],
});

# kate: indent-mode normal; encoding utf-8; space-indent on;
