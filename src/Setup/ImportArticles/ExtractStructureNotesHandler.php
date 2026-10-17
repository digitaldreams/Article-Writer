<?php

namespace App\Setup\ImportArticles;

use App\Shared\Llm\AgentAnswer;
use App\Shared\Article\Article;
use App\Shared\Storage\ArticleFolder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'llm_secondary')]
final readonly class ExtractStructureNotesHandler
{
    public function __construct(
        #[Autowire(service: 'ai.agent.structure_notes')]
        private AgentInterface $agent,
        private ArticleFolder $articleFolder,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ExtractStructureNotes $message): void
    {
        $article = $this->em->find(Article::class, $message->articleId) ?? throw new \RuntimeException('The article is gone.');
        $body = $this->articleFolder->read($article->getFolder(), 'article.md')['body'];
        $answer = AgentAnswer::object($this->agent->call(new MessageBag(
            Message::ofUser($body),
        ), ['response_format' => StructureNotesAnswer::class, 'template_vars' => ['language_level' => $article->getUser()->getLanguageLevel()]]), StructureNotesAnswer::class);

        $this->em->persist(new StructureNote($article, $answer->openingType, $answer->sectionCount, $answer->orderingPattern, $answer->hasRunningExample, $answer->closing));
        $this->em->flush();
    }
}
