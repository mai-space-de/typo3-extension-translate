.. include:: /Documentation/Includes.rst.txt

.. _introduction:

Introduction
============

**maispace/translate** adds a **Translate** button to the TYPO3 backend
edit-form button bar. Clicking the button opens a modal dialog where editors
choose a provider and target language. The extension then fetches the current
record from the database, sends its translatable text fields to the selected
API, and writes the translations back into the open FormEngine form — all
without a page reload.

Supported tables (built-in)
----------------------------

.. list-table::
   :widths: 25 75
   :header-rows: 1

   * - Table
     - Translatable fields
   * - ``pages``
     - ``title``, ``subtitle``, ``nav_title``, ``abstract``, ``description``,
       ``keywords``, ``seo_title``, ``og_title``, ``og_description``,
       ``twitter_title``, ``twitter_description``
   * - ``tt_content``
     - ``header``, ``subheader``, ``bodytext``, ``header_link``
   * - ``sys_file_metadata``
     - ``title``, ``description``, ``caption``, ``alternative``
   * - ``sys_file_reference``
     - ``title``, ``description``, ``alternative``, ``link``

Any extension can register additional tables or fields via
``Configuration/TranslatableTables.php``. See :ref:`translatable-tables`.

Translation providers
----------------------

DeepL
~~~~~

The `DeepL API`_ supports both the free tier (``api-free.deepl.com``) and the
pro tier (``api.deepl.com``). The extension automatically selects the correct
endpoint based on the ``:fx`` suffix in the API key.

HTML markup in ``bodytext`` is preserved via the ``tag_handling=html``
parameter.

OpenAI
~~~~~~

The `OpenAI API`_ is used via a chat completion request. The system prompt
instructs the model to preserve HTML structure. The model is configurable —
the default is ``gpt-4o-mini``.

.. _DeepL API: https://www.deepl.com/de/docs-api/
.. _OpenAI API: https://platform.openai.com/docs/api-reference

Architecture
------------

.. list-table::
   :widths: 35 65
   :header-rows: 1

   * - Class
     - Responsibility
   * - ``TranslateButtonEventListener``
     - Hooks ``ModifyButtonBarEvent`` to inject the Translate button into the
       backend edit-form button bar. Resolves supported tables dynamically
       from ``TranslatableTablesLoader``. Skips rendering when no provider
       API key is configured.
   * - ``TranslateController``
     - Backend AJAX endpoint (``/ajax/translate/record``). Fetches the record,
       resolves non-empty translatable fields via ``TranslatableTablesLoader``,
       delegates to the configured provider, and returns
       ``{ "translations": { "field": "value" } }``.
   * - ``TranslatableTablesLoader``
     - Discovers and merges ``Configuration/TranslatableTables.php`` from all
       active TYPO3 packages. Caches the merged result in the
       ``translate_tables`` TYPO3 system cache.
   * - ``TranslationServiceFactory``
     - Resolves the requested provider service by name.
   * - ``DeeplTranslationService``
     - Translates a string via the DeepL REST API using TYPO3's
       ``RequestFactory``.
   * - ``OpenAiTranslationService``
     - Translates a string via the OpenAI chat completions API using TYPO3's
       ``RequestFactory``.
   * - ``TranslateButton.js``
     - ES module. Opens a ``Modal.confirm`` dialog, POSTs to the AJAX route,
       and writes translations back into open FormEngine fields. Supports
       CKEditor 5 (TYPO3 v13) via ``ckeditorInstance.setData()`` with a
       hidden-textarea fallback.
