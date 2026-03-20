<?php

declare(strict_types=1);

namespace Maispace\Translate\EventListener;

use Maispace\Translate\Service\TranslationServiceFactory;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Adds a "Translate" button to the backend edit-form button bar for pages,
 * content elements (tt_content), file metadata (sys_file_metadata), and file
 * references (sys_file_reference), including all maispace elements.
 *
 * The button is only rendered when at least one translation provider is
 * properly configured via the extension settings.
 */
final class TranslateButtonEventListener
{
    /** Tables for which the translate button is shown. */
    private const SUPPORTED_TABLES = ['pages', 'tt_content', 'sys_file_metadata', 'sys_file_reference'];

    public function __construct(
        private readonly IconFactory $iconFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly TranslationServiceFactory $translationServiceFactory,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function __invoke(ModifyButtonBarEvent $event): void
    {
        // At least one provider must be configured.
        if (empty($this->translationServiceFactory->getAvailableProviderNames())) {
            return;
        }

        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request === null) {
            return;
        }

        $queryParams = $request->getQueryParams();
        $parsedBody = $request->getParsedBody() ?? [];
        $editConf = $queryParams['edit'] ?? $parsedBody['edit'] ?? [];

        if (empty($editConf) || !is_array($editConf)) {
            return;
        }

        $table = (string)key($editConf);

        if (!in_array($table, self::SUPPORTED_TABLES, true)) {
            return;
        }

        $uidList = $editConf[$table] ?? [];
        if (!is_array($uidList) || empty($uidList)) {
            return;
        }

        $uid = (int)key($uidList);
        if ($uid <= 0) {
            return;
        }

        $config = $this->extensionConfiguration->get('translate');
        $defaultProvider = (string)($config['defaultProvider'] ?? 'deepl');
        $defaultSourceLanguage = (string)($config['defaultSourceLanguage'] ?? '');

        $translateUrl = (string)$this->uriBuilder->buildUriFromRoute('ajax_translate_record');

        $language = $this->getLanguageService();
        $title = $language?->sL(
            'LLL:EXT:translate/Resources/Private/Language/locallang.xlf:button.translate'
        ) ?? 'Translate';

        $buttons = $event->getButtons();
        $buttonBar = $event->getButtonBar();

        $translateButton = $buttonBar->makeLinkButton()
            ->setHref('#')
            ->setTitle($title)
            ->setDataAttributes([
                'translate-url' => $translateUrl,
                'translate-table' => $table,
                'translate-uid' => (string)$uid,
                'translate-default-provider' => $defaultProvider,
                'translate-default-source-language' => $defaultSourceLanguage,
                'translate-available-providers' => implode(',', $this->translationServiceFactory->getAvailableProviderNames()),
                'js' => 'translate-button',
            ])
            ->setIcon($this->iconFactory->getIcon('actions-localize', IconSize::SMALL))
            ->setShowLabelText(true);

        $buttons[ButtonBar::BUTTON_POSITION_LEFT][3][] = $translateButton;

        /** @var PageRenderer $pageRenderer */
        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $pageRenderer->loadJavaScriptModule('@maispace/translate/TranslateButton.js');

        $event->setButtons($buttons);
    }

    private function getLanguageService(): ?LanguageService
    {
        return $GLOBALS['LANG'] ?? null;
    }
}
