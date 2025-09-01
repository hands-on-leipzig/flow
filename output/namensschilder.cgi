#!/usr/bin/perl
use strict;
use Carp;
use English;
use DBI;
use CGI ':standard';
use CGI::Carp qw ( fatalsToBrowser carpout);
use Encode;
use File::Copy;

# selbst nachinstallierte Module einbinden
# hierzu Pfad erweitern auf entspr. Verzeichnis (hier bei Hetzner)
use lib "/usr/home/handsb/.linuxbrew/Cellar/perl/5.38.2_1/lib/perl5/site_perl/5.38";
use Config::Std;
use XML::LibXSLT;
use XML::LibXML;

#########################################################
# Eigenes Error-Log fuer PERL
#########################################################
my $LOG_FH;
open($LOG_FH, ">>./data/logs/errorlog.txt") or
    die ("Unable to open Perl-Error-Log: $!\n");
carpout($LOG_FH);

#########################################################
# eigene Module einbinden
#########################################################
use lib "./perl";
use Netcity::Templates;

#########################################################
# Config-Datei einbinden
#########################################################
#read_config 'config.cgi' => my %config;
read_config '../.env' => my %config;

# query-Objekt initialisieren
my $query_cgi = new CGI;
my $params = $query_cgi->Vars; # Vars-Methode liefert tied hash reference

my $dbh;
my $query = "";
my $sth;
my $rv;

# zu Datenbank connecten
# alte config.cgi
#$dbh = DBI->connect("DBI:mysql:database=$config{db}{name};host=$config{db}{host};port=3306","$config{db}{username}","$config{db}{password}");
# neue config.env im Hauptverzeichnis
$dbh = DBI->connect("DBI:mysql:database=$config{''}{DB_DATABASE};host=$config{''}{DB_HOST};port=$config{''}{DB_PORT}","$config{''}{DB_USERNAME}","$config{''}{DB_PASSWORD}");
$query = qq[set names 'utf8'];
$sth = $dbh->prepare($query);
$rv = $sth->execute;

#print "Content-type: text/html; charset=utf-8\n\n";
#print "<html><body>Funktion steht aktuell noch nicht zur Verfügung</body></html>";

my $template = "";
my $error = "";

if ($params->{typ} eq "") {
    print "Content-type: text/html; charset=utf-8\n\n";
    $template = get_template({page=>'namensschilder', directory=>'./templates', variables=>{export=>$params->{export}}}); # , variables=>{usertyp=>$user{typ}}
    print $template;
    exit;
}
else {
    if ($params->{output} eq "texte") {
        texte();
    }
    else {
        aufkleber();
    }
}

exit;

sub texte {
    my $file;
    my $xml_daten;
    
    my $beispiel_filename;
    my $beispiel_file;

    my $output_FH;
    my $input_FH;

    my $liste = "";
    my $liste_copy_to_clipboard = "";

    #my $root;
    #my $parser = XML::DOM::Parser->new;

    if ($file = param("xml_upload")) {
        # XML-Upload-File in Variable lesen
        while (<$file>) {
            $xml_daten .= $_;
        }
        close $file;
    }
    else {
        # dann Beispiel verwenden
        if ($params->{typ} eq "volunteers") {
            $beispiel_filename = "./beispiele/volunteers.xml";
        }
        elsif ($params->{typ} eq "teams") {
            $beispiel_filename = "./beispiele/teams.xml";
        }
        else {
            # nix
        }

        open ($beispiel_file, $beispiel_filename);
        while (<$beispiel_file>) {
            $xml_daten .= $_;
        }
        close $beispiel_file;
    }

    #print "Content-type: text/html; charset=utf-8\n\n";
    
    my $xslt = XML::LibXSLT->new();
    
    #my $source = XML::LibXML->load_xml(location => 'Zeitplan.xml');
    my $source = XML::LibXML->load_xml(string => $xml_daten);
    my $style_doc = XML::LibXML->load_xml(location=>'texte.xsl', no_cdata=>1);
    
    my $stylesheet = $xslt->parse_stylesheet($style_doc);

    my $xslt_params = "";

    my $results;

    $results = $stylesheet->transform($source, role=>"'teams_c2c_each'");
    #$results = $stylesheet->transform($source, role=>"'c_team'", team=>$params->{team});
    $liste = $stylesheet->output_as_bytes($results);

    $results = $stylesheet->transform($source, type=>"'teams_c2c_all'");
    #$results = $stylesheet->transform($source, role=>"'c_team'", team=>$params->{team});
    $liste_copy_to_clipboard = $stylesheet->output_as_bytes($results);

    chop($liste_copy_to_clipboard);

    print "Content-type: text/html; charset=utf-8\n\n";
    $template = get_template({page=>'texte', directory=>'./templates'});
    $template =~ s/<!--flow:liste-->/$liste/eg;
    $template =~ s/<!--flow:liste_copy_to_clipboard-->/$liste_copy_to_clipboard/eg;
    print $template;
    exit;
}

sub texte_alt {
    my $file;
    my $xml_daten;
    
    my $beispiel_filename;
    my $beispiel_file;

    my $output_FH;
    my $input_FH;

    my $root;
    my $parser = XML::DOM::Parser->new;

    if ($file = param("xml_upload")) {
        # XML-Upload-File in Variable lesen
        while (<$file>) {
            $xml_daten .= $_;
        }
        close $file;
    }
    else {
        # dann Beispiel verwenden
        if ($params->{typ} eq "volunteers") {
            $beispiel_filename = "./beispiele/volunteers.xml";
        }
        elsif ($params->{typ} eq "teams") {
            $beispiel_filename = "./beispiele/teams.xml";
        }
        else {
            # nix
        }

        open ($beispiel_file, $beispiel_filename);
        while (<$beispiel_file>) {
            $xml_daten .= $_;
        }
        close $beispiel_file;
    }

    
    #$root = $parser->parsefile($inputfile);
    $root = $parser->parse($xml_daten);
    
    my $teams;
    my $team;
    my $team_name_elemente;
    my $team_name_element;
    my $team_name_child_nodes;
    my $team_name_child_node;
    my $team_name = "";
    my $mitglieder;
    my $mitglied;
    
    my $team_count = 0;

    my $liste = "";
    my $liste_copy_to_clipboard = "";
    
    #print "Content-type: text/html; charset=utf-8\n\n";

    foreach $team ($root->getElementsByTagName("team")) {

        $team_count++;

        $team_name_elemente = $team->getElementsByTagName("name");
        $team_name_element = $team_name_elemente->item(0); # erstes = einziges Element
        $team_name_child_nodes = $team_name_element->getChildNodes;
        $team_name_child_node = $team_name_child_nodes->item(0); # erster = einziger Child-Node
        $team_name = $team_name_child_node->getNodeValue;

        $team_name = Encode::encode("utf-8", $team_name);

        $liste .= $team_name.qq{ <i class="bi-copy" onclick="navigator.clipboard.writeText('$team_name'); return false;"></i><br>};
        $liste_copy_to_clipboard .= $team_name."\\n";

        #$concept = $concept->item(0);
        #$concept = $concept->getChildNodes;
        #$concept = $concept->item(0);
        #$nodevalue = $concept->getNodeValue;
        #$conceptid = $nodevalue;
    }

    print "Content-type: text/html; charset=utf-8\n\n";
    $template = get_template({page=>'texte', directory=>'./templates'});
    $template =~ s/<!--flow:liste-->/$liste/eg;
    $template =~ s/<!--flow:liste_copy_to_clipboard-->/$liste_copy_to_clipboard/eg;
    print $template;
    exit;
}

sub aufkleber {
    my $file;
    
    my $session = "";
    my $filename = "";
    my $output_FH;
    my $input_FH;
    
    #$session = time;
    my $session_filename = time;
    my $xmlfile = "../export/pdf/$session_filename.xml";
    my $pdffile = "../export/pdf/$session_filename.pdf";

    
    # zuerst Verzeichnis anlegen (wenn schon existiert, passiert nix)...
    #my $ok = mkdir("./$session");

    #if ($params->{typ} eq "volunteers") {
    #    $filename = qq{aufkleber_volunteer.xml}
    #}
    #elsif ($params->{typ} eq "teams") {
    #    $filename = qq{aufkleber_team.xml}
    #}
    #else {
    #    $filename = qq{aufkleber.xml};
    #}
    
    if ($file = param("xml_upload")) {
        # XML-Upload-File speichern...

        open $output_FH, ">", qq{../export/pdf/$session_filename.xml};
        while (<$file>) {
            print $output_FH $_;
        }
        close $output_FH;
        close $file;
    }
    else {
        # dann Beispiel kopieren
        if ($params->{typ} eq "volunteers") {
            copy("./beispiele/volunteers.xml", "../export/pdf/$session_filename.xml");
        }
        elsif ($params->{typ} eq "teams") {
            copy("./beispiele/teams.xml", "../export/pdf/$session_filename.xml");
        }
        else {
            # nix
        }
        
        #$template = get_template({page=>'error', directory=>'./templates'}); # , variables=>{usertyp=>$user{typ}}
        #$error = "Keine XML-Datei ausgewählt.";
        #$template =~ s/<!--zeitplan:error-->/$error/g;

        #print "Content-type: text/html; charset=utf-8\n\n";
        #print $template;
        #exit;
    }
    
    # Anfang PDF generieren
    #my $xmlfile = "./$session/$filename";
    my $xslfile = "aufkleber.xsl"; # wird noch ueberschrieben
    #my $pdffile = "./$session/aufkleber.pdf";

   
    my $parameter = qq{};
    
    if ($params->{typ} eq "volunteers") {
        #$xslfile = "aufkleber_volunteer.xsl";
        #$xslfile = "aufkleber.xsl";
        $filename = "Aufkleber_Volunteers";
        $parameter = qq{-param typ "volunteers"};
    }
    elsif ($params->{typ} eq "teams") {
        #$xslfile = "aufkleber.xsl";
        $filename = "Aufkleber_Teams";
        $parameter = qq{-param typ "teams"};
    }
    else {
        # nix
    }

    if ($params->{layout} eq "namensschild") {
        $xslfile = "aufkleber.xsl";
    }
    elsif ($params->{layout} eq "becher") {
        $xslfile = "becher.xsl";
    }
    elsif ($params->{layout} eq "teamschilder") {
        $xslfile = "teamschilder.xsl";
    }
    else {
        $xslfile = "aufkleber.xsl";
    }

    if ($params->{rp_logo} eq "x") {
        $parameter .= qq{ -param rp_logo ja};
    }
    else {
        $parameter .= qq{ -param rp_logo nein};
    }

    my $fop = "";
    if ($ENV{SERVER_NAME} eq "www.fll-braunschweig.de") {
        $fop = "fop";
        #$parameter .= "";
    }
    elsif ($ENV{SERVER_NAME} eq "dev.planning.hands-on-technology.org") {
        $fop = "/usr/home/handsb/public_html/dev-fll-planning/fop/fop/fop";
        $parameter .= " -param server dev";
    }
    elsif ($ENV{SERVER_NAME} eq "test.planning.hands-on-technology.org") {
        $fop = "/usr/home/handsb/public_html/test-fll-planning/fop/fop/fop";
        $parameter .= " -param server test";
    }
    elsif ($ENV{SERVER_NAME} eq "planning.hands-on-technology.org") {
        $fop = "/usr/home/handsb/public_html/fll-planning/fop/fop/fop";
        $parameter .= " -param server prod";
    }
    else {
        $fop = "/usr/home/handsb/public_html/fll-planning/fop/fop/fop";
        $parameter .= " -param server prod";
    }

    system qq{$fop -xml $xmlfile -xsl $xslfile -pdf $pdffile $parameter};
    
    # Filename setzen
    #$filename = q{Lieferschein_Stellplätze};
    #.$auftraege{datum};
    #$filename = decode("utf8", $filename);
    #$filename = encode("utf8", $filename);
    #$filename =~ s/[ ]/_/g;
    #$filename =~ s/ä/ae/g;
    #$filename =~ s/ü/ue/g;
    #$filename =~ s/ö/oe/g;
    #$filename =~ s/ß/ss/g;
    #$filename =~ s/Ä/Ae/g;
    #$filename =~ s/Ü/Ue/g;
    #$filename =~ s/Ö/Oe/g;
    #$filename =~ s/[^a-zA-Z0-9\-_]//g;
    
    #$filename  = $filename.q{.pdf};
    
    $filename = "$filename.pdf";
    
    my $handling = "open"; # default
    my $type="application/pdf";
    print "Content-type: $type\n";
    # direkt oeffnen (wenn moeglich) oder auf Platte speichern? open oder save
    if ($handling eq "save") {
        # auf Platte speichern
        print "Content-Disposition: attachment; filename=$filename\n\n";
    }
    else {
        # direkt oeffnen (wenn moeglich...)
        print "Content-Disposition: inline; filename=$filename\n\n";
    }
    
    my $downloadfile;
    open ($downloadfile, $pdffile);
    
    my $line = <$downloadfile>;
    while ($line ne "") {
        print "$line";
        $line = <$downloadfile>;
    }
    close $downloadfile;
    # Ende PDF

    unlink $xmlfile;
    unlink $pdffile;

    exit;
}

