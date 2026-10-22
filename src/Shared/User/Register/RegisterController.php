<?php

namespace App\Shared\User\Register;

use App\Shared\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/** Open registration (the app runs on 127.0.0.1). The new author is logged in and lands in the onboarding wizard. */
final class RegisterController extends AbstractController
{
    #[Route('/register', name: 'user_register', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher, Security $security): Response
    {
        $form = $this->createForm(RegisterType::class)->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{email: string, password: string} $data */
            $data = $form->getData();
            if (null !== $em->getRepository(User::class)->findOneBy(['email' => $data['email']])) {
                $form->get('email')->addError(new FormError('This email already has an account.'));
            } else {
                $user = new User($data['email']);
                $user->setPassword($hasher->hashPassword($user, $data['password']));
                $em->persist($user);
                $em->flush();
                $security->login($user, 'form_login', 'main');

                // ponytail: the onboarding listener sends a new author from here to the wizard.
                return $this->redirectToRoute('planning_list_plans', status: Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('shared/user/register/register.html.twig', ['form' => $form],
            new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
