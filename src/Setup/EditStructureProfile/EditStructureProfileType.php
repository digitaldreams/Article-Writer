<?php

namespace App\Setup\EditStructureProfile;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<array<string, string>> */
final class EditStructureProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $required = ['constraints' => [new NotBlank()]];

        $builder
            ->add('openingType', TextType::class, $required + ['label' => 'Opening type'])
            ->add('orderingHabit', TextType::class, $required + ['label' => 'Ordering habit'])
            ->add('sectionCount', TextType::class, $required + ['label' => 'Section count'])
            ->add('runningExample', TextType::class, $required + ['label' => 'Running example'])
            ->add('closing', TextType::class, $required + ['label' => 'Closing'])
            ->add('save', SubmitType::class);
    }
}
