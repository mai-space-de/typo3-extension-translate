.. include:: /Documentation/Includes.rst.txt

.. _translatable-tables:

Translatable Tables
===================

**maispace/translate** uses a discovery mechanism to determine which TCA tables
and fields are eligible for translation. Any active TYPO3 extension or site
package can contribute to this list by placing a single PHP file in its
``Configuration/`` directory.

How discovery works
--------------------

``TranslatableTablesLoader`` scans every active TYPO3 package for a file at
``Configuration/TranslatableTables.php``. Each file must return a plain PHP
array keyed by TCA table name. The value for each table is a list of field
names to include in translation.

Files from multiple packages are merged automatically. When the same table
appears in more than one file, the field lists are combined and de-duplicated
(preserving insertion order). The merged result is stored in the
``translate_tables`` TYPO3 system cache and reused on subsequent requests
until the system caches are cleared.

Built-in defaults
-----------------

The ``EXT:translate`` extension itself ships a ``Configuration/TranslatableTables.php``
that registers the default tables and fields:

.. code-block:: php

   <?php
   // EXT:translate/Configuration/TranslatableTables.php

   return [
       'tt_content' => [
           'header',
           'subheader',
           'bodytext',
           'header_link',
       ],
       'pages' => [
           'title',
           'subtitle',
           'nav_title',
           'abstract',
           'description',
           'keywords',
           'seo_title',
           'og_title',
           'og_description',
           'twitter_title',
           'twitter_description',
       ],
       'sys_file_metadata' => [
           'title',
           'description',
           'caption',
           'alternative',
       ],
       'sys_file_reference' => [
           'title',
           'description',
           'alternative',
           'link',
       ],
   ];

Registering custom tables
--------------------------

Create a file named ``TranslatableTables.php`` (not
``TranslatableTablesExample.php``) in the ``Configuration/`` directory of
your extension or site package and return a plain PHP array:

.. code-block:: php

   <?php
   // EXT:my_extension/Configuration/TranslatableTables.php

   return [
       // Register a completely new table for translation
       'tx_myext_domain_model_product' => [
           'name',
           'description',
           'teaser',
       ],

       // Extend an existing table with additional fields
       'tt_content' => [
           'tx_myext_custom_field',
       ],
   ];

No additional setup is required. ``TranslatableTablesLoader`` discovers the
file automatically as soon as the extension is active.

.. note::

   An ``EXT:translate/Configuration/TranslatableTablesExample.php`` file is
   included as a copy-paste template. It contains only documentation comments
   and does not register any tables itself.

Field merging rules
--------------------

.. list-table::
   :widths: 30 70
   :header-rows: 1

   * - Scenario
     - Result
   * - Two packages declare the same table
     - Field lists are merged (union). Each field appears at most once.
   * - Two packages declare the same field for the same table
     - The field is included once (de-duplication).
   * - A package declares a new table not in the built-in defaults
     - The table is registered and the Translate button appears on its edit
       form.
   * - A package's file does not return a PHP array
     - The file is silently ignored.

Clearing the cache
------------------

The discovery result is cached in the ``translate_tables`` system cache. Clear
TYPO3 :guilabel:`System caches` (or run ``vendor/bin/typo3 cache:flush``) after
adding, removing, or modifying a ``TranslatableTables.php`` file to pick up the
changes.
