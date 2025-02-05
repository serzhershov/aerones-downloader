<?php

namespace App\Service;


use App\Entity\Download;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use React\Dns\Resolver\Factory;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Promise\PromiseInterface;
use React\Socket\Connector;
use React\Promise\Deferred;
use Symfony\Component\Filesystem\Filesystem;


class DownloaderService
{
    private Browser $client;
    private Filesystem $filesystem;
    private string $tempDir;
    private string $completedDir;
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;

    public function __construct(EntityManagerInterface $entityManager, LoggerInterface $logger)
    {
        $loop = Loop::get();
        $dnsResolverFactory = new Factory();
        $dns = $dnsResolverFactory->create('1.1.1.1', $loop);
        $connector = new Connector($loop, [
            'timeout' => 120,
            'tls' => ['timeout' => 120],
            'dns' => $dns,
            'happy_eyeballs' => false,
            'tcp' => [
                'bindto' => '0.0.0.0:0',
            ],
        ]);

        $this->client = new Browser($loop, $connector);
        $this->filesystem = new Filesystem();
        $this->tempDir = __DIR__ . '/../../var/temp';
        $this->completedDir = __DIR__ . '/../../var/completed';
        $this->entityManager = $entityManager;
        $this->logger = $logger;

        $this->filesystem->mkdir([$this->tempDir, $this->completedDir]);
    }

    public function getTempDir(): string
    {
        return $this->tempDir;
    }

    public function getCompletedDir(): string
    {
        return $this->completedDir;
    }

    public function downloadFile(Download $download, int $retryCount = 0): PromiseInterface
    {
        $deferred = new Deferred();
        $tempFilePath = $this->tempDir . '/' . $download->getFilename();
        $completedFilePath = $this->completedDir . '/' . $download->getFilename();

        $this->logger->info("Starting download process", [
            'filename' => $download->getFilename(),
            'url' => $download->getUrl(),
            'temp_path' => $tempFilePath,
            'retry_count' => $retryCount
        ]);

        if ($this->filesystem->exists($completedFilePath)) {
            $this->updateDownloadStatus($download, 'completed', 100);
            return \React\Promise\resolve("File already downloaded");
        }

        $startByte = $this->filesystem->exists($tempFilePath) ? filesize($tempFilePath) : 0;

        $headers = [
            'User-Agent' => 'MyDownloader/1.0',
            'Accept-Encoding' => 'identity',
        ];

        if ($startByte > 0) {
            $headers['Range'] = "bytes=$startByte-";
        }

        $this->updateDownloadStatus($download, 'downloading');

        $fileHandle = @fopen($tempFilePath, 'ab+');
        if ($fileHandle === false) {
            $this->logger->error("Failed to open file for writing", [
                'filename' => $download->getFilename(),
                'temp_path' => $tempFilePath,
                'error' => error_get_last()
            ]);
            $deferred->reject(new \RuntimeException("Failed to open file for writing"));
            return $deferred->promise();
        }

        $downloadedBytes = $startByte;
        $contentLength = null;

        $this->client->requestStreaming('GET', $download->getUrl(), $headers)
            ->then(function (ResponseInterface $response) use (
                $fileHandle,
                $download,
                $startByte,
                $deferred,
                $tempFilePath,
                $completedFilePath,
                &$downloadedBytes,
                &$contentLength
            ) {
                // Validate response
                if ($startByte > 0 && $response->getStatusCode() !== 206) {
                    fclose($fileHandle);
                    $this->logger->error("Server doesn't support resuming downloads", [
                        'status_code' => $response->getStatusCode(),
                        'headers' => $response->getHeaders()
                    ]);
                    $this->updateDownloadStatus($download, 'failed');
                    $deferred->reject(new \RuntimeException("Resuming not supported"));
                    return;
                }

                // Determine total file size
                $contentLength = $this->extractContentLength($response, $startByte);
                $download->setContentLength($contentLength);
                $this->entityManager->flush();

                $this->logger->info("Download response received", [
                    'filename' => $download->getFilename(),
                    'content_length' => $contentLength,
                    'start_byte' => $startByte,
                    'status_code' => $response->getStatusCode()
                ]);

                // Pipe response to file stream
                $response->getBody()->on('data', function ($chunk) use (
                    &$downloadedBytes,
                    $contentLength,
                    $download,
                    $fileHandle
                ) {
                    $chunkLength = strlen($chunk);
                    $bytesWritten = fwrite($fileHandle, $chunk);

                    if ($bytesWritten !== $chunkLength) {
                        $this->logger->error("Partial chunk write", [
                            'chunk_length' => $chunkLength,
                            'bytes_written' => $bytesWritten
                        ]);
                    }

                    $downloadedBytes += $bytesWritten;

                    // Update progress
                    $progress = min(100, (int)(($downloadedBytes / $contentLength) * 100));
                    if ($download->getProgress() - $progress >= 5) {
                        $this->updateDownloadStatus($download, 'downloading', $progress);
                    }
                });

                // Handle successful download
                $response->getBody()->on('end', function () use (
                    $fileHandle,
                    &$downloadedBytes,
                    $contentLength,
                    $download,
                    $deferred,
                    $tempFilePath,
                    $completedFilePath
                ) {
                    fclose($fileHandle);

                    $this->logger->info("Download stream ended", [
                        'filename' => $download->getFilename(),
                        'downloaded_bytes' => $downloadedBytes,
                        'expected_content_length' => $contentLength,
                        'file_size' => filesize($tempFilePath)
                    ]);

                    if ($downloadedBytes >= $contentLength) {
                        try {
                            $this->filesystem->mkdir($this->completedDir);
                            $this->filesystem->rename($tempFilePath, $completedFilePath, true);

                            $this->updateDownloadStatus($download, 'completed', 100);

                            $this->logger->info("Download completed successfully", [
                                'filename' => $download->getFilename(),
                                'temp_path' => $tempFilePath,
                                'completed_path' => $completedFilePath
                            ]);

                            $deferred->resolve(true);
                        } catch (\Exception $e) {
                            $this->logger->error("Failed to move completed file", [
                                'filename' => $download->getFilename(),
                                'error' => $e->getMessage(),
                                'temp_path' => $tempFilePath,
                                'completed_path' => $completedFilePath
                            ]);

                            $this->updateDownloadStatus($download, 'failed');
                            $deferred->reject($e);
                        }
                    } else {
                        $this->logger->error("Incomplete download", [
                            'filename' => $download->getFilename(),
                            'downloaded_bytes' => $downloadedBytes,
                            'expected_content_length' => $contentLength,
                            'file_size' => filesize($tempFilePath)
                        ]);

                        $this->updateDownloadStatus($download, 'failed');
                        $deferred->reject(new \RuntimeException("Incomplete download"));
                    }
                });

                // Handle stream errors
                $response->getBody()->on('error', function ($error) use (
                    $fileHandle,
                    $deferred,
                    $download
                ) {
                    fclose($fileHandle);
                    $this->logger->error("Download stream error", [
                        'filename' => $download->getFilename(),
                        'error' => $error->getMessage()
                    ]);

                    $this->updateDownloadStatus($download, 'failed');
                    $deferred->reject($error);
                });
            })
            ->otherwise(function ($error) use (
                $fileHandle,
                $deferred,
                $download,
                $retryCount
            ) {
                if (is_resource($fileHandle)) {
                    fclose($fileHandle);
                }

                $this->logger->error("Download request failed", [
                    'filename' => $download->getFilename(),
                    'error' => $error->getMessage(),
                    'retry_count' => $retryCount
                ]);

                if ($retryCount < 5) {
                    $this->logger->info("Retrying download", [
                        'filename' => $download->getFilename(),
                        'attempt' => $retryCount + 1
                    ]);
                    $deferred->resolve($this->downloadFile($download, $retryCount + 1));
                } else {
                    $this->updateDownloadStatus($download, 'failed');
                    $deferred->reject(new \RuntimeException("Download failed after 5 retries"));
                }
            });

        return $deferred->promise();
    }

    private function extractContentLength(ResponseInterface $response, int $startByte): int
    {
        $contentRange = $response->getHeaderLine('Content-Range');
        if (preg_match('/bytes \d+-\d+\/(\d+)/', $contentRange, $matches)) {
            return (int)$matches[1];
        }

        // Fallback to Content-Length header
        $contentLength = (int)$response->getHeaderLine('Content-Length');

        // If no content length, use start byte plus total downloaded
        return $contentLength > 0 ? $contentLength + $startByte : PHP_INT_MAX;
    }

    public function updateDownloadStatus(Download $download, string $status, int $progress = 0): void
    {
        try {
            $this->entityManager->wrapInTransaction(function () use ($download, $status, $progress) {
                $download->setStatus($status);
                $download->setProgress($progress);
                $this->entityManager->flush();

                $this->logger->info("Download status updated", [
                    'filename' => $download->getFilename(),
                    'status' => $status,
                    'progress' => $progress
                ]);
            });
        } catch (\Exception $e) {
            $this->logger->error("Failed to update download status", [
                'filename' => $download->getFilename(),
                'status' => $status,
                'progress' => $progress,
                'error' => $e->getMessage()
            ]);
        }
    }
}