<?php

namespace App\Planning\ListPlans;

use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ListPlansController extends AbstractController
{
    #[Route('/plans', name: 'planning_list_plans', methods: ['GET'])]
    public function __invoke(EntityManagerInterface $em, StalledCheck $stalled): Response
    {
        /** @var list<Article> $articles */
        $articles = $em->createQuery('SELECT a FROM '.Article::class.' a WHERE a.state != :imported')
            ->setParameter('imported', ArticleState::Imported)->getResult();
        /** @var list<LlmJob> $latestJobs */
        $latestJobs = $em->createQuery('SELECT j FROM '.LlmJob::class.' j WHERE j.id IN (SELECT MAX(j2.id) FROM '.LlmJob::class.' j2 WHERE j2.article IS NOT NULL GROUP BY j2.article)')->getResult();
        $jobs = [];
        foreach ($latestJobs as $job) {
            $jobs[$job->getArticle()?->getId()] = $job;
        }

        // "Finished" = the latest job is done or failed and the article is not written yet.
        $rows = array_map(static function (Article $article) use ($jobs): array {
            $job = $jobs[$article->getId()] ?? null;

            return [
                'article' => $article,
                'job' => $job,
                'finished' => null !== $job && \in_array($job->getStatus(), [LlmJobStatus::Done, LlmJobStatus::Failed], true) && ArticleState::Written !== $article->getState(),
            ];
        }, $articles);
        usort($rows, static fn (array $a, array $b): int => [$b['finished'], $b['article']->getUpdatedAt()] <=> [$a['finished'], $a['article']->getUpdatedAt()]);

        return $this->render('planning/list_plans/index.html.twig', ['rows' => $rows, 'stalled' => $stalled->isStalled()]);
    }
}
