<?xml version="1.0" encoding="UTF-8"?>
<!--
Map a part of an EAD xml file with dcterms and omeka elements.

The fragment may be the header of the finding aid, the frontmatter, the archival
description or any component. It should contains the standard xml declaration.

The structure can't be rebuilt from partial parts without data on relations.

@todo Integrate directly in ead2dcterms with a generic stylesheet.

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
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"

    exclude-result-prefixes="xsl xs mapper ead">

    <!-- Import the generic helpers. -->
    <xsl:import href="../external/Ead2DCterms/XmlMapper/xslt_2/xml_mapper_helpers.xsl" />

    <!-- Import the main processor. -->
    <xsl:import href="../external/Ead2DCterms/ead2dcterms-omeka.xsl" />

    <xsl:output method="xml" indent="yes" encoding="UTF-8" />
    <xsl:strip-space elements="*" />

    <xsl:variable name="root_element" select="local-name(/node())" />

    <xsl:variable name="unique_record" as="xs:string?">
        <xsl:choose>
            <xsl:when test="$root_element = 'ead' or $root_element = 'eadheader'">
                <xsl:text>Finding Aid</xsl:text>
            </xsl:when>
            <xsl:when test="$root_element = 'frontmatter'">
                <xsl:text>Front Matter</xsl:text>
            </xsl:when>
            <xsl:when test="$root_element = 'archdesc'">
                <xsl:text>Archival Description</xsl:text>
            </xsl:when>
            <!-- Used by Pleade for the Finding aid (http://www.pleade.com). -->
            <xsl:when test="$root_element = 'c' and (/*:c/*:eadid or /*:c/*:filedesc)">
                <xsl:text>Finding Aid</xsl:text>
            </xsl:when>
            <xsl:when test="boolean(index-of(
                    tokenize('c|c01|c02|c03|c04|c05|c06|c07|c08|c09|c10|c11|c12', '\|'),
                    $root_element))">
                <xsl:text>Component</xsl:text>
            </xsl:when>
            <xsl:when test="$root_element = 'dao'">
                <xsl:text>Digital Archival Object</xsl:text>
            </xsl:when>
        </xsl:choose>
    </xsl:variable>

    <!-- Identifier and relation can't be set from a partial part. -->
    <xsl:variable name="skip_mapping_types" as="element()?">
        <skip>
            <skip type="identifier" />
            <skip type="relation" />
        </skip>
    </xsl:variable>

    <xsl:variable name="input">
        <xsl:apply-templates />
    </xsl:variable>

    <!-- Main template. -->

    <!-- Managed as a special case currently. -->
    <xsl:template match="ead:ead">
        <xsl:call-template name="map-fragment" />
    </xsl:template>

    <!-- Not used currently. -->
    <xsl:template match="ead:eadheader">
        <xsl:call-template name="map-fragment">
            <xsl:with-param name="wrap" select="'/ead'" />
        </xsl:call-template>
    </xsl:template>

    <!-- Not used currently. -->
    <xsl:template match="ead:frontmatter">
        <xsl:call-template name="map-fragment">
            <xsl:with-param name="wrap" select="'/ead'" />
        </xsl:call-template>
    </xsl:template>

    <xsl:template match="ead:archdesc">
        <xsl:call-template name="map-fragment">
            <xsl:with-param name="wrap" select="'/ead'" />
        </xsl:call-template>
    </xsl:template>

    <xsl:template match="ead:c
            |ead:c01|ead:c02|ead:c03|ead:c04
            |ead:c05|ead:c06|ead:c07|ead:c08
            |ead:c09|ead:c10|ead:c11|ead:c12">
        <xsl:call-template name="map-fragment">
            <!-- This avoids multiple components, even if it's not standard. -->
            <xsl:with-param name="wrap" select="'/ead/archdesc/dsc'" />
        </xsl:call-template>
    </xsl:template>

    <!-- Used by Pleade for the Finding aid (http://www.pleade.com). -->
    <xsl:template match="ead:c[ead:eadid] | ead:c[ead:filedesc]">
        <xsl:variable name="base">
            <xsl:call-template name="rename-root">
                <xsl:with-param name="element" select="'eadheader'" />
            </xsl:call-template>
        </xsl:variable>
        <xsl:call-template name="map-fragment">
            <xsl:with-param name="base" select="$base" />
            <xsl:with-param name="wrap" select="'/ead'" />
        </xsl:call-template>
    </xsl:template>

    <xsl:template match="ead:dao">
        <xsl:call-template name="map-fragment">
            <xsl:with-param name="wrap" select="'/ead/archdesc/dsc/c'" />
        </xsl:call-template>
    </xsl:template>

    <!-- ==============================================================
    Helpers to map a fragment.
    =============================================================== -->

    <xsl:template name="map-fragment">
        <xsl:param name="base" as="node()" select="/" />
        <xsl:param name="wrap" as="xs:string?" select="''" />

        <xsl:sequence select="mapper:wrap-value($base, $wrap)" />
    </xsl:template>

    <xsl:template name="rename-root">
        <xsl:param name="base" as="node()" select="/" />
        <xsl:param name="element" as="xs:string" />

        <xsl:element name="{$element}" namespace="{'http://www.loc.gov/ead'}">
            <xsl:for-each select="namespace::*">
                <xsl:namespace name="{name()}" select="." />
            </xsl:for-each>
            <xsl:for-each select="@*">
                <xsl:attribute name="{name()}" select="." />
            </xsl:for-each>
            <xsl:sequence select="*" />
        </xsl:element>
    </xsl:template>

</xsl:stylesheet>
