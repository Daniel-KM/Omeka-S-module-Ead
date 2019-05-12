<?xml version="1.0" encoding="UTF-8"?>
<!--
Extract individual parts of a full EAD file, so they can be used somewhere else.

This part can be used for OAI-PMH.

Notes
- The config may need to be adapted to follow the main config of EAD to Omeka.
- An option allows to separate or integrate digital objects inside parts.

TODO
- Use directly the main config (but need to manage roots for finding aid and
  archival description).
- Manage daogrp, daoloc...

@version: 20150817
@copyright Daniel Berthereau, 2015
@license CeCILL v2.1 http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
@link https://github.com/Daniel-KM/Omeka-plugin-Ead
-->
<xsl:stylesheet version="2.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:xs="http://www.w3.org/2001/XMLSchema"

    xmlns:mapper="http://mapper"

    xmlns:ead="http://www.loc.gov/ead"

    exclude-result-prefixes="xsl xs mapper ead">

    <!-- Import mapper api. -->
    <xsl:import href="../external/Ead2DCterms/XmlMapper/xslt_2/xml_mapper_helpers.xsl" />

    <xsl:output method="xml" indent="yes" encoding="UTF-8" />
    <xsl:strip-space elements="*" />

    <!-- Digital objects can be "integrated" (default), "separated" or "excluded". -->
    <xsl:param name="set_digital_objects" as="xs:string">integrated</xsl:param>

    <!-- Main template. -->
    <xsl:template match="/">
        <xsl:comment>
            <xsl:text> Extract of the full EAD file. The config may need to be adapted to follow the main config of EAD to Omeka. </xsl:text>
        </xsl:comment>
        <xsl:comment>
            <xsl:text> Digital objects are </xsl:text>
            <xsl:value-of select="$set_digital_objects" />
            <xsl:text>. </xsl:text>
        </xsl:comment>

        <parts>
            <xsl:choose>
                <xsl:when test="$set_digital_objects = 'integrated' or $set_digital_objects = ''">
                    <!-- Main finding aid is an exception and it contains the frontmatter. -->
                    <part xpath="/ead/eadheader">
                        <xsl:element name="ead" namespace="http://www.loc.gov/ead">
                            <xsl:sequence select="/ead:ead/ead:eadheader" />
                            <xsl:sequence select="/ead:ead/ead:frontmatter" />
                        </xsl:element>
                    </part>
                    <xsl:apply-templates select="/ead:ead//*[boolean(index-of(
                            tokenize(
                                'archdesc|c|c01|c02|c03|c04|c05|c06|c07|c08|c09|c10|c11|c12',
                                '\|'),
                            local-name()))]" />
                </xsl:when>

                <xsl:when test="$set_digital_objects = 'separated'">
                    <!-- Main finding aid is an exception and it contains the frontmatter. -->
                    <part xpath="/ead/eadheader">
                        <xsl:element name="ead" namespace="http://www.loc.gov/ead">
                            <xsl:sequence select="/ead:ead/ead:eadheader/mapper:copy-except('dao', .)" />
                            <xsl:sequence select="/ead:ead/ead:frontmatter/mapper:copy-except('dao', .)" />
                        </xsl:element>
                    </part>
                    <xsl:apply-templates select="/ead:ead//*[boolean(index-of(
                            tokenize(
                                'archdesc|c|c01|c02|c03|c04|c05|c06|c07|c08|c09|c10|c11|c12|dao',
                                '\|'),
                            local-name()))]">
                        <xsl:with-param name="digital_objects" select="'dao'" tunnel="yes" />
                    </xsl:apply-templates>
                </xsl:when>

                <xsl:when test="$set_digital_objects = 'excluded'">
                    <!-- Main finding aid is an exception and it contains the frontmatter. -->
                    <part xpath="/ead/eadheader">
                        <xsl:element name="ead" namespace="http://www.loc.gov/ead">
                            <xsl:sequence select="/ead:ead/ead:eadheader/mapper:copy-except('dao', .)" />
                            <xsl:sequence select="/ead:ead/ead:frontmatter/mapper:copy-except('dao', .)" />
                        </xsl:element>
                    </part>
                    <xsl:apply-templates select="/ead:ead//*[boolean(index-of(
                            tokenize(
                                'archdesc|c|c01|c02|c03|c04|c05|c06|c07|c08|c09|c10|c11|c12',
                                '\|'),
                            local-name()))]">
                        <xsl:with-param name="digital_objects" select="'dao'" tunnel="yes" />
                    </xsl:apply-templates>
                </xsl:when>

            </xsl:choose>
        </parts>
    </xsl:template>

    <xsl:template match="ead:*">
        <part xpath="{mapper:get-absolute-xpath(.)}">
            <xsl:sequence select="." />
        </part>
    </xsl:template>

    <xsl:template name="copy-ead-except">
        <xsl:param name="digital_objects" as="xs:string?" select="''" tunnel="yes" />
        <xsl:param name="except" as="xs:string?" />

        <xsl:variable name="copy_except" as="xs:string?" select="
                if ($except = '' or $digital_objects = '')
                then concat($except, $digital_objects)
                else concat($except, '|', $digital_objects)" />

        <part xpath="{mapper:get-absolute-xpath(.)}">
            <xsl:choose>
                <xsl:when test="$copy_except = ''">
                    <xsl:sequence select="." />
                </xsl:when>
                <xsl:otherwise>
                    <xsl:sequence select="mapper:copy-except($copy_except, .)" />
                </xsl:otherwise>
            </xsl:choose>
        </part>
    </xsl:template>

    <xsl:template match="ead:archdesc">
        <xsl:call-template name="copy-ead-except">
            <xsl:with-param name="except" select="'c|c01'" />
        </xsl:call-template>
    </xsl:template>

    <xsl:template match="ead:c">
        <xsl:call-template name="copy-ead-except">
            <xsl:with-param name="except" select="'c'" />
        </xsl:call-template>
    </xsl:template>

    <xsl:template match="ead:c01">
        <xsl:call-template name="copy-ead-except">
            <xsl:with-param name="except" select="'c02'" />
        </xsl:call-template>
    </xsl:template>

    <xsl:template match="ead:c02">
        <xsl:call-template name="copy-ead-except">
            <xsl:with-param name="except" select="'c03'" />
        </xsl:call-template>
    </xsl:template>

    <xsl:template match="ead:c03">
        <xsl:call-template name="copy-ead-except">
            <xsl:with-param name="except" select="'c04'" />
        </xsl:call-template>
    </xsl:template>

    <xsl:template match="ead:c04">
        <xsl:call-template name="copy-ead-except">
            <xsl:with-param name="except" select="'c05'" />
        </xsl:call-template>
    </xsl:template>

    <xsl:template match="ead:c05">
        <xsl:call-template name="copy-ead-except">
            <xsl:with-param name="except" select="'c06'" />
        </xsl:call-template>
    </xsl:template>

    <xsl:template match="ead:c06">
        <xsl:call-template name="copy-ead-except">
            <xsl:with-param name="except" select="'c07'" />
        </xsl:call-template>
    </xsl:template>

    <xsl:template match="ead:c07">
        <xsl:call-template name="copy-ead-except">
            <xsl:with-param name="except" select="'c08'" />
        </xsl:call-template>
    </xsl:template>

    <xsl:template match="ead:c08">
        <xsl:call-template name="copy-ead-except">
            <xsl:with-param name="except" select="'c09'" />
        </xsl:call-template>
    </xsl:template>

    <xsl:template match="ead:c09">
        <xsl:call-template name="copy-ead-except">
            <xsl:with-param name="except" select="'c10'" />
        </xsl:call-template>
    </xsl:template>

    <xsl:template match="ead:c10">
        <xsl:call-template name="copy-ead-except">
            <xsl:with-param name="except" select="'c11'" />
        </xsl:call-template>
    </xsl:template>

    <xsl:template match="ead:c11">
        <xsl:call-template name="copy-ead-except">
            <xsl:with-param name="except" select="'c12'" />
        </xsl:call-template>
    </xsl:template>

    <xsl:template match="ead:c12">
        <xsl:call-template name="copy-ead-except">
            <xsl:with-param name="except" select="''" />
        </xsl:call-template>
    </xsl:template>

</xsl:stylesheet>
