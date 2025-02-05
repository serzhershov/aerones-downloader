<?php

namespace App\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage('async')]
class DownloadMessage
{
    private array $downloadIds;
    private int $retryCount;
    private int $maxRetries;

    public function __construct(array $downloadIds, int $retryCount = 0, int $maxRetries = 3)
    {
        $this->downloadIds = $downloadIds;
        $this->retryCount = $retryCount;
        $this->maxRetries = $maxRetries;
    }

    public function getDownloadIds(): array
    {
        return $this->downloadIds;
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }
}