<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Tests\Unit\Provider;

use Maispace\MaiTranslate\Provider\DeepLTranslationProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use TYPO3\CMS\Core\Http\RequestFactory;

final class DeepLTranslationProviderTest extends TestCase
{
    private RequestFactory&MockObject $requestFactory;

    protected function setUp(): void
    {
        $this->requestFactory = $this->createMock(RequestFactory::class);
    }

    // ── getIdentifier() ──────────────────────────────────────────────────────

    #[Test]
    public function getIdentifierReturnsDeepL(): void
    {
        $provider = new DeepLTranslationProvider($this->requestFactory, 'key');
        self::assertSame('deepl', $provider->getIdentifier());
    }

    // ── translate() — happy path ─────────────────────────────────────────────

    #[Test]
    public function translateReturnsTranslatedText(): void
    {
        $responseBody = $this->makeBodyMock(json_encode([
            'translations' => [['text' => 'Hallo Welt', 'detected_source_language' => 'EN']],
        ], JSON_THROW_ON_ERROR));
        $response = $this->makeResponseMock(200, $responseBody);

        $this->requestFactory->method('request')->willReturn($response);

        $provider = new DeepLTranslationProvider($this->requestFactory, 'my-key:fx', true);
        $result = $provider->translate('Hello World', 'en', 'de');

        self::assertSame('Hallo Welt', $result);
    }

    #[Test]
    public function translatePassesCorrectAuthorizationHeader(): void
    {
        $responseBody = $this->makeBodyMock(json_encode([
            'translations' => [['text' => 'test']],
        ], JSON_THROW_ON_ERROR));
        $response = $this->makeResponseMock(200, $responseBody);

        $this->requestFactory->expects(self::once())
            ->method('request')
            ->with(
                self::stringContains('/v2/translate'),
                'POST',
                self::callback(static function (array $options): bool {
                    return isset($options['headers']['Authorization'])
                        && str_starts_with($options['headers']['Authorization'], 'DeepL-Auth-Key ');
                }),
            )
            ->willReturn($response);

        $provider = new DeepLTranslationProvider($this->requestFactory, 'test-key');
        $provider->translate('text', 'en', 'de');
    }

    #[Test]
    public function translateUsesFreeApiUrlWhenFlagIsTrue(): void
    {
        $responseBody = $this->makeBodyMock(json_encode([
            'translations' => [['text' => 'ok']],
        ], JSON_THROW_ON_ERROR));
        $response = $this->makeResponseMock(200, $responseBody);

        $this->requestFactory->expects(self::once())
            ->method('request')
            ->with(self::stringContains('api-free.deepl.com'), self::anything(), self::anything())
            ->willReturn($response);

        $provider = new DeepLTranslationProvider($this->requestFactory, 'key:fx', true);
        $provider->translate('text', 'en', 'de');
    }

    #[Test]
    public function translateUsesPaidApiUrlWhenFlagIsFalse(): void
    {
        $responseBody = $this->makeBodyMock(json_encode([
            'translations' => [['text' => 'ok']],
        ], JSON_THROW_ON_ERROR));
        $response = $this->makeResponseMock(200, $responseBody);

        $this->requestFactory->expects(self::once())
            ->method('request')
            ->with(self::stringContains('api.deepl.com'), self::anything(), self::anything())
            ->willReturn($response);

        $provider = new DeepLTranslationProvider($this->requestFactory, 'paid-key', false);
        $provider->translate('text', 'en', 'de');
    }

    #[Test]
    public function translateConvertsLanguageCodesToUpperCase(): void
    {
        $responseBody = $this->makeBodyMock(json_encode([
            'translations' => [['text' => 'ok']],
        ], JSON_THROW_ON_ERROR));
        $response = $this->makeResponseMock(200, $responseBody);

        $this->requestFactory->expects(self::once())
            ->method('request')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(static function (array $options): bool {
                    $body = json_decode($options['body'], true);
                    return $body['source_lang'] === 'EN' && $body['target_lang'] === 'DE';
                }),
            )
            ->willReturn($response);

        $provider = new DeepLTranslationProvider($this->requestFactory, 'key');
        $provider->translate('text', 'en', 'de');
    }

    // ── translate() — error handling ─────────────────────────────────────────

    #[Test]
    public function translateThrowsRuntimeExceptionOnNon200Response(): void
    {
        $responseBody = $this->makeBodyMock('{"message":"Quota exceeded"}');
        $response = $this->makeResponseMock(456, $responseBody);

        $this->requestFactory->method('request')->willReturn($response);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/456/');

        $provider = new DeepLTranslationProvider($this->requestFactory, 'key');
        $provider->translate('text', 'en', 'de');
    }

    #[Test]
    public function translateThrowsRuntimeExceptionOn403Response(): void
    {
        $responseBody = $this->makeBodyMock('{"message":"Invalid auth key"}');
        $response = $this->makeResponseMock(403, $responseBody);

        $this->requestFactory->method('request')->willReturn($response);

        $this->expectException(RuntimeException::class);

        $provider = new DeepLTranslationProvider($this->requestFactory, 'bad-key');
        $provider->translate('text', 'en', 'de');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeBodyMock(string $contents): StreamInterface&MockObject
    {
        $body = $this->createMock(StreamInterface::class);
        $body->method('getContents')->willReturn($contents);
        return $body;
    }

    private function makeResponseMock(int $statusCode, StreamInterface $body): ResponseInterface&MockObject
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn($body);
        return $response;
    }
}
