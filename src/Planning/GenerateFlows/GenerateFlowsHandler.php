<?php

namespace App\Planning\GenerateFlows;

use App\Planning\FlowOption;
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

#[AsMessageHandler(fromTransport: 'llm_primary')]
final readonly class GenerateFlowsHandler
{
    public function __construct(
        #[Autowire(service: 'ai.agent.primary')]
        private AgentInterface $agent,
        private EntityManagerInterface $em,
        private Environment $twig,
        private PlanContext $planContext,
    ) {
    }

    public function __invoke(GenerateFlows $message): void
    {
        $article = $this->em->find(Article::class, $message->articleId) ?? throw new \RuntimeException('The article is gone.');
        if ($this->em->getRepository(FlowOption::class)->count(['article' => $article]) > 0) {
            return;
        }
        $context = $this->planContext->of($article);

        $answer = AgentAnswer::object($this->agent->call(new MessageBag(
            Message::forSystem($this->twig->render('planning/generate_flows/generate_flows.md.twig', $context)),
            Message::ofUser($this->twig->render('planning/_plan_context.md.twig', $context)),
        ), ['response_format' => FlowList::class]), FlowList::class);

        if (\count($answer->flows) < 2 || \count($answer->flows) > 3) {
            throw new \RuntimeException(\sprintf('The model offered %d flows; 2 or 3 are expected.', \count($answer->flows)));
        }
        foreach ($answer->flows as $description) {
            $this->em->persist(new FlowOption($article, $description));
        }
        $this->em->flush();
    }
}
