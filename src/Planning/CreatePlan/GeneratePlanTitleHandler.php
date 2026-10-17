<?php

namespace App\Planning\CreatePlan;

use App\Shared\Llm\AgentAnswer;
use App\Planning\PlanInput;
use App\Shared\Article\Article;
use App\Shared\WriterProfile\WriterProfile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Twig\Environment;

#[AsMessageHandler(fromTransport: 'llm_secondary')]
final readonly class GeneratePlanTitleHandler
{
    public function __construct(
        #[Autowire(service: 'ai.agent.secondary')]
        private AgentInterface $agent,
        private EntityManagerInterface $em,
        private Environment $twig,
    ) {
    }

    public function __invoke(GeneratePlanTitle $message): void
    {
        $article = $this->em->find(Article::class, $message->articleId) ?? throw new \RuntimeException('The article is gone.');
        $input = $this->em->getRepository(PlanInput::class)->findOneBy(['article' => $article]) ?? throw new \RuntimeException('The plan input is gone.');
        $level = $this->em->getRepository(WriterProfile::class)->findOneBy(['user' => $article->getUser()])?->getLanguageLevel() ?? '';

        $answer = AgentAnswer::object($this->agent->call(new MessageBag(
            Message::forSystem($this->twig->render('planning/create_plan/generate_plan_title.md.twig', ['language_level' => $level])),
            Message::ofUser($this->twig->render('planning/_plan_input.md.twig', ['input' => $input])),
        ), ['response_format' => PlanTitleAnswer::class]), PlanTitleAnswer::class);

        $article->setTitle($answer->title);
        $this->em->flush();
    }
}
