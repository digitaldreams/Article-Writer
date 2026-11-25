<?php

namespace App\Planning\Article;

use App\Planning\Reference;
use App\Planning\ReferenceStatus;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Storage\ArticleFolder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Twig\Environment;

/**
 * The plan comes from the md files only, never from the DB, so the writer's hand edits win.
 * A replay rewrites article.md fully; the state moves only from approved.
 */
#[AsMessageHandler(fromTransport: 'llm_primary')]
final readonly class WriteArticleHandler
{
    public function __construct(
        #[Autowire(service: 'ai.agent.article')]
        private AgentInterface $agent,
        private EntityManagerInterface $em,
        private ArticleFolder $folder,
        private Environment $twig,
        #[Autowire('%app.ai.primary_platform%')]
        private string $primaryPlatform,
    ) {
    }

    public function __invoke(WriteArticle $message): void
    {
        $article = $this->em->find(Article::class, $message->articleId) ?? throw new \RuntimeException('The article is gone.');
        $folder = $article->getFolder();
        $user = $article->getUser();
        // Whole pages won a blind test on cloud models (2026-11-02); they don't fit a local model's context (num_ctx 16k), so it keeps the notes.
        $wholePages = 'ai.platform.ollama' !== $this->primaryPlatform;

        $body = trim($this->agent->call(new MessageBag(
            Message::ofUser($this->twig->render('planning/article/_plan_files.md.twig', [
                'idea' => $this->folder->read($folder, 'idea.md')['body'],
                'outline' => $this->folder->read($folder, 'outline.md')['body'],
                'answers' => file_exists($this->folder->pathOf($folder, 'answers.md')) ? $this->folder->read($folder, 'answers.md')['body'] : null,
                'references' => array_map(static fn (Reference $reference) => [
                    'location' => $reference->getLocation(),
                    'text' => $wholePages ? $reference->getExtractedText() : $reference->getDigest(),
                ], $this->em->getRepository(Reference::class)->findBy(['article' => $article, 'status' => ReferenceStatus::Done], ['id' => 'ASC'])),
                'structure_profile' => $user->getWritingProfile()['structure'],
                'voice' => $user->getWritingProfile()['voice'],
            ])),
        ), ['template_vars' => ['language_level' => $user->getLanguageLevel()]])->asText());
        if ('' === $body) {
            throw new \RuntimeException('The model wrote an empty article.');
        }

        $this->folder->write($folder, 'article.md', ['plan_id' => (int) $article->getId(), 'title' => $article->getTitle()], $body."\n");
        if (ArticleState::Approved === $article->getState()) {
            $article->moveTo(ArticleState::Written);
            $this->em->flush();
        }
    }
}
