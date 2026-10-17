<?php

namespace App\Planning\FinalCheckup;

use App\Planning\OutlineCheckup;
use App\Planning\OutlineItems;
use App\Planning\PlanContext;
use App\Shared\Article\Article;
use App\Shared\Llm\AgentAnswer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Twig\Environment;

/** Checks the round against the plan and suggests a polished title and summary per item. A replay replaces both. */
#[AsMessageHandler(fromTransport: 'llm_primary')]
final readonly class FinalCheckupHandler
{
    public function __construct(
        #[Autowire(service: 'ai.agent.checkup')]
        private AgentInterface $agent,
        private EntityManagerInterface $em,
        private Environment $twig,
        private PlanContext $planContext,
        private OutlineItems $outline,
    ) {
    }

    public function __invoke(FinalCheckup $message): void
    {
        $article = $this->em->find(Article::class, $message->articleId) ?? throw new \RuntimeException('The article is gone.');
        $items = $this->outline->ofRound($article, $message->round);
        $context = $this->planContext->of($article) + ['items' => $items];

        $answer = AgentAnswer::object($this->agent->call(new MessageBag(
            Message::ofUser($this->twig->render('planning/final_checkup/_outline.md.twig', $context)),
        ), ['response_format' => CheckupAnswer::class, 'template_vars' => ['language_level' => $context['language_level']]]), CheckupAnswer::class);

        if (\count($answer->items) !== \count($items)) {
            throw new \RuntimeException(\sprintf('The checkup polished %d items; the outline has %d.', \count($answer->items), \count($items)));
        }

        $this->em->wrapInTransaction(function () use ($article, $message, $items, $answer): void {
            $this->em->createQuery('DELETE FROM '.OutlineCheckup::class.' c WHERE c.article = :article AND c.round = :round')
                ->setParameters(['article' => $article, 'round' => $message->round])->execute();
            $this->em->persist(new OutlineCheckup($article, $message->round, $answer->issues));

            foreach ($items as $i => $item) {
                $polished = $answer->items[$i];
                $unchanged = $polished->title === $item->getTitle() && $polished->summary === $item->getSummary();
                $unchanged ? $item->clearSuggestion() : $item->suggest($polished->title, $polished->summary);
            }
        });
    }
}
