## Translation Providers

* DeepL integration — translate content records via the DeepL REST API (`/v2/translate`)
* OpenAI integration — alternative translation backend via OpenAI Chat Completions API
* Configurable provider — switch between DeepL and OpenAI at runtime via Extension Manager settings
* Translation auditing — every API call is recorded in `tx_maitranslate_log` with table, UID, field, language pair, provider, and result status (`success` / `truncated` / `failed`)
* Length guard — optional per-target character limit caps over-length translations on a word boundary (multibyte-safe) to protect fixed-size DB columns and UI labels
* Backend log module — admin-only backend module at `/module/mai-translate` showing the 100 most recent log entries

---

## 1. Translation Provider Interface

`TranslationProviderInterface` defines the contract for all backends:

| Method | Return | Description |
|--------|--------|-------------|
| `getIdentifier()` | `string` | Stable machine-readable key, e.g. `'deepl'` or `'openai'` |
| `translate(text, sourceLang, targetLang)` | `string` | Translate plain or HTML text; throws `RuntimeException` on API error |

Language codes are ISO 2-letter strings (e.g. `'en'`, `'de'`, `'uk'`, `'ar'`). Providers are responsible for any required uppercasing.

---

## 2. DeepL Translation Provider

`DeepLTranslationProvider` calls the DeepL REST API at `/v2/translate`.

**Free-tier vs. paid-tier URL routing:**

| Key suffix | Base URL |
|------------|----------|
| Ends with `:fx` | `https://api-free.deepl.com` |
| Any other suffix | `https://api.deepl.com` |

The factory detects the key suffix automatically — no manual setting required.

**Request format:**

```
POST /v2/translate
Authorization: DeepL-Auth-Key <apiKey>
Content-Type: application/json

{"text": ["<text>"], "source_lang": "EN", "target_lang": "DE"}
```

Returns `translations[0].text`. Throws `RuntimeException` with `"DeepL API error (HTTP N): <body>"` on any non-200 response.

---

## 3. OpenAI Translation Provider

`OpenAiTranslationProvider` calls `https://api.openai.com/v1/chat/completions`.

**Model:** `gpt-4o-mini` (default); any chat-capable model is accepted (`gpt-4o`, `gpt-4-turbo`, `gpt-3.5-turbo`).

**System prompt:** `"You are a professional translator. Translate the given text accurately, preserving formatting, HTML tags, and meaning. Return only the translated text without any explanation or additional commentary."`

**User message format:** `"Translate the following text from EN to DE:\n\n<text>"`

Returns `choices[0].message.content`. Throws `RuntimeException` with `"OpenAI API error (HTTP N): <body>"` on any non-200 response.

---

## 4. Provider Factory

`TranslationProviderFactory::create()` reads the `mai_translate` extension configuration at runtime and returns the correct provider instance.

| Extension setting | Type | Default | Effect |
|-------------------|------|---------|--------|
| `provider` | string | `deepl` | `'deepl'` or `'openai'` |
| `deepLApiKey` | string | _(empty)_ | DeepL auth key; `:fx` suffix → free-tier URL |
| `openAiApiKey` | string | _(empty)_ | OpenAI `Bearer` token |
| `openAiModel` | string | `gpt-4o-mini` | OpenAI model identifier |

**Selection logic:**

```
provider = 'openai' → OpenAiTranslationProvider(apiKey, model)
anything else        → DeepLTranslationProvider(apiKey, useFreeApi = key.endsWith(':fx'))
```

Concrete provider classes are **not** auto-wired by the DI container — they require scalar API key arguments that are unavailable at container build time. `TranslationProviderFactory` is registered in `Services.yaml` as the factory for `TranslationProviderInterface`, so `TranslationService` receives the correct backend via standard constructor injection.

---

## 5. Translation Service

`TranslationService` is the single orchestration entry point for upstream callers.

**Method signature:**

```php
translate(
    string $text,
    string $sourceLanguage,
    string $targetLanguage,
    string $recordTable = '',
    int    $recordUid   = 0,
    string $field       = '',
    int    $maxLength   = 0,
): string
```

**Execution flow:**

```
caller → TranslationService::translate()
           ↓
     provider->translate(text, src, tgt)
           ↓ success                    ↓ failure
     lengthGuard->enforce(...)        logService->log(... 'failed')
           ↓                                ↓
     logService->log(... status)     re-throw RuntimeException
           ↓
     return $translated
```

**Logging guard:** when `$recordTable === ''`, logging is skipped entirely — no entry is written to `tx_maitranslate_log`. This is intended for ad-hoc or preview translations where auditing is not needed.

**Length guard:** when `$maxLength > 0` and the provider returns a translation longer than that many Unicode characters, `TranslationLengthGuard::enforce()` caps the result to a length-safe value — the translation cut to at most `$maxLength` characters, preferring the last word boundary (a single over-length word is hard-cut). The returned string is guaranteed never to exceed `$maxLength`, and the log entry records the status `'truncated'` instead of `'success'`. `$maxLength = 0` (the default) disables the limit. This protects fixed-size targets such as an SEO title (`varchar(255)`) or fixed-width UI labels from machine-translation length expansion (German/Cyrillic/Arabic targets run longer than English). Lengths are counted with `mb_strlen()`, not bytes.

Inject `TranslationService` via constructor DI. The concrete provider is resolved by the container using `TranslationProviderFactory`.

---

## 6. Translation Log

### TranslationLogService

Sole writer to `tx_maitranslate_log`. Called by `TranslationService` after each API call.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$recordTable` | string | DB table of the translated record, e.g. `'tt_content'` |
| `$recordUid` | int | UID of the translated record |
| `$field` | string | Field name that was translated, e.g. `'bodytext'` |
| `$sourceLanguage` | string | ISO source language code |
| `$targetLanguage` | string | ISO target language code |
| `$provider` | string | Provider identifier (`'deepl'` or `'openai'`) |
| `$status` | string | Result: `'success'`, `'truncated'` (length-capped), or `'failed'` |

Inserts one row with `pid=0`, `tstamp`, and `crdate` set to `time()`.

### TranslationLogRepository

Read-only query API using `ConnectionPool` (not Extbase). Returns `TranslationLog[]`.

| Method | Default limit | Order | Filter |
|--------|--------------|-------|--------|
| `findRecent(int $limit)` | (required) | `crdate DESC` | none |
| `findByRecordTable(string $tableName, int $limit = 100)` | 100 | `crdate DESC` | `record_table = $tableName` |

### TranslationLog Domain Model

Immutable value object. All properties are `readonly`; no setters.

| Property | Type | Getter | Notes |
|----------|------|--------|-------|
| `uid` | int | `getUid()` | Auto-assigned by DB |
| `recordTable` | string | `getRecordTable()` | e.g. `'tt_content'` |
| `recordUid` | int | `getRecordUid()` | |
| `field` | string | `getField()` | e.g. `'bodytext'` |
| `sourceLanguage` | string | `getSourceLanguage()` | ISO code |
| `targetLanguage` | string | `getTargetLanguage()` | ISO code |
| `provider` | string | `getProvider()` | `'deepl'` or `'openai'` |
| `status` | string | `getStatus()` | `'success'`, `'truncated'`, or `'failed'` |

Convenience: `isSuccess(): bool` — returns `true` when `status === 'success'`. A `'truncated'` entry is therefore reported as non-success, flagging translations that were length-capped for editor review.

---

## 7. Backend Module

`TranslationLogBackendController` — registered as a TYPO3 backend module under `web`.

| Setting | Value |
|---------|-------|
| Module ID | `mai_translate` |
| Path | `/module/mai-translate` |
| Parent | `web` |
| Access | Admin only |
| Workspaces | Online only |
| Icon | `mai-backend-module` (from `mai_base`) |
| Action | `index` — loads `findRecent(100)` entries and renders via `Index.html` |
| Shortcut button | Registered as `'mai_translate'` / `'Translation Log'` |

---

## 8. Extension Configuration

Set in TYPO3 → Extensions → `mai_translate` → Configure:

| Key | Default | Type | Purpose |
|-----|---------|------|---------|
| `provider` | `deepl` | string | Active backend: `'deepl'` or `'openai'` |
| `deepLApiKey` | _(empty)_ | string | DeepL authentication key |
| `openAiApiKey` | _(empty)_ | string | OpenAI authentication key |
| `openAiModel` | `gpt-4o-mini` | string | OpenAI model identifier |

**Key not set:** if `deepLApiKey` is empty and the provider is `'deepl'`, the DeepL API will return HTTP 403 (auth failure). `TranslationService` will log a `'failed'` entry and re-throw the exception.

---

## 9. Database Table

### `tx_maitranslate_log`

Append-only audit table. Records are never updated or deleted programmatically.

| Column | SQL type | Default | Purpose |
|--------|----------|---------|---------|
| `uid` | int(11) AUTO_INCREMENT | — | Primary key |
| `pid` | int(11) | 0 | Always 0 (root page) |
| `tstamp` | int(11) | 0 | Unix timestamp (update) |
| `crdate` | int(11) | 0 | Unix timestamp (creation) |
| `record_table` | varchar(255) | `''` | Source DB table |
| `record_uid` | int(11) unsigned | 0 | Source record UID |
| `field` | varchar(255) | `''` | Translated field name |
| `source_language` | varchar(10) | `''` | ISO source language |
| `target_language` | varchar(10) | `''` | ISO target language |
| `provider` | varchar(20) | `''` | `'deepl'` or `'openai'` |
| `status` | varchar(20) | `'success'` | `'success'` or `'failed'` |

TCA configuration: `recordsCanOnlyBeRead()` + `setAccessableOnlyByAdmins()` — the table is never writable from the backend list view.

---

## 10. Integration Guide

Inject `TranslationService` and call `translate()` from a DataHandler hook, command, or backend button:

```php
use Maispace\MaiTranslate\Service\TranslationService;

final class ContentTranslationHook
{
    public function __construct(
        private readonly TranslationService $translationService,
    ) {}

    public function translateRecord(string $table, int $uid, string $field, string $value): string
    {
        return $this->translationService->translate(
            text: $value,
            sourceLanguage: 'de',
            targetLanguage: 'en',
            recordTable: $table,
            recordUid: $uid,
            field: $field,
        );
    }
}
```

**Ad-hoc / preview translation (no log entry):**

```php
$preview = $this->translationService->translate(
    text: $value,
    sourceLanguage: 'de',
    targetLanguage: 'en',
    // omit recordTable → logging is skipped
);
```

**Error handling:** `TranslationService::translate()` re-throws the provider's `RuntimeException` after logging. Callers must catch it or let it propagate.

---

## 11. Architecture Constraints

* **Dev tool** — `mai_translate` is in the Developer Tools layer and may depend only on `mai_base` (Infrastructure). It must never depend on Feature or Theme & Mail extensions.
* **Sole log writer** — only `TranslationService` (via `TranslationLogService`) may write to `tx_maitranslate_log`. Never insert directly from hooks or other services.
* **No Extbase** — the repository uses `ConnectionPool` directly (not `AbstractRepository`), and the domain model is a plain PHP value object (not an Extbase `AbstractEntity`). This is intentional to avoid Extbase overhead for a log-only read path.
* **No SCSS** — no stylesheet is bundled; log display relies on TYPO3 core backend styles.
* **No mail dispatch** — error notifications, if needed in the future, must route through `mai_mail`.
* **Provider API keys are runtime values** — never hard-code API keys in PHP; always read from `ExtensionConfiguration`. The factory pattern exists precisely to defer key resolution until request time.
* **HTML preservation** — both providers are prompted/configured to preserve HTML tags in the translated output. Callers passing raw HTML should test the output for tag integrity.
