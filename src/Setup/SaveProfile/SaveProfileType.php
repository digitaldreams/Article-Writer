<?php

namespace App\Setup\SaveProfile;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<array<string, string>> */
final class SaveProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $required = ['constraints' => [new NotBlank()]];

        $builder
            ->add('shortBio', TextareaType::class, $required + ['label' => 'Short bio'])
            ->add('longBio', TextareaType::class, $required + ['label' => 'Long bio'])
            ->add('languageLevel', TextType::class, $required + ['label' => 'Language level'])
            ->add('save', SubmitType::class);
    }
}
