<?php

namespace App\Setup\EditStructureProfile;

use App\Shared\Llm\LlmJob;
use App\Shared\User\User;
use App\Shared\WriterProfile\WriterProfile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class EditStructureProfileController extends AbstractController
{
    #[Route('/setup/profile', name: 'setup_edit_structure_profile', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, EntityManagerInterface $em, #[CurrentUser] User $user): Response
    {
        $profile = $em->getRepository(WriterProfile::class)->findOneBy([]) ?? new WriterProfile($user);
        $form = $this->createForm(EditStructureProfileType::class, $profile->getStructureProfile() ?? [])->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, string> $data */
            $data = $form->getData();
            $profile->setStructureProfile($data);
            $em->persist($profile);
            $em->flush();

            return $this->redirectToRoute('setup_edit_structure_profile');
        }

        return $this->render('setup/edit_structure_profile/index.html.twig', [
            'form' => $form,
            'jobs' => $this->jobs($em),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** @return list<array{label: string, job: LlmJob}> */
    private function jobs(EntityManagerInterface $em): array
    {
        /** @var list<LlmJob> $jobs */
        $jobs = $em->getRepository(LlmJob::class)->createQueryBuilder('j')
            ->leftJoin('j.article', 'a')->addSelect('a')
            ->where('j.step IN (:steps)')->setParameter('steps', ['extract_structure_notes', 'merge_structure_profile'])
            ->orderBy('j.id', 'DESC')->setMaxResults(100)
            ->getQuery()->getResult();

        return array_map(static fn (LlmJob $job): array => [
            'label' => 'extract_structure_notes' === $job->getStep() ? ($job->getArticle()?->getTitle() ?? '') : 'Merge into one profile',
            'job' => $job,
        ], $jobs);
    }
}
