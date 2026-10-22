<?php

namespace App\Shared\User\Register;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<array{email: string, password: string}> */
final class RegisterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, ['constraints' => [new NotBlank(), new Email()], 'attr' => ['autocomplete' => 'email']])
            ->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'The passwords do not match.',
                'constraints' => [new NotBlank()],
                'first_options' => ['label' => 'Password', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Repeat password', 'attr' => ['autocomplete' => 'new-password']],
            ])
            ->add('register', SubmitType::class, ['label' => 'Create account']);
    }
}
