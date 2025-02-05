<?php

namespace App\Controller;

use App\Entity\Download;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class ApiController extends AbstractController
{
    #[Route('/api/downloads/progress', name: 'api_downloads_progress', methods: ['GET'])]
    public function getDownloadsProgress(EntityManagerInterface $entityManager): JsonResponse
    {
        $downloads = $entityManager->getRepository(Download::class)->findAll();
        $progressData = [];
        $shouldPoll = false;

        foreach ($downloads as $download) {
            $progressData[] = [
                'id' => $download->getId(),
                'filename' => $download->getFilename(),
                'status' => $download->getStatus(),
                'progress' => $download->getProgress(),
            ];

            // Check if any download is still processing
            if (in_array($download->getStatus(), ['queued', 'downloading'])) {
                $shouldPoll = true;
            }
        }

        return $this->json([
            'downloads' => $progressData,
            'shouldPoll' => $shouldPoll,
        ]);
    }
}