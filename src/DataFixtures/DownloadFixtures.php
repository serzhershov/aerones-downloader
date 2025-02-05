<?php

namespace App\DataFixtures;

use App\Entity\Download;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class DownloadFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $downloads = [
            [
                'filename' => 'output_20sec.mp4',
                'url' => 'https://storage.googleapis.com/public_test_access_ae/output_20sec.mp4',
                'status' => 'pending',
            ],
            [
                'filename' => 'output_30sec.mp4',
                'url' => 'https://storage.googleapis.com/public_test_access_ae/output_30sec.mp4',
                'status' => 'pending',
            ],
            [
                'filename' => 'output_40sec.mp4',
                'url' => 'https://storage.googleapis.com/public_test_access_ae/output_40sec.mp4',
                'status' => 'pending',
            ],
            [
                'filename' => 'output_50sec.mp4',
                'url' => 'https://storage.googleapis.com/public_test_access_ae/output_50sec.mp4',
                'status' => 'pending',
            ],
            [
                'filename' => 'output_60sec.mp4',
                'url' => 'https://storage.googleapis.com/public_test_access_ae/output_60sec.mp4',
                'status' => 'pending',
            ],
        ];

        foreach ($downloads as $data) {
            $download = new Download();
            $download->setFilename($data['filename']);
            $download->setUrl($data['url']);
            $download->setStatus($data['status']);
            $manager->persist($download);
        }

        $manager->flush();
    }
}