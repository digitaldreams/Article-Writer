<?php

namespace App\Planning\Brainstorm;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** @extends AbstractType<array<string, mixed>> */
final class AddReferencesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('links', TextareaType::class, [
                'required' => false,
                'help' => 'One URL per line',
                'constraints' => [new Callback($this->validateUrls(...))],
            ])
            ->add('add', SubmitType::class, ['label' => 'Add references']);
    }

    private function validateUrls(?string $links, ExecutionContextInterface $context): void
    {
        $context->getValidator()->inContext($context)->validate(UrlLines::split($links), new All(new Url()));
    }
}
