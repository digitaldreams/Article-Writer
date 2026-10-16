<?php

namespace App\Planning\CreatePlan;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** @extends AbstractType<array<string, mixed>> */
final class CreatePlanType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $files = ['multiple' => true, 'required' => false];

        $builder
            ->add('topic', TextType::class, ['constraints' => [new NotBlank(), new Length(max: 200)]])
            ->add('audience', TextType::class, ['required' => false])
            ->add('readerLevel', TextType::class, ['required' => false, 'label' => 'Reader level'])
            ->add('roughFlow', TextareaType::class, ['required' => false, 'label' => 'Rough flow'])
            ->add('links', TextareaType::class, [
                'required' => false,
                'help' => 'One URL per line',
                'constraints' => [new Callback($this->validateUrls(...))],
            ])
            ->add('docs', FileType::class, $files + [
                'constraints' => [new All(new File(mimeTypes: ['text/markdown', 'text/x-markdown', 'text/plain', 'application/pdf']))],
            ])
            ->add('images', FileType::class, $files + [
                'constraints' => [new All(new File(mimeTypes: ['image/*']))],
            ])
            ->add('create', SubmitType::class);
    }

    private function validateUrls(?string $links, ExecutionContextInterface $context): void
    {
        $context->getValidator()->inContext($context)->validate(UrlLines::split($links), new All(new Url()));
    }
}
