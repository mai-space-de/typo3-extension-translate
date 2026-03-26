<?php

declare(strict_types = 1);

namespace Maispace\MaiTranslate\Service;

/**
 * Factory that resolves the requested translation service by its provider name.
 */
final class TranslationServiceFactory
{
    /** @var TranslationServiceInterface[] */
    private array $services;

    public function __construct(
        DeeplTranslationService $deeplService,
        OpenAiTranslationService $openAiService,
    ) {
        $this->services = [
            $deeplService->getName()  => $deeplService,
            $openAiService->getName() => $openAiService,
        ];
    }

    /**
     * @throws \InvalidArgumentException when $provider is unknown
     */
    public function get(string $provider): TranslationServiceInterface
    {
        if (!isset($this->services[$provider])) {
            throw new \InvalidArgumentException(sprintf('Unsupported translation provider "%s". Supported: %s', $provider, implode(', ', array_keys($this->services))), 1_700_000_020);
        }

        return $this->services[$provider];
    }

    /**
     * Returns all registered service identifiers.
     *
     * @return string[]
     */
    public function getProviderNames(): array
    {
        return array_keys($this->services);
    }

    /**
     * Returns all available (configured) service identifiers.
     *
     * @return string[]
     */
    public function getAvailableProviderNames(): array
    {
        return array_keys(array_filter($this->services, static fn (TranslationServiceInterface $s) => $s->isAvailable()));
    }
}
