<?php

declare(strict_types = 1);

namespace Maispace\MaiTranslate\EventListener;

use Maispace\MaiTranslate\Loader\TranslatableTablesLoader;
use Maispace\MaiTranslate\Service\TranslationServiceFactory;
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
 * Adds a "Translate" button to the backend edit-form button bar for any table
 * that is registered via Configuration/TranslatableTables.php in an active
 * TYPO3 package.
 *
 * The button is only rendered when at least one translation provider is
 * properly configured via the extension settings.
 */
final class TranslateButtonEventListener
{
    public function __construct(
        private readonly IconFactory $iconFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly TranslationServiceFactory $translationServiceFactory,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly TranslatableTablesLoader $translatableTablesLoader,
    ) {
    }

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

        $supportedTables = array_keys($this->translatableTablesLoader->getTranslatableTables());
        if (!in_array($table, $supportedTables, true)) {
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
                'translate-url'                     => $translateUrl,
                'translate-table'                   => $table,
                'translate-uid'                     => (string)$uid,
                'translate-default-provider'        => $defaultProvider,
                'translate-default-source-language' => $defaultSourceLanguage,
                'translate-available-providers'     => implode(',', $this->translationServiceFactory->getAvailableProviderNames()),
                'js'                                => 'translate-button',
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
