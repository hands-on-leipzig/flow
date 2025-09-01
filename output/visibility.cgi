#!/usr/bin/perl
use strict;
use Carp;
use English;
use DBI;
use CGI ':standard';
use CGI::Carp qw ( fatalsToBrowser carpout );
use Encode;

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
read_config '../../.env' => my %config;

# query-Objekt initialisieren
my $query_cgi = new CGI;
my $params = $query_cgi->Vars; # Vars-Methode liefert tied hash reference

my $dbh;
my $query = "";
my $sth;
my $rv;
my @row;

my $query_activity_type_detail = "";
my $sth_activity_type_detail;
my $rv_activity_type_detail;
my @row_activity_type_detail;

my $query_role = "";
my $sth_role;
my $rv_role;
my @row_role;

# zu Datenbank connecten
# alte config.cgi
#$dbh = DBI->connect("DBI:mysql:database=$config{db}{name};host=$config{db}{host};port=3306","$config{db}{username}","$config{db}{password}");
# neue config.env im Hauptverzeichnis
$dbh = DBI->connect("DBI:mysql:database=$config{''}{DB_DATABASE};host=$config{''}{DB_HOST};port=$config{''}{DB_PORT}","$config{''}{DB_USERNAME}","$config{''}{DB_PASSWORD}");
$query = qq[set names 'utf8'];
$sth = $dbh->prepare($query);
$rv = $sth->execute;


if ($params->{action} eq "list") {
    show_list();
}
elsif ($params->{action} eq "toggle_visibility_ajax") {
    toggle_visibility_ajax();
}
else {
    show_list();
}


sub show_list {
    print "Content-type: text/html; charset=utf-8\n\n";

    my $template = "";

    my $liste = "";


    ################################################################################
    # am Anfang die Tabelle (Matrix) visibility in einen Hash einlesen
    ################################################################################
    my %visibility_matrix;

    my $visibility_id = "";
    my $visibility_activity_type_detail = "";
    my $visibility_role = "";

    $query = qq{select
                id,
                activity_type_detail,
                role
                from m_visibility
            };
    $sth = $dbh->prepare($query);
    $rv = $sth->execute;

    if ($rv ne "0E0") {
        while (@row = $sth->fetchrow_array) {
            $visibility_id = $row[0];
            $visibility_activity_type_detail = $row[1];
            $visibility_role = $row[2];

            $visibility_matrix{$visibility_role}{$visibility_activity_type_detail} = $visibility_id;
        }
    }

    ################################################################################
    # erst alle Rollen holen als Spaltenueberschriften
    ################################################################################
    my $role_id = "";
    my $role_name = "";
    my $role_first_program_name = "";
    my $role_first_program_color_hex = "";

    $query_role = qq{select
                    m_role.id,
                    m_role.name,
                    m_first_program.name,
                    m_first_program.color_hex
                    from m_role
                    left join m_first_program on m_role.first_program=m_first_program.id
                    order by ISNULL(m_role.first_program) ASC, m_first_program.sequence, m_role.sequence
                    };
    $sth_role = $dbh->prepare($query_role);
    $rv_role = $sth_role->execute;

    $liste .= qq{<tr><th></th>};

    if ($rv_role ne "0E0") {
        while (@row_role = $sth_role->fetchrow_array) {
            $role_id = $row_role[0];
            $role_name = $row_role[1];
            $role_first_program_name = $row_role[2];
            $role_first_program_color_hex = $row_role[3];

            if ($role_first_program_color_hex eq "") {
                $role_first_program_color_hex = "888888"; # default
            }

            $liste .= qq{<th scope="col" style="width:60px; background-color:#$role_first_program_color_hex; color:#ffffff; writing-mode:vertical-lr; rotate:0.5turn"><strong>$role_name</strong></th>};
            # writing-mode:vertical-rl; rotate:0.5turn
        }
    }

    $liste .= qq{</tr>};

    ################################################################################
    # jetzt die Activity-Type-Details holen
    ################################################################################
    my $activity_type_detail_id = "";
    my $activity_type_detail_name = "";
    my $activity_type_detail_first_program_name = "";
    my $activity_type_detail_first_program_color_hex = "";

    $query_activity_type_detail = qq{select
                                    m_activity_type_detail.id,
                                    m_activity_type_detail.name,
                                    m_first_program.name,
                                    m_first_program.color_hex
                                    from m_activity_type_detail
                                    left join m_first_program on m_activity_type_detail.first_program=m_first_program.id
                                    order by ISNULL(m_activity_type_detail.first_program) ASC, m_first_program.sequence, m_activity_type_detail.name
                                    };
    $sth_activity_type_detail = $dbh->prepare($query_activity_type_detail);
    $rv_activity_type_detail = $sth_activity_type_detail->execute;

    if ($rv_activity_type_detail ne "0E0") {
        while (@row_activity_type_detail = $sth_activity_type_detail->fetchrow_array) {
            $activity_type_detail_id = $row_activity_type_detail[0];
            $activity_type_detail_name = $row_activity_type_detail[1];
            $activity_type_detail_first_program_name = $row_activity_type_detail[2];
            $activity_type_detail_first_program_color_hex = $row_activity_type_detail[3];

            if ($activity_type_detail_first_program_color_hex eq "") {
                $activity_type_detail_first_program_color_hex = "888888"; # default
            }

            $liste .= qq{<tr><td style="width:200px; background-color:#$activity_type_detail_first_program_color_hex; color:#ffffff"><strong>$activity_type_detail_name</strong></td>};

            ################################################################################
            # jetzt je activity_type_detail fuer alle Rollen pruefen, ob visibility gesetzt
            # wichtig: selbe Reihenfolge wie oben fuer Ueberschriften!
            ################################################################################
            $query_role = qq{select
                            m_role.id,
                            m_role.name,
                            m_first_program.name,
                            m_first_program.color_hex
                            from m_role
                            left join m_first_program on m_role.first_program=m_first_program.id
                            order by ISNULL(m_role.first_program) ASC, m_first_program.sequence, m_role.sequence
                            };
            $sth_role = $dbh->prepare($query_role);
            $rv_role = $sth_role->execute;

            if ($rv_role ne "0E0") {
                while (@row_role = $sth_role->fetchrow_array) {
                    $role_id = $row_role[0];
                    $role_name = $row_role[1];
                    $role_first_program_name = $row_role[2];
                    $role_first_program_color_hex = $row_role[3];

                    if (defined $visibility_matrix{$role_id}{$activity_type_detail_id}) {
                        # Visibility ist gesetzt
                        #$liste .= qq{<td class="text-end">$visibility_matrix{$role_id}{$activity_type_detail_id}</td>};
                        $liste .= qq{<td class="text-end"><input type="checkbox" checked data-bs-toggle="tooltip" data-bs-title="$role_first_program_name $role_name sieht $activity_type_detail_first_program_name $activity_type_detail_name" onclick="toggle_visibility_modal($role_id, '$role_first_program_name $role_name', $activity_type_detail_id, '$activity_type_detail_first_program_name $activity_type_detail_name');"></td>};
                    }
                    else {
                        $liste .= qq{<td class="text-end"><input type="checkbox" data-bs-toggle="tooltip" data-bs-title="$role_first_program_name $role_name sieht $activity_type_detail_first_program_name $activity_type_detail_name" onclick="toggle_visibility_modal($role_id, '$role_first_program_name $role_name', $activity_type_detail_id, '$activity_type_detail_first_program_name $activity_type_detail_name');"></td>};
                    }
                }
            }

            $liste .= qq{</tr>};
        }
    }


    $template = get_template({page=>'visibility', directory=>'./templates'}); # , variables=>{usertyp=>$user{typ}}

    $template =~ s/<!--microsite:liste-->/$liste/eg;

    print $template;

}

sub toggle_visibility_ajax {
    print "Content-type: text/html; charset=utf-8\n\n";

    my $query = "";
    my $sth;
    my $rv;
    my @row;

    my $visibility_id = "";

    $query = qq{select
                id
                from m_visibility
                where 
                activity_type_detail = $params->{activity_type_detail}
                and role=$params->{role}
            };
    $sth = $dbh->prepare($query);
    $rv = $sth->execute;

    if ($rv ne "0E0") {
        # Eintrag vorhanden -> d.h. bei toggle nun entfernen
        @row = $sth->fetchrow_array;
        $visibility_id = $row[0];

        $query = qq{delete 
                    from m_visibility
                    where id=$visibility_id
                    and activity_type_detail = $params->{activity_type_detail}
                    and role=$params->{role}
                   };
        $sth = $dbh->prepare($query);
        $rv = $sth->execute;
    }
    else {
        # Eintrag nicht vorhanden -> d.h. bei toggle nun einfuegen
        $query = qq{insert into
                    m_visibility
                    set activity_type_detail = $params->{activity_type_detail},
                    role=$params->{role}
                   };
        $sth = $dbh->prepare($query);
        $rv = $sth->execute;
    }

}
