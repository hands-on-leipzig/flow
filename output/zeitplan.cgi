#!/usr/bin/perl
use strict;
use Carp;
use English;
use DBI;
use CGI ':standard';
use CGI::Carp qw ( fatalsToBrowser carpout);
use Encode;
use DateTime;
use DateTime::Format::DateParse;
use DateTime::Locale;
#use DateTime::Locale::de_DE; # veraltet...

my $loc = DateTime::Locale->load('de_DE');


# selbst nachinstallierte Module einbinden
# hierzu Pfad erweitern auf entspr. Verzeichnis (hier bei Hetzner)
use lib "/usr/home/handsb/.linuxbrew/Cellar/perl/5.38.2_1/lib/perl5/site_perl/5.38";
use Config::Std;
#use Config::Tiny;

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
use Netcity::Datum;

#########################################################
# Config-Datei einbinden
#########################################################
#read_config 'config.cgi' => my %config;
read_config '../../.env' => my %config;

# query-Objekt initialisieren
my $query_cgi = new CGI;
my $params = $query_cgi->Vars; # Vars-Methode liefert tied hash reference

my $dbh;
my $query = "";
my $sth;
my $rv;
my @row;

# zu Datenbank connecten
# alte config.cgi
#$dbh = DBI->connect("DBI:mysql:database=$config{db}{name};host=$config{db}{host};port=3306","$config{db}{username}","$config{db}{password}");
# neue config.env im Hauptverzeichnis
$dbh = DBI->connect("DBI:mysql:database=$config{''}{DB_DATABASE};host=$config{''}{DB_HOST};port=$config{''}{DB_PORT}","$config{''}{DB_USERNAME}","$config{''}{DB_PASSWORD}");
$query = qq[set names 'utf8'];
$sth = $dbh->prepare($query);
$rv = $sth->execute;

if ($params->{export} eq "pdf") {
    $params->{brief} = "no";
}

if ($params->{brief} eq "") {
    $params->{brief} = "yes";
    $params->{role} = 14; # Publikum Gesamt
}

if ($params->{expired} eq "") {
    $params->{expired} = "yes";
}

my $aktuelle_zeit = "";
my $aktuelle_zeit_plus_1_stunde = "";

if ($params->{hours} eq "") {
    # wieviele Stunden in die Zukunft sollen bei output=slide Activity-Groups angezeigt werden?
    $params->{hours} = 1;
}

if ($params->{now} ne "") {
    if ($params->{now} =~ /^(\d{2}|\d{4})\-(\d{1,2})\-(\d{1,2}) (\d{1,2}):(\d{1,2})$/) {
        my $datum_jahr = $1;
        if (length($datum_jahr) == 2) {
            $datum_jahr = qq{20$datum_jahr};
        }
        my $datum_monat = sprintf("%02d", $2);
        my $datum_tag = sprintf("%02d", $3);
        my $stunden = sprintf("%02d", $4);
        my $minuten = sprintf("%02d", $5);

        my $stunden_plus_1 = sprintf("%02d", $4 + $params->{hours});

        $aktuelle_zeit = qq{$datum_jahr-$datum_monat-$datum_tag $stunden:$minuten};

        $aktuelle_zeit_plus_1_stunde = qq{$datum_jahr-$datum_monat-$datum_tag $stunden_plus_1:$minuten};
        #print "Content-type: text/html; charset=utf-8\n\n";
        #print $aktuelle_zeit;

        # Leerzeichen wieder durch + ersetzen
        $params->{now} =~ s/ /\+/;
    }
    else {
        print "Content-type: text/html; charset=utf-8\n\n";
        print "Fehlerhafter Parameter now=$params->{now}";
        exit;
    }
}
else {
    # aktuelle Uhrzeit ermitteln
    my $today_sec;
    my $today_min;
    my $today_hour;
    my $today_mday;
    my $today_mon;
    my $today_jahr;
    my $today_wday;
    my $today_yday;
    my $today_isdst;

    ($today_sec,$today_min,$today_hour,$today_mday,$today_mon,$today_jahr,$today_wday,$today_yday,$today_isdst) = localtime(time());

    # Datum
    my $datum_tag = sprintf("%02d", $today_mday);
    my $datum_monat = sprintf("%02d", $today_mon + 1);
    my $datum_jahr = $today_jahr + 1900;
    #my $datum = qq{$datum_jahr-$datum_monat-$datum_tag};
    # Uhrzeit
    my $stunden = sprintf("%02d", $today_hour);
    my $minuten = sprintf("%02d", $today_min);

    my $stunden_plus_1 = sprintf("%02d", $today_hour + $params->{hours});

    # aktuelle Zeit (Datunm + Uhrzeit)
    $aktuelle_zeit = qq{$datum_jahr-$datum_monat-$datum_tag $stunden:$minuten};

    $aktuelle_zeit_plus_1_stunde = qq{$datum_jahr-$datum_monat-$datum_tag $stunden_plus_1:$minuten};
}

#print "Content-type: text/html; charset=utf-8\n\n";
#print $aktuelle_zeit;

# Tisch-Bezeichnungen aus Tabelle table_event in Hash einlesen
my %table_name;

my $table_count = 0;
$query = qq{select set_value
            from plan_param_value
            join m_parameter on plan_param_value.parameter=m_parameter.id
            where m_parameter.name="r_tables"
            and plan_param_value.plan=$params->{plan}
           };
$sth = $dbh->prepare($query);
$rv = $sth->execute;

if ($rv ne "0E0") {
    @row = $sth->fetchrow_array;
    $table_count = $row[0];
}

# Initialisieren auf Tisch 1 = 1, Tisch 2 = 2, ...
for (my $loop = 1; $loop <= $table_count; $loop++) {
    $table_name{$loop} = $loop;
}

$query = qq{select
            table_event.table_number,
            table_event.table_name
            from plan
            left join table_event on plan.event=table_event.event
            where plan.id=$params->{plan}
        };
$sth = $dbh->prepare($query);
$rv = $sth->execute;

if ($rv ne "0E0") {
    while (@row = $sth->fetchrow_array) {
        $table_name{$row[0]} = $row[1];
    }
}
# Ende: Tisch-Bezeichnungen aus Tabelle table_event in Hash einlesen

my %plan_metadata;
%plan_metadata = %{get_plan_metadata({plan=>$params->{plan}})};

#print "Content-type: text/html; charset=utf-8\n\n";
#print $plan_metadata{regional_partner_name};

get_zeitplan();

sub get_plan_metadata {
    # Infos zum Plan holen
    my ($arg_ref) = @_; # uebergebene Argumente
    my $plan = $arg_ref->{plan};

    my $query = "";
    my $sth;
    my $rv;
    my @row;

    my %plan_metadata;

    $query = qq{select
                plan.name,
                plan.last_change,
                plan.event,
                event.name,
                event.date,
                event.days,
                m_level.name,
                event.qrcode,
                event.regional_partner,
                regional_partner.name,
                regional_partner.region,
                event.event_explore,
                event.event_challenge,
                event.level
                from plan
                join event on plan.event=event.id
                join m_level on event.level=m_level.id
                join regional_partner on event.regional_partner=regional_partner.id
                where plan.id=$plan
            };
    $sth = $dbh->prepare($query);
    $rv = $sth->execute;

    if ($rv ne "0E0") {
        @row = $sth->fetchrow_array;

        $plan_metadata{name} = $row[0];
        $plan_metadata{last_change} = $row[1];
        $plan_metadata{event_id} = $row[2];
        $plan_metadata{event_name} = $row[3];
        $plan_metadata{event_date} = $row[4];
        $plan_metadata{event_days} = $row[5];
        $plan_metadata{level_name} = $row[6];
        $plan_metadata{qrcode} = $row[7];
        $plan_metadata{regional_partner_id} = $row[8];
        $plan_metadata{regional_partner_name} = $row[9];
        $plan_metadata{regional_partner_region} = $row[10];
        $plan_metadata{event_explore} = $row[11];
        $plan_metadata{event_challenge} = $row[12];
        $plan_metadata{event_level} = $row[13];
    }

    # weitere Informationen holen
    # Plan-Parameter
    # u.a.
    # e_teams
    # c_teams
    # plus alle weiteren
    my %plan_parameter;

    $query = qq{select
                m_parameter.name,
                plan_param_value.set_value
                from plan_param_value
                join m_parameter on m_parameter.id=plan_param_value.parameter
                where plan_param_value.plan=$plan
            };
    $sth = $dbh->prepare($query);
    $rv = $sth->execute;

    if ($rv ne "0E0") {
        while (@row = $sth->fetchrow_array) {
            $plan_parameter{$row[0]} = $row[1];
        }
    }
    # Ende Parameter


    # alte Unterscheidung erstmal nachbauen mit neuen Parametern
    if ($plan_parameter{e_teams} eq "") {
        $plan_metadata{event_explore} = 0;
    }
    else {
        $plan_metadata{event_explore} = $plan_parameter{e_teams}; # nicht korrekt, es ist nicht mehr die DRAHT-Event-ID sondern nun die Anzahl Teams... sollte aber fuer Unterscheidungen aufs selbe rauskommen...
    }
    if ($plan_parameter{c_teams} eq "") {
        $plan_metadata{event_challenge} = 0;
    }
    else {
        $plan_metadata{event_challenge} = $plan_parameter{c_teams}; # nicht korrekt, es ist nicht mehr die DRAHT-Event-ID sondern nun die Anzahl Teams... sollte aber fuer Unterscheidungen aufs selbe rauskommen...
    }

    
    # Enddate errechnen aus date und days
    my $end_date_datetime;
    $end_date_datetime = DateTime::Format::DateParse->parse_datetime($plan_metadata{event_date});
    $end_date_datetime->add(days => $plan_metadata{event_days} - 1);
    $plan_metadata{event_enddate} = $end_date_datetime->ymd; # Rueckgabe im Format 2002-12-06
    $plan_metadata{event_enddate} = konvertiere_datum($plan_metadata{event_enddate}, 'de');

    # jetzt auch erst event_date ins deutsche Format konvertieren
    $plan_metadata{event_date} = konvertiere_datum($plan_metadata{event_date}, 'de');

    #if ($plan_metadata{event_level} == 1 && $plan_parameter{e_team} > 0 && !($plan_parameter{c_team} > 0)) {
    # doch nochmal auf alten Stand zurueck geaendert! Es gilt also alt/nicht mehr benutzt ist doch aktuell und wird benutzt!
    if ($plan_metadata{event_level} == 1 && $plan_metadata{event_explore} > 0 && !($plan_metadata{event_challenge} > 0)) {
        #$plan_parameter{e_team}
        #$plan_parameter{c_team}
        # Event-Level = Regionalwettbewerb
        # neu: e_team > 0 (zugehoerige Explore-Ausstellung vorhanden)
        # neu: c_team nicht > 0 (kein zugehoeriger Challenge-Wettbewerb vorhanden)
        # nicht mehr benutzt: Explore > 0 (zugehoerige Explore-Ausstellung vorhanden)
        # nicht mehr benutzt: Challenge nicht > 0 (kein zugehoeriger Challenge-Wettbewerb vorhanden)
        $plan_metadata{level_name} = "Ausstellung";
    }

    return \%plan_metadata;
}

sub get_zeitplan {
    my $query = "";
    my $sth;
    my $rv;
    my @row;

    # check auf fehlende Parameter / Inkonsistenzen

    #$event_date = konvertiere_datum($event_date, 'de');

    my $auswahl = get_auswahl();

    my $detailplan = "";
    my %detailplan_data;
    my $actual_activity_group_id = "";

    my $detailplan_xml = "";
    
    my $role = "";
    my $role_id = "";
    my $role_name = "";
    my $role_first_program = "";
    my $role_first_program_name = "";
    my $role_first_program_color_hex = "";
    my $role_first_program_logo_white = "";

    my $role_xml = "";
    my $role_filename = "";
    
    if ($params->{role} ne "") {
        # Infos zur Rolle holen
        $query = qq{select
                    m_role.id,
                    m_role.name,
                    m_role.first_program,
                    m_first_program.name,
                    m_first_program.color_hex,
                    m_first_program.logo_white
                    from m_role
                    left join m_first_program on m_first_program.id=m_role.first_program
                    where m_role.id=$params->{role}
                   };
        $sth = $dbh->prepare($query);
        $rv = $sth->execute;

        if ($rv ne "0E0") {
            @row = $sth->fetchrow_array;

            $role_id = $row[0];
            $role_name = $row[1];
            $role_first_program = $row[2];
            $role_first_program_name = lc($row[3]); # Umwandlung in lower case
            $role_first_program_color_hex = $row[4];
            $role_first_program_logo_white = $row[5];

            if ($role_first_program_color_hex eq "") {
                $role_first_program_color_hex = "888888";
            }
            if ($role_first_program_logo_white eq "") {
                $role_first_program_logo_white = "FLL_column_heading.png";
            }
        }

        if ($role_id == 3) {
            # Challenge Team
            my $team_name = "";
            my $team_number_hot = "";
            my $team_room_name = "";

            $query = qq{select
                        team.name,
                        team.team_number_hot,
                        room.name
                        from team_plan
                        join team on team_plan.team=team.id
                        left join room on room.id=team_plan.room
                        where team_plan.plan=$params->{plan}
                        and team.first_program=3
                        and team_plan.team_number_plan=$params->{team}
                    };
            $sth = $dbh->prepare($query);
            $rv = $sth->execute;

            if ($rv ne "0E0") {
                @row = $sth->fetchrow_array;
                $team_name = $row[0];
                $team_number_hot = $row[1];
                $team_room_name = $row[2];

                if ($team_room_name ne "") {
                    $team_room_name = qq{- Raum $team_room_name};
                }

                $role = qq{Team $team_name<br>$team_number_hot $team_room_name};
                $role_xml = qq{Team $team_name ($team_number_hot) $team_room_name};
                $role_filename = qq{Team-$team_name-$team_number_hot};
            }
            else {
                $team_name = qq{$params->{team}};
                $team_number_hot = "";

                $role = qq{Team $team_name};
                $role_xml = qq{Team $team_name};
                $role_filename = qq{Team-$team_name};
            }
        }
        elsif ($role_id == 5) {
            # SchiedsrichterIn
            $role = qq{Tisch $table_name{$params->{table}}};
            $role_xml = qq{Tisch $table_name{$params->{table}}};
            $role_filename = qq{Tisch-$table_name{$params->{table}}};
        }
        elsif ($role_id == 11) {
            # Robot-Check
            $role = qq{Robot-Check Tisch $table_name{$params->{table}}};
            $role_xml = qq{Robot-Check Tisch $table_name{$params->{table}}};
            $role_filename = qq{Robot-Check-Tisch-$table_name{$params->{table}}};
        }
        elsif ($role_id == 4) {
            # Jury
            $role = qq{Jury $params->{lane}};
            $role_xml = qq{Jury $params->{lane}};
            $role_filename = qq{Jury $params->{lane}};
        }
        elsif ($role_id == 8) {
            # Explore Team
            my $team_name = "";
            my $team_number_hot = "";

            $query = qq{select
                        team.name,
                        team.team_number_hot
                        from team_plan
                        join team on team_plan.team=team.id
                        where team_plan.plan=$params->{plan}
                        and team.first_program=2
                        and team_plan.team_number_plan=$params->{team}
                    };
            $sth = $dbh->prepare($query);
            $rv = $sth->execute;

            if ($rv ne "0E0") {
                @row = $sth->fetchrow_array;
                $team_name = $row[0];
                $team_number_hot = $row[1];

                $role = qq{Team $team_name<br>$team_number_hot};
                $role_xml = qq{Team $team_name ($team_number_hot)};
                $role_filename = qq{Team-$team_name-$team_number_hot};
            }
            else {
                $team_name = qq{$params->{team}};
                $team_number_hot = "";

                $role = qq{Team $team_name};
                $role_xml = qq{Team $team_name};
                $role_filename = qq{Team-$team_name};
            }
        }
        elsif ($role_id == 9) {
            # GutachterIn
            $role = qq{GutachterIn $params->{lane}};
            $role_xml = qq{GutachterIn $params->{lane}};
            $role_filename = qq{GutachterIn-$params->{lane}};
        }
        else {
            # alles andere
            $role = qq{$role_name};
            $role_xml = qq{$role_name};
            $role_filename = qq{$role_name}
        }

        # nur, wenn bereits etwas ausgewaehlt wurde, auch den Detailplan ermitteln/aufrufen
        #$detailplan = get_detailplan();
        %detailplan_data = %{get_detailplan()};
        $detailplan = $detailplan_data{plan_html};
        $actual_activity_group_id = $detailplan_data{actual_activity_group_id};


        if ($params->{export} eq "pdf") {
            my $session_filename = time;

            $detailplan_xml = $detailplan_data{plan_xml};

            my $logo_path = "";
            if ($ENV{SERVER_NAME} eq "www.fll-braunschweig.de") {
                $logo_path = "logos";
            }
            elsif ($ENV{SERVER_NAME} eq "dev.planning.hands-on-technology.org") {
                $logo_path = "/usr/home/handsb/public_html/dev-fll-planning/output/logos";
            }
            elsif ($ENV{SERVER_NAME} eq "test.planning.hands-on-technology.org") {
                $logo_path = "/usr/home/handsb/public_html/test-fll-planning/output/logos";
            }
            elsif ($ENV{SERVER_NAME} eq "planning.hands-on-technology.org") {
                $logo_path = "/usr/home/handsb/public_html/fll-planning/output/logos";
            }
            else {
                $logo_path = "/usr/home/handsb/public_html/fll-planning/output/logos";
            }

            my $template_FH;
            open $template_FH, '<', './template_zeitplan.xml';

            my $out_FH;
            open $out_FH, '>', "../export/pdf/$session_filename.xml";
            #open $out_FH, '>', "./zeitplan.xml";

            while (<$template_FH>) {
                s/<!--zeitplan:logo_path-->/$logo_path/eg;
                s/<!--zeitplan:level_name-->/$plan_metadata{level_name}/eg;
                s/<!--zeitplan:regionalpartner_region-->/$plan_metadata{regional_partner_region}/eg;
                s/<!--zeitplan:regionalpartner_name-->/$plan_metadata{regional_partner_name}/eg;
                s/<!--zeitplan:event_date-->/$plan_metadata{event_date}/eg;

                s/<!--zeitplan:event_start-->/$detailplan_data{start}/eg;
                s/<!--zeitplan:event_end-->/$detailplan_data{end}/eg;

                s/<!--zeitplan:qrcode-->/$plan_metadata{qrcode}/eg;

                s/<!--zeitplan:first_program_name-->/$role_first_program_name/eg;
                s/<!--zeitplan:first_program_color_hex-->/$role_first_program_color_hex/eg;
                s/<!--zeitplan:first_program_logo_white-->/$role_first_program_logo_white/eg;
                s/<!--zeitplan:detailplan-->/$detailplan_xml/eg;
                s/<!--zeitplan:role-->/$role_xml/eg;
                s/<!--zeitplan:plan_last_change-->/$plan_metadata{last_change}/eg;

                print $out_FH "$_";
            }
            close $template_FH;
            
            close $out_FH;

            make_pdf({session_filename=>$session_filename, output_filename=>$role_filename});
            exit;
        }
    }

    if ($plan_metadata{event_date} ne $plan_metadata{event_enddate}) {
        # bei mehrtaegig = Zeitraum ausgeben
        $plan_metadata{event_date} = $plan_metadata{event_date}." - ".$plan_metadata{event_enddate}
    }

    my $logos = get_logos({event=>$plan_metadata{event_id}});

    my $template = "";

    if ($params->{brief} eq "yes") {
        $template = get_template({page=>'zeitplan_brief', directory=>'./templates', variables=>{export=>$params->{export}, event_challenge=>$plan_metadata{event_challenge}}}); # , variables=>{usertyp=>$user{typ}}
    }
    else {
        if ($params->{output} eq "slide") {
            $template = get_template({page=>'zeitplan_slide', directory=>'./templates', variables=>{export=>$params->{export}}}); # , variables=>{usertyp=>$user{typ}}
        }
        else {
            $template = get_template({page=>'zeitplan', directory=>'./templates', variables=>{export=>$params->{export}, event_challenge=>$plan_metadata{event_challenge}}}); # , variables=>{usertyp=>$user{typ}}
        }
    }

    if ($params->{hours} > 1) {
        $params->{hours} = qq{$params->{hours} Stunden};
    }
    else {
        $params->{hours} = qq{einer Stunde};
    }
    
    $template =~ s/<!--zeitplan:plan-->/$params->{plan}/eg;

    $template =~ s/<!--zeitplan:qrcode-->/$plan_metadata{qrcode}/eg;

    $template =~ s/<!--zeitplan:hours-->/$params->{hours}/eg;

    $template =~ s/<!--zeitplan:auswahl-->/$auswahl/eg;
    $template =~ s/<!--zeitplan:role-->/$role/eg;
    $template =~ s/<!--zeitplan:detailplan-->/$detailplan/eg;
    $template =~ s/<!--zeitplan:logos-->/$logos/eg;
    $template =~ s/<!--zeitplan:actual_activity_group_id-->/$actual_activity_group_id/eg;

    $template =~ s/<!--zeitplan:plan_name-->/$plan_metadata{name}/eg;
    $template =~ s/<!--zeitplan:plan_last_change-->/$plan_metadata{last_change}/eg;
    $template =~ s/<!--zeitplan:event_id-->/$plan_metadata{event_id}/eg;
    $template =~ s/<!--zeitplan:event_name-->/$plan_metadata{event_name}/eg;
    $template =~ s/<!--zeitplan:event_date-->/$plan_metadata{event_date}/eg;
    $template =~ s/<!--zeitplan:level_name-->/$plan_metadata{level_name}/eg;
    $template =~ s/<!--zeitplan:regionalpartner_name-->/$plan_metadata{regional_partner_name}/eg;
    $template =~ s/<!--zeitplan:regionalpartner_region-->/$plan_metadata{regional_partner_region}/eg;

    print "Content-type: text/html; charset=utf-8\n\n";
    print $template;
}

sub get_detailplan {
    my $query = "";
    my $sth;
    my $rv;
    my @row;

    my $query_activities = "";
    my $sth_activities;
    my $rv_activities;
    my @row_activities;

    my $detailplan = "";

    my $event_id = "";

    # check auf fehlende Parameter / Inkonsistenzen

    # jetzt noch die Event-ID ermitteln, wird aktuell fuer die Raeume benoetigt!
    $query = qq{select
                plan.event
                from plan
                where plan.id=$params->{plan}
            };
    $sth = $dbh->prepare($query);
    $rv = $sth->execute;

    if ($rv ne "0E0") {
        @row = $sth->fetchrow_array;

        $event_id = $row[0];
    }


    # erstmal Teams in Hash einlesen
    my %team;
    %team = %{teams_in_hash({plan=>$params->{plan}})};

    my $zeitplan_item_vorlage = "";

    if ($params->{output} eq "slide") {
        $zeitplan_item_vorlage = qq{<div class="col" id="<!--activity_group_id-->">
                                        <div class="card h-100">
                                            <div class="card-body text-center start-info rounded"  style="color:#ffffff; background-color:#<!--activity_group_first_program_color_hex-->">
                                                <div class="card-title">
                                                    <h5><!--activity_group_detail_name--></h5>
                                                </div>
                                                <div class="card-text">
                                                    <!--activities-->
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                   }; #  rounded-3" style="padding:3px; color:#ffffff; background-color:#<!--activity_group_first_program_color_hex-->
    }
    else {
        $zeitplan_item_vorlage = qq{<div id="<!--activity_group_id-->" class="border rounded-3 position-relative" style="padding:20px 10px 10px 10px; margin-top:30px; margin-bottom:30px">
            <span class="position-absolute top-0 start-0 translate-middle-y badge rounded-pill" style="background-color:#<!--activity_group_first_program_color_hex-->"><!--activity_group_detail_name-->
            <!--activity_group_description-->
            </span>
            <!--activities-->
        </div>
        };
    }

    # <!--activity_group_name--> wird nicht mehr vorweg ausgegeben...
    # vorher class = bg-danger fuer badge
    # jetzt durch konkreten Farbwert ersetzt

    my $activity_group_id = "";
    my $activity_group_activity_type_detail_id = "";
    my $activity_group_activity_type_detail_name = "";
    my $activity_group_activity_type_detail_description = "";
    my $activity_group_activity_type_detail_link = "";
    my $activity_group_activity_type_id = "";
    my $activity_group_activity_type_name = "";
    my $activity_group_activity_type_description = "";
    my $activity_group_first_program_name = "";
    my $activity_group_overview_plan_column = "";
    my $activity_group_first_program_color_hex = "";

    my $activity_group_activity_type_detail_description_html = "";

    my $activity_activity_type_detail_id = "";
    my $activity_activity_type_detail_name = "";
    my $activity_activity_type_detail_description = "";
    my $activity_activity_type_detail_link = "";
    my $activity_activity_type_id = "";
    my $activity_activity_type_name = "";
    my $activity_activity_type_description = "";

    my $activity_activity_type_detail_description_html = "";

    my $activity_first_program_name = "";
    my $activity_start = "";
    my $activity_start_datum = "";
    my $activity_start_uhrzeit = "";
    my $activity_end = "";
    my $activity_end_datum = "";
    my $activity_end_uhrzeit = "";
    my $activity_room_type_name = "";
    my $activity_room_name = "";
    my $activity_room_navigation_instruction = "";
    my $activity_jury_lane = "";
    my $activity_jury_team = "";
    my $activity_table_1 = "";
    my $activity_table_1_team = "";
    my $activity_table_2 = "";
    my $activity_table_2_team = "";

    my $activity_extra_block_name = "";
    my $activity_extra_block_description = "";
    my $activity_extra_block_room_name = "";
    my $activity_extra_block_room_navigation_instruction = "";

    my $zeitplan_item = "";
    my $activity_item = "";
    my $activity_item_list = "";

    my $xml_activity_groups = "";
    my $xml_activity_group = "";
    my $xml_activities = "";
    my $xml_activity = "";
    my $xml_activity_titel = "";
    my $xml_activity_detail = "";

    my $team_name_output = "";


    my $where_lane = "";
    my $where_table = "";
    my $where_team = "";

    my %activity_groups;

    my %activity_group_rooms;
    my $activity_group_rooms_list = "";
    my $activity_group_rooms_anzahl = 0;

    my $actual_activity_group_id = "";

    my %detailplan_data;

    # hier erstmal alles holen, was bzgl. der Activity-Group theoretisch fuer die Rolle vorhanden/sichtbar waere (einzige Bedingung:Visibility!)
    # Filterung auf tatsaechlich fuer die Rolle vorhandene Activities folgt im Anschluss
    # kann dazu fuehren, dass die Activity-Group dann gar nicht angezeigt wird (weil keine relevanten Inhalte fuer die Rolle)
    $query = qq{select
    activity_group.id,
    m_activity_type_detail.id,
    m_activity_type_detail.name,
    m_activity_type_detail.description,
    m_activity_type_detail.link,
    m_activity_type.id,
    m_activity_type.name,
    m_activity_type.description,
    m_first_program.name,
    m_activity_type.overview_plan_column,
    m_first_program.color_hex
    from activity_group
    join m_activity_type_detail on activity_group.activity_type_detail=m_activity_type_detail.id
    join m_activity_type on m_activity_type_detail.activity_type=m_activity_type.id
    left join m_first_program on m_activity_type_detail.first_program=m_first_program.id
    left join m_visibility on activity_group.activity_type_detail=m_visibility.activity_type_detail and m_visibility.role=$params->{role}
    where activity_group.plan=$params->{plan}
    and not(isnull(m_visibility.id))
    };
    #activity_type.name,
    #activity_type.description,
    #join activity_type on activity_type_detail.activity_type=activity_type.id
    $sth = $dbh->prepare($query);
    $rv = $sth->execute;

    #print "Content-type: text/html; charset=utf-8\n\n";
    #print qq{$query<br><br>};

    if ($rv ne "0E0") {
        while (@row = $sth->fetchrow_array) {
                
            $activity_group_id = $row[0];
            $activity_group_activity_type_detail_id = $row[1];
            $activity_group_activity_type_detail_name = $row[2];
            $activity_group_activity_type_detail_description = $row[3];
            $activity_group_activity_type_detail_link = $row[4];
            $activity_group_activity_type_id = $row[5];
            $activity_group_activity_type_name = $row[6];
            $activity_group_activity_type_description = $row[7];
            $activity_group_first_program_name = $row[8];
            $activity_group_overview_plan_column = $row[9];
            $activity_group_first_program_color_hex = $row[10];

            if ($activity_group_first_program_color_hex eq "") {
                $activity_group_first_program_color_hex = "888888"; # default
            }

            # Hash befuellen
            $activity_groups{$activity_group_id}{activity_type_detail_id} = $activity_group_activity_type_detail_id;
            $activity_groups{$activity_group_id}{activity_type_detail_name} = $activity_group_activity_type_detail_name;
            $activity_groups{$activity_group_id}{activity_type_detail_description} = $activity_group_activity_type_detail_description;
            $activity_groups{$activity_group_id}{activity_type_detail_link} = $activity_group_activity_type_detail_link;
            $activity_groups{$activity_group_id}{activity_type_id} = $activity_group_activity_type_id;
            $activity_groups{$activity_group_id}{activity_type_name} = $activity_group_activity_type_name;
            $activity_groups{$activity_group_id}{activity_type_description} = $activity_group_activity_type_description;
            $activity_groups{$activity_group_id}{first_program_name} = $activity_group_first_program_name;
            $activity_groups{$activity_group_id}{overview_plan_column} = $activity_group_overview_plan_column;
            $activity_groups{$activity_group_id}{first_program_color_hex} = $activity_group_first_program_color_hex;
            $activity_groups{$activity_group_id}{start} = "";
            $activity_groups{$activity_group_id}{end} = "";
            # Ende Hash befuellen

            # jetzt noch Activities innerhalb der Group holen
            if ($params->{lane} ne "") {
                $where_lane = "and (activity.jury_lane=$params->{lane} or ISNULL(activity.jury_lane))";
            }
            else {
                $where_lane = "";
            }
            if ($params->{table} ne "") {
                $where_table = "and (activity.table_1=$params->{table} or activity.table_2=$params->{table} or (ISNULL(activity.table_1) and ISNULL(activity.table_2)))";
            }
            else {
                $where_table = "";
            }
            if ($params->{team} ne "") {
                $where_team = "and (   (m_activity_type_detail.id=1 and activity.jury_team=$params->{team})
                                    or (m_activity_type_detail.id=17 and activity.jury_team=$params->{team})
                                    or (m_activity_type_detail.id=42 and activity.jury_team=$params->{team})
                                    or (m_activity_type_detail.id=15 and (activity.table_1_team=$params->{team} or activity.table_2_team=$params->{team}
                                                                          or (activity_group.activity_type_detail!=8
                                                                              and activity_group.activity_type_detail!=9
                                                                              and activity_group.activity_type_detail!=10
                                                                              and activity_group.activity_type_detail!=11
                                                                              and (ISNULL(activity.table_1_team) or ISNULL(activity.table_2_team))
                                                                             )
                                                                         )
                                       )
                                    or (m_activity_type_detail.id=16 and (activity.table_1_team=$params->{team} or activity.table_2_team=$params->{team}))
                                    or (m_activity_type_detail.id!=1 and m_activity_type_detail.id!=15 and m_activity_type_detail.id!=17 and m_activity_type_detail.id!=42 and m_activity_type_detail.id!=16 and isnull(activity.jury_team))
                                )";
                                # 1 = Begutachtung (EXPLORE)
                                # 17 = Präsentation und Fragen (Jury)
                                # 42 = LC mit Team
                                # 15 = Match (Robot-Game)
                                # 16 = Robot-Check (Robot-Game)
            }
            else {
                $where_team = "";
            }


            # Liste fuer Web und XML initialisieren (je Activity-Group)
            $activity_item_list = "";
            $xml_activities = "";

            %activity_group_rooms = (); # leeren
            $activity_group_rooms_list = ""; #leeren

            $query_activities = qq{select
            date_format(activity.start,'%Y-%m-%d %H:%i'),
            date_format(activity.start,'%Y-%m-%d'),
            date_format(activity.start,'%H:%i'),
            date_format(activity.end,'%Y-%m-%d %H:%i'),
            date_format(activity.end,'%Y-%m-%d'),
            date_format(activity.end,'%H:%i'),
            m_activity_type_detail.id,
            m_activity_type_detail.name,
            m_activity_type_detail.description,
            m_activity_type_detail.link,
            m_activity_type.id,
            m_activity_type.name,
            m_activity_type.description,
            m_room_type.name,
            room.name,
            room.navigation_instruction,
            activity.jury_lane,
            activity.jury_team,
            activity.table_1,
            activity.table_1_team,
            activity.table_2,
            activity.table_2_team,
            extra_block.name,
            extra_block.description,
            extra_block_room.name,
            extra_block_room.navigation_instruction
            from activity
            join m_activity_type_detail on activity.activity_type_detail=m_activity_type_detail.id
            join m_activity_type on m_activity_type_detail.activity_type=m_activity_type.id
            left join m_visibility on activity.activity_type_detail=m_visibility.activity_type_detail and m_visibility.role=$params->{role}
            left join m_room_type on activity.room_type=m_room_type.id
            left join room_type_room on activity.room_type=room_type_room.room_type and room_type_room.event=$event_id
            left join room on room_type_room.room=room.id
            join activity_group on activity_group.id=activity.activity_group
            left join extra_block on extra_block.id=activity.extra_block
            left join room as extra_block_room on extra_block.room=extra_block_room.id
            where activity.activity_group=$activity_group_id
            and not(isnull(m_visibility.id))
            $where_lane
            $where_table
            $where_team
            order by time_format(activity.start,'%H:%i') ASC
            };
            $sth_activities = $dbh->prepare($query_activities);
            $rv_activities = $sth_activities->execute;

            #print "Content-type: text/html; charset=utf-8\n\n";
            #print qq{$query_activities<br><br>};

            if ($rv_activities ne "0E0") {
                while (@row_activities = $sth_activities->fetchrow_array) {
                    $activity_start = $row_activities[0];
                    $activity_start_datum = $row_activities[1];
                    $activity_start_uhrzeit = $row_activities[2];
                    $activity_end = $row_activities[3];
                    $activity_end_datum = $row_activities[4];
                    $activity_end_uhrzeit = $row_activities[5];

                    #print $activity_start."<br>";
                    #print $activity_end."<br>";

                    $activity_activity_type_detail_id = $row_activities[6];
                    $activity_activity_type_detail_name = $row_activities[7];
                    $activity_activity_type_detail_description = $row_activities[8];
                    $activity_activity_type_detail_link = $row_activities[9];

                    $activity_activity_type_id = $row_activities[10];
                    $activity_activity_type_name = $row_activities[11];
                    $activity_activity_type_description = $row_activities[12];

                    $activity_room_type_name = $row_activities[13];
                    $activity_room_name = $row_activities[14];
                    $activity_room_navigation_instruction = $row_activities[15];

                    $activity_jury_lane = $row_activities[16];
                    $activity_jury_team = $row_activities[17];
                    $activity_table_1 = $row_activities[18];
                    $activity_table_1_team = $row_activities[19];
                    $activity_table_2 = $row_activities[20];
                    $activity_table_2_team = $row_activities[21];

                    $activity_extra_block_name = $row_activities[22];
                    $activity_extra_block_description = $row_activities[23];
                    $activity_extra_block_room_name = $row_activities[24];
                    $activity_extra_block_room_navigation_instruction = $row_activities[25];

                    if ($activity_room_type_name eq "") {
                        $activity_room_type_name = "nicht spezifiziert";
                    }
                    if ($activity_room_name eq "") {
                        $activity_room_name = "[$activity_room_type_name]";
                    }
                    if ($activity_room_navigation_instruction ne "") {
                        $activity_room_navigation_instruction = "($activity_room_navigation_instruction)";
                    }

                    if ($activity_activity_type_detail_id == 47 || $activity_activity_type_detail_id == 48 || $activity_activity_type_detail_id == 49 || $activity_activity_type_detail_id == 50 || $activity_activity_type_detail_id == 51 || $activity_activity_type_detail_id == 52) {
                        # eingeschobener (47,48,49) oder freier Block (50,51,52)
                        $activity_activity_type_detail_name = $activity_extra_block_name;

                        # auch den Titel der Activity-Group ($activity_group_activity_type_detail_name) in diesem Fall auf $activity_extra_block_name setzen
                        # extra Bloecke haben immer eine eigene Activity-Group und keine Schwester-Activities
                        $activity_group_activity_type_detail_name = $activity_extra_block_name;

                        # der Room wird hier auch anders ermittelt
                        $activity_room_name = $activity_extra_block_room_name;
                        $activity_room_navigation_instruction = $activity_extra_block_room_navigation_instruction;
                    }

                    $activity_group_rooms{$activity_room_name} = "x"; # alle Raeume in Hash sammeln , 'x' ist dummy-Eintrag, wir brauchen nur die keys spaeter

                    if ($activity_activity_type_detail_description ne "") {
                        if ($activity_activity_type_detail_link ne "") {
                            $activity_activity_type_detail_link = qq{&lt;a href="$activity_activity_type_detail_link" target="_blank"&gt;weitere Infos&lt;/a&gt;};
                        }
                        $activity_activity_type_detail_description_html = $activity_activity_type_detail_description;
                        $activity_activity_type_detail_description_html =~ s/'/&apos;/g;
                        $activity_activity_type_detail_description_html = qq{<span data-bs-toggle="popover" data-bs-placement="top" data-bs-html="true" data-bs-content='$activity_activity_type_detail_description_html. $activity_activity_type_detail_link'><i class="bi-info-circle"></i></span>};
                    }
                    else {
                        $activity_activity_type_detail_description_html = "";
                    }


                    $activity_item = qq{<div>
    <strong>$activity_start_uhrzeit-$activity_end_uhrzeit</strong>
    <br>$activity_activity_type_detail_name $activity_activity_type_detail_description_html<br>};

                    # initialisieren
                    $xml_activity_titel = "";
                    $xml_activity_detail = "";


                    if ($activity_activity_type_detail_id == 17 || $activity_activity_type_detail_id == 18 || $activity_activity_type_detail_id == 42 || $activity_activity_type_detail_id == 43) {
                        # Jury (Challenge) / 17 + 18 (17 mit Team, 18 Bewertung)
                        # auch Live-Challenge / 42 + 43 (42 mit Team, 43 ohne Team)
                        # Rolle = Challenge-Team = 3
                        if ($params->{role} == 3) {
                            # wenn Rolle = Team, dann nur die Jurygruppe (lane) ausgeben
                            $activity_item .= qq{Jury $activity_jury_lane};
                            $xml_activity_titel = qq{Jury $activity_jury_lane};
                            $xml_activity_detail = "";
                        }
                        else {
                            $team_name_output = get_team_name({team_number_plan=>$activity_jury_team, team_first_program=>3, team_hash_ref=>\%team});

                            #$activity_item .= qq{Jury $activity_jury_lane<br><a href="zeitplan.cgi?plan=$params->{plan}&brief=no&expired=$params->{expired}&now=$params->{now}&role=3&team=$activity_jury_team" class="teamlink">$team{$activity_jury_team}{3}{name} ($team{$activity_jury_team}{3}{number_hot})</a>};
                            $activity_item .= qq{Jury $activity_jury_lane<br><a href="zeitplan.cgi?plan=$params->{plan}&brief=no&expired=$params->{expired}&now=$params->{now}&role=3&team=$activity_jury_team" class="teamlink">$team_name_output</a>};
                            if ($team{$activity_jury_team}{3}{location} ne "") {
                                $activity_item .= qq{ aus }.$team{$activity_jury_team}{3}{location};
                            }
                            $xml_activity_titel = qq{Jury $activity_jury_lane};
                            if ($team{$activity_jury_team}{3}{location} ne "") {
                                $xml_activity_titel .= qq{ aus }.$team{$activity_jury_team}{3}{location};
                            }
                            #$xml_activity_detail = qq{$team{$activity_jury_team}{3}{name} ($team{$activity_jury_team}{3}{number_hot})};
                            $xml_activity_detail = $team_name_output;
                        }
                    }
                    elsif ($activity_activity_type_detail_id == 1) {
                        # Begutachtung (Explore)
                        # Rolle = Explore-Team = 8
                        if ($params->{role} == 8) {
                            # wenn Rolle = Team, dann nur die Gutachtergruppe (lane) ausgeben
                            $activity_item .= qq{Jury $activity_jury_lane};
                            $xml_activity_titel = qq{Jury $activity_jury_lane};
                            $xml_activity_detail = "";
                        }
                        else {
                            $team_name_output = get_team_name({team_number_plan=>$activity_jury_team, team_first_program=>2, team_hash_ref=>\%team});

                            #$activity_item .= qq{Jury $activity_jury_lane<br><a href="zeitplan.cgi?plan=$params->{plan}&brief=no&expired=$params->{expired}&now=$params->{now}&role=8&team=$activity_jury_team" class="teamlink">$team{$activity_jury_team}{2}{name} ($team{$activity_jury_team}{2}{number_hot})</a>};
                            $activity_item .= qq{Jury $activity_jury_lane<br><a href="zeitplan.cgi?plan=$params->{plan}&brief=no&expired=$params->{expired}&now=$params->{now}&role=8&team=$activity_jury_team" class="teamlink">$team_name_output</a>};
                            if ($team{$activity_jury_team}{2}{location} ne "") {
                                $activity_item .= qq{ aus }.$team{$activity_jury_team}{2}{location};
                            }
                            $xml_activity_titel = qq{Jury $activity_jury_lane};
                            if ($team{$activity_jury_team}{2}{location} ne "") {
                                $xml_activity_titel .= qq{ aus }.$team{$activity_jury_team}{2}{location};
                            }
                            #$xml_activity_detail = qq{$team{$activity_jury_team}{2}{name} ($team{$activity_jury_team}{2}{number_hot})};
                            $xml_activity_detail = $team_name_output;
                        }
                    }
                    elsif ($activity_activity_type_detail_id == 15 || $activity_activity_type_detail_id == 16) {
                        # Robot-Game-Match
                        # oder Robot-Check
                        if ($params->{role} == 3) {
                            if ($params->{team} == $activity_table_1_team || $params->{team} == $activity_table_2_team) {
                                # wenn Rolle = Team und Team konkret beteiligt, dann nur den Tisch (table) ausgeben
                                if ($params->{team} == $activity_table_1_team) {
                                    # Tisch 1
                                    $activity_item .= qq{Tisch $table_name{$activity_table_1}};
                                    $xml_activity_titel = "";
                                    $xml_activity_detail = qq{Tisch $table_name{$activity_table_1}};
                                }
                                else {
                                    # Tisch 2
                                    $activity_item .= qq{Tisch $table_name{$activity_table_2}};
                                    $xml_activity_titel = "";
                                    $xml_activity_detail = qq{Tisch $table_name{$activity_table_2}};
                                }
                            }
                            else {
                                # andernfalls ist mindestens eines der beiden Teams = NULL und das Team nicht konkret beteiligt
                                # bedeutet: ausfallende Testrunde, freiwilliges Team ohne Wertung in Vorrunde oder noch nicht ermittelte Finalrunden
                                if ($activity_group_activity_type_detail_id == 8) {
                                    # Testrunde
                                    # fuer Team gar nicht anzeigen
                                    # wird bereits in Datenbankabfrage verhindert
                                }
                                elsif ($activity_group_activity_type_detail_id == 9 || $activity_group_activity_type_detail_id == 10 || $activity_group_activity_type_detail_id == 11) {
                                    # Vorrunde 1-3 (freiwilliges Team ohne Wertung)
                                    # wird aufgrund Datenbankabfrage bereits verhindert, d.h. dem Team nicht angezeigt
                                    if ($activity_table_1_team eq "" || $activity_table_1_team == 0) {
                                        # Tisch 1
                                        $activity_item .= qq{Tisch $table_name{$activity_table_1}};
                                        $activity_item .= ": ";
                                        $activity_item .= text_fuer_kein_team({activity_group_activity_type_detail_id => $activity_group_activity_type_detail_id});
                                        $xml_activity_titel = "";
                                        $xml_activity_detail = qq{Tisch $table_name{$activity_table_1}};
                                        $xml_activity_detail .= ": ";
                                        $xml_activity_detail .= text_fuer_kein_team({activity_group_activity_type_detail_id => $activity_group_activity_type_detail_id});
                                    }
                                    else {
                                        # Tisch 2
                                        $activity_item .= qq{Tisch $table_name{$activity_table_2}};
                                        $activity_item .= ": ";
                                        $activity_item .= text_fuer_kein_team({activity_group_activity_type_detail_id => $activity_group_activity_type_detail_id});
                                        $xml_activity_titel = "";
                                        $xml_activity_detail = qq{Tisch $table_name{$activity_table_2}};
                                        $xml_activity_detail .= ": ";
                                        $xml_activity_detail .= text_fuer_kein_team({activity_group_activity_type_detail_id => $activity_group_activity_type_detail_id});
                                    }
                                }
                                else {
                                    # Finalrunden
                                    $activity_item .= text_fuer_kein_team({activity_group_activity_type_detail_id => $activity_group_activity_type_detail_id});
                                    $xml_activity_titel = "";
                                    $xml_activity_detail = text_fuer_kein_team({activity_group_activity_type_detail_id => $activity_group_activity_type_detail_id});
                                }
                            }
                        }
                        elsif ($params->{role} == 5 || $params->{role} == 11) {
                            # wenn Rolle = Schiedsrichter, dann nur das Team am Tisch ausgeben
                            # ebenso, wenn Rolle = Robot-Check
                            if ($params->{table} == $activity_table_1) {
                                # Tisch 1
                                if ($activity_table_1_team eq "" || $activity_table_1_team == 0) {
                                    # wenn kein Team angegeben
                                    $activity_item .= text_fuer_kein_team({activity_group_activity_type_detail_id => $activity_group_activity_type_detail_id});
                                    $xml_activity_titel = "";
                                    $xml_activity_detail = text_fuer_kein_team({activity_group_activity_type_detail_id => $activity_group_activity_type_detail_id});
                                }
                                else {
                                    $team_name_output = get_team_name({team_number_plan=>$activity_table_1_team, team_first_program=>3, team_hash_ref=>\%team});

                                    #$activity_item .= qq{<a href="zeitplan.cgi?plan=$params->{plan}&brief=no&expired=$params->{expired}&now=$params->{now}&role=3&team=$activity_table_1_team" class="teamlink">$team{$activity_table_1_team}{3}{name} ($team{$activity_table_1_team}{3}{number_hot})</a>};
                                    $activity_item .= qq{<a href="zeitplan.cgi?plan=$params->{plan}&brief=no&expired=$params->{expired}&now=$params->{now}&role=3&team=$activity_table_1_team" class="teamlink">$team_name_output</a>};
                                    $xml_activity_titel = "";
                                    #$xml_activity_detail = qq{$team{$activity_table_1_team}{3}{name} ($team{$activity_table_1_team}{3}{number_hot})};
                                    $xml_activity_detail = $team_name_output;
                                }
                            }
                            else {
                                # Tisch 2
                                if ($activity_table_2_team eq "" || $activity_table_2_team == 0) {
                                    # wenn kein Team angegeben
                                    $activity_item .= text_fuer_kein_team({activity_group_activity_type_detail_id => $activity_group_activity_type_detail_id});
                                    $xml_activity_titel = "";
                                    $xml_activity_detail = text_fuer_kein_team({activity_group_activity_type_detail_id => $activity_group_activity_type_detail_id});
                                }
                                else {
                                    $team_name_output = get_team_name({team_number_plan=>$activity_table_2_team, team_first_program=>3, team_hash_ref=>\%team});

                                    #$activity_item .= qq{<a href="zeitplan.cgi?plan=$params->{plan}&brief=no&expired=$params->{expired}&now=$params->{now}&role=3&team=$activity_table_2_team" class="teamlink">$team{$activity_table_2_team}{3}{name} ($team{$activity_table_2_team}{3}{number_hot})</a>};
                                    $activity_item .= qq{<a href="zeitplan.cgi?plan=$params->{plan}&brief=no&expired=$params->{expired}&now=$params->{now}&role=3&team=$activity_table_2_team" class="teamlink">$team_name_output</a>};
                                    $xml_activity_titel = "";
                                    #$xml_activity_detail = qq{$team{$activity_table_2_team}{3}{name} ($team{$activity_table_2_team}{3}{number_hot})};
                                    $xml_activity_detail = $team_name_output;
                                }
                            }
                        }
                        else {
                            # Team 1 Table 1 - Team 2 Table 2
                            if ($activity_table_1_team eq "" || $activity_table_1_team == 0) {
                                # wenn kein Team angegeben
                                $activity_item .= text_fuer_kein_team({activity_group_activity_type_detail_id => $activity_group_activity_type_detail_id});
                                $xml_activity_titel = "";
                                $xml_activity_detail = text_fuer_kein_team({activity_group_activity_type_detail_id => $activity_group_activity_type_detail_id});
                            }
                            else {
                                $team_name_output = get_team_name({team_number_plan=>$activity_table_1_team, team_first_program=>3, team_hash_ref=>\%team});

                                #$activity_item .= qq{<a href="zeitplan.cgi?plan=$params->{plan}&brief=no&expired=$params->{expired}&now=$params->{now}&role=3&team=$activity_table_1_team" class="teamlink">$team{$activity_table_1_team}{3}{name} ($team{$activity_table_1_team}{3}{number_hot})</a> Tisch $activity_table_1};
                                $activity_item .= qq{<a href="zeitplan.cgi?plan=$params->{plan}&brief=no&expired=$params->{expired}&now=$params->{now}&role=3&team=$activity_table_1_team" class="teamlink">$team_name_output</a> Tisch $table_name{$activity_table_1}};
                                $xml_activity_titel = "";
                                #$xml_activity_detail = qq{$team{$activity_table_1_team}{3}{name} ($team{$activity_table_1_team}{3}{number_hot}) Tisch $activity_table_1};
                                $xml_activity_detail = qq{$team_name_output Tisch $table_name{$activity_table_1}};
                            }
                            $activity_item .= qq{ - };
                            $xml_activity_detail .= qq{ - };
                            if ($activity_table_2_team eq "" || $activity_table_2_team == 0) {
                                # wenn kein Team angegeben
                                $activity_item .= text_fuer_kein_team({activity_group_activity_type_detail_id => $activity_group_activity_type_detail_id});
                                $xml_activity_titel = "";
                                $xml_activity_detail .= text_fuer_kein_team({activity_group_activity_type_detail_id => $activity_group_activity_type_detail_id});
                            }
                            else {
                                $team_name_output = get_team_name({team_number_plan=>$activity_table_2_team, team_first_program=>3, team_hash_ref=>\%team});

                                #$activity_item .= qq{<a href="zeitplan.cgi?plan=$params->{plan}&brief=no&expired=$params->{expired}&now=$params->{now}&role=3&team=$activity_table_2_team" class="teamlink">$team{$activity_table_2_team}{3}{name} ($team{$activity_table_2_team}{3}{number_hot})</a> Tisch $activity_table_2};
                                $activity_item .= qq{<a href="zeitplan.cgi?plan=$params->{plan}&brief=no&expired=$params->{expired}&now=$params->{now}&role=3&team=$activity_table_2_team" class="teamlink">$team_name_output</a> Tisch $table_name{$activity_table_2}};
                                $xml_activity_titel = "";
                                #$xml_activity_detail .= qq{$team{$activity_table_2_team}{3}{name} ($team{$activity_table_2_team}{3}{number_hot}) Tisch $activity_table_2};
                                $xml_activity_detail .= qq{$team_name_output Tisch $table_name{$activity_table_2}};
                            }
                        }
                    }
                    elsif ($activity_activity_type_detail_id == 47 || $activity_activity_type_detail_id == 48 || $activity_activity_type_detail_id == 49 || $activity_activity_type_detail_id == 50 || $activity_activity_type_detail_id == 51 || $activity_activity_type_detail_id == 52) {
                        # eingeschobener (47,48,49) oder freier Block (50,51,52)
                        # nichts zu $activity_item ergaenzen
                        #$activity_item .= "";
                        $xml_activity_titel = "";
                        # oder: ?
                        #$xml_activity_titel = $activity_extra_block_name;
                        $xml_activity_detail = "";

                        # Description kommt in diesem Fall aus dem extra_block, daher die description aus activity_type_detail ueberschreiben
                        $activity_group_activity_type_detail_description = $activity_extra_block_description;
                    }
                    # Raum noch fuer extra_block anpassen!
                    $activity_item .= qq{<br><i class="bi-geo"></i> $activity_room_name $activity_room_navigation_instruction};

                    # jetzt noch weiterfuehrende Links in andere Tools ergaenzen
                    if ($params->{role} == 4) {
                        # 4 = Challenge Jury
                        if ($activity_activity_type_detail_id == 17) {
                            $activity_item .= qq{<br><a href="https://jurytimer.hands-on-technology.org" target="_blank" class="teamlink">Jury-Timer</a>};
                        }
                        if ($activity_activity_type_detail_id == 18) {
                            $activity_item .= qq{<br><a href="https://evaluation.hands-on-technology.org" target="_blank" class="teamlink">Auswertungssoftware</a>};
                        }
                    }

                    # jetzt noch Start und Ende Zeit speichern
                    # Start
                    if ($activity_groups{$activity_group_id}{start} eq "") {
                        $activity_groups{$activity_group_id}{start} = $activity_start;
                        $activity_groups{$activity_group_id}{start_datum} = $activity_start_datum;
                        $activity_groups{$activity_group_id}{start_uhrzeit} = $activity_start_uhrzeit;
                    }
                    else {
                        if ($activity_start lt $activity_groups{$activity_group_id}{start}) {
                            $activity_groups{$activity_group_id}{start} = $activity_start;
                            $activity_groups{$activity_group_id}{start_datum} = $activity_start_datum;
                            $activity_groups{$activity_group_id}{start_uhrzeit} = $activity_start_uhrzeit;
                        }
                    }
                    # Ende
                    if ($activity_groups{$activity_group_id}{end} eq "") {
                        $activity_groups{$activity_group_id}{end} = $activity_end;
                        $activity_groups{$activity_group_id}{end_datum} = $activity_end_datum;
                        $activity_groups{$activity_group_id}{end_uhrzeit} = $activity_end_uhrzeit;
                    }
                    else {
                        if ($activity_end gt $activity_groups{$activity_group_id}{end}) {
                            $activity_groups{$activity_group_id}{end} = $activity_end;
                            $activity_groups{$activity_group_id}{end_datum} = $activity_end_datum;
                            $activity_groups{$activity_group_id}{end_uhrzeit} = $activity_end_uhrzeit;
                        }
                    }
                    # Ende Start/Ende-Zeit der Aktivity-Group

                    $activity_item .= qq{</div>};

                    if ($activity_item_list ne "") {
                        $activity_item_list .= "<hr>";
                    }

                    $activity_item_list .= $activity_item;
                    # und auch XML
                    $xml_activity = qq{
                    <activity>
    <start>$activity_start_uhrzeit</start>
    <end>$activity_end_uhrzeit</end>
    <activity_type_detail_name>$activity_activity_type_detail_name</activity_type_detail_name>
    <title>$xml_activity_titel</title>
    <detail>$xml_activity_detail</detail>
    <room>$activity_room_name</room>
</activity>
};
                    $xml_activities .= $xml_activity;
                }
            }

            # ab hier Filterung auf Zeit (je nach Parametern)
            if (   $activity_item_list ne ""
                && (   ($params->{output} ne "slide" && ($params->{expired} eq "yes" || $activity_groups{$activity_group_id}{end} ge $aktuelle_zeit))
                    || ($params->{output} eq "slide" && ($activity_groups{$activity_group_id}{end} ge $aktuelle_zeit && $activity_groups{$activity_group_id}{start} le $aktuelle_zeit_plus_1_stunde))
                   )
               ) {
                # nur, wenn auch was enthalten ist
                if ($activity_group_activity_type_detail_description ne "") {
                    if ($activity_group_activity_type_detail_link ne "") {
                        $activity_group_activity_type_detail_link = qq{&lt;a href="$activity_group_activity_type_detail_link" target="_blank"&gt;weitere Infos&lt;/a&gt;};
                    }
                    $activity_group_activity_type_detail_description_html = $activity_group_activity_type_detail_description;
                    $activity_group_activity_type_detail_description_html =~ s/'/&apos;/g;
                    $activity_group_activity_type_detail_description_html = qq{<span data-bs-toggle="popover" data-bs-placement="top" data-bs-html="true" data-bs-content='$activity_group_activity_type_detail_description_html. $activity_group_activity_type_detail_link<br>Start: $activity_groups{$activity_group_id}{start}<br>Ende: $activity_groups{$activity_group_id}{end}'><i class="bi-info-circle"></i></span>};
                }
                else {
                    $activity_group_activity_type_detail_description_html = "";
                }

                $zeitplan_item = $zeitplan_item_vorlage;

                # da $activity_group_activity_type_name jetzt gar nicht mehr ausgegeben wird, muss auch nicht auf Gleichheit abgeglichen werden...
                #if ($activity_group_activity_type_detail_name eq $activity_group_activity_type_name) {
                #    $activity_group_activity_type_detail_name = "";
                #}

                if ($params->{brief} eq "yes" || $params->{output} eq "slide") {
                    foreach my $activity_group_room (sort keys %activity_group_rooms) {
                        if ($activity_group_rooms_list ne "") {
                            $activity_group_rooms_list .= ", ";
                        }
                        $activity_group_rooms_list .= $activity_group_room;
                    }

                    $activity_group_rooms_anzahl = keys %activity_group_rooms;
                    if ($activity_group_rooms_anzahl > 1) {
                        #$activity_group_rooms_list = qq{in $activity_group_rooms_anzahl Räumen};
                        $activity_group_rooms_list =~ s/, /<br>/g;
                        if ($params->{brief} eq "yes") {
                            $activity_group_rooms_list = qq{<span data-bs-toggle="popover" data-bs-placement="top" data-bs-html="true" data-bs-content='$activity_group_rooms_list'>in $activity_group_rooms_anzahl Räumen <i class="bi-info-circle"></i></span>};
                        }
                        else {
                            # fuer Slide:
                            # unveraendert, d.h. ohne Popover sondern direkt anzeigen
                        }
                    }
                    if ($params->{brief} eq "yes") {
                        $activity_item_list = qq{<strong>$activity_groups{$activity_group_id}{start_uhrzeit} - $activity_groups{$activity_group_id}{end_uhrzeit}</strong> <i class="bi-geo"></i> $activity_group_rooms_list};
                    }
                    else {
                        $activity_item_list = qq{<div class="fs-5 fw-bold">$activity_groups{$activity_group_id}{start_uhrzeit} - $activity_groups{$activity_group_id}{end_uhrzeit}</div>$activity_group_rooms_list};
                    }
                }
                else {
                    # $activity_item_list unveraendert
                }

                $zeitplan_item =~ s/<!--activities-->/$activity_item_list/g;
                $zeitplan_item =~ s/<!--activity_group_id-->/$activity_group_id/g;
                $zeitplan_item =~ s/<!--activity_group_detail_name-->/$activity_group_activity_type_detail_name/g; # das erscheint in der Titelzeile der Activity-Group
                $zeitplan_item =~ s/<!--activity_group_detail_description-->/$activity_group_activity_type_detail_description_html/g;
                $zeitplan_item =~ s/<!--activity_group_name-->/$activity_group_activity_type_name/g;
                $zeitplan_item =~ s/<!--activity_group_description-->/$activity_group_activity_type_detail_description_html/g;
                $zeitplan_item =~ s/<!--activity_group_first_program_color_hex-->/$activity_group_first_program_color_hex;/g;

                # nun noch XML generieren fuer PDF
                $xml_activity_group = qq{<activity_group>
    <activity_type_name>$activity_group_activity_type_name</activity_type_name>
    <overview_plan_column>$activity_group_overview_plan_column</overview_plan_column>
    <activity_type_detail_name>$activity_group_activity_type_detail_name</activity_type_detail_name>
    <activity_type_detail_description>$activity_group_activity_type_detail_description</activity_type_detail_description>
    <start>$activity_groups{$activity_group_id}{start_uhrzeit}</start>
    <end>$activity_groups{$activity_group_id}{end_uhrzeit}</end>
    <activities>
        $xml_activities
    </activities>
</activity_group>                
                };

                # statt dessen erstmal im Hash speichern fuer spaetere Sortierung/Ausgabe
                $activity_groups{$activity_group_id}{content} = $zeitplan_item;
                # und auch als XML speichern
                $activity_groups{$activity_group_id}{xml_content} = $xml_activity_group;
            }
            else {
                # $activity_groups{$activity_group_id} aus Hash entfernen
                delete $activity_groups{$activity_group_id};
            }
            
        }

        # jetzt Activity Groups sortieren bzgl. Anfangs-/Endzeit
        # und in $detailplan schreiben/ausgeben
        $detailplan = ""; # vorher loeschen

        my $datum_merker = "";
        my $datum_deutsch = "";
        my $datum_tag = "";
        my $dt;
        my $dt_jahr;
        my $dt_monat;
        my $dt_tag;

        foreach my $activity_group ( sort {$activity_groups{$a}{start} cmp $activity_groups{$b}{start} or $activity_groups{$a}{end} cmp $activity_groups{$b}{end}} keys %activity_groups) {
            if (!defined($detailplan_data{start})) {
                $detailplan_data{start} = $activity_groups{$activity_group}{start_uhrzeit};
            }

            if ($xml_activity_groups eq "" || (($plan_metadata{event_date} ne $plan_metadata{event_enddate}) && ($params->{output} ne "slide") && ($activity_groups{$activity_group}{start_datum} gt $datum_merker))) {
                $dt_jahr = substr($activity_groups{$activity_group}{start_datum},0,4);
                $dt_monat = substr($activity_groups{$activity_group}{start_datum},5,2);
                $dt_tag = substr($activity_groups{$activity_group}{start_datum},8,2);

                $dt = DateTime->new(year=>$dt_jahr, month=>$dt_monat, day=>$dt_tag);
                $dt->set_locale('de_DE');
                $datum_tag = $dt->day_name;
                $datum_deutsch = konvertiere_datum($activity_groups{$activity_group}{start_datum}, 'de');
            }

            # muss fuer XML/PDF als erstes einmal initial ausgegeben werden
            if ($xml_activity_groups eq "") {
                # vor allererster activity_group
                $xml_activity_groups = qq{<day>
<date>
<date_iso>$activity_groups{$activity_group}{start_datum}</date_iso>
<date_output>$datum_deutsch</date_output>
<date_day_name>$datum_tag</date_day_name>
</date>
<activity_groups>
};
            }

            ################################################################################
            # Datum als Zwischenueberschrift ausgeben bei mehrtaegigen Events
            # und nicht bei output=slide
            if (($plan_metadata{event_date} ne $plan_metadata{event_enddate}) && ($params->{output} ne "slide") && ($activity_groups{$activity_group}{start_datum} gt $datum_merker)) {
                $detailplan .= qq{
<div class="row">
    <div class="col-12">
        <p style="text-align:center; font-size:clamp(20px, 6vw, 50px); font-weight:bold; color:#24355C" >
            $datum_tag, $datum_deutsch
        </p>
    </div>
</div>
};

                if ($datum_merker ne "") {
                    # wenn nicht der erste Tag, dann abschliessende Tags (des Vortags) setzen
                    $xml_activity_groups .= qq{</activity_groups></day>};

                    $xml_activity_groups .= qq{<day>
<date>
<date_iso>$activity_groups{$activity_group}{start_datum}</date_iso>
<date_output>$datum_deutsch</date_output>
<date_day_name>$datum_tag</date_day_name>
</date>
<activity_groups>
};
                }

                $datum_merker = $activity_groups{$activity_group}{start_datum};
            }
            # Ende Datum als Zwischenueberschrift ausgeben bei mehrtaegigen Events
            ################################################################################

            $detailplan .= $activity_groups{$activity_group}{content};
            $xml_activity_groups .= $activity_groups{$activity_group}{xml_content};

            # jetzt noch die erste Activity-Group, deren End-Zeit noch nicht abgelaufen ist, als aktuelle Activity-Group merken (zum automatisch Anspringen)
            # sobald einmal gesetzt, nicht weiter pruefen (die erste passt / ist die richtige)
            if ($actual_activity_group_id eq "" && $activity_groups{$activity_group}{end} ge $aktuelle_zeit) {
                $actual_activity_group_id = $activity_group;
            }

            $detailplan_data{end} = $activity_groups{$activity_group}{end_uhrzeit};
        }

        # abschliessende Tags setzen
        $xml_activity_groups .= qq{</activity_groups></day>};

    }
    else {
        $detailplan = qq{Plan $params->{plan} hat keine Activity-Groups für die gewählte Rolle};
    }

    #my %detailplan_data;
    #$detailplan_data{start} = ...;
    #$detailplan_data{end} = $activity_groups{$activity_group}{end};
    $detailplan_data{actual_activity_group_id} = $actual_activity_group_id;
    $detailplan_data{plan_html} = $detailplan;
    $detailplan_data{plan_xml} = $xml_activity_groups;
    
    return (\%detailplan_data);
}

sub get_auswahl {
    my $query = "";
    my $sth;
    my $rv;
    my @row;

    my $query_differentiation = "";
    my $sth_differentiation;
    my $rv_differentiation;
    my @row_differentiation;

    #if ($params->{plan} eq "") {
    #    $params->{plan} = 256;
    #}

    my $template = "";
    my $auswahl = "";

    my $role = "";
    my $auswahl_background_color = "";
    my $auswahl_logo = "";
    my $title = "";

    $template = get_template({page=>'auswahl', directory=>'./templates'}); # , variables=>{usertyp=>$user{typ}}

    my $accordeon_item_vorlage = qq{
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#role_<!--role_id-->" aria-expanded="false" aria-controls="role_<!--role_id-->" style="background-color:#<!--first_program_color-->">
                        <img src="logos/<!--first_program_logo-->" height="20px">
                        <span style="padding-left:40px; color:#FFFFFF"><!--role_name--></span>
                    </button>
                </h2>
                <div id="role_<!--role_id-->" class="accordion-collapse collapse" data-bs-parent="#auswahl">
                    <div class="accordion-body">
                        <div class="list-group list-group-flush">
                            <!--differentiation-->
                        </div>
                    </div>
                </div>
            </div> <!-- accordion-item -->
    };

    my $role_id = "";
    my $role_name = "";
    my $role_first_program = "";
    # $role_description hier noetig?
    my $role_differentiation_type = "";
    my $role_differentiation_source = "";
    my $role_differentiation_parameter = "";
    my $first_program_color_hex = "";
    my $first_program_logo_white = "";

    my $differentiation = "";
    my $differentiation_number = "";

    my $differentiation_id = "";
    my $differentiation_name = "";

    my $accordeon_item = "";

    #my $event_explore = "";
    #my $event_challenge = "";
    my $event_level = "";
    my $where_first_program = "";
    my $where_live_challenge_jury = "";

    my $robot_check = "";
    my $where_robot_check = "";

    ##########################################################################################
    # zuerst ermitteln, ob Explore und/oder Challenge im Event angeboten wird
    # und zwar ueber die Parameter e_teams und c_teams
    # und passende where-Bedingung aufbauen
    # Ebenso den Level ermitteln (Regio, Quali, Finale)
    ##########################################################################################

    # weitere Informationen holen
    # Plan-Parameter
    # u.a.
    # e_teams
    # c_teams
    # plus alle weiteren
    my %plan_parameter;

    $query = qq{select
                m_parameter.name,
                plan_param_value.set_value
                from plan_param_value
                join m_parameter on m_parameter.id=plan_param_value.parameter
                where plan_param_value.plan=$params->{plan}
            };
    $sth = $dbh->prepare($query);
    $rv = $sth->execute;

    if ($rv ne "0E0") {
        while (@row = $sth->fetchrow_array) {
            $plan_parameter{$row[0]} = $row[1];
        }
    }
    # Ende Parameter


    $query = qq{select
                event.level
                from plan
                join event on plan.event=event.id
                where plan.id=$params->{plan}
            };
    $sth = $dbh->prepare($query);
    $rv = $sth->execute;

    if ($rv ne "0E0") {
        @row = $sth->fetchrow_array;
        # fruher wurden noch event_explore und event_challenge geholt, jetzt ueber die Parameter geloest
        #$event_explore = $row[0];
        #$event_challenge = $row[1];
        $event_level = $row[0];
    }

    if ($plan_parameter{e_teams} > 0) {
        $where_first_program = "or m_role.first_program=2"; # Explore
    }
    if ($plan_parameter{c_teams} > 0) {
        $where_first_program .= " or m_role.first_program=3"; # Challenge
    }

    if ($event_level != 3) {
        $where_live_challenge_jury = "and m_role.id != 16"; # Rolle Live-Challenge-Jury ausblenden, wenn kein Finale (level=3)
    }
    ##########################################################################################
    # Ende ob Explore und/oder Challenge bzw. welcher Level
    ##########################################################################################

    ##########################################################################################
    # dann noch ermitteln, ob es einen Robot-Check gibt
    ##########################################################################################
    $query = qq{select
                set_value
                from plan_param_value
                join m_parameter on plan_param_value.parameter=m_parameter.id
                where m_parameter.name="r_robot_check" and plan_param_value.plan=$params->{plan}
               };

    $sth = $dbh->prepare($query);
    $rv = $sth->execute;

    if ($rv ne "0E0") {
        @row = $sth->fetchrow_array;
        $robot_check = $row[0];
    }

    if ($robot_check == 0) {
        $where_robot_check = "and m_role.id != 11"; # Rolle Robot-Check ausblenden, wenn nicht eingeplant 
    }

    ##########################################################################################
    # Ende Ermittlung Robot-Check
    ##########################################################################################

    ##########################################################################################
    # Teams in Hash einlesen
    ##########################################################################################
    my %team;
    %team = %{teams_in_hash({plan=>$params->{plan}})};
    ##########################################################################################
    # Ende
    ##########################################################################################


    $query = qq{select
                m_role.id,
                m_role.name,
                m_role.first_program,
                m_role.differentiation_type,
                m_role.differentiation_source,
                m_role.differentiation_parameter,
                m_first_program.color_hex,
                m_first_program.logo_white
                from m_role
                left join m_first_program on m_role.first_program=m_first_program.id
                where (ISNULL(m_role.first_program)
                       $where_first_program
                      )
                      $where_robot_check
                      $where_live_challenge_jury
                order by
                ISNULL(m_role.first_program) ASC,
                m_first_program.sequence ASC,
                m_role.sequence ASC
            };
            # ISNULL(m_role.first_program) ASC betrachtet zuerst alle Eintraege, bei denen m_role.first_program nicht NULL ist (ISNULL liefert dann 0, ansonsten 1)
    $sth = $dbh->prepare($query);
    $rv = $sth->execute;

    if ($rv ne "0E0") {
        while (@row = $sth->fetchrow_array) {

            $differentiation = "";
                
            $role_id = $row[0];
            $role_name = $row[1];
            $role_first_program = $row[2];
            $role_differentiation_type = $row[3];
            $role_differentiation_source = $row[4];
            $role_differentiation_parameter = $row[5];
            $first_program_color_hex = $row[6];
            $first_program_logo_white = $row[7];

            if (!defined $first_program_color_hex) {
                $first_program_color_hex = "888888"; # default
            }
            if (!defined $first_program_logo_white) {
                $first_program_logo_white = "FLL_column_heading.png"; # default
            }

            if ($role_differentiation_type eq "number") {
                # z.B. lanes oder tables

                # in $role_differentiation_source noch den [plan] ersetzen
                $role_differentiation_source =~ s/\[plan\]/$params->{plan}/g;

                # dann die differentiation_number durch Datenbankabfrage ermitteln
                $query_differentiation = $role_differentiation_source;

                $sth_differentiation = $dbh->prepare($query_differentiation);
                $rv_differentiation = $sth_differentiation->execute;

                if ($rv_differentiation ne "0E0") {
                    @row_differentiation = $sth_differentiation->fetchrow_array;
                            
                    $differentiation_number = $row_differentiation[0];

                    # in Schleife Differentiation 1-$differentiation_number ausgeben
                    for (my $differentiation_count = 1; $differentiation_count <= $differentiation_number; $differentiation_count++) {
                        my $role_name_display = "";
                        
                        if ($role_id == 3 || $role_id == 8) {
                            # Challenge- oder Explore-Team
                            if (defined $team{$differentiation_count}{$role_first_program}{name}) {
                                # erstmal ohne Organisation, weil sonst zu unuebersichtlich...
                                $role_name_display = $team{$differentiation_count}{$role_first_program}{name}." <i class='bi-geo'></i> ".$team{$differentiation_count}{$role_first_program}{location};
                            }
                            else {
                                $role_name_display = "$role_name $differentiation_count";
                            }
                        }
                        elsif ($role_id == 5 || $role_id == 11) {
                            # SchiedsrichterIn
                            # oder Robot-CheckerIn
                            $role_name_display = qq{Tisch $table_name{$differentiation_count}};
                        }
                        else {
                            $role_name_display = "$role_name $differentiation_count";
                        }
                        $differentiation .= qq{<a href="zeitplan.cgi?plan=$params->{plan}&brief=no&expired=$params->{expired}&now=$params->{now}&role=$role_id&amp;$role_differentiation_parameter=$differentiation_count&export=$params->{export}" class="list-group-item list-group-item-action">$role_name_display</a><br>};
                    }
                }

            }
            elsif ($role_differentiation_type eq "list") {
                # z.B. Teams aus Tabelle
                # z.B. fuer Challenge-Team
                # select team_plan.team_number_plan, team.name from team_plan join team on team_plan.team=team.id where plan=[plan] and team.first_program=3 order by team.name
                # und Explore-Team
                # select team_plan.team_number_plan, team.name from team_plan join team on team_plan.team=team.id where plan=[plan] and team.first_program=2 order by team.name
                # wird aber beides nicht mehr verwendet seit Umbau...

                # in $role_differentiation_source noch den [plan] ersetzen
                $role_differentiation_source =~ s/\[plan\]/$params->{plan}/g;

                # dann die differentiation_number durch Datenbankabfrage ermitteln
                $query_differentiation = $role_differentiation_source;

                $sth_differentiation = $dbh->prepare($query_differentiation);
                $rv_differentiation = $sth_differentiation->execute;

                if ($rv_differentiation ne "0E0") {
                    while (@row_differentiation = $sth_differentiation->fetchrow_array) {
                        $differentiation_id = $row_differentiation[0];
                        $differentiation_name = $row_differentiation[1];

                        $differentiation .= qq{<a href="zeitplan.cgi?plan=$params->{plan}&brief=no&expired=$params->{expired}&now=$params->{now}&role=$role_id&amp;$role_differentiation_parameter=$differentiation_id&export=$params->{export}" class="list-group-item list-group-item-action">$differentiation_name</a><br>};
                    }
                }
            }
            else {
                # NULL oder was ungueltiges
                $differentiation = qq{<a href="zeitplan.cgi?plan=$params->{plan}&brief=no&expired=$params->{expired}&now=$params->{now}&role=$role_id&export=$params->{export}" class="list-group-item list-group-item-action">$role_name</a><br>};
            }



            $accordeon_item = $accordeon_item_vorlage;

            $accordeon_item =~ s/<!--role_id-->/$role_id/g;
            $accordeon_item =~ s/<!--role_name-->/$role_name/g;
            $accordeon_item =~ s/<!--first_program_color-->/$first_program_color_hex/g;
            $accordeon_item =~ s/<!--first_program_logo-->/$first_program_logo_white/g;

            $accordeon_item =~ s/<!--differentiation-->/$differentiation/g;

            $auswahl .= $accordeon_item;
        }
    }

    $template =~ s/<!--zeitplan:auswahl-->/$auswahl/eg;

    return $template;
}

sub get_logos {
    my ($arg_ref) = @_; # uebergebene Argumente
    my $event = $arg_ref->{event};

    my $query = "";
    my $sth;
    my $rv;
    my @row;

    my $path = "";
    my $title = "";
    my $link = "";
    my $logos = "";

    $query = qq{select
                logo.path,
                logo.title,
                logo.link
                from event_logo
                join logo on event_logo.logo=logo.id
                where event_logo.event=$event
            };
    $sth = $dbh->prepare($query);
    $rv = $sth->execute;

    if ($rv ne "0E0") {
        while (@row = $sth->fetchrow_array) {
            $path = $row[0];
            $title = $row[1];
            $link = $row[2];

            # evtl. fuer lokalen Test
            # https://dev.planning.hands-on-technology.org/
            # vor den $path haengen
            $logos .= qq{
<div class="col">
    <div class="card h-100">
        <div class="card-body text-center start-info">};
            if ($link ne "") {
                $logos .= qq{<a href="$link" target="_blank">};
            }
            $logos .= qq{<img src="/$path" width="100%" alt="$title" title="$title">};
            if ($link ne "") {
                $logos .= qq{</a>};
            }
            $logos .= qq{
        </div>
    </div>
</div>
};
        }
    }

    return $logos;
}

sub teams_in_hash {
    #  Teams in Hash einlesen
    my ($arg_ref) = @_; # uebergebene Argumente
    my $plan = $arg_ref->{plan};

    my $query = "";
    my $sth;
    my $rv;
    my @row;

    my %team;
    my $team_id = "";
    my $team_first_program = "";
    my $team_name = "";
    my $team_number_hot = "";
    my $team_number_plan = "";
    my $team_location = "";
    my $team_organization = "";
    my $team_room_name = "";

    $query = qq{select
                team.id,
                team.first_program,
                team.name,
                team.team_number_hot,
                team_plan.team_number_plan,
                team.location,
                team.organization,
                room.name
                from team_plan
                join team on team_plan.team=team.id
                left join room on room.id=team_plan.room
                where team_plan.plan=$plan
            };
    $sth = $dbh->prepare($query);
    $rv = $sth->execute;

    if ($rv ne "0E0") {
        while (@row = $sth->fetchrow_array) {
            $team_id = $row[0];
            $team_first_program = $row[1];
            $team_name = $row[2];
            $team_number_hot = $row[3];
            $team_number_plan = $row[4];
            $team_location = $row[5];
            $team_organization = $row[6];
            $team_room_name = $row[7];


            $team{$team_number_plan}{$team_first_program}{id} = $team_id;
            $team{$team_number_plan}{$team_first_program}{name} = $team_name;
            $team{$team_number_plan}{$team_first_program}{number_hot} = $team_number_hot;
            $team{$team_number_plan}{$team_first_program}{location} = $team_location;
            $team{$team_number_plan}{$team_first_program}{organization} = $team_organization;
            $team{$team_number_plan}{$team_first_program}{room_name} = $team_room_name;
        }
    }

    return (\%team);
}

sub get_team_name {
    my ($arg_ref) = @_; # uebergebene Argumente
    my $team_number_plan = $arg_ref->{team_number_plan};
    my $team_first_program = $arg_ref->{team_first_program};
    my $team_hash_ref = $arg_ref->{team_hash_ref};

    my $team_name = "";

    # Ausgabe des Teamnamens definieren
    if (defined $team_hash_ref->{$team_number_plan}{$team_first_program}{name}) {
        $team_name = $team_hash_ref->{$team_number_plan}{$team_first_program}{name}." (".$team_hash_ref->{$team_number_plan}{$team_first_program}{number_hot}.")";
    }
    else {
        $team_name = "Team $team_number_plan";
    }

    return $team_name;
}

sub text_fuer_kein_team {
    my ($arg_ref) = @_; # uebergebene Argumente
    my $activity_group_activity_type_detail_id = $arg_ref->{activity_group_activity_type_detail_id};

    my $activity_item = "";

    if ($activity_group_activity_type_detail_id == 8) {
        # Testrunde
        $activity_item .= qq{-};
    }
    elsif ($activity_group_activity_type_detail_id == 9 || $activity_group_activity_type_detail_id == 10 || $activity_group_activity_type_detail_id == 11) {
        # Vorrunde 1-3
        $activity_item .= qq{freiwilliges Team ohne Wertung};
    }
    else {
        # Finalrunden
        $activity_item .= qq{?};
    }

    return $activity_item;
}

sub make_pdf {
    my ($arg_ref) = @_; # uebergebene Argumente
    my $session_filename = $arg_ref->{session_filename};
    my $output_filename = $arg_ref->{output_filename};

    my $xslfile = "./detailplan_pdf.xsl";
    #my $filename = "zeitplan.pdf";
    my $filename = $output_filename;

    $filename =~ s/[ ]/_/g;
    $filename =~ s/ä/ae/g;
    $filename =~ s/ü/ue/g;
    $filename =~ s/ö/oe/g;
    $filename =~ s/ß/ss/g;
    $filename =~ s/Ä/Ae/g;
    $filename =~ s/Ü/Ue/g;
    $filename =~ s/Ö/Oe/g;
    $filename =~ s/[^a-zA-Z0-9\-_]//g;

    #my $parameter = qq{ -param role "c_judge" -param lane "1"};
    
    my $xmlfile = "../export/pdf/$session_filename.xml";
    my $pdffile = "../export/pdf/$session_filename.pdf";

    my $fop = "";
    if ($ENV{SERVER_NAME} eq "www.fll-braunschweig.de") {
        $fop = "fop";
    }
    elsif ($ENV{SERVER_NAME} eq "dev.planning.hands-on-technology.org") {
        $fop = "/usr/home/handsb/public_html/dev-fll-planning/fop/fop/fop";        
    }
    elsif ($ENV{SERVER_NAME} eq "test.planning.hands-on-technology.org") {
        $fop = "/usr/home/handsb/public_html/test-fll-planning/fop/fop/fop";        
    }
    elsif ($ENV{SERVER_NAME} eq "planning.hands-on-technology.org") {
        $fop = "/usr/home/handsb/public_html/fll-planning/fop/fop/fop";
    }
    else {
        $fop = "/usr/home/handsb/public_html/fll-planning/fop/fop/fop";
    }

    #system qq{/usr/home/handsb/public_html/dev-fll-planning/fop/fop/fop -xml $xmlfile -xsl $xslfile -pdf $pdffile}; # $parameter
    system qq{$fop -xml $xmlfile -xsl $xslfile -pdf $pdffile}; # $parameter
    
    #$filename = "$filename.pdf";
    
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
    # Ende PDF

    #unlink $xmlfile;
    #unlink $pdffile;

    #print "Content-type: text/html; charset=utf-8\n\n";
    #print "OK";
    exit;

}

