.. include:: /Documentation/Includes.rst.txt

.. _configuration:

Configuration
=============

All configuration is managed through TYPO3 Extension Configuration
(:guilabel:`Admin Tools → Settings → Extension Configuration → mai_translate`).

.. _ext-conf:

Extension Configuration reference
----------------------------------

.. list-table::
   :widths: 25 15 60
   :header-rows: 1

   * - Key
     - Default
     - Description
   * - ``deeplApiKey``
     - *(empty)*
     - DeepL REST API key. Append ``:fx`` to use the free-tier endpoint
       (``api-free.deepl.com``). When empty, the DeepL provider is disabled.
   * - ``openAiApiKey``
     - *(empty)*
     - OpenAI API key. When empty, the OpenAI provider is disabled.
   * - ``openAiModel``
     - ``gpt-4o-mini``
     - OpenAI model identifier passed in the chat completion request.
   * - ``defaultProvider``
     - ``deepl``
     - Provider pre-selected in the modal dialog when it opens. Must be
       ``deepl`` or ``openai``.
   * - ``defaultSourceLanguage``
     - *(empty)*
     - Source language code pre-filled in the modal (e.g. ``EN``, ``DE``).
       Leave empty to let the provider auto-detect the source language.

AJAX route
----------

The controller is registered as a backend AJAX route:

.. code-block:: text

   POST /ajax/translate/record

Query / body parameters:

.. list-table::
   :widths: 20 10 70
   :header-rows: 1

   * - Parameter
     - Required
     - Description
   * - ``table``
     - yes
     - TCA table name (must be registered in ``TranslatableTables.php``).
   * - ``uid``
     - yes
     - Numeric record UID.
   * - ``targetLanguage``
     - yes
     - Target language code (e.g. ``DE``, ``EN-GB``, ``FR``).
   * - ``provider``
     - no
     - Translation provider (``deepl`` or ``openai``). Defaults to ``deepl``.
   * - ``sourceLanguage``
     - no
     - Source language code. Pass ``auto`` or omit for auto-detection.

Response (success):

.. code-block:: json

   {
     "translations": {
       "header": "Translated heading",
       "bodytext": "<p>Translated body</p>"
     }
   }

Error responses use standard HTTP status codes (``400``, ``404``, ``500``,
``503``) with a JSON body of ``{ "error": "…" }``.

DeepL provider details
-----------------------

* Automatically switches between the free endpoint (``api-free.deepl.com``)
  and the pro endpoint (``api.deepl.com``) based on the ``:fx`` suffix in
  the API key.
* Passes ``tag_handling=html`` for all fields so that HTML markup in
  ``bodytext`` is preserved through translation.
* Language codes are normalised to upper-case before sending to the API.

OpenAI provider details
------------------------

* Uses the ``/v1/chat/completions`` endpoint.
* The system prompt instructs the model to act as a translation engine and
  return only the translated text, preserving any HTML structure.
* The model identifier is taken from the ``openAiModel`` extension
  configuration key (default: ``gpt-4o-mini``).
* Language codes are passed in natural language inside the prompt
  (e.g. "Translate to German").

Caching
-------

The merged ``TranslatableTables`` configuration is cached in the
``translate_tables`` TYPO3 system cache (``SimpleFileBackend``,
``defaultLifetime = 0``). The cache is invalidated when TYPO3
:guilabel:`System caches` are cleared. An in-process property cache inside
``TranslatableTablesLoader`` prevents redundant cache reads within the same
HTTP request.
