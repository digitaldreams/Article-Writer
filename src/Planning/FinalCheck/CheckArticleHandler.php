<?php

namespace App\Planning\FinalCheck;

use App\Planning\ArticleSections;
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

/** Checks the whole article against its title, plan and outline. A new check replaces the old notes. */
#[AsMessageHandler(fromTransport: 'llm_primary')]
final readonly class CheckArticleHandler
{
    public const MAX_NOTES = 8;

    public function __construct(
        #[Autowire(service: 'ai.agent.final_check')]
        private AgentInterface $agent,
        private EntityManagerInterface $em,
        private ArticleFolder $folder,
        private PlanFiles $planFiles,
        private Environment $twig,
    ) {
    }

    public function __invoke(CheckArticle $message): void
    {
        $article = $this->em->find(Article::class, $message->articleId) ?? throw new \RuntimeException('The article is gone.');
        $body = $this->folder->read($article->getFolder(), 'article.md')['body'];
        $headings = array_keys(ArticleSections::split($body));

        $answer = AgentAnswer::object($this->agent->call(new MessageBag(
            Message::ofUser($this->twig->render('planning/final_check/_check_request.md.twig', [
                ...$this->planFiles->of($article),
                'headings' => $headings,
                'body' => $body,
            ])),
        ), ['response_format' => CheckReport::class, 'template_vars' => [
            'max' => self::MAX_NOTES,
            'language_level' => $article->getUser()->getLanguageLevel(),
        ]]), CheckReport::class);
        // ponytail: a weak model may return too many; the first ones matter most
        $notes = \array_slice($answer->notes, 0, self::MAX_NOTES);

        $this->em->wrapInTransaction(function () use ($article, $notes, $headings): void {
            $this->em->createQuery('DELETE FROM '.CheckNote::class.' n WHERE n.article = :article')->setParameter('article', $article)->execute();
            foreach ($notes as $position => $note) {
                $section = \in_array(trim($note->section), $headings, true) ? trim($note->section) : CheckNote::GENERAL;
                $this->em->persist(new CheckNote($article, $section, trim($note->problem), trim($note->suggestion), $position));
            }
        });
    }
}
