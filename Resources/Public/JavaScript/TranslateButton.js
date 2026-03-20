/**
 * TranslateButton.js
 *
 * Handles the "Translate" button in the TYPO3 backend edit-form button bar.
 *
 * On click the module opens a TYPO3 modal dialog that lets the editor choose
 * a translation provider and a target language.  After confirmation it calls
 * the backend AJAX endpoint, receives the translated field values, and writes
 * them back into the currently open edit form.
 *
 * Works for pages and tt_content records (all maispace elements).
 */
import Modal from '@typo3/backend/modal.js';
import Severity from '@typo3/backend/severity.js';
import Notification from '@typo3/backend/notification.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';

const TRANSLATE_LANGUAGES = [
    { code: 'BG', label: 'Bulgarian' },
    { code: 'CS', label: 'Czech' },
    { code: 'DA', label: 'Danish' },
    { code: 'DE', label: 'German' },
    { code: 'EL', label: 'Greek' },
    { code: 'EN', label: 'English' },
    { code: 'ES', label: 'Spanish' },
    { code: 'ET', label: 'Estonian' },
    { code: 'FI', label: 'Finnish' },
    { code: 'FR', label: 'French' },
    { code: 'HU', label: 'Hungarian' },
    { code: 'ID', label: 'Indonesian' },
    { code: 'IT', label: 'Italian' },
    { code: 'JA', label: 'Japanese' },
    { code: 'KO', label: 'Korean' },
    { code: 'LT', label: 'Lithuanian' },
    { code: 'LV', label: 'Latvian' },
    { code: 'NB', label: 'Norwegian (Bokmål)' },
    { code: 'NL', label: 'Dutch' },
    { code: 'PL', label: 'Polish' },
    { code: 'PT', label: 'Portuguese' },
    { code: 'RO', label: 'Romanian' },
    { code: 'RU', label: 'Russian' },
    { code: 'SK', label: 'Slovak' },
    { code: 'SL', label: 'Slovenian' },
    { code: 'SV', label: 'Swedish' },
    { code: 'TR', label: 'Turkish' },
    { code: 'UK', label: 'Ukrainian' },
    { code: 'ZH', label: 'Chinese' },
];

/**
 * Build the HTML content for the translation modal.
 */
function buildModalContent(defaultProvider, defaultSourceLanguage, availableProviders) {
    const providerOptions = availableProviders
        .map(p => `<option value="${p}"${p === defaultProvider ? ' selected' : ''}>${p === 'deepl' ? 'DeepL' : 'OpenAI'}</option>`)
        .join('');

    const languageOptions = TRANSLATE_LANGUAGES
        .map(l => `<option value="${l.code}">${l.label} (${l.code})</option>`)
        .join('');

    return `
<div class="form-group mt-3">
    <label for="translate-provider" class="form-label fw-bold">Translation provider</label>
    <select id="translate-provider" class="form-select">
        ${providerOptions}
    </select>
</div>
<div class="form-group mt-3">
    <label for="translate-target-language" class="form-label fw-bold">Target language <span class="text-danger">*</span></label>
    <select id="translate-target-language" class="form-select">
        <option value="">– select –</option>
        ${languageOptions}
    </select>
</div>
<div class="form-group mt-3">
    <label for="translate-source-language" class="form-label fw-bold">Source language <small class="text-muted">(optional, leave empty for auto-detect)</small></label>
    <select id="translate-source-language" class="form-select">
        <option value="">Auto-detect</option>
        ${languageOptions}
    </select>
</div>
`;
}

/**
 * Write translated values back into the edit form.
 *
 * TYPO3 FormEngine stores field values in form elements with names like:
 *   data[tt_content][42][header]
 *   data[pages][7][title]
 *
 * For RTE (CKEditor 5) fields, TYPO3 v13 stores the serialised HTML in a
 * hidden textarea with the same name and also renders the editor inside a
 * container element whose ID follows the pattern
 *   data-{table}-{uid}-{field}-richtext-editor
 * We update the hidden textarea (which FormEngine reads on save) and, if a
 * CKEditor 5 instance is attached to the container element, we update the
 * editor's live content too so the editor display stays in sync.
 */
function applyTranslations(table, uid, translations) {
    let applied = 0;

    for (const [field, value] of Object.entries(translations)) {
        const fieldName = `data[${table}][${uid}][${field}]`;

        // Find the form element (input, textarea, hidden) by its name attribute.
        const input = document.querySelector(`[name="${fieldName}"]`);
        if (input) {
            input.value = value;
            input.dispatchEvent(new Event('change', { bubbles: true }));
            applied++;

            // For RTE fields, also update the CKEditor 5 editor display so the
            // user sees the translated content immediately. In TYPO3 v13 the
            // editor container element carries a `ckeditorInstance` property
            // that is set by the @typo3/rte-ckeditor integration after mount.
            const rteContainerId = `data-${table}-${uid}-${field}-richtext-editor`;
            const rteContainer = document.getElementById(rteContainerId);
            if (rteContainer?.ckeditorInstance) {
                try {
                    rteContainer.ckeditorInstance.setData(value);
                } catch (_) {
                    // Best-effort; the textarea value (already set above) is the
                    // authoritative source used by FormEngine on save.
                }
            }

            continue;
        }

        // Fallback: search by the TYPO3 RTE mirror-textarea ID pattern used in
        // older or alternative integrations.
        const rteId = `data-${table}-${uid}-${field}-richtext`;
        const rteEl = document.getElementById(rteId);
        if (rteEl) {
            rteEl.value = value;
            rteEl.dispatchEvent(new Event('change', { bubbles: true }));
            applied++;
        }
    }

    return applied;
}

/**
 * Perform the AJAX call to the backend translation endpoint and apply the result.
 */
async function performTranslation(url, table, uid, provider, targetLanguage, sourceLanguage) {
    Notification.info('Translating…', '', 2);

    const requestUrl = new URL(url, window.location.origin);
    requestUrl.searchParams.set('table', table);
    requestUrl.searchParams.set('uid', uid);
    requestUrl.searchParams.set('provider', provider);
    requestUrl.searchParams.set('targetLanguage', targetLanguage);
    if (sourceLanguage) {
        requestUrl.searchParams.set('sourceLanguage', sourceLanguage);
    }

    try {
        const response = await new AjaxRequest(requestUrl.toString()).get();
        const data = await response.resolve('application/json');

        if (data.error) {
            Notification.error('Translation failed', data.error, 8);
            return;
        }

        const translations = data.translations ?? {};
        if (Object.keys(translations).length === 0) {
            Notification.warning('No translatable fields', 'No translatable fields found for this record.', 5);
            return;
        }

        const applied = applyTranslations(table, uid, translations);
        if (applied > 0) {
            Notification.success('Translation applied', `${applied} field(s) translated successfully.`, 5);
        } else {
            Notification.warning('Translation applied', 'Fields were translated but could not be written back automatically. Please copy the values manually.', 8);
        }
    } catch (error) {
        Notification.error('Translation failed', error.message ?? String(error), 8);
    }
}

/**
 * Open the translation modal for the given button element.
 */
function openTranslateModal(buttonEl) {
    const url = buttonEl.dataset.translateUrl;
    const table = buttonEl.dataset.translateTable;
    const uid = buttonEl.dataset.translateUid;
    const defaultProvider = buttonEl.dataset.translateDefaultProvider || 'deepl';
    const defaultSourceLanguage = buttonEl.dataset.translateDefaultSourceLanguage || '';
    const availableProviders = (buttonEl.dataset.translateAvailableProviders || 'deepl').split(',').filter(Boolean);

    const modalContent = document.createElement('div');
    modalContent.innerHTML = buildModalContent(defaultProvider, defaultSourceLanguage, availableProviders);

    const modal = Modal.confirm(
        'Translate record',
        modalContent,
        Severity.info,
        [
            {
                text: 'Cancel',
                active: false,
                btnClass: 'btn-default',
                name: 'cancel',
            },
            {
                text: 'Translate now',
                active: true,
                btnClass: 'btn-primary',
                name: 'translate',
            },
        ]
    );

    modal.addEventListener('button.clicked', async (event) => {
        const name = event.detail?.button?.name ?? event.target?.name;
        if (name !== 'translate') {
            modal.hideModal();
            return;
        }

        const providerSelect = modal.querySelector('#translate-provider');
        const targetSelect = modal.querySelector('#translate-target-language');
        const sourceSelect = modal.querySelector('#translate-source-language');

        const provider = providerSelect?.value || defaultProvider;
        const targetLanguage = targetSelect?.value || '';
        const sourceLanguage = sourceSelect?.value || '';

        if (!targetLanguage) {
            Notification.warning('Missing target language', 'Please select a target language.', 4);
            return;
        }

        modal.hideModal();

        await performTranslation(url, table, uid, provider, targetLanguage, sourceLanguage);
    });
}

/**
 * Attach click handlers to all translate buttons on the page.
 */
function initTranslateButtons() {
    document.querySelectorAll('[data-js="translate-button"]').forEach((btn) => {
        btn.addEventListener('click', (event) => {
            event.preventDefault();
            openTranslateModal(btn);
        });
    });
}

// Initialise immediately and also after TYPO3 re-renders the button bar
// (e.g. after an inline-save that replaces DOM nodes).
document.addEventListener('DOMContentLoaded', initTranslateButtons);
initTranslateButtons();
