<?php

namespace App\Setup\ImportArticles;

use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobs;
use App\Shared\Llm\LlmJobStatus;
use App\Shared\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ImportArticlesController extends AbstractController
{
    /** Rendered inside /setup: the upload form and one job card per imported file. */
    public function panel(EntityManagerInterface $em): Response
    {
        return $this->render('setup/import_articles/_panel.html.twig', ['files' => $this->files($em, ['step' => 'import_file'])]);
    }

    /** Rendered inside /setup/profile: the files that failed, each with Retry. */
    public function failedFiles(EntityManagerInterface $em): Response
    {
        return $this->render('setup/import_articles/_failed_files.html.twig', ['files' => $this->files($em, ['step' => 'import_file', 'status' => LlmJobStatus::Failed])]);
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return list<array{job: LlmJob, filename: string}>
     */
    private function files(EntityManagerInterface $em, array $criteria): array
    {
        $jobs = $em->getRepository(LlmJob::class)->findBy($criteria, ['id' => 'DESC'], 50);

        return array_map(static function (LlmJob $job): array {
            $message = $job->storedMessage();

            return ['job' => $job, 'filename' => $message instanceof ImportFile ? $message->filename : ''];
        }, $jobs);
    }

    #[Route('/setup/import', name: 'setup_import_articles', methods: ['POST'])]
    public function __invoke(Request $request, EntityManagerInterface $em, LlmJobs $jobs, #[CurrentUser] User $user): Response
    {
        $uploads = array_merge($request->files->all('files'), $request->files->all('directory'));
        $files = array_filter($uploads, static fn ($f) => $f instanceof UploadedFile && 'md' === strtolower($f->getClientOriginalExtension()));
        if ([] === $files) {
            return $this->redirectToRoute('setup_save_profile', status: Response::HTTP_SEE_OTHER);
        }

        $modified = json_decode($request->request->getString('modified'), true);
        $modified = \is_array($modified) ? $modified : [];

        $now = new \DateTimeImmutable();
        $em->wrapInTransaction(function () use ($files, $modified, $jobs, $now, $user): void {
            foreach ($files as $file) {
                $name = $file->getClientOriginalName();
                $ms = $modified[$name] ?? null;
                $jobs->start(null, 'import_file', 'io', static fn (int $id) => new ImportFile(
                    $id,
                    $name,
                    (string) file_get_contents($file->getPathname()),
                    $now,
                    \is_int($ms) ? new \DateTimeImmutable('@'.intdiv($ms, 1000)) : null,
                ), $user);
            }
        });

        return $this->redirectToRoute('setup_save_profile', status: Response::HTTP_SEE_OTHER);
    }
}
