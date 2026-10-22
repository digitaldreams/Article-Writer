<?php

namespace App\Planning\ApprovePlan;

use App\Planning\OutlineItems;
use App\Planning\PlanApproved;
use App\Planning\PlanInput;
use App\Planning\Question;
use App\Planning\Reference;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJobs;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** The md files are written first; only then the state commits. A failed write commits nothing. */
final class ApprovePlanController extends AbstractController
{
    #[Route('/plans/{id<\d+>}/approve', name: 'planning_approve_plan', methods: ['POST'])]
    public function __invoke(
        Article $article,
        EntityManagerInterface $em,
        EventDispatcherInterface $events,
        OutlineItems $outline,
        PlanFiles $files,
        LlmJobs $jobs,
    ): Response {
        if (ArticleState::Outline !== $article->getState()) {
            throw new ConflictHttpException('A plan can only be approved at the outline step.');
        }
        if ($jobs->isRunning($article, 'generate_outline')) {
            throw new ConflictHttpException('A new outline round is being written.');
        }
        $items = $outline->latest($article);
        if ([] === $items) {
            throw new ConflictHttpException('An empty outline can\'t be approved.');
        }
        try {
            $files->write(
                $article,
                $em->getRepository(PlanInput::class)->findOneBy(['article' => $article]) ?? throw new \RuntimeException('The plan input is gone.'),
                $em->getRepository(Reference::class)->findBy(['article' => $article], ['id' => 'ASC']),
                $items,
                $em->getRepository(Question::class)->findBy(['article' => $article], ['position' => 'ASC']),
                new \DateTimeImmutable(),
            );
        } catch (IOExceptionInterface $e) {
            // The outline page belongs to EditOutline; it renders this error.
            return $this->forward('App\Planning\EditOutline\ShowOutlineController', ['article' => $article, 'approveError' => 'The plan files could not be written: '.$e->getMessage()]);
        }

        $em->wrapInTransaction(static function () use ($article, $em, $events): void {
            $article->moveTo(ArticleState::Approved);
            $em->flush();
            $events->dispatch(new PlanApproved((int) $article->getId()));
        });

        return $this->redirectToRoute('planning_open_plan', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
