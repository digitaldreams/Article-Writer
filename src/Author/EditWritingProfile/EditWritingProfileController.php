<?php

namespace App\Author\EditWritingProfile;

use App\Author\WritingNote;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use App\Shared\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class EditWritingProfileController extends AbstractController
{
    #[Route('/author/profile', name: 'author_edit_writing_profile', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, EntityManagerInterface $em, #[CurrentUser] User $user): Response
    {
        $form = $this->profileForm($user)->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, string|null> $data */
            $data = array_map(static fn (?string $v): string => (string) $v, $form->getData());
            $user->setWritingProfile(
                array_intersect_key($data, array_flip(EditWritingProfileType::STRUCTURE)),
                array_intersect_key($data, array_flip(EditWritingProfileType::VOICE)),
            );
            $em->flush();

            return $this->redirectToRoute('author_edit_writing_profile');
        }

        return $this->render('author/edit_writing_profile/index.html.twig', [
            'form' => $form,
            'jobs' => $this->jobs($em),
            'analyzed' => $em->getRepository(WritingNote::class)->count([]),
            'library' => $em->getRepository(Article::class)->count(['state' => ArticleState::Imported]),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** Rendered inside the onboarding wizard: the same form, posting to /author/profile. */
    public function form(#[CurrentUser] User $user): Response
    {
        return $this->render('author/edit_writing_profile/_form.html.twig', ['form' => $this->profileForm($user)]);
    }

    /** @return FormInterface<array<string, string|null>> */
    private function profileForm(User $user): FormInterface
    {
        $profile = $user->getWritingProfile();

        return $this->createForm(EditWritingProfileType::class, $profile['structure'] + $profile['voice'], ['action' => $this->generateUrl('author_edit_writing_profile')]);
    }

    /** @return list<array{label: string, job: LlmJob}> */
    private function jobs(EntityManagerInterface $em): array
    {
        /** @var list<LlmJob> $jobs */
        $jobs = $em->getRepository(LlmJob::class)->createQueryBuilder('j')
            ->leftJoin('j.article', 'a')->addSelect('a')
            ->where('j.step IN (:steps)')->setParameter('steps', ['extract_writing_notes', 'merge_writing_profile'])
            ->orderBy('j.id', 'DESC')->setMaxResults(100)
            ->getQuery()->getResult();

        return array_map(static fn (LlmJob $job): array => [
            'label' => 'extract_writing_notes' === $job->getStep() ? ($job->getArticle()?->getTitle() ?? '') : 'Merge into one profile',
            'job' => $job,
        ], $jobs);
    }
}
