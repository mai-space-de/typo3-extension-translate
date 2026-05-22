<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Controller\Backend;

use Maispace\MaiBase\Controller\Backend\AbstractBackendController;
use Maispace\MaiTranslate\Domain\Repository\TranslationLogRepository;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;

#[AsController]
final class TranslationLogBackendController extends AbstractBackendController
{
    private const int LOG_LIMIT = 100;

    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        IconFactory $iconFactory,
        private readonly TranslationLogRepository $translationLogRepository,
    ) {
        parent::__construct($moduleTemplateFactory, $iconFactory);
    }

    public function indexAction(): ResponseInterface
    {
        $moduleTemplate = $this->createModuleTemplate();
        $this->addShortcutButton($moduleTemplate, 'mai_translate', 'Translation Log');
        $this->assignMultiple($moduleTemplate, [
            'logEntries' => $this->translationLogRepository->findRecent(self::LOG_LIMIT),
        ]);

        return $this->renderModuleResponse($moduleTemplate, 'Index');
    }
}
