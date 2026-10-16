<?php

namespace App\Planning\GenerateOutline;

use App\Planning\FlowOption;
use App\Planning\OutlineItem;
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

/** Writes one outline round. A round that already has items is skipped, so a replay adds nothing. */
#[AsMessageHandler(fromTransport: 'llm_primary')]
final readonly class GenerateOutlineHandler
{
    public function __construct(
        #[Autowire(service: 'ai.agent.primary')]
        private AgentInterface $agent,
        private EntityManagerInterface $em,
        private Environment $twig,
        private PlanContext $planContext,
        private OutlineItems $outline,
    ) {
    }

    public function __invoke(GenerateOutline $message): void
    {
        $article = $this->em->find(Article::class, $message->articleId) ?? throw new \RuntimeException('The article is gone.');
        if ([] !== $this->outline->ofRound($article, $message->round)) {
            return;
        }

        $context = $this->planContext->of($article) + [
            'flow' => $this->em->getRepository(FlowOption::class)->findOneBy(['article' => $article, 'chosen' => true]),
            'previous_items' => $this->outline->ofRound($article, $message->round - 1),
            'instruction' => $message->instruction,
            'reason' => $message->reason,
        ];

        $answer = AgentAnswer::object($this->agent->call(new MessageBag(
            Message::forSystem($this->twig->render('planning/generate_outline/generate_outline.md.twig', $context)),
            Message::ofUser($this->twig->render('planning/generate_outline/_request.md.twig', $context)),
        ), ['response_format' => OutlineAnswer::class]), OutlineAnswer::class);

        if ([] === $answer->items) {
            throw new \RuntimeException('The model answered with an empty outline.');
        }
        foreach ($answer->items as $position => $item) {
            $this->em->persist(new OutlineItem($article, $message->round, $position, $item->title, $item->summary));
        }
        $this->em->flush();
    }
}
