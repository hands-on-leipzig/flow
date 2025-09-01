<?xml version="1.0" encoding="UTF-8"?>

<xsl:stylesheet xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:fo="http://www.w3.org/1999/XSL/Format" version="1.0">

    <xsl:param name="session"/>
    
    <xsl:variable name="logo_path" select="event/logo_path"/>
    
    <xsl:variable name="qrcode" select="event/qrcode"/>
    
    <!-- nur dummy -->
    <xsl:variable name="url"/>
    <xsl:variable name="output" select="'pdf'"/>
    
    <!-- ab hier statisch -->
    <xsl:variable name="page-height">297mm</xsl:variable>
    <xsl:variable name="page-width">210mm</xsl:variable>
    <xsl:variable name="page-margin-left">10mm</xsl:variable> <!-- 10 -->
    <xsl:variable name="page-margin-right">10mm</xsl:variable> <!-- 10 -->
    <xsl:variable name="page-margin-top">10mm</xsl:variable> <!-- 10 -->
    <xsl:variable name="page-margin-bottom">30mm</xsl:variable> <!-- 10 --> <!-- Fusszeile mit Logos beginn bei 27cm von oben -->
    
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
    
    <!-- Beginn Konfiguration Detailplan -->
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
    <xsl:variable name="activity_group_font_family" select="'Helvetica'"/>
    <xsl:variable name="activity_group_font_size" select="7"/> <!-- 7 -->
    <xsl:variable name="activity_group_font_weight" select="'bold'"/>
    <xsl:variable name="activity_group_color" select="'000000'"/>
    <xsl:variable name="activity_font_family" select="'Helvetica'"/>
    <xsl:variable name="activity_font_size" select="7"/> <!-- 7 -->
    <xsl:variable name="activity_font_weight" select="'normal'"/>
    <xsl:variable name="activity_color" select="'000000'"/>
    <!-- Kopfzeile (mit Logos) -->
    <xsl:variable name="kopfzeile_position_y_cm" select="0.75"/> <!-- 0.75 -->
    <xsl:variable name="kopfzeile_hoehe_cm" select="2"/> <!-- 2 -->
    <!-- Fusszeile (mit Logos) -->
    <xsl:variable name="fusszeile_position_y_cm" select="27"/> <!-- 27 -->
    <xsl:variable name="fusszeile_hoehe_cm" select="2"/> <!-- 2 -->
    <xsl:variable name="version_left_cm" select="20.3"/> <!-- 20.3 -->
    <xsl:variable name="version_top_cm" select="22.7"/> <!-- 22.7 -->
    <xsl:variable name="version_width_cm" select="5"/> <!-- 5 -->
    <xsl:variable name="version_font_size" select="5"/> <!-- 5 -->
    
    <xsl:variable name="fuenftel_contentbreite_cm" select="19 div 5"/> <!-- 19 cm Contentbreite (21 abzgl. links und rechts je 1 cm Rand -->

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
                    
                        <!-- kann ggf. auch in einer Schleife aufgerufen werden (über alle Teams z.B.) -->
                        <xsl:call-template name="detailplan"/>
                    </fo:block>
    
                    <fo:block id="LASTPAGE"></fo:block>
                </fo:flow>
            </fo:page-sequence>
        </fo:root>
    </xsl:template>
    
    <!--########################################################################################-->
    <!-- Template für einen Detailplan                                                          -->
    <!-- für mehrere Pläne, z.B. für alle Jurygruppen auf einmal, in for-each-Schleife aufrufen -->
    <!--########################################################################################-->
    <xsl:template name="detailplan">
        
        <xsl:for-each select="days/day">
            <xsl:sort select="date/date_iso"/>
            
            <fo:block page-break-before="always"></fo:block>
            
            <!-- Kopfzeile mit Logos -->
            <fo:block-container absolute-position="fixed" top="{$kopfzeile_position_y_cm}cm" left="1cm" height="{$kopfzeile_hoehe_cm}cm" width="19cm">
                <fo:table table-layout="fixed" width="100%">
                    <fo:table-column column-number="1" column-width="{$fuenftel_contentbreite_cm * 3}cm"/>
                    <fo:table-column column-number="2" column-width="{$fuenftel_contentbreite_cm}cm"/>
                    <fo:table-column column-number="3" column-width="{$fuenftel_contentbreite_cm}cm"/>
                    <fo:table-body>
                        <fo:table-row>
                            <fo:table-cell column-number="1" text-align="left" padding="0mm" margin="0mm">
                                <fo:block font-family="{$event_title_font_family}" font-size="{$event_title_font_size}pt" font-weight="{$event_title_font_weight}" color="#{$event_title_color}">
                                    <fo:inline font-style="italic">FIRST</fo:inline> LEGO League <xsl:value-of select="concat(../../level,' ',../../region)"/>
                                </fo:block>
                                <fo:block font-family="{$event_location_font_family}" font-size="{$event_location_font_size}pt" font-weight="{$event_location_font_weight}" color="#{$event_location_color}">
                                    <xsl:value-of select="../../regionalpartner"/>
                                </fo:block>
                                <fo:block font-family="{$day_date_font_family}" font-size="{$day_date_font_size}pt" font-weight="{$day_date_font_weight}" color="#{$day_date_color}">
                                    <xsl:value-of select="date/date_day_name"/>, <xsl:value-of select="date/date_output"/> 
                                </fo:block>
                            </fo:table-cell>
                            <fo:table-cell column-number="2" text-align="left" padding="0mm" margin="0mm">
                                <fo:block>
                                    <!--
                                    <fo:external-graphic src="logos/qrcode_zeitplan_online.png" content-height="{$kopfzeile_hoehe_cm}cm" vertical-align="bottom"/>
                                    -->
                                    <fo:external-graphic src="url('data:image/png;base64,{$qrcode}')" content-height="{$kopfzeile_hoehe_cm}cm" vertical-align="bottom"/>
                                    
                                </fo:block>
                            </fo:table-cell>
                            <fo:table-cell column-number="3" text-align="right" padding="0mm" margin="0mm">
                                <fo:block>
                                    <fo:external-graphic src="{$logo_path}/saison.png" content-height="{$kopfzeile_hoehe_cm}cm" vertical-align="bottom"/>
                                </fo:block>
                            </fo:table-cell>
                        </fo:table-row>
                    </fo:table-body>
                </fo:table>
            </fo:block-container>
            <!-- Ende Kopfzeile mit Logos -->
            
            <fo:block space-before="2.3cm"></fo:block>
            
            <!-- Titelzeile farbig mit passendem (Competition-) Logo -->
            <xsl:variable name="title_background_color">
                <xsl:value-of select="../../title_background_color"/>
            </xsl:variable>
            
            <xsl:variable name="title_logo">
                <xsl:value-of select="../../title_logo"/>
            </xsl:variable>
            
            
            <fo:block font-family="{$event_title_font_family}" font-size="{$event_title_font_size}pt" font-weight="{$event_title_font_weight}" color="#{$event_title_color}">

                <fo:table table-layout="fixed" width="100%" space-before="3mm">
                    <fo:table-column column-number="1" column-width="9.5cm"/>
                    <fo:table-column column-number="2" column-width="9.5cm"/>
                    <fo:table-body>
                        <fo:table-row background-color="#{$title_background_color}">
                            <fo:table-cell column-number="1" text-align="left" padding="1.5mm" margin="0mm">
                                <fo:block>
                                    <fo:external-graphic src="{$logo_path}/{$title_logo}" content-height="0.7cm" vertical-align="middle"/>
                                </fo:block>
                            </fo:table-cell>
                            <fo:table-cell column-number="2" text-align="right" padding-right="1.5mm" padding-top="4mm" margin="0mm">
                                <fo:block color="#FFFFFF" vertical-align="middle">
                                    
                                    <!-- hier der eigentliche Titel -->
                                    <xsl:value-of select="../../role"/>

                                </fo:block>
                            </fo:table-cell>
                        </fo:table-row>
                    </fo:table-body>
                </fo:table>
                
                <!-- jetzt geht es mit den Activity-Groups los ... -->
                <xsl:for-each select="activity_groups/activity_group">
                    <xsl:sort select="begin"/>
                    
                    <xsl:call-template name="activity_group"/>
                </xsl:for-each>
                
                
            </fo:block>
            

            
            <!-- Version -->
            <fo:block-container absolute-position="fixed" top="{$version_top_cm}cm" left="{$version_left_cm}cm" reference-orientation="90" width="{$version_width_cm}cm">
                <fo:block font-size="{$version_font_size}pt" color="#9c9d9f">
                    Plangenerierung: <xsl:value-of select="../../plan_last_change"/>
                </fo:block>
            </fo:block-container>
            
        </xsl:for-each> <!-- days/day -->
            
    </xsl:template>
    
    <!--########################################################################################-->
    <!-- Template für Activity_Group                                                            -->
    <!--########################################################################################-->
    <xsl:template name="activity_group">
        <fo:block keep-together.within-page="always">
            <fo:table table-layout="fixed" width="100%" space-before="2mm">
                <fo:table-column column-number="1" column-width="2cm"/>
                <fo:table-column column-number="2" column-width="4cm"/>
                <fo:table-column column-number="3" column-width="9cm"/>
                <fo:table-column column-number="4" column-width="4cm"/>
                <fo:table-body>
                    
                    <fo:table-row background-color="#cccccc">
                        <fo:table-cell column-number="1" text-align="left" padding="1.5mm" margin="0mm">
                            <fo:block font-family="{$activity_group_font_family}" font-size="{$activity_group_font_size}pt" font-weight="{$activity_group_font_weight}" color="#{$activity_group_color}">
                                <xsl:value-of select="concat(start,'-',end)"/>
                            </fo:block>
                        </fo:table-cell>
                        <fo:table-cell column-number="2" number-columns-spanned="2" text-align="left" padding="1.5mm" margin="0mm">
                            <fo:block font-family="{$activity_group_font_family}" font-size="{$activity_group_font_size}pt" font-weight="$activity_group_font_weight" color="#{$activity_group_color}">
                                <xsl:value-of select="activity_type_detail_name"/>
                                <!-- activity_type_name nicht mehr mit ausgeben, da dadurch Dopplungen im Titel mit reinkommen -->
                                <!--
                                <xsl:value-of select="concat(activity_type_name,' ',activity_type_detail_name)"/>
                                -->
                            </fo:block>
                        </fo:table-cell>
                        <!--
                        <fo:table-cell column-number="3" text-align="left" padding="1.5mm" margin="0mm">
                            <fo:block font-family="{$activity_group_font_family}" font-size="{$activity_group_font_size}pt" font-weight="$activity_group_font_weight" color="#{$activity_group_color}">
                                
                            </fo:block>
                        </fo:table-cell>
                        -->
                        <fo:table-cell column-number="4" text-align="left" padding="1.5mm" margin="0mm">
                            <fo:block font-family="{$activity_group_font_family}" font-size="{$activity_group_font_size}pt" font-weight="$activity_group_font_weight" color="#{$activity_group_color}">
                                <xsl:value-of select="room"/>
                            </fo:block>
                        </fo:table-cell>
                    </fo:table-row>
                    
                    <xsl:for-each select="activities/activity">
                        <xsl:sort select="start"/>
                        <xsl:call-template name="activity"/>
                    </xsl:for-each>
                    
                    
                </fo:table-body>
            </fo:table>
        </fo:block>
    </xsl:template>
    
    <!--########################################################################################-->
    <!-- Template für eine Activity                                                  -->
    <!--########################################################################################-->
    <xsl:template name="activity">
        <!--<xsl:if test="start != ../../start or end != ../../end or activity_type_detail_name != ../../activity_type_detail_name or detail != ''">-->
            <!-- nur wenn start, end, activity_type_detail_name von der übergeordneten der activity-group abweichen oder detail nicht leer ist (mindestens eins davon) -->
            <!-- ggf. noch checken, ob es die einzige activity in der activity-group ist... -->
            <fo:table-row>
                <fo:table-cell column-number="1" text-align="left" padding-left="1.5mm" padding-top="1mm" margin="0mm">
                    <fo:block font-family="{$activity_font_family}" font-size="{$activity_font_size}pt" font-weight="{$activity_font_weight}" color="#{$activity_color}">
                        <xsl:value-of select="concat(start,'-',end)"/>
                    </fo:block>
                </fo:table-cell>
                <fo:table-cell column-number="2" text-align="left" padding-left="1.5mm" padding-top="1mm" margin="0mm">
                    <fo:block font-family="{$activity_font_family}" font-size="{$activity_font_size}pt" font-weight="{$activity_font_weight}" color="#{$activity_color}">
                        <xsl:value-of select="concat(activity_type_detail_name,' ',title)"/>
                    </fo:block>
                </fo:table-cell>
                <fo:table-cell column-number="3" text-align="left" padding-left="1.5mm" padding-top="1mm" margin="0mm">
                    <fo:block font-family="{$activity_font_family}" font-size="{$activity_font_size}pt" font-weight="{$activity_font_weight}" color="#{$activity_color}">
                        <xsl:value-of select="detail"/>
                    </fo:block>
                </fo:table-cell>
                <fo:table-cell column-number="4" text-align="left" padding-left="1.5mm" padding-top="1mm" margin="0mm">
                    <fo:block font-family="{$activity_font_family}" font-size="{$activity_font_size}pt" font-weight="{$activity_font_weight}" color="#{$activity_color}">
                        <xsl:value-of select="room"/>
                    </fo:block>
                </fo:table-cell>
            </fo:table-row>
        <!--</xsl:if>-->    
    </xsl:template>
    
    
</xsl:stylesheet>
