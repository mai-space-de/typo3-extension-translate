<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Domain\Model;

final class TranslationLog
{
    public function __construct(
        private readonly int $uid,
        private readonly string $recordTable,
        private readonly int $recordUid,
        private readonly string $field,
        private readonly string $sourceLanguage,
        private readonly string $targetLanguage,
        private readonly string $provider,
        private readonly string $status,
    ) {}

    public function getUid(): int
    {
        return $this->uid;
    }

    public function getRecordTable(): string
    {
        return $this->recordTable;
    }

    public function getRecordUid(): int
    {
        return $this->recordUid;
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function getSourceLanguage(): string
    {
        return $this->sourceLanguage;
    }

    public function getTargetLanguage(): string
    {
        return $this->targetLanguage;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }
}
