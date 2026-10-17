<?php

namespace App\Setup\SaveProfile;

use App\Shared\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class SaveProfileController extends AbstractController
{
    #[Route('/setup', name: 'setup_save_profile', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, EntityManagerInterface $em, #[CurrentUser] User $user): Response
    {
        $form = $this->createForm(SaveProfileType::class, [
            'shortBio' => $user->getShortBio(),
            'longBio' => $user->getLongBio(),
            'languageLevel' => $user->getLanguageLevel(),
        ])->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{shortBio: string, longBio: string, languageLevel: string} $data */
            $data = $form->getData();
            $user->updateProfile($data['shortBio'], $data['longBio'], $data['languageLevel']);
            $em->flush();

            return $this->redirectToRoute('setup_save_profile');
        }

        return $this->render('setup/save_profile/index.html.twig', ['form' => $form]);
    }
}
