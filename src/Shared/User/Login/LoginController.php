<?php

namespace App\Shared\User\Login;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class LoginController extends AbstractController
{
    /** form_login handles the POST; this only renders the form. */
    #[Route('/login', name: 'user_login', methods: ['GET', 'POST'])]
    public function __invoke(AuthenticationUtils $auth): Response
    {
        return $this->render('shared/user/login/login.html.twig', [
            'last_email' => $auth->getLastUsername(),
            'error' => $auth->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'user_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('The firewall handles logout.');
    }
}
