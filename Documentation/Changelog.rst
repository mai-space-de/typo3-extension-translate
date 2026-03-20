.. include:: /Documentation/Includes.rst.txt

.. _changelog:

Changelog
=========

13.0.0 — 2026-03-20
---------------------

Added
~~~~~

* **TranslatableTables discovery mechanism** — any active TYPO3 extension or
  site package can register additional tables and fields for translation by
  placing a ``Configuration/TranslatableTables.php`` file that returns a plain
  PHP array:

  * ``TranslatableTablesLoader`` service scans all active packages, merges
    field lists (union with de-duplication), and caches the result in the
    ``translate_tables`` TYPO3 system cache (``SimpleFileBackend``,
    invalidated with system caches)
  * In-process property cache on ``TranslatableTablesLoader`` avoids
    repeated cache reads within the same HTTP request
  * ``TranslateButtonEventListener`` and ``TranslateController`` now derive
    their supported tables dynamically from the loader — no hardcoded
    constants
  * ``Configuration/TranslatableTables.php`` — built-in defaults for
    ``pages``, ``tt_content``, ``sys_file_metadata``, ``sys_file_reference``
  * ``Configuration/TranslatableTablesExample.php`` — copy-paste template
    for third-party packages

* **``sys_file_metadata`` support** — translatable fields: ``title``,
  ``description``, ``caption``, ``alternative``

* **``sys_file_reference`` support** — translatable fields: ``title``,
  ``description``, ``alternative``, ``link``

* **Translate button** — added to the backend edit-form button bar via
  ``ModifyButtonBarEvent`` for all registered tables. Button is only shown
  when at least one provider API key is configured.

* **DeepL provider** — translates via the DeepL REST API:

  * Auto-selects free (``api-free.deepl.com``) or pro (``api.deepl.com``)
    endpoint based on the ``:fx`` suffix in the API key
  * Passes ``tag_handling=html`` to preserve markup in ``bodytext``

* **OpenAI provider** — translates via the OpenAI chat completions API:

  * System prompt preserves HTML structure
  * Model is configurable via extension configuration (default: ``gpt-4o-mini``)

* **``TranslateButton.js``** — ES module that handles the frontend interaction:

  * Opens a ``Modal.confirm`` dialog for provider / language selection
  * POSTs to the ``/ajax/translate/record`` AJAX route
  * Writes translated values back into open FormEngine fields without a
    page reload
  * Supports CKEditor 5 (TYPO3 v13) via ``ckeditorInstance.setData()``
    with a hidden-textarea fallback for plain text fields

* **Extension Configuration** — all provider credentials and defaults exposed
  via ``ext_conf_template.txt``:

  * ``deeplApiKey``, ``openAiApiKey``, ``openAiModel``,
    ``defaultProvider``, ``defaultSourceLanguage``

* **RST documentation** — Introduction, Installation, Configuration,
  TranslatableTables, and Changelog pages

* **Code quality tooling**:

  * ``.editorconfig`` — consistent file formatting rules
  * ``.php-cs-fixer.php`` — PHP-CS-Fixer rule set (``@PSR2``, ``@Symfony``,
    ``@PHP82Migration`` plus project-specific overrides)
  * ``phpstan.neon`` — PHPStan level ``max`` with ``saschaegerer/phpstan-typo3``
  * ``phpunit.xml.dist`` — PHPUnit configuration for unit tests
  * ``Tests/Unit/`` — unit tests for ``TranslationServiceFactory`` and
    ``TranslatableTablesLoader``
  * ``.github/workflows/ci.yml`` — CI pipeline: composer validate, unit
    tests (PHP 8.2 + 8.3 × TYPO3 13.4), PHPStan, PHP-CS-Fixer,
    EditorConfig check
