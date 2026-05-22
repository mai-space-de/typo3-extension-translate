<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Tests\Unit\Provider;

use Maispace\MaiTranslate\Provider\OpenAiTranslationProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use TYPO3\CMS\Core\Http\RequestFactory;

final class OpenAiTranslationProviderTest extends TestCase
{
    private RequestFactory&MockObject $requestFactory;

    protected function setUp(): void
    {
        $this->requestFactory = $this->createMock(RequestFactory::class);
    }

    // ── getIdentifier() ──────────────────────────────────────────────────────

    #[Test]
    public function getIdentifierReturnsOpenAi(): void
    {
        $provider = new OpenAiTranslationProvider($this->requestFactory, 'key');
        self::assertSame('openai', $provider->getIdentifier());
    }

    // ── translate() — happy path ─────────────────────────────────────────────

    #[Test]
    public function translateReturnsTranslatedText(): void
    {
        $responseBody = $this->makeBodyMock(json_encode([
            'choices' => [['message' => ['content' => 'Hallo Welt']]],
        ], JSON_THROW_ON_ERROR));
        $response = $this->makeResponseMock(200, $responseBody);

        $this->requestFactory->method('request')->willReturn($response);

        $provider = new OpenAiTranslationProvider($this->requestFactory, 'sk-test');
        $result = $provider->translate('Hello World', 'en', 'de');

        self::assertSame('Hallo Welt', $result);
    }

    #[Test]
    public function translatePassesBearerTokenInAuthorizationHeader(): void
    {
        $responseBody = $this->makeBodyMock(json_encode([
            'choices' => [['message' => ['content' => 'ok']]],
        ], JSON_THROW_ON_ERROR));
        $response = $this->makeResponseMock(200, $responseBody);

        $this->requestFactory->expects(self::once())
            ->method('request')
            ->with(
                self::stringContains('api.openai.com'),
                'POST',
                self::callback(static function (array $options): bool {
                    return isset($options['headers']['Authorization'])
                        && $options['headers']['Authorization'] === 'Bearer sk-mykey';
                }),
            )
            ->willReturn($response);

        $provider = new OpenAiTranslationProvider($this->requestFactory, 'sk-mykey');
        $provider->translate('text', 'en', 'de');
    }

    #[Test]
    public function translateUsesConfiguredModel(): void
    {
        $responseBody = $this->makeBodyMock(json_encode([
            'choices' => [['message' => ['content' => 'ok']]],
        ], JSON_THROW_ON_ERROR));
        $response = $this->makeResponseMock(200, $responseBody);

        $this->requestFactory->expects(self::once())
            ->method('request')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(static function (array $options): bool {
                    $body = json_decode($options['body'], true);
                    return $body['model'] === 'gpt-4o';
                }),
            )
            ->willReturn($response);

        $provider = new OpenAiTranslationProvider($this->requestFactory, 'key', 'gpt-4o');
        $provider->translate('text', 'en', 'de');
    }

    #[Test]
    public function translateUsesDefaultModelGpt4oMini(): void
    {
        $responseBody = $this->makeBodyMock(json_encode([
            'choices' => [['message' => ['content' => 'ok']]],
        ], JSON_THROW_ON_ERROR));
        $response = $this->makeResponseMock(200, $responseBody);

        $this->requestFactory->expects(self::once())
            ->method('request')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(static function (array $options): bool {
                    $body = json_decode($options['body'], true);
                    return $body['model'] === 'gpt-4o-mini';
                }),
            )
            ->willReturn($response);

        $provider = new OpenAiTranslationProvider($this->requestFactory, 'key');
        $provider->translate('text', 'en', 'de');
    }

    #[Test]
    public function translateIncludesSourceAndTargetLanguageInPrompt(): void
    {
        $responseBody = $this->makeBodyMock(json_encode([
            'choices' => [['message' => ['content' => 'ok']]],
        ], JSON_THROW_ON_ERROR));
        $response = $this->makeResponseMock(200, $responseBody);

        $this->requestFactory->expects(self::once())
            ->method('request')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(static function (array $options): bool {
                    $body = json_decode($options['body'], true);
                    $userMessage = $body['messages'][1]['content'] ?? '';
                    return str_contains($userMessage, 'EN') && str_contains($userMessage, 'DE');
                }),
            )
            ->willReturn($response);

        $provider = new OpenAiTranslationProvider($this->requestFactory, 'key');
        $provider->translate('Hello', 'en', 'de');
    }

    // ── translate() — error handling ─────────────────────────────────────────

    #[Test]
    public function translateThrowsRuntimeExceptionOnNon200Response(): void
    {
        $responseBody = $this->makeBodyMock('{"error":{"message":"Invalid API key"}}');
        $response = $this->makeResponseMock(401, $responseBody);

        $this->requestFactory->method('request')->willReturn($response);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/401/');

        $provider = new OpenAiTranslationProvider($this->requestFactory, 'bad-key');
        $provider->translate('text', 'en', 'de');
    }

    #[Test]
    public function translateThrowsRuntimeExceptionOn429Response(): void
    {
        $responseBody = $this->makeBodyMock('{"error":{"message":"Rate limit exceeded"}}');
        $response = $this->makeResponseMock(429, $responseBody);

        $this->requestFactory->method('request')->willReturn($response);

        $this->expectException(RuntimeException::class);

        $provider = new OpenAiTranslationProvider($this->requestFactory, 'key');
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
