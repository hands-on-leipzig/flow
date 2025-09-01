package Netcity::Datum;

use version; our $VERSION = qv('0.0.1');

use strict;
use warnings;
use Carp;

require Exporter;
our @ISA = qw(Exporter);
our @EXPORT = qw(konvertiere_datum datum_heute);


sub konvertiere_datum {
    my $datum = $_[0];
    my $format_out = $_[1];
    my $tag;
    my $monat;
    my $jahr;
    
    if (!(defined $datum)) {
        $datum = "";
    }
    if (!(defined $format_out)) {
        $format_out = "";
    }

    if ($datum =~ /^(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})$/) {
        # prueft auf Format DD.MM.YYYY wobei Monat und Tag ein oder zweistellig sein duerfen
        # und Jahresangabe zwei- oder vierstellig
        # zweistellige Jahresangabe wird in vierstellige umgewandelt, hierbei werden 
        # Werte > 30 als 19xx und Werte <= 30 als 20xx interpretiert
        # traditionelles deutsches Format
        # prueft auf Muster am Anfang und Ende ^$ -> d.h. exakt dieses Muster (geht evtl. noch eleganter?)
        $tag = $1;
        $monat = $2;
        $jahr = $3;
    }
    else {
        # prueft auf Format YYYY-MM-DD wobei Monat und Tag ein oder zweistellig sein duerfen
        # und Jahresangabe zwei- oder vierstellig
        # zweistellige Jahresangabe wird in vierstellige umgewandelt, hierbei werden 
        # Werte > 30 als 19xx und Werte <= 30 als 20xx interpretiert
        # US/Datenbank/neues DIN Format
        # prueft auf Muster am Anfang und Ende ^$ -> d.h. exakt dieses Muster (geht evtl. noch eleganter?)
        if ($datum =~ /^(\d{2}|\d{4})-(\d{1,2})-(\d{1,2})$/) {
            $tag = $3;
            $monat = $2;
            $jahr = $1;
        }
        else {
            # kein gueltiges Datumsformat erkannt
            
            # erstmal als Dummy ein 'auffaelliges' Datum setzen
            #$datum = "1970-01-01";
            $datum = "";
            return $datum;
        }
    }
    
    if (length($tag) == 1) {
        $tag = "0".$tag;
    }
    if (length($monat) == 1) {
        $monat = "0".$monat;
    }
    if (length($jahr) == 2) {
        if (eval($jahr) > 30) {
            $jahr = "19".$jahr;
        }
        else {
            $jahr = "20".$jahr;
        }
    }
    
    if ($format_out eq "de") {
        $datum = "$tag.$monat.$jahr";
    }
    else {
        $datum = "$jahr-$monat-$tag";
    }
    
    # ansonsten noch pruefen, ob es ein gueltiges Datum ist, also
    # z.B. sowas wie 2005-55-55 abfangen
    
    return $datum;
}


sub datum_heute	{
    my $offset = $_[0]; # wieviele Tage spaeter?
    my $language = $_[1]; # welche Sprache? (deutsch / english)
    my $today_sec;
    my $today_min;
    my $today_hour;
    my $today_mday;
    my $today_mon;
    my $today_jahr;
    my $today_wday;
    my $today_yday;
    my $today_isdst;
    my $unixtime;
    my @monate;
    my @MonthDays;
    my $datum;

    if (!(defined $offset)) {
        $offset = 0;
    }

    if (!(defined $language)) {
        $language = "";
    }

    # define constants
    @monate = ("Januar", "Februar", "März", "April", "Mai", "Juni", "Juli", "August", "September", "Oktober", "November", "Dezember");
    #if ($PARAM_ALLGEMEIN{lang} eq "deutsch")
    #	{
    #	@monate = ("Januar", "Februar", "März", "April", "Mai", "Juni", "Juli", "August", "September", "Oktober", "November", "Dezember");
    #	}
    if ($language eq "english") {
        @monate = ("January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December");
    }

    @MonthDays = (31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31);
    
    # schoene Schaltjahrberechnung, nuetzt jedoch nix wenn $jahr gar nicht feststeht... ausserdem soll nur heutiges Datum ermittelt werden...
    # if (&Schaltjahr($jahr+1900)) {$MonthDays[1]=29;} 

    # today ermitteln
    $unixtime = time;
    if ($offset != 0) {
        $offset = $offset * 86400; # ein Tag hat 86400 Sekunden
        $unixtime += $offset;
    }

    ($today_sec,$today_min,$today_hour,$today_mday,$today_mon,$today_jahr,$today_wday,$today_yday,$today_isdst) = localtime($unixtime);
    # Uhrzeit?
    #$Jetztzeit = localtime(time);
    #@Zeit = split(/ +/,$Jetztzeit);
    #@Uhrzeit = split(/:/,$Zeit[3]);

    $today_jahr=$today_jahr+1900;   #year2000...
    $today_mon=$today_mon+1;        #monat 0..11 -> 1..12
    #$today_mday=$today_mday+1;        #tag 0..30 -> 1..31 ??

    #if ($offset != 0)
    #	{
    #	$today_mday += $offset;
    #	}

    $datum = $today_jahr."-".$today_mon."-".$today_mday;

    return ($datum); # im ISO-Format zurueckgeben
}

	
sub Schaltjahr {
    my($y)=shift;
    if (($y%4==0)&&($y%100!=0)||($y%400==0)) {
        return(1);
    }
    else {
        return(0);
    }
}



1;
