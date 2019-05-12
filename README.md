EAD (module for Omeka S)
========================

[EAD] is a module for [Omeka S] that allows to import, update and delete EAD
metadata inside Omeka and to display them.


Installation
------------

First, install the modules [Generic] and [Bulk Import].

The module uses an external library, [`Ead2DCterms`], so use the release zip to
install it, or use and init the source.

See general end user documentation for [installing a module].

* From the zip

Download the last release [`Ead.zip`] from the list of releases (the master does
not contain the dependency), and uncompress it in the `modules` directory.

* From the source and for development

If the module was installed from the source, rename the folder of the module to
`Ead`, go to the root of the module, and run:

```
    composer install
```

Composer will install the tool `Ead2DCterms` in `data/xsl`.

A xslt 2 processor may need to be installed too. See install help of [Bulk Import].


Usage
-----

### Management

A new menu item is available in the admin sidebar, `EAD curation`.

The process is based on EAD 2002 standard. The form allows to import, update or
delete items. Just set a file or a url and select options.

An important option is the base id. In EAD 2002, the individual components have
no individual id, so they should be created. They are created with the format
`base_id/xpath/to/component`, for example `FA-1/ead/archdesc/dsc/c[2]/c[5]/c[1]`.
So it requires a common base id, that can be the document uri, the filename, an
internal id or a custom id. It must be the same between each process, since this
value is used to update or to delete existing items.

The custom id is a choice from the attributes of the element `/ead/eadheader/eadid`.
For example, if this element has these attributes:
```php
[
    'identifier' => 'alpha',
    'mainagencycode' => 'beta',
    'url' => 'https://example.org/gamma',
]
```

Then, to set `my_base_id` as base id, the option should be:

```
alpha = my_base_id
```

This process allows to manage metadata of multiple finding aids easily, but will
be simplified in a future version.

### Note about EAD files.

Some ead files cannot be imported: some parsers don’t manage the doctype,
require the namespace, or don’t manage entities or cdata. Furthermore, a secure
server (https) may not be able to fetch an unsecure dtd (http). A quick fix is
available, but if it is not enough, you have to remove doctype and entities and
to add the namespace.

### Display

By default, the relations are displayed in the standard block "Linked resources"
of the items. To see the full tree structure and the other relations, the theme
may be updated to use the view helper `$this->ead($item)`.


TODO
----

* Manage the xml with a doctype (via full domxml, not simplexml).
* Optimize structure building via direct queries to the database. See Omeka
  plugin Ead.
* Replace textual properties by a single property saved as literal xml with
  module RdfDatatype.
* Simplify creation of custom id.


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.


Troubleshooting
---------------

See online issues on the [module issues] page on GitHub.


License
-------

This module is published under the [CeCILL v2.1] licence, compatible with
[GNU/GPL] and approved by [FSF] and [OSI].

In consideration of access to the source code and the rights to copy, modify and
redistribute granted by the license, users are provided only with a limited
warranty and the software’s author, the holder of the economic rights, and the
successive licensors only have limited liability.

In this respect, the risks associated with loading, using, modifying and/or
developing or reproducing the software by the user are brought to the user’s
attention, given its Free Software status, which may make it complicated to use,
with the result that its use is reserved for developers and experienced
professionals having in-depth computer knowledge. Users are therefore encouraged
to load and test the suitability of the software as regards their requirements
in conditions enabling the security of their systems and/or data to be ensured
and, more generally, to use and operate it in the same conditions of security.
This Agreement may be freely reproduced and published, provided it is not
altered, and that no provisions are either added or removed herefrom.


Copyright
---------

* Copyright Daniel Berthereau, 2015-2019 (see [Daniel-KM] on GitHub)


[Omeka S]: https://omeka.org/s
[EAD]: https://github.com/Daniel-KM/Omeka-S-module-EAD
[Bulk Import]: https://github.com/Daniel-KM/Omeka-S-module-BulkImport
[Generic]: https://github.com/Daniel-KM/Omeka-S-module-Generic
[Installing a module]: https://omeka.org/s/docs/user-manual/modules/#installing-modules
[module issues]: https://github.com/Daniel-KM/Omeka-S-module-EAD/issues
[`Ead2DCterms`]: https://github.com/Daniel-KM/Ead2DCterms
[`Ead.zip`]: https://github.com/Daniel-KM/Omeka-S-module-Ead/releases
[`data/xsl`]: https://github.com/Daniel-KM/Omeka-S-module-Ead/tree/master/data/xsl
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[Daniel-KM]: https://github.com/Daniel-KM "Daniel Berthereau"
