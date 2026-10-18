<?php

namespace App\Planning\ReviewArticle;

use App\Planning\ArticleSections;
use App\Planning\PlanInput;
use App\Planning\ReaderNote;
use App\Shared\Article\Article;
use App\Shared\Llm\AgentAnswer;
use App\Shared\Storage\ArticleFolder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Twig\Environment;

/** The reader reads the whole article and leaves notes per section. A new review replaces the old notes. */
#[AsMessageHandler(fromTransport: 'llm_primary')]
final readonly class ReviewArticleHandler
{
    public const MAX_NOTES = 8;

    public function __construct(
        #[Autowire(service: 'ai.agent.review')]
        private AgentInterface $agent,
        private EntityManagerInterface $em,
        private ArticleFolder $folder,
        private Environment $twig,
    ) {
    }

    public function __invoke(ReviewArticle $message): void
    {
        $article = $this->em->find(Article::class, $message->articleId) ?? throw new \RuntimeException('The article is gone.');
        $input = $this->em->getRepository(PlanInput::class)->findOneBy(['article' => $article]) ?? throw new \RuntimeException('The plan input is gone.');
        $body = $this->folder->read($article->getFolder(), 'article.md')['body'];
        $headings = array_keys(ArticleSections::split($body));

        $answer = AgentAnswer::object($this->agent->call(new MessageBag(
            Message::ofUser($this->twig->render('planning/review_article/_request.md.twig', [
                'headings' => $headings,
                'body' => $body,
            ])),
        ), ['response_format' => ReaderReview::class, 'template_vars' => [
            'audience' => '' !== $input->getAudience() ? $input->getAudience() : 'not given',
            'reader_level' => $input->getReaderLevel() ?? 'not given',
            'max' => self::MAX_NOTES,
            'language_level' => $article->getUser()->getLanguageLevel(),
        ]]), ReaderReview::class);
        if (\count($answer->notes) > self::MAX_NOTES) {
            throw new \RuntimeException(\sprintf('The reader left %d notes; at most %d are allowed.', \count($answer->notes), self::MAX_NOTES));
        }

        $this->em->wrapInTransaction(function () use ($article, $answer, $headings): void {
            $this->em->createQuery('DELETE FROM '.ReaderNote::class.' n WHERE n.article = :article')->setParameter('article', $article)->execute();
            foreach ($answer->notes as $position => $note) {
                $section = \in_array(trim($note->section), $headings, true) ? trim($note->section) : ReaderNote::GENERAL;
                $this->em->persist(new ReaderNote($article, $section, trim($note->problem), trim($note->suggestion), $position));
            }
        });
    }
}
