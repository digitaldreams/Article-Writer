<?php

namespace App\Planning\FinalCheck;

use App\Shared\Llm\AgentAnswer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Twig\Environment;

/** Rewrites the whole article from the picked check notes. The title and subtitle lines stay as the writer chose them. */
#[AsMessageHandler(fromTransport: 'llm_primary')]
final readonly class RefactorArticleHandler
{
    private const TITLE_LINES = '/\A# .*\n(## .*\n)?/';

    public function __construct(
        #[Autowire(service: 'ai.agent.refactor')]
        private AgentInterface $agent,
        private EntityManagerInterface $em,
        private PlanFiles $planFiles,
        private Environment $twig,
    ) {
    }

    public function __invoke(RefactorArticle $message): void
    {
        // A newer refactor request replaced this one.
        $refactor = $this->em->find(ArticleRefactor::class, $message->refactorId);
        if (null === $refactor) {
            return;
        }
        $article = $refactor->getArticle();

        $answer = AgentAnswer::object($this->agent->call(new MessageBag(
            Message::ofUser($this->twig->render('planning/final_check/_refactor_request.md.twig', [
                ...$this->planFiles->of($article),
                'body' => $refactor->getBefore(),
                'notes' => $this->em->getRepository(CheckNote::class)->findBy(['article' => $article, 'id' => $refactor->getNoteIds()], ['position' => 'ASC']),
                'voice' => $article->getUser()->getWritingProfile()['voice'],
            ])),
        ), ['response_format' => RefactorAnswer::class, 'template_vars' => [
            'language_level' => $article->getUser()->getLanguageLevel(),
        ]]), RefactorAnswer::class);
        if ('' === trim($answer->body)) {
            throw new \RuntimeException('The model wrote an empty article.');
        }

        $rest = ltrim(preg_replace(self::TITLE_LINES, '', ltrim($answer->body), 1) ?? $answer->body);
        $body = preg_match(self::TITLE_LINES, $refactor->getBefore(), $m) ? $m[0]."\n".$rest : $rest;

        $refactor->suggest(rtrim($body)."\n", array_values(array_filter(array_map('trim', $answer->changes), static fn (string $c) => '' !== $c)));
        $this->em->flush();
    }
}
