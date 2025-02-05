<?php

namespace App\MessageHandler;

use App\Entity\Download;
use App\Message\DownloadMessage;
use App\Service\DownloaderService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Handler\BatchHandlerInterface;
use Symfony\Component\Messenger\Handler\BatchHandlerTrait;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
class DownloadBatchHandler implements BatchHandlerInterface
{
    use BatchHandlerTrait;

    private DownloaderService $downloader;
    private EntityManagerInterface $em;
    private LoggerInterface $logger;
    private MessageBusInterface $messageBus;

    public function __construct(
        DownloaderService $downloader,
        EntityManagerInterface $em,
        LoggerInterface $logger,
        MessageBusInterface $messageBus
    ) {
        $this->downloader = $downloader;
        $this->em = $em;
        $this->logger = $logger;
        $this->messageBus = $messageBus;
    }

    public function __invoke(DownloadMessage $message): void
    {
        $this->handle($message, null);
    }

    private function process(array $jobs): void
    {
        $loop = Loop::get();
        $promises = [];
        $downloads = [];
        $failedDownloads = [];

        foreach ($jobs as [$message, $ack]) {
            if (!$message instanceof DownloadMessage) {
                continue;
            }

            // Process each download ID in the message concurrently
            foreach ($message->getDownloadIds() as $downloadId) {
                $download = $this->em->find(Download::class, $downloadId);
                $downloads[$downloadId] = $download;
                if (!$download || $download->getStatus() === 'completed') {
                    continue;
                }

                $download->setStatus('downloading');
                $this->em->flush();

                $promises[] = $this->downloader->downloadFile($download)
                    ->then(
                        function () use ($download) {
                            $download->setStatus('completed');
                            $this->em->flush();
                        },
                        function (\Throwable $e) use ($download, $message, &$failedDownloads) {
                            $failedDownloads[] = $download->getId();
                            $this->logger->error('Download failed', [
                                'filename' => $download->getFilename(),
                                'error' => $e->getMessage(),
                                'retryCount' => $message->getRetryCount(),
                            ]);
                            return \React\Promise\reject($e);
                        }
                    );
            }
        }

        \React\Promise\all($promises)
            ->then(function () use ($jobs, $loop) {
                foreach ($jobs as [$message, $ack]) {
                    $ack->ack($message);
                }

                $this->em->clear();
                $loop->stop();
            })
            ->otherwise(function (\Throwable $e) use ($jobs, $loop, $failedDownloads, $downloads) {
                foreach ($jobs as [$message, $ack]) {
                    $ack->nack($e);
                    $this->logger->error('Batch processing failed', ['error' => $e->getMessage()]);
                }

                if ($message->getRetryCount() < $message->getMaxRetries()) {
                    $this->messageBus->dispatch(
                        new DownloadMessage(
                            $failedDownloads,
                            $message->getRetryCount() + 1,
                            $message->getMaxRetries()
                        )
                    );
                    $this->logger->info('Retry scheduled', [
                        'ids' => $failedDownloads,
                        'attempt' => $message->getRetryCount() + 1,
                    ]);
                } else {
                    // Mark the download as failed
                    foreach ($failedDownloads as $downloadId) {
                        $downloads[$downloadId]->setStatus('failed');
                    }
                    $this->em->flush();
                    $this->logger->critical('Max retries exceeded', [
                        'ids' => $failedDownloads,
                    ]);
                }
                $loop->stop();
            });

        $loop->run();
    }

    private function getBatchSize(): int
    {
        return 5;
    }
}