<?php

namespace App\Planning\CreatePlan;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<array<string, ?string>> */
final class CreatePlanType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('topic', TextType::class, ['constraints' => [new NotBlank(), new Length(max: 200)]])
            ->add('idea', TextareaType::class, ['required' => false])
            ->add('create', SubmitType::class);
    }
}
