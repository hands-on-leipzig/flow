<?xml version="1.0" encoding="UTF-8"?>

<xsl:stylesheet xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:fo="http://www.w3.org/1999/XSL/Format" version="1.0">

    <!-- verwendete Parameter -->
    <xsl:param name="typ"/>
    <xsl:param name="rp_logo"/>
    <xsl:param name="server"/>
    
    <xsl:variable name="grafx_path">
        <xsl:choose>
            <xsl:when test="$server='dev'">
                <xsl:value-of select="'/usr/home/handsb/public_html/dev-fll-planning/output/logos'"/>
            </xsl:when>
            <xsl:when test="$server='test'">
                <xsl:value-of select="'/usr/home/handsb/public_html/test-fll-planning/output/logos'"/>
            </xsl:when>
            <xsl:when test="$server='prod'">
                <xsl:value-of select="'/usr/home/handsb/public_html/fll-planning/output/logos'"/>
            </xsl:when>
            <xsl:otherwise>
                <!-- fll-braunschweig.de -->
                <xsl:value-of select="'logos'"/>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:variable>

<!-- Variablen fuers Seitenlayout -->
<!-- <xsl:variable name="dina4-height">297mm</xsl:variable> -->
<xsl:variable name="dina4-height">297mm</xsl:variable> <!-- Ueberlaenge bei FSP-Etiketten -->
<xsl:variable name="dina4-width">210mm</xsl:variable>
<xsl:variable name="dina4-margin-left">10mm</xsl:variable>
<xsl:variable name="dina4-margin-right">10mm</xsl:variable>
<xsl:variable name="dina4-margin-top">21.3mm</xsl:variable>
<xsl:variable name="dina4-margin-bottom">21.30mm</xsl:variable>
    
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
<!-- Ende Variablen fuers Seitenlayout-->

<xsl:template match="/">

    <fo:root xmlns:fo="http://www.w3.org/1999/XSL/Format">
        <fo:layout-master-set>
<!-- simple page master -->
            <fo:simple-page-master master-name="fsp-erste-seite"
                page-height="{$dina4-height}"
                page-width="{$dina4-width}"
                margin-left="{$dina4-margin-left}"
                margin-right="{$dina4-margin-right}"
                margin-top="{$dina4-margin-top}"
                margin-bottom="{$dina4-margin-bottom}">
                <fo:region-body margin-top="{$kopfbereich-erste-seite-extent} + {$body-erste-seite-margin-top}" margin-bottom="{$fussbereich-erste-seite-extent} + {$body-erste-seite-margin-bottom}" column-count="4" column-gap="2.4mm"/>
                <fo:region-before region-name="kopfbereich-erste-seite" extent="{$kopfbereich-erste-seite-extent}"/>
                <fo:region-after region-name="fussbereich-erste-seite" extent="{$fussbereich-erste-seite-extent}"/>
            </fo:simple-page-master>
            <fo:simple-page-master master-name="fsp-mittlere-seite"
                page-height="{$dina4-height}"
                page-width="{$dina4-width}"
                margin-left="{$dina4-margin-left}"
                margin-right="{$dina4-margin-right}"
                margin-top="{$dina4-margin-top}"
                margin-bottom="{$dina4-margin-bottom}">
                <fo:region-body margin-top="{$kopfbereich-mittlere-seite-extent} + {$body-mittlere-seite-margin-top}" margin-bottom="{$fussbereich-mittlere-seite-extent} + {$body-mittlere-seite-margin-bottom}" column-count="4" column-gap="2.4mm"/>
                <fo:region-before region-name="kopfbereich-mittlere-seite" extent="{$kopfbereich-mittlere-seite-extent}"/>
                <fo:region-after region-name="fussbereich-mittlere-seite" extent="{$fussbereich-mittlere-seite-extent}"/>
            </fo:simple-page-master>
            <fo:simple-page-master master-name="fsp-letzte-seite"
                page-height="{$dina4-height}"
                page-width="{$dina4-width}"
                margin-left="{$dina4-margin-left}"
                margin-right="{$dina4-margin-right}"
                margin-top="{$dina4-margin-top}"
                margin-bottom="{$dina4-margin-bottom}">
                <fo:region-body margin-top="{$kopfbereich-letzte-seite-extent} + {$body-letzte-seite-margin-top}" margin-bottom="{$fussbereich-letzte-seite-extent} + {$body-letzte-seite-margin-bottom}" column-count="4" column-gap="2.4mm"/>
                <fo:region-before region-name="kopfbereich-letzte-seite" extent="{$kopfbereich-letzte-seite-extent}"/>
                <fo:region-after region-name="fussbereich-letzte-seite" extent="{$fussbereich-letzte-seite-extent}"/>
            </fo:simple-page-master>
            <fo:simple-page-master master-name="fsp-eine-seite"
                page-height="{$dina4-height}"
                page-width="{$dina4-width}"
                margin-left="{$dina4-margin-left}"
                margin-right="{$dina4-margin-right}"
                margin-top="{$dina4-margin-top}"
                margin-bottom="{$dina4-margin-bottom}">
                <fo:region-body margin-top="{$kopfbereich-eine-seite-extent} + {$body-eine-seite-margin-top}" margin-bottom="{$fussbereich-eine-seite-extent} + {$body-eine-seite-margin-bottom}" column-count="4" column-gap="2.4mm"/>
                <fo:region-before region-name="kopfbereich-eine-seite" extent="{$kopfbereich-eine-seite-extent}"/>
                <fo:region-after region-name="fussbereich-eine-seite" extent="{$fussbereich-eine-seite-extent}"/>
            </fo:simple-page-master>
<!-- page sequence master -->
            <fo:page-sequence-master master-name="fsp">
                <fo:repeatable-page-master-alternatives maximum-repeats="1">
                    <fo:conditional-page-master-reference master-reference="fsp-erste-seite" page-position="first"/>
                    <fo:conditional-page-master-reference master-reference="fsp-eine-seite" page-position="last"/>
                </fo:repeatable-page-master-alternatives>
                <fo:repeatable-page-master-alternatives maximum-repeats="no-limit">
                    <fo:conditional-page-master-reference master-reference="fsp-letzte-seite" page-position="last"/>
                    <fo:conditional-page-master-reference master-reference="fsp-mittlere-seite" page-position="rest"/>
                </fo:repeatable-page-master-alternatives>
            </fo:page-sequence-master>
        </fo:layout-master-set> 
<!-- page sequence -->
        <fo:page-sequence master-reference="fsp">
            <fo:static-content flow-name="kopfbereich-erste-seite">
                <block></block>                                
            </fo:static-content>
            <fo:static-content flow-name="kopfbereich-mittlere-seite">
                <block></block>
            </fo:static-content>
            <fo:static-content flow-name="kopfbereich-letzte-seite">
                <block></block>
            </fo:static-content>
            <fo:static-content flow-name="kopfbereich-eine-seite">
                <block></block>                
            </fo:static-content>
            <fo:static-content flow-name="fussbereich-erste-seite">
                <block></block>
            </fo:static-content>
            <fo:static-content flow-name="fussbereich-mittlere-seite">
                <block></block>
            </fo:static-content>
            <fo:static-content flow-name="fussbereich-letzte-seite">
                <block></block>
            </fo:static-content>
            <fo:static-content flow-name="fussbereich-eine-seite">
                <block></block>
            </fo:static-content>
            
            <fo:flow flow-name="xsl-region-body">
                
                <xsl:choose>
                    <xsl:when test="$typ='teams'">
                        <xsl:call-template name="offset" >
                            <xsl:with-param name="offset" select="teams/@offset"/> <!-- Attribut an volunteers oder teams -->
                        </xsl:call-template>
                        
                        <xsl:call-template name="teams"/>
                    </xsl:when>
                    <xsl:when test="$typ='volunteers'">
                        <xsl:call-template name="offset" >
                            <xsl:with-param name="offset" select="volunteers/@offset"/> <!-- Attribut an volunteers oder teams -->
                        </xsl:call-template>
                        
                        <xsl:call-template name="volunteers"/>
                    </xsl:when>
                    <xsl:otherwise>
                        <xsl:call-template name="teams"/>
                    </xsl:otherwise>
                </xsl:choose>
                
            </fo:flow>
            
        </fo:page-sequence>
    </fo:root>

</xsl:template>
    
    <!--################-->
    <!-- Template teams -->
    <!--################-->
    <xsl:template name="teams">
        <xsl:for-each select="teams/team">
            <xsl:for-each select="mitglieder/mitglied">
                <xsl:call-template name="etikett"/>    
            </xsl:for-each>
        </xsl:for-each>
    </xsl:template>
    
    <!--#####################-->
    <!-- Template volunteers -->
    <!--#####################-->
    <xsl:template name="volunteers">
        <xsl:for-each select="volunteers/volunteer">
            <xsl:call-template name="etikett"/>
        </xsl:for-each>
    </xsl:template>
    
    <!--#####################-->
    <!-- Template etikett    -->
    <!--#####################-->
    <xsl:template name="etikett">
        <fo:block-container height="21.2mm" width="45.7mm">
            <fo:block-container position="absolute" top="3mm" left="2mm" width="41.7mm">
                <fo:block font-size="4mm" text-align="center"><xsl:value-of select="concat(vorname,' ',nachname)" /></fo:block>
                <fo:block font-size="3mm" text-align="center"><xsl:value-of select="../../name" /></fo:block>
            </fo:block-container>
            <fo:block-container position="absolute" bottom="2mm" height="4mm">
                <xsl:choose>
                    <xsl:when test="../../programm='Challenge'">
                        <fo:block text-align="center"><fo:external-graphic src="{$grafx_path}/aufkleber/FLL-Challenge.png" content-height="4mm" vertical-align="middle"/></fo:block>
                    </xsl:when>
                    <xsl:when test="../../programm='Explore'">
                        <fo:block text-align="center"><fo:external-graphic src="{$grafx_path}/aufkleber/FLL-Explore.png" content-height="4mm" vertical-align="middle"/></fo:block>
                    </xsl:when>
                    <xsl:otherwise>
                        <fo:block text-align="center"><fo:external-graphic src="{$grafx_path}/aufkleber/FIRSTLego_iconHorz_RGB.png" content-height="4mm" vertical-align="middle"/></fo:block>
                    </xsl:otherwise>
                </xsl:choose>
            </fo:block-container>
        </fo:block-container>
        
    </xsl:template>

    <xsl:template name="offset">
        <xsl:param name="offset"/>
        
        <xsl:if test="$offset &gt; 0">
            <fo:block-container height="21.2mm" width="45.7mm">
                <fo:block></fo:block>    
            </fo:block-container>
            
            <xsl:call-template name="offset">
                <xsl:with-param name="offset" select="$offset - 1"/>
            </xsl:call-template>
        </xsl:if>
    </xsl:template>

</xsl:stylesheet>
