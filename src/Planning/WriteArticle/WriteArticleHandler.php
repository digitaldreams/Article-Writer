<?php

namespace App\Planning\WriteArticle;

use App\Planning\Reference;
use App\Planning\ReferenceStatus;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\AgentAnswer;
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
    ) {
    }

    public function __invoke(WriteArticle $message): void
    {
        $article = $this->em->find(Article::class, $message->articleId) ?? throw new \RuntimeException('The article is gone.');
        $folder = $article->getFolder();
        $user = $article->getUser();

        $body = AgentAnswer::text($this->agent->call(new MessageBag(
            Message::ofUser($this->twig->render('planning/write_article/_plan_files.md.twig', [
                'idea' => $this->folder->read($folder, 'idea.md')['body'],
                'outline' => $this->folder->read($folder, 'outline.md')['body'],
                'answers' => file_exists($this->folder->pathOf($folder, 'answers.md')) ? $this->folder->read($folder, 'answers.md')['body'] : null,
                'digests' => $this->em->getRepository(Reference::class)->findBy(['article' => $article, 'status' => ReferenceStatus::Done], ['id' => 'ASC']),
                'structure_profile' => $user->getWritingProfile()['structure'],
                'voice' => $user->getWritingProfile()['voice'],
            ])),
        ), ['template_vars' => ['language_level' => $user->getLanguageLevel()]]));
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
