<?xml version="1.0" encoding="UTF-8"?>
<!--
Map an intermediate xml file with dcterms and omeka elements to "documents".

This is a simple copy, except:
- the root is changed from "records" to "documents";
- files are nested inside items;
- relations "Is Part Of" between item and file are removed;
- the record type is added ("Item" or "File");
- the item type "Archival Finding Aid" is added to the main record;
- specific elements are renamed according to Omeka elements labels for EAD;
- extra elements are moved to extra.

@version: 20160125
@copyright Daniel Berthereau, 2015-2016
@license CeCILL v2.1 http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
@link https://github.com/Daniel-KM/Omeka-plugin-Ead
-->
<xsl:stylesheet version="2.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:xs="http://www.w3.org/2001/XMLSchema"

    xmlns:dcterms="http://purl.org/dc/terms/"
    xmlns:omeka="http://localhost/omeka"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:xlink="http://www.w3.org/1999/xlink"

    exclude-result-prefixes="xsl xs">

    <xsl:output method="xml" indent="yes" encoding="UTF-8" />
    <xsl:strip-space elements="*" />

    <xsl:param name="mapping_path">ead2omeka_mapping.xml</xsl:param>

    <xsl:variable name="mapping" as="node()*" select="document($mapping_path)/mapping" />

    <xsl:template match="/">
        <xsl:comment>
            <xsl:text> Converted from the intermediate file used to convert EAD 2002 into DCterms and Omeka. </xsl:text>
        </xsl:comment>
        <documents>
            <xsl:apply-templates select="records/record[@type != 'Digital Archival Object']"
                mode="item" />
        </documents>
    </xsl:template>

    <xsl:template match="record" mode="item">
        <record name="{dcterms:identifier[last()]}" recordType="Item">
            <xsl:if test="@type = 'Finding Aid'">
                <xsl:attribute name="itemType">Archival Finding Aid</xsl:attribute>
            </xsl:if>
            <xsl:apply-templates select="." mode="record" />
            <xsl:apply-templates select="../record
                    [@type = 'Digital Archival Object']
                    [dcterms:isPartOf = current()/dcterms:identifier[last()]]"
                mode="file" />
        </record>
    </xsl:template>

    <xsl:template match="record" mode="file">
        <record file="{dcterms:identifier[last()]}" recordType="File">
            <xsl:apply-templates select="." mode="record" />
        </record>
    </xsl:template>

    <xsl:template match="record" mode="record">
        <xsl:for-each select="namespace::*
                [. = 'http://purl.org/dc/terms/' or . = 'http://localhost/omeka']">
            <xsl:choose>
                <!-- Simple copy for Dublin Core, automatically managed. -->
                <xsl:when test=". = 'http://purl.org/dc/terms/'">
                    <xsl:choose>
                        <!-- Exception for items: don't copy "hasPart" of files. -->
                        <xsl:when test="not(../@type = 'Digital Archival Object')">
                            <xsl:sequence select="../dcterms:*
                                [not(
                                        local-name() = 'hasPart'
                                    and
                                        . = current()/../../record
                                            [@type = 'Digital Archival Object']
                                            /dcterms:identifier[last()]
                                )]
                            " />
                        </xsl:when>
                        <!-- Exception for files: don't copy "isPartOf" the item. -->
                        <xsl:when test="../@type = 'Digital Archival Object'">
                            <xsl:sequence select="../dcterms:*
                                [not(
                                        local-name() = 'isPartOf'
                                    and
                                        . = current()/../../record
                                            [not(@type = 'Digital Archival Object')]
                                            /dcterms:identifier[last()]
                                )]
                            " />
                        </xsl:when>
                        <xsl:otherwise>
                            <xsl:sequence select="../dcterms:*" />
                        </xsl:otherwise>
                    </xsl:choose>
                </xsl:when>

                <!-- Conversion to elements for others (namespace "omeka"). -->
                <xsl:otherwise>
                    <xsl:variable name="name_space">
                        <xsl:apply-templates select="../@type" mode="element_set_name">
                            <xsl:with-param name="namespace" select="." />
                        </xsl:apply-templates>
                    </xsl:variable>

                    <xsl:choose>
                        <!-- Extra and standard elements are the same, but without element set. -->
                        <!-- Currently, may be used only for omeka:xpath. -->
                        <xsl:when test="$name_space = 'extra'">
                            <xsl:variable name="elements">
                                <xsl:for-each-group select="../*[namespace-uri() = current()]" group-by="name()">
                                    <xsl:variable name="label" as="xs:string?"
                                        select="$mapping/elementSet/map[@to = current-grouping-key()]/@label" />
                                    <extra name="{$label}">
                                        <xsl:for-each select="current-group()">
                                            <data>
                                                <xsl:sequence select="node()" />
                                            </data>
                                        </xsl:for-each>
                                    </extra>
                                </xsl:for-each-group>
                            </xsl:variable>

                            <xsl:if test="count($elements/node()) &gt; 0">
                                <xsl:sequence select="$elements" />
                            </xsl:if>
                        </xsl:when>

                        <xsl:when test="$name_space != ''">
                            <xsl:variable name="elements">
                                <xsl:for-each-group select="../*[namespace-uri() = current()]" group-by="name()">
                                    <xsl:variable name="label" as="xs:string?"
                                        select="$mapping/elementSet/map[@to = current-grouping-key()]/@label" />
                                    <element name="{$label}">
                                        <xsl:for-each select="current-group()">
                                            <data>
                                                <xsl:sequence select="node()" />
                                            </data>
                                        </xsl:for-each>
                                    </element>
                                </xsl:for-each-group>
                            </xsl:variable>

                            <xsl:if test="count($elements/node()) &gt; 0">
                                <elementSet name="{$name_space}" >
                                    <xsl:sequence select="$elements" />
                                </elementSet>
                            </xsl:if>
                        </xsl:when>
                    </xsl:choose>
                </xsl:otherwise>
            </xsl:choose>
        </xsl:for-each>
    </xsl:template>

    <xsl:template match="record/@type" mode="element_set_name">
        <xsl:param name="namespace" as="xs:string" />

        <xsl:choose>
            <xsl:when test="$namespace = 'http://purl.org/dc/terms/'">
                <xsl:text>Dublin Core</xsl:text>
            </xsl:when>
            <xsl:when test="$namespace  = 'http://localhost/omeka'">
                <xsl:choose>
                    <xsl:when test=". = 'Archival Description' or . = 'Component'">
                        <xsl:text>EAD Archive</xsl:text>
                    </xsl:when>
                    <xsl:when test=". = 'Digital Archival Object'">
                        <xsl:text>extra</xsl:text>
                    </xsl:when>
                    <xsl:otherwise>
                        <xsl:text>Item Type Metadata</xsl:text>
                    </xsl:otherwise>
                </xsl:choose>
            </xsl:when>
        </xsl:choose>
    </xsl:template>

</xsl:stylesheet>
