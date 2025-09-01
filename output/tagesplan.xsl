<?xml version="1.0" encoding="UTF-8"?>

<xsl:stylesheet xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:fo="http://www.w3.org/1999/XSL/Format" version="1.0">

    <xsl:param name="session"/>
    
    <!--
    <xsl:include href="paper-size.xsl"></xsl:include>
    -->
    <!-- DIN A4 Settings -->
    <xsl:variable name="page-height">297mm</xsl:variable>
    <xsl:variable name="page-width">210mm</xsl:variable>
    <xsl:variable name="page-margin-left">10mm</xsl:variable> <!-- 10 -->
    <xsl:variable name="page-margin-right">10mm</xsl:variable> <!-- 10 -->
    <xsl:variable name="page-margin-top">10mm</xsl:variable> <!-- 10 -->
    <xsl:variable name="page-margin-bottom">10mm</xsl:variable> <!-- 10 -->
    
    <xsl:variable name="kopfbereich-erste-seite-extent">0mm</xsl:variable>
    <xsl:variable name="fussbereich-erste-seite-extent">0mm</xsl:variable>
    <xsl:variable name="kopfbereich-mittlere-seite-extent">0mm</xsl:variable>
    <xsl:variable name="fussbereich-mittlere-seite-extent">0mm</xsl:variable>
    <xsl:variable name="kopfbereich-letzte-seite-extent">0mm</xsl:variable>
    <xsl:variable name="fussbereich-letzte-seite-extent">0mm</xsl:variable>
    <xsl:variable name="kopfbereich-eine-seite-extent">0mm</xsl:variable>
    <xsl:variable name="fussbereich-eine-seite-extent">0mm</xsl:variable>
    
    <xsl:variable name="body-erste-seite-margin-top">0mm</xsl:variable>
    <xsl:variable name="body-erste-seite-margin-bottom">0mm</xsl:variable>
    <xsl:variable name="body-mittlere-seite-margin-top">0mm</xsl:variable>
    <xsl:variable name="body-mittlere-seite-margin-bottom">0mm</xsl:variable>
    <xsl:variable name="body-letzte-seite-margin-top">0mm</xsl:variable>
    <xsl:variable name="body-letzte-seite-margin-bottom">0mm</xsl:variable>
    <xsl:variable name="body-eine-seite-margin-top">0mm</xsl:variable>
    <xsl:variable name="body-eine-seite-margin-bottom">0mm</xsl:variable>
    
    <!-- Beginn Konfiguration Tagesplan innerhalb der Seite -->
    <!-- vertikal / Höhe -->
    <xsl:variable name="beginn_cm" select="3.5"/> <!-- 3.5 -->
    <xsl:variable name="ende_cm" select="26.2"/> <!-- 26.2 -->
    <!-- horizontal / Breite / Spalten -->
    <xsl:variable name="beginn_spalten_cm" select="1"/> <!-- 1 -->
    <xsl:variable name="ende_spalten_cm" select="20"/> <!-- 20 -->
    <xsl:variable name="abstand_zwischen_spalten_cm" select="0.25"/> <!-- 0.25 -->
    <!-- oberhalb davon: -->
    <xsl:variable name="spaltenueberschrift_hoehe_cm" select="1.25"/> <!-- 1.25 -->
    <xsl:variable name="spaltenueberschrift_abstand_cm" select="0.4"/> <!-- 0.4 -->
    <!-- einzelne Elemente -->
    <!-- Schriftgröße, Farbe, Hintergrund, ... -->
    <xsl:variable name="event_title_font_family" select="'Helvetica'"/>
    <xsl:variable name="event_title_font_size" select="15"/> <!-- 15 -->
    <xsl:variable name="event_title_font_weight" select="'bold'"/>
    <xsl:variable name="event_title_color" select="'000000'"/>
    <xsl:variable name="event_location_font_family" select="'Helvetica'"/>
    <xsl:variable name="event_location_font_size" select="15"/> <!-- 15 -->
    <xsl:variable name="event_location_font_weight" select="'bold'"/>
    <xsl:variable name="event_location_color" select="'000000'"/>
    <xsl:variable name="day_date_font_family" select="'Helvetica'"/>
    <xsl:variable name="day_date_font_size" select="15"/> <!-- 15 -->
    <xsl:variable name="day_date_font_weight" select="'bold'"/>
    <xsl:variable name="day_date_color" select="'000000'"/>
    <xsl:variable name="column_heading_font_family" select="'Helvetica'"/>
    <xsl:variable name="column_heading_font_size" select="11"/> <!-- 11 -->
    <xsl:variable name="column_heading_font_weight" select="'bold'"/>
    <xsl:variable name="column_heading_color" select="'000000'"/>
    <xsl:variable name="activity_padding_cm" select="0.1"/> <!-- 0.1 -->
    <xsl:variable name="activity_title_font_family" select="'Helvetica'"/>
    <xsl:variable name="activity_title_font_size" select="7"/> <!-- 7 -->
    <xsl:variable name="activity_title_font_weight" select="'bold'"/>
    <xsl:variable name="activity_title_color" select="'000000'"/>
    <xsl:variable name="activity_time_font_family" select="'Helvetica'"/>
    <xsl:variable name="activity_time_font_size" select="7"/> <!-- 7 -->
    <xsl:variable name="activity_time_font_weight" select="'bold'"/>
    <xsl:variable name="activity_time_color" select="'000000'"/>
    <xsl:variable name="activity_room_font_family" select="'Helvetica'"/>
    <xsl:variable name="activity_room_font_size" select="6"/> <!-- 6 -->
    <xsl:variable name="activity_room_font_weight" select="'normal'"/>
    <xsl:variable name="activity_room_color" select="'000000'"/>
    <xsl:variable name="activity_description_font_family" select="'Helvetica'"/>
    <xsl:variable name="activity_description_font_size" select="5"/> <!-- 5 -->
    <xsl:variable name="activity_description_font_weight" select="'normal'"/>
    <xsl:variable name="activity_description_color" select="'000000'"/>
    <xsl:variable name="activity_description_space_before_mm" select="1"/> <!-- 1 -->
    <!-- Kopfzeile (mit Logos) -->
    <xsl:variable name="kopfzeile_position_y_cm" select="0.75"/> <!-- 0.75 -->
    <!--
    <xsl:variable name="kopfzeile_position_x_cm" select="2"/>
    <xsl:variable name="kopfzeile_breite_cm" select="2"/>
    -->
    <xsl:variable name="kopfzeile_hoehe_cm" select="2"/> <!-- 2 -->
    <!-- Fusszeile (mit Logos) -->
    <xsl:variable name="fusszeile_position_y_cm" select="27"/> <!-- 27 -->
    <!--
    <xsl:variable name="fusszeile_position_x_cm" select="2"/>
    <xsl:variable name="fusszeile_breite_cm" select="2"/>
    -->
    <xsl:variable name="fusszeile_hoehe_cm" select="2"/> <!-- 2 -->
    <xsl:variable name="version_left_cm" select="20.3"/> <!-- 20.3 -->
    <xsl:variable name="version_top_cm" select="22.7"/> <!-- 22.7 -->
    <xsl:variable name="version_width_cm" select="5"/> <!-- 5 -->
    <xsl:variable name="version_font_size" select="5"/> <!-- 5 -->
    
    <!-- Ende DIN A4 Settings -->
    
    <xsl:variable name="contentbreite_cm" select="$ende_spalten_cm - $beginn_spalten_cm"/>
    <xsl:variable name="halbe_contentbreite_cm" select="$contentbreite_cm div 2"/>
    <xsl:variable name="drittel_contentbreite_cm" select="$contentbreite_cm div 3"/>
    <xsl:variable name="viertel_contentbreite_cm" select="$contentbreite_cm div 4"/>
    <xsl:variable name="fuenftel_contentbreite_cm" select="$contentbreite_cm div 5"/>
    
    <xsl:template match="event">

        <fo:root xmlns:fo="http://www.w3.org/1999/XSL/Format">
            <fo:layout-master-set>
    <!-- simple page master -->
                <fo:simple-page-master master-name="rechnung-erste-seite"
                    page-height="{$page-height}"
                    page-width="{$page-width}"
                    margin-left="{$page-margin-left}"
                    margin-right="{$page-margin-right}"
                    margin-top="{$page-margin-top}"
                    margin-bottom="{$page-margin-bottom}">
                    <fo:region-body margin-top="{$kopfbereich-erste-seite-extent} + {$body-erste-seite-margin-top}" margin-bottom="{$fussbereich-erste-seite-extent} + {$body-erste-seite-margin-bottom}"/>
                    <fo:region-before region-name="kopfbereich-erste-seite" extent="{$kopfbereich-erste-seite-extent}"/>
                    <fo:region-after region-name="fussbereich-erste-seite" extent="{$fussbereich-erste-seite-extent}"/>
                </fo:simple-page-master>
                <fo:simple-page-master master-name="rechnung-mittlere-seite"
                    page-height="{$page-height}"
                    page-width="{$page-width}"
                    margin-left="{$page-margin-left}"
                    margin-right="{$page-margin-right}"
                    margin-top="{$page-margin-top}"
                    margin-bottom="{$page-margin-bottom}">
                    <fo:region-body margin-top="{$kopfbereich-mittlere-seite-extent} + {$body-mittlere-seite-margin-top}" margin-bottom="{$fussbereich-mittlere-seite-extent} + {$body-mittlere-seite-margin-bottom}"/>
                    <fo:region-before region-name="kopfbereich-mittlere-seite" extent="{$kopfbereich-mittlere-seite-extent}"/>
                    <fo:region-after region-name="fussbereich-mittlere-seite" extent="{$fussbereich-mittlere-seite-extent}"/>
                </fo:simple-page-master>
                <fo:simple-page-master master-name="rechnung-letzte-seite"
                    page-height="{$page-height}"
                    page-width="{$page-width}"
                    margin-left="{$page-margin-left}"
                    margin-right="{$page-margin-right}"
                    margin-top="{$page-margin-top}"
                    margin-bottom="{$page-margin-bottom}">
                    <fo:region-body margin-top="{$kopfbereich-letzte-seite-extent} + {$body-letzte-seite-margin-top}" margin-bottom="{$fussbereich-letzte-seite-extent} + {$body-letzte-seite-margin-bottom}"/>
                    <fo:region-before region-name="kopfbereich-letzte-seite" extent="{$kopfbereich-letzte-seite-extent}"/>
                    <fo:region-after region-name="fussbereich-letzte-seite" extent="{$fussbereich-letzte-seite-extent}"/>
                </fo:simple-page-master>
                <fo:simple-page-master master-name="rechnung-eine-seite"
                    page-height="{$page-height}"
                    page-width="{$page-width}"
                    margin-left="{$page-margin-left}"
                    margin-right="{$page-margin-right}"
                    margin-top="{$page-margin-top}"
                    margin-bottom="{$page-margin-bottom}">
                    <fo:region-body margin-top="{$kopfbereich-eine-seite-extent} + {$body-eine-seite-margin-top}" margin-bottom="{$fussbereich-eine-seite-extent} + {$body-eine-seite-margin-bottom}"/>
                    <fo:region-before region-name="kopfbereich-eine-seite" extent="{$kopfbereich-eine-seite-extent}"/>
                    <fo:region-after region-name="fussbereich-eine-seite" extent="{$fussbereich-eine-seite-extent}"/>
                </fo:simple-page-master>
    <!-- page sequence master -->
                <fo:page-sequence-master master-name="rechnung">
                    <fo:repeatable-page-master-alternatives maximum-repeats="1">
                        <fo:conditional-page-master-reference master-reference="rechnung-erste-seite" page-position="first"/>
                        <fo:conditional-page-master-reference master-reference="rechnung-eine-seite" page-position="last"/>
                    </fo:repeatable-page-master-alternatives>
                    <fo:repeatable-page-master-alternatives maximum-repeats="no-limit">
                        <fo:conditional-page-master-reference master-reference="rechnung-letzte-seite" page-position="last"/>
                        <fo:conditional-page-master-reference master-reference="rechnung-mittlere-seite" page-position="rest"/>
                    </fo:repeatable-page-master-alternatives>
                </fo:page-sequence-master>
            </fo:layout-master-set> 
    <!-- page sequence -->
            <fo:page-sequence master-reference="rechnung">
                <fo:static-content flow-name="kopfbereich-erste-seite">
                    <fo:block></fo:block>
                </fo:static-content>
                <fo:static-content flow-name="kopfbereich-mittlere-seite">
                    <fo:block></fo:block>
                </fo:static-content>
                <fo:static-content flow-name="kopfbereich-letzte-seite">
                    <fo:block></fo:block>
                </fo:static-content>
                <fo:static-content flow-name="kopfbereich-eine-seite">
                    <fo:block></fo:block>
                </fo:static-content>
                <fo:static-content flow-name="fussbereich-erste-seite">
                    <fo:block></fo:block>
                </fo:static-content>
                <fo:static-content flow-name="fussbereich-mittlere-seite">
                    <fo:block></fo:block>
                </fo:static-content>
                <fo:static-content flow-name="fussbereich-letzte-seite">
                    <fo:block></fo:block>
                </fo:static-content>
                <fo:static-content flow-name="fussbereich-eine-seite">
                    <fo:block></fo:block>
                </fo:static-content>
                
                <fo:flow flow-name="xsl-region-body">
                    
                    <fo:block font-size="9pt" font-family="Helvetica, Arial, 'Nimbus Sans L', 'sans-serif'">
                        <!-- einige Versionen von Acrobat Reader ersetzen Helvetica durch Arial -->
                        <!-- einige Versionen von GhostScript ersetzen Helvetica durch 'Nimbus Sans L' -->
                        
                        <!--
                        <xsl:for-each select="days/day">
                            <xsl:sort select="date/date_iso"/>
                            -->
                            <fo:block page-break-before="always"></fo:block>
                            
                            <!-- Kopfzeile mit Logos -->
                            <fo:block-container absolute-position="fixed" top="{$kopfzeile_position_y_cm}cm" left="{$beginn_spalten_cm}cm" height="{$kopfzeile_hoehe_cm}cm" width="{$contentbreite_cm}cm">
                                <fo:table table-layout="fixed" width="100%">
                                    <fo:table-column column-number="1" column-width="{$fuenftel_contentbreite_cm * 3}cm"/>
                                    <fo:table-column column-number="2" column-width="{$fuenftel_contentbreite_cm}cm"/>
                                    <fo:table-column column-number="3" column-width="{$fuenftel_contentbreite_cm}cm"/>
                                    <fo:table-body>
                                        <fo:table-row>
                                            <fo:table-cell column-number="1" text-align="left" padding="0mm" margin="0mm">
                                                
                                                
                                                <fo:block font-family="{$event_title_font_family}" font-size="{$event_title_font_size}pt" font-weight="{$event_title_font_weight}" color="#{$event_title_color}">
                                                    <fo:inline font-style="italic">FIRST</fo:inline> LEGO League <xsl:value-of select="concat(level,' ',region)"/>
                                                </fo:block>
                                                <fo:block font-family="{$event_location_font_family}" font-size="{$event_location_font_size}pt" font-weight="{$event_location_font_weight}" color="#{$event_location_color}">
                                                    <xsl:value-of select="regionalpartner"/>
                                                </fo:block>
                                                <fo:block font-family="{$day_date_font_family}" font-size="{$day_date_font_size}pt" font-weight="{$day_date_font_weight}" color="#{$day_date_color}">
                                                    <xsl:value-of select="date"/>
                                                </fo:block>
                                                
                                                <!-- hartverdrahtet fuer Davos -->
                                                <!--
                                                <fo:block font-family="{$event_title_font_family}" font-size="{$event_title_font_size}pt" font-weight="{$event_title_font_weight}" color="#{$event_title_color}">
                                                    <fo:inline font-style="italic">FIRST</fo:inline> LEGO League D-A-CH Finale 2024
                                                </fo:block>
                                                -->
                                            </fo:table-cell>
                                            <fo:table-cell column-number="2" text-align="left" padding="0mm" margin="0mm">
                                                <fo:block>
                                                    <fo:external-graphic src="logos/qrcode_zeitplan_online.png" content-height="{$kopfzeile_hoehe_cm}cm" vertical-align="bottom"/>
                                                </fo:block>
                                            </fo:table-cell>
                                            <fo:table-cell column-number="3" text-align="right" padding="0mm" margin="0mm">
                                                <fo:block>
                                                    <fo:external-graphic src="logos/saison.png" content-height="{$kopfzeile_hoehe_cm}cm" vertical-align="bottom"/>
                                                </fo:block>
                                            </fo:table-cell>
                                        </fo:table-row>
                                    </fo:table-body>
                                </fo:table>
                            </fo:block-container>
                            <!-- Ende Kopfzeile mit Logos -->

                            <!-- Anzahl Spalten ermitteln fuer den Tag -->
                            <xsl:variable name="anzahl_spalten" select="count(columns/column)"/>
                            <!-- Berechnung der einzelnen Spaltenbreite -->
                            <xsl:variable name="spaltenbreite_cm" select="(($ende_spalten_cm - $beginn_spalten_cm) - ($anzahl_spalten - 1) * $abstand_zwischen_spalten_cm) div $anzahl_spalten"/>
                            
                            
                            <!-- Beginn und Ende (Uhrzeit) des jeweiligen Tages aus XML holen -->
                            <xsl:variable name="beginn_uhrzeit_stunde" select="substring-before(start,':')"/>
                            <xsl:variable name="beginn_uhrzeit_minute" select="substring-after(start,':')"/>
                            <xsl:variable name="ende_uhrzeit_stunde" select="substring-before(end,':')"/>
                            <xsl:variable name="ende_uhrzeit_minute" select="substring-after(end,':')"/>
                        
                            <!-- Umrechnung 60 Minuten -> 100 Einheiten einer Stunde -->
                            <xsl:variable name="beginn_uhrzeit_minute_100" select="(100 div 60) * $beginn_uhrzeit_minute"/>
                            <!-- Stunde * 100 -->
                            <xsl:variable name="beginn_uhrzeit" select="($beginn_uhrzeit_stunde * 100) + $beginn_uhrzeit_minute_100"/>
                            <!-- Umrechnung 60 Minuten -> 100 Einheiten einer Stunde -->
                            <xsl:variable name="ende_uhrzeit_minute_100" select="(100 div 60) * $ende_uhrzeit_minute"/>
                            <!-- Stunde * 100 -->
                            <xsl:variable name="ende_uhrzeit" select="($ende_uhrzeit_stunde * 100) + $ende_uhrzeit_minute_100"/>
                            
                            <!-- Koordinatensystem-Transformation -->
                            <xsl:variable name="minutenfaktor_1" select="$ende_cm - ($beginn_cm + $spaltenueberschrift_hoehe_cm + $spaltenueberschrift_abstand_cm)"/>
                            <xsl:variable name="minutenfaktor_2" select="$ende_uhrzeit - $beginn_uhrzeit"/>
                            <xsl:variable name="minutenfaktor" select="$minutenfaktor_1 div $minutenfaktor_2"/>
                            <!--
                            <xsl:variable name="minutenfaktor" select="({$ende_cm}-{$beginn_cm})/({$ende_uhrzeit_stunde}-{$beginn_uhrzeit_stunde})"/>
                            -->
                            
                            <xsl:variable name="spaltenueberschrift_content_hoehe_cm" select="$spaltenueberschrift_hoehe_cm - (2 * $activity_padding_cm * 3)"/> <!-- 2 * 0.6 fuer A2 -->
                            
                            <!-- /////////////////////////////////////////////////////////// -->
                            <!-- hier nur die Ausgabe der Spaltenüberschriften               -->
                            <!-- /////////////////////////////////////////////////////////// -->
                            <xsl:for-each select="columns/column">
                                <!-- horizontale Positionierung -->
                                <xsl:variable name="terminposition_spalte" select="$beginn_spalten_cm + (number - 1) * ($spaltenbreite_cm + $abstand_zwischen_spalten_cm)"/>
                                
                                <!-- hier nur die Definition der Hintergrundfarbe der Spaltenüberschrift -->
                                <!-- in Abhängigkeit vom Topic in der Spalten-Definition                 -->
                                <xsl:variable name="column_heading_background_color">
                                    <xsl:choose>
                                        <xsl:when test="topic='Allgemein'">
                                            <xsl:value-of select="'888888'"/>
                                        </xsl:when>
                                        <xsl:when test="topic='Challenge' or topic='Robot-Game' or topic='Live-Challenge'">
                                            <xsl:value-of select="'ED1C24'"/>
                                        </xsl:when>
                                        <xsl:when test="topic='Explore'">
                                            <xsl:value-of select="'00A651'"/>
                                        </xsl:when>
                                        <xsl:otherwise>
                                            <xsl:value-of select="'888888'"/>
                                        </xsl:otherwise>
                                    </xsl:choose>
                                </xsl:variable>
                                
                                <xsl:if test="number=1 or (topic!=../column[number=number(current()/number)-1]/topic and not((topic='Challenge' or topic='Robot-Game' or topic='Live-Challenge') and (../column[number=number(current()/number)-1]/topic='Challenge' or ../column[number=number(current()/number)-1]/topic='Robot-Game' or ../column[number=number(current()/number)-1]/topic='Live-Challenge')))">

                                    <!-- für Sonderfälle bzgl. zusammengefassten Spalten etc. -->
                                    <xsl:variable name="spaltenueberschrift_breite_cm">
                                        <xsl:choose>
                                            <xsl:when test="topic=../column[number=number(current()/number)+1]/topic or ((topic='Challenge' or topic='Robot-Game' or topic='Live-Challenge') and (../column[number=number(current()/number)+1]/topic='Challenge' or ../column[number=number(current()/number)+1]/topic='Robot-Game' or ../column[number=number(current()/number)+1]/topic='Live-Challenge'))">
                                                <xsl:value-of select="$spaltenbreite_cm + $abstand_zwischen_spalten_cm + $spaltenbreite_cm"/>
                                            </xsl:when>
                                            <xsl:otherwise>
                                                <xsl:value-of select="$spaltenbreite_cm"/>
                                            </xsl:otherwise>
                                        </xsl:choose>
                                    </xsl:variable>
                                    
                                    <fo:block-container absolute-position="fixed" top="{$beginn_cm}cm" left="{$terminposition_spalte}cm" background-color="#{$column_heading_background_color}" height="{$spaltenueberschrift_hoehe_cm}cm" width="{$spaltenueberschrift_breite_cm}cm">
                                        <fo:block text-align="center" font-family="{$column_heading_font_family}" font-size="{$column_heading_font_size}pt" font-weight="{$column_heading_font_weight}" color="#{$column_heading_color}" margin="{$activity_padding_cm * 2.5}cm">
                                            <!--
                                            <xsl:value-of select="heading"/>
                                            -->
                                            <xsl:choose>
                                                <xsl:when test="topic='Allgemein'">
                                                    <fo:block><fo:external-graphic src="logos/FLL_column_heading.png" content-height="{$spaltenueberschrift_content_hoehe_cm}cm" vertical-align="middle"/></fo:block>
                                                </xsl:when>
                                                <xsl:when test="topic='Challenge' or topic='Robot-Game' or topic='Live-Challenge'">
                                                    <fo:block><fo:external-graphic src="logos/FLL_Challenge_column_heading.png" content-height="{$spaltenueberschrift_content_hoehe_cm}cm" vertical-align="middle"/></fo:block>
                                                </xsl:when>
                                                <xsl:when test="topic='Explore'">
                                                    <fo:block><fo:external-graphic src="logos/FLL_Explore_column_heading.png" content-height="{$spaltenueberschrift_content_hoehe_cm}cm" vertical-align="middle"/></fo:block>
                                                </xsl:when>
                                                <xsl:otherwise>
                                                    <!-- ansonsten nur Heading ausgeben -->
                                                    <fo:block color="#ffffff"><xsl:value-of select="heading"/></fo:block>
                                                </xsl:otherwise>
                                            </xsl:choose>
                                        </fo:block>
                                    </fo:block-container>
                                </xsl:if>
                                
                            </xsl:for-each>
                        
                            <!--<xsl:for-each select="activities/activity">-->
                            <!--<xsl:for-each select="activity_groups/activity_group[@c_visitor='yes' or @e_visitor='yes']">-->
                            <xsl:for-each select="activity_groups/activity_group">
                                    
                                    
                                <xsl:variable name="start_stunde" select="substring-before(start,':')"/>
                                <xsl:variable name="start_minute" select="substring-after(start,':')"/>
                                <xsl:variable name="end_stunde" select="substring-before(end,':')"/>
                                <xsl:variable name="end_minute" select="substring-after(end,':')"/>
                                <!-- Umrechnung 60 Minuten -> 100 Einheiten einer Stunde -->
                                <xsl:variable name="beginn_termin_minute_100" select="(100 div 60) * $start_minute"/>
                                <!-- Stunde * 100 -->
                                <xsl:variable name="beginn_termin" select="($start_stunde * 100) + $beginn_termin_minute_100"/>
                                <!-- Umrechnung 60 Minuten -> 100 Einheiten einer Stunde -->
                                <xsl:variable name="ende_termin_minute_100" select="(100 div 60) * $end_minute"/>
                                <!-- Stunde * 100 -->
                                <xsl:variable name="ende_termin" select="($end_stunde * 100) + $ende_termin_minute_100"/>
                                    
                                <xsl:variable name="terminposition" select="$beginn_cm + $spaltenueberschrift_hoehe_cm + $spaltenueberschrift_abstand_cm + $minutenfaktor * ($beginn_termin - $beginn_uhrzeit)"/>
                                <xsl:variable name="terminhoehe" select="$minutenfaktor * ($ende_termin - $beginn_termin)"/>
                                
                                <!-- Umstellung auf Topic statt Column -->
                                <!-- Element overview_plan_column ersetzt Attribut topic in activity_group -->
                                <xsl:variable name="column" select="../../columns/column[topic=current()/overview_plan_column]/number"/>
                                
                                <!-- horizontale Positionierung -->
                                <xsl:variable name="terminposition_spalte" select="$beginn_spalten_cm + ($column - 1) * ($spaltenbreite_cm + $abstand_zwischen_spalten_cm)"/>
                                
                                <xsl:variable name="halbe_spaltenbreite_cm" select="($spaltenbreite_cm - (2 * $activity_padding_cm)) div 2"/>
                                <xsl:variable name="drittel_spaltenbreite_cm" select="($spaltenbreite_cm - (2 * $activity_padding_cm)) div 3"/>
                                
                                <!--
                                <fo:block><xsl:value-of select="$beginn_termin_minute_100"></xsl:value-of></fo:block>
                                <fo:block><xsl:value-of select="$beginn_termin"></xsl:value-of></fo:block>
                                <fo:block><xsl:value-of select="$ende_termin_minute_100"></xsl:value-of></fo:block>
                                <fo:block><xsl:value-of select="$ende_termin"></xsl:value-of></fo:block>
                                <fo:block><xsl:value-of select="$terminposition"></xsl:value-of></fo:block>
                                <fo:block><xsl:value-of select="$terminhoehe"></xsl:value-of></fo:block>
                                -->
                                <!--
                                <fo:block><xsl:value-of select="$start_stunde"></xsl:value-of></fo:block>
                                <fo:block><xsl:value-of select="$start_minute"></xsl:value-of></fo:block>
                                -->
                                
                                <xsl:variable name="activity_background_color">
                                    <xsl:choose>
                                        <!--<xsl:when test="(title='Eröffnung') or (title='Preisverleihung und Eröffnung') or (title='FLL Challenge Preisverleihung') or (title='FLL Explore Preisverleihung') or (title='Preisverleihung') or (title='FIRST Lego League Preisverleihung')">-->
                                        <xsl:when test="contains(activity_type_detail_name, 'Eröffnung') or contains(activity_type_detail_name, 'Gemeinsame Eröffnung') or contains(activity_type_detail_name, 'Preisverleihung')">
                                            <xsl:value-of select="'FFDC00'"/>
                                        </xsl:when>
                                        <xsl:otherwise>
                                            <xsl:choose>
                                                <xsl:when test="$column=1">
                                                    <xsl:value-of select="'CCCCCC'"/>
                                                </xsl:when>
                                                <xsl:when test="$column=2">
                                                    <xsl:value-of select="'CCCCCC'"/> <!-- FFBD9E -->
                                                </xsl:when>
                                                <xsl:when test="$column=3">
                                                    <xsl:value-of select="'CCCCCC'"/> <!-- FFBD9E -->
                                                </xsl:when>
                                                <xsl:when test="$column=4">
                                                    <xsl:value-of select="'CCCCCC'"/> <!-- 97FFBA -->
                                                </xsl:when>
                                                <xsl:otherwise>
                                                    <xsl:value-of select="'CCCCCC'"/>
                                                </xsl:otherwise>
                                            </xsl:choose>
                                        </xsl:otherwise>
                                    </xsl:choose>
                                </xsl:variable>
                                
                                <fo:block-container absolute-position="fixed" top="{$terminposition}cm" left="{$terminposition_spalte}cm" background-color="#{$activity_background_color}" height="{$terminhoehe}cm" width="{$spaltenbreite_cm}cm">
                                    <fo:block margin="{$activity_padding_cm}cm">
                                        <!-- Title and Time in one row -->
                                        <fo:table table-layout="fixed" width="100%">
                                            <fo:table-column column-number="1" column-width="{$drittel_spaltenbreite_cm * 2}cm"/>
                                            <fo:table-column column-number="2" column-width="{$drittel_spaltenbreite_cm}cm"/>
                                            <fo:table-body>
                                                <fo:table-row>
                                                    <fo:table-cell column-number="1" text-align="left" padding="0mm" margin="0mm"><fo:block font-family="{$activity_title_font_family}" font-size="{$activity_title_font_size}pt" font-weight="{$activity_title_font_weight}" color="#{$activity_title_color}"><xsl:value-of select="activity_type_detail_name"/></fo:block></fo:table-cell>
                                                    <fo:table-cell column-number="2" text-align="right" padding="0mm" margin="0mm"><fo:block font-family="{$activity_time_font_family}" font-size="{$activity_time_font_size}pt" font-weight="{$activity_time_font_weight}" color="#{$activity_time_color}"><xsl:value-of select="concat(start,'-',end)"/></fo:block></fo:table-cell>
                                                </fo:table-row>
                                            </fo:table-body>
                                        </fo:table>
                                        <!-- end title and Time -->
                                        <!--
                                        <fo:block font-family="{$activity_title_font_family}" font-size="{$activity_title_font_size}pt" font-weight="{$activity_title_font_weight}" color="#{$activity_title_color}"><xsl:value-of select="title"/></fo:block>
                                        <fo:block font-family="{$activity_time_font_family}" font-size="{$activity_time_font_size}pt" font-weight="{$activity_time_font_weight}" color="#{$activity_time_color}"><xsl:value-of select="concat(begin/hour,':',begin/minute,'-',end/hour,':',end/minute)"/></fo:block>
                                        -->
                                        <fo:block font-family="{$activity_room_font_family}" font-size="{$activity_room_font_size}pt" font-weight="{$activity_room_font_weight}" color="#{$activity_room_color}"><xsl:value-of select="location"/></fo:block>
                                        <fo:block font-family="{$activity_description_font_family}" font-size="{$activity_description_font_size}pt" font-weight="{$activity_description_font_weight}" color="#{$activity_description_color}" space-before="{$activity_description_space_before_mm}mm"><xsl:value-of select="activity_type_detail_description"/></fo:block>
                                    </fo:block>
                                </fo:block-container>
                                
                            </xsl:for-each>

                            <!-- Fusszeile mit Logos -->
                            <!-- alt vom Finale Dresden -->
                            <!--                            
                            <xsl:variable name="content_height_ljbw_cm" select="$fusszeile_hoehe_cm * 1.3"/>
                            
                            <fo:block-container absolute-position="fixed" top="{$fusszeile_position_y_cm}cm" left="{$beginn_spalten_cm}cm" height="{$content_height_ljbw_cm}cm" width="{$contentbreite_cm}cm">
                                <fo:table table-layout="fixed" width="100%">
                                    <fo:table-column column-number="1" column-width="{$drittel_contentbreite_cm}cm"/>
                                    <fo:table-column column-number="2" column-width="{$drittel_contentbreite_cm}cm"/>
                                    <fo:table-column column-number="3" column-width="{$drittel_contentbreite_cm}cm"/>
                                    <fo:table-body>
                                        <fo:table-row>
                                            <fo:table-cell column-number="1" text-align="left" padding="0mm" margin="0mm"><fo:block><fo:external-graphic src="logos/HoT.png" content-height="{$fusszeile_hoehe_cm}cm" vertical-align="middle"/></fo:block></fo:table-cell>
                                            <fo:table-cell column-number="2" text-align="center" padding="0mm" margin="0mm"><fo:block><fo:external-graphic src="logos/LJBW.png" content-height="{$content_height_ljbw_cm}cm" vertical-align="middle"/></fo:block></fo:table-cell>
                                            <fo:table-cell column-number="3" text-align="right" padding="0mm" margin="0mm"><fo:block><fo:external-graphic src="logos/Robot_Valley.png" content-height="{$fusszeile_hoehe_cm}cm" vertical-align="middle"/></fo:block></fo:table-cell>
                                        </fo:table-row>
                                    </fo:table-body>
                                </fo:table>
                            </fo:block-container>
                            -->
                            
                            <fo:block-container absolute-position="fixed" top="{$fusszeile_position_y_cm + ($fusszeile_hoehe_cm div 2)}cm" left="{$beginn_spalten_cm}cm" height="{$fusszeile_hoehe_cm}cm">
                                <fo:block><fo:external-graphic src="logos/HoT.png" content-height="{$fusszeile_hoehe_cm div 2}cm" /></fo:block>
                            </fo:block-container>
                            
                            <fo:block-container absolute-position="fixed" top="{$fusszeile_position_y_cm}cm" left="{$beginn_spalten_cm}cm" height="{$fusszeile_hoehe_cm}cm" width="{$contentbreite_cm}cm">
                                <fo:table table-layout="fixed" width="100%">
                                    <fo:table-column column-number="1" column-width="{$fuenftel_contentbreite_cm}cm"/>
                                    <fo:table-column column-number="2" column-width="{$fuenftel_contentbreite_cm * 4}cm"/>
                                    <fo:table-body>
                                        <fo:table-row>
                                            <fo:table-cell column-number="1" text-align="left" padding="0mm" margin="0mm"><fo:block><!--<fo:external-graphic src="logos/HoT.png" content-height="{$fusszeile_hoehe_cm div 2}cm" vertical-align="bottom"/>--></fo:block></fo:table-cell>
                                            <fo:table-cell column-number="2" text-align="right" padding="0mm" margin="0mm"><fo:block><fo:external-graphic src="{$session}/partner.png" content-height="{$fusszeile_hoehe_cm}cm" vertical-align="middle"/></fo:block></fo:table-cell>
                                        </fo:table-row>
                                    </fo:table-body>
                                </fo:table>
                            </fo:block-container>
                            <!-- Ende Fusszeile mit Logos -->
                            
                            <!-- Version -->
                            <fo:block-container absolute-position="fixed" top="{$version_top_cm}cm" left="{$version_left_cm}cm" reference-orientation="90" width="{$version_width_cm}cm">
                                <fo:block font-size="{$version_font_size}pt" color="#9c9d9f">
                                    Plan-Version: <xsl:value-of select="../../planner_version"/> / Generiert: <xsl:value-of select="../../generated"/>
                                </fo:block>
                            </fo:block-container>

<!-- days... -->
                        <!--
</xsl:for-each>
-->

                    </fo:block>
    
                    <fo:block id="LASTPAGE"></fo:block>
                </fo:flow>
            </fo:page-sequence>
        </fo:root>
    </xsl:template>
</xsl:stylesheet>
