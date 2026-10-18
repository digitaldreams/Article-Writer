<?php

namespace App\Author\EditBio;

use App\Shared\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class EditBioController extends AbstractController
{
    #[Route('/author', name: 'author_edit_bio', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, EntityManagerInterface $em, #[CurrentUser] User $user): Response
    {
        $form = $this->createForm(EditBioType::class, [
            'shortBio' => $user->getShortBio(),
            'longBio' => $user->getLongBio(),
            'languageLevel' => $user->getLanguageLevel(),
        ])->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{shortBio: string, longBio: string, languageLevel: string} $data */
            $data = $form->getData();
            $user->updateProfile($data['shortBio'], $data['longBio'], $data['languageLevel']);
            $em->flush();

            return $this->redirectToRoute('author_edit_bio');
        }

        return $this->render('author/edit_bio/index.html.twig', ['form' => $form]);
    }
}
