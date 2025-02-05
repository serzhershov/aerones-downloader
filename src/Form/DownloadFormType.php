<?php

namespace App\Form;

use App\Entity\Download;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DownloadFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('filename', TextType::class, [
                'label' => 'Filename',
                'attr' => ['placeholder' => 'Enter the filename'],
            ])
            ->add('url', UrlType::class, [
                'label' => 'URL',
                'attr' => ['placeholder' => 'Enter the file URL'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Download::class,
        ]);
    }
}
