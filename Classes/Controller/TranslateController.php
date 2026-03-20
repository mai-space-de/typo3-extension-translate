<?php

declare(strict_types=1);

namespace Maispace\Translate\Controller;

use Maispace\Translate\Service\TranslationServiceFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Backend AJAX controller that translates a single record's text fields
 * using the configured translation provider.
 *
 * Supported tables: pages, tt_content (all maispace elements).
 *
 * Translatable fields per table:
 *   pages      – title, subtitle, nav_title, abstract, description, keywords,
 *                seo_title, og_title, og_description, twitter_title, twitter_description
 *   tt_content – header, subheader, bodytext, header_link
 */
final class TranslateController
{
    /**
     * Fields that are candidates for translation, grouped by table.
     * Only non-empty fields that exist on the record are actually translated.
     */
    private const TRANSLATABLE_FIELDS = [
        'tt_content' => [
            'header',
            'subheader',
            'bodytext',
            'header_link',
        ],
        'pages' => [
            'title',
            'subtitle',
            'nav_title',
            'abstract',
            'description',
            'keywords',
            'seo_title',
            'og_title',
            'og_description',
            'twitter_title',
            'twitter_description',
        ],
    ];

    public function __construct(
        private readonly TranslationServiceFactory $translationServiceFactory,
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Translates text fields of a record and returns the translated values.
     *
     * Query parameters:
     *   table          – 'pages' or 'tt_content'
     *   uid            – numeric record UID
     *   targetLanguage – target language code (e.g. 'DE', 'EN', 'FR')
     *   provider       – 'deepl' (default) or 'openai'
     *   sourceLanguage – optional source language code; omit for auto-detection
     */
    public function translateAction(ServerRequestInterface $request): ResponseInterface
    {
        $params = array_merge(
            $request->getQueryParams(),
            (array)($request->getParsedBody() ?? [])
        );

        $table = (string)($params['table'] ?? '');
        $uid = (int)($params['uid'] ?? 0);
        $targetLanguage = trim((string)($params['targetLanguage'] ?? ''));
        $provider = trim((string)($params['provider'] ?? 'deepl'));
        $sourceLanguage = trim((string)($params['sourceLanguage'] ?? 'auto'));

        if ($table === '' || $uid <= 0 || $targetLanguage === '') {
            return new JsonResponse(['error' => 'Missing required parameters: table, uid, targetLanguage'], 400);
        }

        if (!array_key_exists($table, self::TRANSLATABLE_FIELDS)) {
            return new JsonResponse(['error' => sprintf('Unsupported table "%s"', $table)], 400);
        }

        $record = $this->fetchRecord($table, $uid);
        if ($record === null) {
            return new JsonResponse(['error' => sprintf('Record %d not found in table %s', $uid, $table)], 404);
        }

        try {
            $service = $this->translationServiceFactory->get($provider);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        if (!$service->isAvailable()) {
            return new JsonResponse(['error' => sprintf('Provider "%s" is not configured', $provider)], 503);
        }

        $fieldsToTranslate = $this->resolveFieldsToTranslate($table, $record);
        $translations = [];

        foreach ($fieldsToTranslate as $field) {
            $value = (string)($record[$field] ?? '');
            if (trim($value) === '') {
                continue;
            }

            try {
                $translations[$field] = $service->translate($value, $targetLanguage, $sourceLanguage);
            } catch (\RuntimeException $e) {
                return new JsonResponse(['error' => 'Translation failed: ' . $e->getMessage()], 500);
            }
        }

        return new JsonResponse(['translations' => $translations]);
    }

    /**
     * Fetch a single record from the database.
     */
    private function fetchRecord(string $table, int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);

        $record = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAssociative();

        return $record ?: null;
    }

    /**
     * Returns the subset of translatable fields that actually exist in the record
     * and have a non-empty value.
     *
     * @return string[]
     */
    private function resolveFieldsToTranslate(string $table, array $record): array
    {
        $candidates = self::TRANSLATABLE_FIELDS[$table] ?? [];
        return array_filter($candidates, static fn(string $field) => array_key_exists($field, $record));
    }
}
