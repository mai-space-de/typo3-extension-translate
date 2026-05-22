<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Tests\Unit\Controller\Backend;

use Maispace\MaiBase\Controller\Backend\AbstractBackendController;
use Maispace\MaiTranslate\Controller\Backend\TranslationLogBackendController;
use Maispace\MaiTranslate\Domain\Repository\TranslationLogRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;

final class TranslationLogBackendControllerTest extends TestCase
{
    #[Test]
    public function controllerExtendsAbstractBackendController(): void
    {
        self::assertTrue(
            is_subclass_of(TranslationLogBackendController::class, AbstractBackendController::class),
        );
    }

    #[Test]
    public function constructorDeclaresModuleTemplateFactoryParameter(): void
    {
        $params = (new \ReflectionMethod(TranslationLogBackendController::class, '__construct'))
            ->getParameters();

        $names = array_map(static fn(\ReflectionParameter $p) => $p->getName(), $params);
        self::assertContains('moduleTemplateFactory', $names);

        $factoryParam = array_values(array_filter(
            $params,
            static fn(\ReflectionParameter $p) => $p->getName() === 'moduleTemplateFactory',
        ))[0];

        $type = $factoryParam->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame(ModuleTemplateFactory::class, $type->getName());
    }

    #[Test]
    public function constructorDeclaresIconFactoryParameter(): void
    {
        $params = (new \ReflectionMethod(TranslationLogBackendController::class, '__construct'))
            ->getParameters();

        $names = array_map(static fn(\ReflectionParameter $p) => $p->getName(), $params);
        self::assertContains('iconFactory', $names);

        $iconParam = array_values(array_filter(
            $params,
            static fn(\ReflectionParameter $p) => $p->getName() === 'iconFactory',
        ))[0];

        $type = $iconParam->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame(IconFactory::class, $type->getName());
    }

    #[Test]
    public function constructorRequiresTranslationLogRepository(): void
    {
        $params = (new \ReflectionMethod(TranslationLogBackendController::class, '__construct'))
            ->getParameters();

        $names = array_map(static fn(\ReflectionParameter $p) => $p->getName(), $params);
        self::assertContains('translationLogRepository', $names);

        $repoParam = array_values(array_filter(
            $params,
            static fn(\ReflectionParameter $p) => $p->getName() === 'translationLogRepository',
        ))[0];

        $type = $repoParam->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame(TranslationLogRepository::class, $type->getName());
    }

    #[Test]
    public function indexActionMethodExists(): void
    {
        self::assertTrue(
            method_exists(TranslationLogBackendController::class, 'indexAction'),
        );
    }

    #[Test]
    public function indexActionReturnsResponseInterface(): void
    {
        $returnType = (new \ReflectionMethod(TranslationLogBackendController::class, 'indexAction'))
            ->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame(ResponseInterface::class, $returnType->getName());
    }

    #[Test]
    public function translationLogRepositoryPropertyIsReadOnly(): void
    {
        $prop = new \ReflectionProperty(TranslationLogBackendController::class, 'translationLogRepository');
        self::assertTrue($prop->isReadOnly());
    }

    #[Test]
    public function logLimitConstantIsOneHundred(): void
    {
        $const = new \ReflectionClassConstant(TranslationLogBackendController::class, 'LOG_LIMIT');
        self::assertSame(100, $const->getValue());
    }
}
