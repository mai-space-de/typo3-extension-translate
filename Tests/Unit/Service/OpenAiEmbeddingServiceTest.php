<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Tests\Unit\Service;

use Maispace\MaiTranslate\Service\OpenAiEmbeddingService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use TYPO3\CMS\Core\Http\RequestFactory;

final class OpenAiEmbeddingServiceTest extends TestCase
{
    private RequestFactory&MockObject $requestFactory;

    protected function setUp(): void
    {
        $this->requestFactory = $this->createMock(RequestFactory::class);
    }

    // ── getIdentifier() ──────────────────────────────────────────────────────

    #[Test]
    public function getIdentifierReturnsOpenAiTextEmbedding3Small(): void
    {
        $service = $this->createService('key');
        self::assertSame('openai/text-embedding-3-small', $service->getIdentifier());
    }

    // ── getMaxInputTokens() ──────────────────────────────────────────────────

    #[Test]
    public function getMaxInputTokensReturnsDefault8191(): void
    {
        $service = $this->createService('key');
        self::assertSame(8191, $service->getMaxInputTokens());
    }

    #[Test]
    public function getMaxInputTokensReturnsConfiguredValue(): void
    {
        $service = new OpenAiEmbeddingService($this->requestFactory, 'key', 1536, 4096);
        self::assertSame(4096, $service->getMaxInputTokens());
    }

    // ── getVectorDimension() ─────────────────────────────────────────────────

    #[Test]
    public function getVectorDimensionReturnsDefault1536(): void
    {
        $service = $this->createService('key');
        self::assertSame(1536, $service->getVectorDimension());
    }

    #[Test]
    public function getVectorDimensionReturnsConfiguredValue(): void
    {
        $service = new OpenAiEmbeddingService($this->requestFactory, 'key', 256);
        self::assertSame(256, $service->getVectorDimension());
    }

    // ── getTokenCount() ──────────────────────────────────────────────────────

    #[Test]
    public function getTokenCountReturnsOneForEmptyString(): void
    {
        $service = $this->createService('key');
        self::assertSame(1, $service->getTokenCount(''));
    }

    #[Test]
    public function getTokenCountReturnsOneForVeryShortString(): void
    {
        $service = $this->createService('key');
        self::assertSame(1, $service->getTokenCount('a'));
    }

    #[Test]
    public function getTokenCountReturnsApproximationBasedOnCharacterCount(): void
    {
        $service = $this->createService('key');
        // 4 characters at ~0.25 tokens/char = 1 token, floored
        self::assertSame(1, $service->getTokenCount('test'));
        // 8 characters at ~0.25 tokens/char = 2 tokens
        self::assertSame(2, $service->getTokenCount('testing'));
        // 20 characters at ~0.25 tokens/char = 5 tokens
        self::assertSame(5, $service->getTokenCount('this is a string for'));
    }

    #[Test]
    public function getTokenCountHandlesMultibyteCharacters(): void
    {
        $service = $this->createService('key');
        // 'ÄÖÜäöüß' = 7 multibyte characters
        $count = $service->getTokenCount('ÄÖÜäöüß');
        self::assertGreaterThanOrEqual(1, $count);
    }

    // ── embedText() — happy path ─────────────────────────────────────────────

    #[Test]
    public function embedTextReturnsEmbeddingVector(): void
    {
        $embedding = [0.001, 0.002, 0.003];
        $this->mockSuccessfulResponse([$embedding]);

        $service = $this->createService('sk-test');
        $result = $service->embedText('Hello world');

        self::assertSame($embedding, $result);
    }

    #[Test]
    public function embedTextReturnsEmptyArrayOnEmptyResponse(): void
    {
        $this->mockSuccessfulResponse([]);

        $service = $this->createService('sk-test');
        $result = $service->embedText('Hello');

        self::assertSame([], $result);
    }

    // ── embedTexts() — happy path ────────────────────────────────────────────

    #[Test]
    public function embedTextsReturnsEmbeddingsForMultipleTexts(): void
    {
        $embedding1 = [0.1, 0.2];
        $embedding2 = [0.3, 0.4];
        $this->mockSuccessfulResponse([$embedding1, $embedding2]);

        $service = $this->createService('sk-test');
        $result = $service->embedTexts(['First text', 'Second text']);

        self::assertCount(2, $result);
        self::assertSame($embedding1, $result[0]);
        self::assertSame($embedding2, $result[1]);
    }

    #[Test]
    public function embedTextsReturnsEmptyArrayForEmptyInput(): void
    {
        $this->mockSuccessfulResponse([]);

        $service = $this->createService('sk-test');
        $result = $service->embedTexts([]);

        self::assertSame([], $result);
    }

    #[Test]
    public function embedTextsPreservesInputOrder(): void
    {
        $this->requestFactory->method('request')->willReturnCallback(function () {
            // Return embeddings in reverse index order to test sorting
            return $this->makeResponse(200, json_encode([
                'data' => [
                    ['index' => 1, 'embedding' => [0.9, 0.8]],
                    ['index' => 0, 'embedding' => [0.1, 0.2]],
                    ['index' => 2, 'embedding' => [0.5, 0.6]],
                ],
            ], JSON_THROW_ON_ERROR));
        });

        $service = $this->createService('sk-test');
        $result = $service->embedTexts(['A', 'B', 'C']);

        self::assertCount(3, $result);
        self::assertSame([0.1, 0.2], $result[0]);
        self::assertSame([0.9, 0.8], $result[1]);
        self::assertSame([0.5, 0.6], $result[2]);
    }

    // ── embedTexts() — Request structure ─────────────────────────────────────

    #[Test]
    public function embedTextsSendsBearerTokenInAuthorizationHeader(): void
    {
        $this->requestFactory->expects(self::once())
            ->method('request')
            ->with(
                self::stringContains('api.openai.com'),
                'POST',
                self::callback(static function (array $options): bool {
                    return isset($options['headers']['Authorization'])
                        && $options['headers']['Authorization'] === 'Bearer sk-my-secret';
                }),
            )
            ->willReturn($this->makeResponse(200, '{"data":[]}'));

        $service = $this->createService('sk-my-secret');
        $service->embedTexts(['test']);
    }

    #[Test]
    public function embedTextsSendsModelAndDimensionsInBody(): void
    {
        $this->requestFactory->expects(self::once())
            ->method('request')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(static function (array $options): bool {
                    $body = json_decode($options['body'], true);
                    return ($body['model'] ?? '') === 'text-embedding-3-small'
                        && ($body['dimensions'] ?? 0) === 1536
                        && ($body['input'] ?? []) === ['Hello'];
                }),
            )
            ->willReturn($this->makeResponse(200, '{"data":[]}'));

        $service = $this->createService('key');
        $service->embedTexts(['Hello']);
    }

    #[Test]
    public function embedTextsSendsConfiguredDimensionsInBody(): void
    {
        $this->requestFactory->expects(self::once())
            ->method('request')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(static function (array $options): bool {
                    $body = json_decode($options['body'], true);
                    return ($body['dimensions'] ?? 0) === 256;
                }),
            )
            ->willReturn($this->makeResponse(200, '{"data":[]}'));

        $service = new OpenAiEmbeddingService($this->requestFactory, 'key', 256);
        $service->embedTexts(['Hello']);
    }

    #[Test]
    public function embedTextsSendsInputArrayInBody(): void
    {
        $this->requestFactory->expects(self::once())
            ->method('request')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(static function (array $options): bool {
                    $body = json_decode($options['body'], true);
                    return ($body['input'] ?? []) === ['First', 'Second', 'Third'];
                }),
            )
            ->willReturn($this->makeResponse(200, '{"data":[]}'));

        $service = $this->createService('key');
        $service->embedTexts(['First', 'Second', 'Third']);
    }

    // ── embedTexts() — error handling ───────────────────────────────────────

    #[Test]
    public function embedTextsThrowsRuntimeExceptionOnNon200Response(): void
    {
        $responseBody = $this->makeBodyMock('{"error":{"message":"Invalid API key"}}');
        $response = $this->makeResponseMock(401, $responseBody);

        $this->requestFactory->method('request')->willReturn($response);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/401/');

        $service = $this->createService('bad-key');
        $service->embedTexts(['test']);
    }

    #[Test]
    public function embedTextsThrowsRuntimeExceptionOn429Response(): void
    {
        $responseBody = $this->makeBodyMock('{"error":{"message":"Rate limit exceeded"}}');
        $response = $this->makeResponseMock(429, $responseBody);

        $this->requestFactory->method('request')->willReturn($response);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/429/');

        $service = $this->createService('key');
        $service->embedTexts(['test']);
    }

    #[Test]
    public function embedTextsThrowsRuntimeExceptionOnInvalidJsonResponse(): void
    {
        $responseBody = $this->makeBodyMock('not-json');
        $response = $this->makeResponseMock(200, $responseBody);

        $this->requestFactory->method('request')->willReturn($response);

        $this->expectException(\JsonException::class);

        $service = $this->createService('key');
        $service->embedTexts(['test']);
    }

    #[Test]
    public function embedTextThrowsRuntimeExceptionOnApiError(): void
    {
        $responseBody = $this->makeBodyMock('{"error":{"message":"Rate limit"}}');
        $response = $this->makeResponseMock(500, $responseBody);

        $this->requestFactory->method('request')->willReturn($response);

        $this->expectException(RuntimeException::class);

        $service = $this->createService('key');
        $service->embedText('Hello');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function createService(string $apiKey): OpenAiEmbeddingService
    {
        return new OpenAiEmbeddingService($this->requestFactory, $apiKey);
    }

    /**
     * @param list<list<float>> $embeddings
     */
    private function mockSuccessfulResponse(array $embeddings): void
    {
        $data = [];
        foreach ($embeddings as $i => $embedding) {
            $data[] = ['index' => $i, 'embedding' => $embedding];
        }

        $this->requestFactory->method('request')->willReturn(
            $this->makeResponse(200, json_encode(['data' => $data], JSON_THROW_ON_ERROR)),
        );
    }

    private function makeResponse(int $statusCode, string $body): ResponseInterface&MockObject
    {
        return $this->makeResponseMock($statusCode, $this->makeBodyMock($body));
    }

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
