<?php

namespace App\Author\AddFeeds;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;

/** @extends AbstractType<array{url: string}> */
final class AddFeedType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('url', UrlType::class, [
                'label' => 'Feed URL',
                'default_protocol' => null,
                'constraints' => [new NotBlank(), new Url(protocols: ['http', 'https'], requireTld: false)],
            ])
            ->add('add', SubmitType::class, ['label' => 'Add feed']);
    }
}
