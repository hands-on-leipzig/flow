<?xml version="1.0" encoding="UTF-8"?>

<xsl:stylesheet xmlns:xsl="http://www.w3.org/1999/XSL/Transform" version="1.0">
    <xsl:output method="html" encoding="utf-8" indent="yes"/>

    <xsl:param name="type"/>
    
    <xsl:template match="teams">
        <xsl:choose>
            <xsl:when test="$type='teams_c2c_each'">
                <xsl:call-template name="teams_c2c_each"/>
            </xsl:when>
            <xsl:when test="$type='teams_c2c_all'">
                <xsl:call-template name="teams_c2c_all"/>
            </xsl:when>
            <xsl:otherwise>
                <xsl:call-template name="teams_c2c_each"/>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>
        
    <xsl:template name="teams_c2c_each">
        <xsl:for-each select="team">
            <xsl:variable name="teamname">
                <xsl:value-of select="name"/>
            </xsl:variable>
            <xsl:value-of select="name"/> <i class="bi-copy" onclick="navigator.clipboard.writeText('{$teamname}'); return false;"></i><br/>
        </xsl:for-each>
    </xsl:template>

    <xsl:template name="teams_c2c_all">
        <xsl:for-each select="team"><xsl:value-of select="name"/>\n</xsl:for-each>
    </xsl:template>

</xsl:stylesheet>
