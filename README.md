# typo3-extension-translate

TYPO3 extension that adds a **"Translate"** button to the backend edit-form button bar for pages and content elements, enabling one-click translation via [DeepL](https://www.deepl.com/de/docs-api/) or [OpenAI](https://platform.openai.com/docs/api-reference).

## Features

* Translate button appears on every **page** and **content element** edit form in the TYPO3 backend.
* Supports all **maispace elements** ([typo3-extension-elements](https://github.com/mai-space-de/typo3-extension-elements)):
  `element-headline`, `element-text`, `element-html`, `element-table`, `element-image`, `element-video`, `element-file`.
* Two built-in translation providers:
  * **DeepL** – free and pro tiers (HTML-aware translation)
  * **OpenAI** – configurable model (HTML markup is preserved)
* Button is only displayed when at least one provider is configured.
* Translated values are written back directly into the open edit form – no page reload required.
* Uses the same **button bar event approach** as [typo3-extension-base](https://github.com/mai-space-de/typo3-extension-base).

## Requirements

* TYPO3 CMS 13.4+

## Installation

```bash
composer require maispace/translate
```

Then activate the extension:

```bash
vendor/bin/typo3 extension:activate translate
```

## Configuration

Open **Admin Tools → Settings → Extension Configuration → translate** and set:

| Key | Description |
|---|---|
| `deeplApiKey` | Your DeepL API key (append `:fx` for free tier) |
| `openAiApiKey` | Your OpenAI API key |
| `openAiModel` | OpenAI model to use (default: `gpt-4o-mini`) |
| `defaultProvider` | Default provider shown in the modal (`deepl` or `openai`) |
| `defaultSourceLanguage` | Default source language code (leave empty for auto-detect) |

## Translatable fields

### tt_content (all CTypes, including maispace elements)

| Field | Description |
|---|---|
| `header` | Main heading |
| `subheader` | Sub-heading |
| `bodytext` | Body text / RTE content / HTML / table content |
| `header_link` | Link field attached to the heading |

### pages

| Field | Description |
|---|---|
| `title` | Page title |
| `subtitle` | Subtitle |
| `nav_title` | Navigation title |
| `abstract` | Abstract / teaser |
| `description` | Meta description |
| `keywords` | Meta keywords |
| `seo_title` | SEO title |
| `og_title` | Open Graph title |
| `og_description` | Open Graph description |
| `twitter_title` | Twitter card title |
| `twitter_description` | Twitter card description |

## Usage

1. Open any page or content element in the TYPO3 backend.
2. Click the **Translate** button (globe icon) in the button bar.
3. Select the **provider** and **target language** in the modal dialog.
4. Optionally choose a source language (leave empty for auto-detect).
5. Click **Translate now** – fields are populated automatically.
6. Review and **save** the record as usual.

## License

GPL-2.0-or-later

## Author

**Joel Maximilian Mai**

* Website: [maispace.de](https://www.maispace.de)
* Email: [joel@maispace.de](mailto:joel@maispace.de)