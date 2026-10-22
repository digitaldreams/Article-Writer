<?php

namespace App\Author\EditWritingProfile;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<array<string, string|null>> */
final class EditWritingProfileType extends AbstractType
{
    public const STRUCTURE = ['openingType', 'orderingHabit', 'sectionCount', 'runningExample', 'closing'];
    public const VOICE = ['tone', 'sentenceStyle', 'devices', 'codeHabit', 'samples'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $required = ['constraints' => [new NotBlank()]];
        // Voice is optional: it is empty until the first analysis, and must not block saving the structure.
        $optional = ['required' => false];

        $builder
            ->add('openingType', TextareaType::class, $required + [
                'label' => 'Opening type',
                'help' => 'How you usually start an article. Example: a real problem story, a question, a definition.',
            ])
            ->add('orderingHabit', TextareaType::class, $required + [
                'label' => 'Ordering habit',
                'help' => 'The order you usually put sections in. Example: simple to advanced, step by step, by feature.',
            ])
            ->add('sectionCount', TextareaType::class, $required + [
                'label' => 'Section count',
                'help' => 'How many sections your articles usually have. Example: 5 to 7.',
            ])
            ->add('runningExample', TextareaType::class, $required + [
                'label' => 'Running example',
                'help' => 'Whether one example runs through the whole article, or each section has its own.',
            ])
            ->add('closing', TextareaType::class, $required + [
                'label' => 'Closing',
                'help' => 'How you usually end an article. Example: a summary, next steps, a call to action.',
            ])
            ->add('tone', TextareaType::class, $optional + [
                'label' => 'Tone',
                'help' => 'The feeling of your writing. Example: friendly and direct, dry humor.',
            ])
            ->add('sentenceStyle', TextareaType::class, $optional + [
                'label' => 'Sentence style',
                'help' => 'Sentence length and who you talk to. Example: short sentences, talks to the reader as "you".',
            ])
            ->add('devices', TextareaType::class, $optional + [
                'label' => 'Devices',
                'help' => 'Tricks you use again and again. Example: questions to the reader, analogies, ❌/✅ comparisons.',
            ])
            ->add('codeHabit', TextareaType::class, $optional + [
                'label' => 'Code habit',
                'help' => 'How you show and explain code. Example: small snippet first, then a line-by-line walk.',
            ])
            ->add('samples', TextareaType::class, $optional + [
                'label' => 'Samples (your own paragraphs, a blank line between them)',
                'help' => '2 to 3 of your own paragraphs. The AI matches their sound and never copies them. Analyze picks them; check they are your real words.',
            ])
            ->add('save', SubmitType::class);
    }
}
