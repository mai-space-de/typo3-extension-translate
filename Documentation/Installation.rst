.. include:: /Documentation/Includes.rst.txt

.. _installation:

Installation
============

Requirements
------------

* TYPO3 13.4 LTS
* PHP 8.2 or higher

Composer
--------

.. code-block:: bash

   composer require maispace/translate

Activate the extension if not using composer-mode:

.. code-block:: bash

   vendor/bin/typo3 extension:activate translate

Provider configuration
----------------------

Open :guilabel:`Admin Tools → Settings → Extension Configuration → translate`
and fill in at least one API key:

.. list-table::
   :widths: 25 75
   :header-rows: 1

   * - Key
     - Description
   * - ``deeplApiKey``
     - Your DeepL API key. Append ``:fx`` for the free tier
       (e.g. ``abc123:fx``).
   * - ``openAiApiKey``
     - Your OpenAI API key.
   * - ``openAiModel``
     - OpenAI model identifier. Defaults to ``gpt-4o-mini``.
   * - ``defaultProvider``
     - Provider pre-selected in the modal dialog (``deepl`` or ``openai``).
       Defaults to ``deepl``.
   * - ``defaultSourceLanguage``
     - Source language code pre-filled in the modal (e.g. ``EN``). Leave
       empty for auto-detection.

The Translate button only appears when at least one provider has a configured
API key.

Translatable fields
-------------------

The built-in configuration covers ``pages``, ``tt_content``,
``sys_file_metadata``, and ``sys_file_reference``. To register additional
tables or extend existing ones from your own extension or site package, see
:ref:`translatable-tables`.

Usage
-----

1. Open any page, content element, file metadata, or file reference record
   in the TYPO3 backend.
2. Click the **Translate** button (globe icon) in the button bar.
3. Select the **provider** and **target language** in the modal dialog.
4. Optionally choose a source language (leave empty for auto-detect).
5. Click **Translate now** — fields are populated automatically.
6. Review and **save** the record as usual.
