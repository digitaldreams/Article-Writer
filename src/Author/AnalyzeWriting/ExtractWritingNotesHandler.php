<?php

namespace App\Author\AnalyzeWriting;

use App\Author\WritingNote;
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
final readonly class ExtractWritingNotesHandler
{
    public function __construct(
        #[Autowire(service: 'ai.agent.writing_notes')]
        private AgentInterface $agent,
        private ArticleFolder $articleFolder,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ExtractWritingNotes $message): void
    {
        $article = $this->em->find(Article::class, $message->articleId) ?? throw new \RuntimeException('The article is gone.');
        // A Retry of an old failed card after a newer analysis already noted the article.
        if (null !== $this->em->getRepository(WritingNote::class)->findOneBy(['article' => $article])) {
            return;
        }
        $body = $this->articleFolder->read($article->getFolder(), 'article.md')['body'];
        $answer = AgentAnswer::object($this->agent->call(new MessageBag(
            Message::ofUser($body),
        ), ['response_format' => WritingNotesAnswer::class, 'template_vars' => ['language_level' => $article->getUser()->getLanguageLevel()]]), WritingNotesAnswer::class);

        $this->em->persist(new WritingNote(
            $article, $answer->openingType, $answer->sectionCount, $answer->orderingPattern, $answer->hasRunningExample, $answer->closing,
            $answer->tone, $answer->sentenceStyle, $answer->devices, $answer->codeHabit, $answer->samples,
        ));
        $this->em->flush();
    }
}
