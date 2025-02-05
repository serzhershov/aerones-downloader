<?php

namespace App\Controller;

use App\Entity\Download;
use App\Form\DownloadFormType;
use App\Message\DownloadMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AdminController extends AbstractController
{
    #[Route('/', name: 'admin_index')]
    public function index(EntityManagerInterface $entityManager): Response
    {
        $downloads = $entityManager->getRepository(Download::class)->findAll();

        return $this->render('admin/index.html.twig', [
            'downloads' => $downloads,
        ]);
    }

    #[Route('/admin/new', name: 'admin_new')]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $download = new Download();
        $form = $this->createForm(DownloadFormType::class, $download);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $download->setStatus('queued');
            $download->setProgress(0);
            $entityManager->persist($download);
            $entityManager->flush();

            return $this->redirectToRoute('admin_index');
        }

        return $this->render('admin/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/admin/show/{id}', name: 'admin_show')]
    public function show(Download $download): Response
    {
        return $this->render('admin/show.html.twig', [
            'download' => $download,
        ]);
    }

    #[Route('/admin/force-download-selected', name: 'admin_force_download_selected', methods: ['POST'])]
    public function forceDownloadSelected(Request $request, MessageBusInterface $messageBus, EntityManagerInterface $entityManager): Response
    {
        $selectedDownloadIds = $request->request->all('selectedDownloads');
        $downloads = $entityManager->getRepository(Download::class)->findBy(['id' => $selectedDownloadIds]);

        $downloadIds = [];
        foreach ($downloads as $download) {
            $download->setStatus('queued');
            $entityManager->flush();
            $downloadIds[] = $download->getId();
        }

        $messageBus->dispatch(new DownloadMessage($downloadIds));

        return $this->redirectToRoute('admin_index');
    }
}