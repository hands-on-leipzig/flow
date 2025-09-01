package Netcity::Templates;

use version; our $VERSION = qv('0.0.1');

use strict;
use warnings;
use Carp;

use English;

#use lib "/local/perlmodule";
#use Netcity::DB;

require Exporter;
our @ISA = qw(Exporter);
our @EXPORT = qw(get_template replace_login_tags replace_form_fields replace_error_tags get_email_template);

# Aufruf: get_template ({templatename=>"xyz", templatedir=>"xyz", language=>"deutsch"})

sub get_template {
    my ($arg_ref) = @_; # uebergebene Argumente
    my $page = $arg_ref->{page};
    my $directory   = exists $arg_ref->{directory}   ? $arg_ref->{directory}   : q{}; # optional
    my $language    = exists $arg_ref->{language}    ? $arg_ref->{language}    : q{}; # optional
    my ($variables) = exists $arg_ref->{variables}   ? $arg_ref->{variables}   :  {}; # optional

    my $template = "";
    my $dateiname = "";
    my $template_spezifisch = "";
    my $mastertemplate_name = "";
    my $mastertemplate_dateiname = "";
    my $template_master = "";
    
    my $variable; 
    
    # erstmal behelfsweise...
    # $PARAM_ALLGEMEIN{lang} = "deutsch";

    #print ${$variables}{v1};
    #print $variables->{v1};
    #print ${$variables}{v2};
    #print $variables->{v2};

    if ($directory eq "") {
        $dateiname = "$page.html";
    }
    else {
        $dateiname = "$directory/$page.html";
    }

    open my $template_FH, '<', $dateiname or errorhandling_page($dateiname);
    while (<$template_FH>) {
        # 1. Zeile (oder auch spaeter, auf jeden Fall separate Zeile)
        # wenn Template nur Subtemplate ist, dann spaeter noch Mastertemplate laden
        if (/<!--template:master=([a-zA-Z0-9_\-]*)-->/) {
            # Filename von Mastertemplate 
            $mastertemplate_name = $1;
            # in diesem Fall Zeile nach Bearbeitung auch komplett entfernen/unterdruecken
        }
        else {
            $template_spezifisch .= $_;
        }
    }
    close $template_FH;

    # ggf. Mastertemplate laden
    if ($mastertemplate_name ne "") {
        $template = get_mastertemplate({template=>$template_spezifisch, page=>$mastertemplate_name, directory=>$arg_ref->{directory}, language=>$arg_ref->{variables}, variables=>$arg_ref->{variables}});
    }
    else {
        $template = $template_spezifisch;
    }

    foreach $variable (keys %{$variables}) {
        $template = eval_if_variable({template=>$template, variable=>$variable, value=>$variables->{$variable}});
    }

    $template = eval_if_page({template=>$template, page=>$page});

    # nun noch passend kodieren (erstmal default utf-8)
    # evtl. doch lieber nicht...
    #$template = Encode::encode("utf-8", $template);

    return $template;
}

sub get_mastertemplate {
    my ($arg_ref) = @_; # uebergebene Argumente
    my $template = $arg_ref->{template};
    my $page = $arg_ref->{page};
    my $directory   = exists $arg_ref->{directory}   ? $arg_ref->{directory}   : q{}; # optional
    my $language    = exists $arg_ref->{language}    ? $arg_ref->{language}    : q{}; # optional
    my ($variables) = exists $arg_ref->{variables}   ? $arg_ref->{variables}   :  {}; # optional

    #my $template = "";
    my $dateiname = "";
    my $template_spezifisch = "";
    my $mastertemplate_name = "";
    my $mastertemplate_dateiname = "";
    my $template_master = "";
    
    my $variable; 

    if ($page ne "") {
        if ($directory eq "") {
            $mastertemplate_dateiname = "$page.html";
        }
        else {
            $mastertemplate_dateiname = "$directory/$page.html";
        }

        open my $template_FH, '<', $mastertemplate_dateiname or errorhandling_master($mastertemplate_dateiname);
        while (<$template_FH>) {
            # 1. Zeile (oder auch spaeter, auf jeden Fall separate Zeile)
            # wenn Template nur Subtemplate ist, dann spaeter noch Mastertemplate laden
            if (/<!--template:master=([a-zA-Z0-9_\-]*)-->/) {
                # Filename von Mastertemplate 
                $mastertemplate_name = $1;
                # in diesem Fall Zeile nach Bearbeitung auch komplett entfernen/unterdruecken
            }
            else {
                $template_master .= $_;
            }
        }
        close $template_FH;

        # jetzt erst Content und Master zusammenfuegen
        $template_master =~ s/<!--template:content-->/$template/eg;
        $template = $template_master;

        # ggf. Mastertemplate laden
        if ($mastertemplate_name ne "") {
            $template = get_mastertemplate({template=>$template, page=>$mastertemplate_name, directory=>$arg_ref->{directory}, language=>$arg_ref->{variables}, variables=>$arg_ref->{variables}});
        }
        else {
            #$template = $template;
        }
    }
    else {
        #$template = $template;
    }

    return $template;
}

sub eval_if_variable {
    my ($arg_ref) = @_; # uebergebene Argumente
    my $template = $arg_ref->{template};
    my $variable = $arg_ref->{variable};
    my $value = $arg_ref->{value};

    if (!(defined $value)) {
        $value = "";
    }

    my $BEGIN;
    my $END;

    my $empty = q{};

    ###################################################################
    # zunaechst der = Fall
    ###################################################################
    $BEGIN = "<!--template:if variable $variable=$value-->";
    $END = "<!--template:\/if variable $variable=$value-->";
    # nur if-Tags loeschen
    $template =~ s/$BEGIN//g;
    $template =~ s/$END//g;

    # anschliessend:
    # alle anderen if-Tags (mit anderen values) mit eingeschlossene Inhalte loeschen
    $BEGIN = "<!--template:if variable $variable=[a-zA-Z0-9_\-]*-->";
    $END = "<!--template:\/if variable $variable=[a-zA-Z0-9_\-]*-->";
    $template =~ s/$BEGIN((?:(?!$BEGIN).)*)$END/$empty/sge; # /s heisst . matched auch newline, /e = eval righthandside

    ###################################################################
    # nun der != Fall
    ###################################################################
    $BEGIN = "<!--template:if variable $variable!=$value-->";
    $END = "<!--template:\/if variable $variable!=$value-->";
    # if-Tag mit eingeschlossenen Inhalten loeschen
    $template =~ s/$BEGIN((?:(?!$BEGIN).)*)$END/$empty/sge; # /s heisst . matched auch newline, /e = eval righthandside

    # anschliessend:
    $BEGIN = "<!--template:if variable $variable!=[a-zA-Z0-9_\-]*-->";
    $END = "<!--template:\/if variable $variable!=[a-zA-Z0-9_\-]*-->";
    # bei allen anderen if-Tags (mit anderen values) nur die if-Tags loeschen
    $template =~ s/$BEGIN//g;
    $template =~ s/$END//g;

    return $template
}

sub eval_if_page {
    my ($arg_ref) = @_; # uebergebene Argumente
    my $template = $arg_ref->{template};
    my $page = $arg_ref->{page};

    my $BEGIN;
    my $END;

    my $empty = q{};

    ###################################################################
    # zunaechst der = Fall
    ###################################################################
    $BEGIN = "<!--template:if page=$page-->";
    $END = "<!--template:\/if page=$page-->";
    # nur if-Tags loeschen
    $template =~ s/$BEGIN//g;
    $template =~ s/$END//g;

    # anschliessend:
    # alle anderen if-Tags (mit anderen values) mit eingeschlossene Inhalte loeschen
    $BEGIN = "<!--template:if page=[a-zA-Z0-9_\-]*-->";
    $END = "<!--template:\/if page=[a-zA-Z0-9_\-]*-->";
    $template =~ s/$BEGIN((?:(?!$BEGIN).)*)$END/$empty/sge; # /s heisst . matched auch newline, /e = eval righthandside

    ###################################################################
    # nun der != Fall
    ###################################################################
    $BEGIN = "<!--template:if page!=$page-->";
    $END = "<!--template:\/if page!=$page-->";
    # if-Tag mit eingeschlossenen Inhalten loeschen
    $template =~ s/$BEGIN((?:(?!$BEGIN).)*)$END/$empty/sge; # /s heisst . matched auch newline, /e = eval righthandside

    # anschliessend:
    $BEGIN = "<!--template:if page!=[a-zA-Z0-9_\-]*-->";
    $END = "<!--template:\/if page!=[a-zA-Z0-9_\-]*-->";
    # bei allen anderen if-Tags (mit anderen values) nur die if-Tags loeschen
    $template =~ s/$BEGIN//g;
    $template =~ s/$END//g;

    return $template
}

sub errorhandling_page {
    print "Content-type: text/html; charset=utf-8\n\n";
    print q{<html><head><meta http-equiv="content-type" content="text/html; charset=utf-8"></head><body>};
    print Encode::encode("utf-8", "Kann Page '$_[0]' nicht öffnen: $OS_ERROR");
    print q{</body></html>};
    croak "Kann Page '$_[0]' nicht öffnen: $OS_ERROR";
    exit;
}

sub errorhandling_master {
    print "Content-type: text/html; charset=utf-8\n\n";
    print "Kann Master '$_[0]' nicht öffnen: $OS_ERROR";
    croak "Kann Master '$_[0]' nicht öffnen: $OS_ERROR";
    exit;
}


# Aufruf: replace_login_tags ({session=>"session", user=>\%user, template=>$template})
sub replace_login_tags {
    my ($arg_ref) = @_; # uebergebene Argumente
    my $session = exists $arg_ref->{session}   ? $arg_ref->{session}   : q{}; # optional
    my %user = %{$arg_ref->{user}};
    my $template = $arg_ref->{template};

    $template =~ s/<!--login:session-->/$session/eg;
    $template =~ s/<!--login:user_id-->/$user{id}/eg;
    $template =~ s/<!--login:user_vorname-->/$user{vorname}/eg;
    $template =~ s/<!--login:user_nachname-->/$user{nachname}/eg;
    $template =~ s/<!--login:user_typ-->/$user{typ}/eg;
    
    # in diesem Zug auch den Seitentitel setzen
    # kann vorher im jeweiligen Skript auch anders gesetzt werden, hier nur default
    $template =~ s/<!--template:title-->/BAQ RMA Tool/g;

    return $template;
}


# Aufruf: replace_form_field ({template=>$template, namespace=>'namespace', zuordnungen=>\%zuordnungen, wert=>\%wert, seite=>'seite'})
sub replace_form_fields {
    my ($arg_ref) = @_; # uebergebene Argumente
    my $template = $arg_ref->{template};
    my $namespace = $arg_ref->{namespace};
    my %zuordnungen = %{$arg_ref->{zuordnungen}};
    my %wert = %{$arg_ref->{wert}};
    my $seite = $arg_ref->{seite};
    
    my $form_field;
    my $ersetztext;
    my $ersetztext2;
    my $streamlink;
    my $streamlink2;
    
    my $wert_temp;
    
    foreach $form_field (keys %zuordnungen) {

        if (!(defined $wert{$form_field})) {
            $wert{$form_field} = "";
        }

        if (field_is_in_page ({field=>$form_field, page=>$seite, zuordnungen=>\%zuordnungen})) {
            ######################################################################
            # Select
            ######################################################################
            if ($zuordnungen{$form_field}{form_type} eq "select") {
                $ersetztext = "$form_field"."_options";
                $template =~ s/<!--$namespace:$ersetztext-->/$wert{$form_field."_options"}/eg;
                $ersetztext = "$form_field"."_entry";
                $template =~ s/<!--$namespace:$ersetztext-->/$wert{$form_field."_entry"}/eg;
            }
            ######################################################################
            # Radio
            ######################################################################
            if ($zuordnungen{$form_field}{form_type} eq "radio") {
                $ersetztext = "$form_field"."_".$wert{$form_field}."_checked";
                $template =~ s/<!--$namespace:$ersetztext-->/checked/g;
                $ersetztext = "$form_field";
                $template =~ s/<!--$namespace:$ersetztext(_)[a-z0-9]*_checked-->//g;
            }
            elsif ($zuordnungen{$form_field}{form_type} eq "file") {
                if ($wert{$form_field} ne "") {
                    $ersetztext = "$form_field"."_image";
                    $ersetztext2 = "$form_field"."_download";
                    $streamlink = qq[stream_image.cgi?id=$wert{'id'}&typ=$namespace&filename=$wert{$form_field}&session=<!--login:session-->];
                    $streamlink2 = qq[stream_material.cgi?id=$wert{'id'}&filename=$wert{$form_field}&session=<!--login:session-->];
                    $template =~ s/<!--$namespace:$ersetztext-->/$streamlink/eg;
                    $template =~ s/<!--$namespace:$ersetztext2-->/$streamlink2/eg;
                }
                else {
                    $ersetztext = "$form_field"."_image";
                    $ersetztext2 = "$form_field"."_download";
                    $template =~ s/<!--$namespace:$ersetztext-->//g;
                    $template =~ s/<!--$namespace:$ersetztext2-->//g;
                }
            }

            if ($zuordnungen{$form_field}{form_type} eq "text" && $zuordnungen{$form_field}{db_type} eq "varchar") {
                # in Input-Feldern " ersetzen
                $wert_temp = $wert{$form_field};
                $wert_temp =~ s/"/&quot;/g;
                $template =~ s/<!--$namespace:$form_field-->/$wert_temp/eg;
            }
            else {
                $template =~ s/<!--$namespace:$form_field-->/$wert{$form_field}/eg;
            }

            if (exists $zuordnungen{$form_field}{required}) {
                $ersetztext = "$form_field"."_required_regex";
                $template =~ s/<!--$namespace:$ersetztext-->/$zuordnungen{$form_field}{required}/eg;
            }
            else {
                $ersetztext = ">$form_field"."_required_regex";
                $template =~ s/<!--$namespace:$ersetztext-->//g;
            }
            
            
            ############################################################################
            # wird derzeit noch nicht richtig verwendet / gesetzt...
            # Formularfelder disablen/readonly setzen
            $template =~ s/<!--$namespace:readonly-->/readonly/g;
            $template =~ s/<!--$namespace:disabled-->/disabled/g;
            
            $template =~ s/<!--$namespace:readonly-->//g;
            $template =~ s/<!--$namespace:disabled-->//g;
            ############################################################################
        }
    }
    return $template;
}

sub replace_error_tags {
    my ($arg_ref) = @_; # uebergebene Argumente
    my $template = $arg_ref->{template};
    my $namespace = $arg_ref->{namespace};
    my %zuordnungen = %{$arg_ref->{zuordnungen}};
    my %error = %{$arg_ref->{error}};
    my $seite = $arg_ref->{seite};
    
    my $form_field;
    my $ersetztext;
    my $streamlink;
    
    foreach $form_field (keys %zuordnungen) {
        if (field_is_in_page ({field=>$form_field, page=>$seite, zuordnungen=>\%zuordnungen})) {
            #if ($zuordnungen{$form_field}{form_type} eq "select") {
            #    $ersetztext = "$form_field"."_options";
            #    $template =~ s/<!--$namespace:$ersetztext-->/$wert{$form_field."_options"}/eg;
            #    $ersetztext = "$form_field"."_entry";
            #    $template =~ s/<!--$namespace:$ersetztext-->/$wert{$form_field."_entry"}/eg;
            #}
            #elsif ($zuordnungen{$form_field}{form_type} eq "file") {
            #    $ersetztext = "$form_field"."_image";
            #    $streamlink = qq[stream_image.cgi?typ=$namespace&id=$wert{'id'}&filename=$wert{$form_field}&session=<!--login:session-->];
            #    $template =~ s/<!--$namespace:$ersetztext-->/$streamlink/eg;
            #}
           
            $ersetztext = "$form_field"."_error";
            if (exists $error{$form_field}) {
                $template =~ s/<!--$namespace:$ersetztext-->/$error{$form_field}/eg;
            }
            else {
                $template =~ s/<!--$namespace:$ersetztext-->//eg;
            }
        }
    }
    return $template;
}


sub get_email_template {
    my ($arg_ref) = @_; # uebergebene Argumente
    my $page = $arg_ref->{page};
    my $directory   = exists $arg_ref->{directory}   ? $arg_ref->{directory}   : q{}; # optional
    my $language    = exists $arg_ref->{language}    ? $arg_ref->{language}    : q{}; # optional
    my ($variables) = exists $arg_ref->{variables}   ? $arg_ref->{variables}   :  {}; # optional

    my $template = "";
    my $dateiname = "";
    
    my $variable; 
    
    if ($directory eq "") {
        $dateiname = "$page.txt";
    }
    else {
        $dateiname = "$directory/$page.txt";
    }

    open my $template_FH, '<', $dateiname or errorhandling_page($dateiname);
    while (<$template_FH>) {
        $template .= $_;
    }
    close $template_FH;

    foreach $variable (keys %{$variables}) {
        $template = eval_if_variable({template=>$template, variable=>$variable, value=>$variables->{$variable}});
    }

    $template = eval_if_page({template=>$template, page=>$page});

    # nun noch passend kodieren (erstmal default utf-8)
    # evtl. doch lieber nicht...
    #$template = Encode::encode("utf-8", $template);

    return $template;
}

1