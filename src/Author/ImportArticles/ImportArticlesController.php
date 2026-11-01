<?php

namespace App\Author\ImportArticles;

use App\Author\ImportArticle;
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
    private const SESSION_KEY = 'import_job_ids';

    /**
     * The upload form and one job card per imported file.
     * On /author ($all false): only this session's last import, until Close. The onboarding wizard shows all.
     */
    public function panel(Request $request, EntityManagerInterface $em, bool $all = false): Response
    {
        $ids = $all ? null : $request->getSession()->get(self::SESSION_KEY, []);
        $files = match (true) {
            null === $ids => $this->files($em, ['step' => 'import_file']),
            [] === $ids => [],
            default => $this->files($em, ['step' => 'import_file', 'id' => $ids]),
        };

        return $this->render('author/import_articles/_panel.html.twig', ['files' => $files]);
    }

    #[Route('/author/import/close', name: 'author_import_close', methods: ['POST'])]
    public function close(Request $request): Response
    {
        $request->getSession()->remove(self::SESSION_KEY);

        return $this->redirectToRoute('author_edit_bio', status: Response::HTTP_SEE_OTHER);
    }

    /** Rendered inside /author/profile: the files of this session's import that failed, each with Retry. */
    public function failedFiles(Request $request, EntityManagerInterface $em): Response
    {
        $ids = $request->getSession()->get(self::SESSION_KEY, []);
        $files = [] === $ids ? [] : $this->files($em, ['step' => 'import_file', 'status' => LlmJobStatus::Failed, 'id' => $ids]);

        return $this->render('author/import_articles/_failed_files.html.twig', ['files' => $files]);
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

            return ['job' => $job, 'filename' => $message instanceof ImportArticle ? $message->filename : ''];
        }, $jobs);
    }

    #[Route('/author/import', name: 'author_import_articles', methods: ['POST'])]
    public function __invoke(Request $request, EntityManagerInterface $em, LlmJobs $jobs, #[CurrentUser] User $user): Response
    {
        $modified = json_decode($request->request->getString('modified'), true);
        $modified = \is_array($modified) ? $modified : [];

        /** @var list<array{string, string, 'md'|'medium', ?\DateTimeImmutable}> $entries name, content, format, file date */
        $entries = [];
        foreach (array_merge($request->files->all('files'), $request->files->all('directory')) as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }
            if (!$file->isValid()) {
                return $this->importError($file->getErrorMessage());
            }
            $name = $file->getClientOriginalName();
            $extension = strtolower($file->getClientOriginalExtension());
            if ('md' === $extension) {
                $ms = $modified[$name] ?? null;
                $entries[] = [$name, (string) file_get_contents($file->getPathname()), 'md', \is_int($ms) ? new \DateTimeImmutable('@'.intdiv($ms, 1000)) : null];
            } elseif ('zip' === $extension) {
                $posts = $this->zipEntries($file->getPathname());
                if (null === $posts || [] === $posts) {
                    return $this->importError(null === $posts ? "$name is not a readable zip." : "No posts found in $name.");
                }
                array_push($entries, ...$posts);
            }
        }

        $ids = $em->wrapInTransaction(function () use ($entries, $jobs, $user): array {
            $ids = [];
            foreach ($entries as [$name, $content, $format, $date]) {
                $ids[] = $jobs->start(null, 'import_file', 'io', static fn (int $id) => new ImportArticle($id, $name, $content, publishedAt: $date, format: $format), $user)->getId();
            }

            return $ids;
        });
        $request->getSession()->set(self::SESSION_KEY, $ids);

        return $this->redirectToRoute('author_edit_bio', status: Response::HTTP_SEE_OTHER);
    }

    private function importError(string $message): Response
    {
        return $this->render('author/import_articles/error.html.twig', ['message' => $message], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * Medium export posts (posts/*.html, drafts left out) and any .md, read in memory: nothing is extracted, so no path in the zip reaches the disk.
     *
     * @return list<array{string, string, 'md'|'medium', ?\DateTimeImmutable}>|null null when the file is no zip
     */
    private function zipEntries(string $path): ?array
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::RDONLY)) {
            return null;
        }
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $stat = $zip->statIndex($i);
            // macOS metadata, and anything over 5 MB unpacked (a zip bomb, not a post).
            if (false === $stat || 1 === preg_match('~(^|/)(__MACOSX/|\._)~', $stat['name']) || $stat['size'] > 5_000_000) {
                continue;
            }
            // (^|/): the export folder may sit one level down after a re-zip.
            $format = match (true) {
                1 === preg_match('~(^|/)posts/(?!draft)[^/]+\.html$~', $stat['name']) => 'medium',
                str_ends_with(strtolower($stat['name']), '.md') => 'md',
                default => null,
            };
            $content = null === $format ? false : $zip->getFromIndex($i);
            if (false !== $content) {
                $entries[] = [basename($stat['name']), $content, $format, new \DateTimeImmutable('@'.$stat['mtime'])];
            }
        }
        $zip->close();

        return $entries;
    }
}
