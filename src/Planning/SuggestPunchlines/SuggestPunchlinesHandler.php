<?php

namespace App\Planning\SuggestPunchlines;

use App\Planning\PlanInput;
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

/** Adds one round of share punchlines and saves the Medium topics to article.md. The system prompt is prompts/share_agent.md, set on the agent in ai.yaml. */
#[AsMessageHandler(fromTransport: 'llm_primary')]
final readonly class SuggestPunchlinesHandler
{
    public const PUNCHLINES_PER_ROUND = 5;
    private const MEDIUM_TOPICS = 5;

    public function __construct(
        #[Autowire(service: 'ai.agent.share')]
        private AgentInterface $agent,
        private EntityManagerInterface $em,
        private ArticleFolder $folder,
        private Environment $twig,
    ) {
    }

    public function __invoke(SuggestPunchlines $message): void
    {
        $article = $this->em->find(Article::class, $message->articleId) ?? throw new \RuntimeException('The article is gone.');
        $punchlines = $this->em->getRepository(Punchline::class);
        if ($punchlines->count(['article' => $article, 'round' => $message->round]) > 0) {
            return;
        }

        $answer = AgentAnswer::object($this->agent->call(new MessageBag(
            Message::ofUser($this->twig->render('planning/suggest_punchlines/_request.md.twig', [
                'input' => $this->em->getRepository(PlanInput::class)->findOneBy(['article' => $article]) ?? throw new \RuntimeException('The plan input is gone.'),
                'seen' => $punchlines->findBy(['article' => $article], ['id' => 'ASC']),
                'writer_idea' => $message->writerIdea,
                'body' => $this->folder->read($article->getFolder(), 'article.md')['body'],
            ])),
        ), ['response_format' => PunchlineList::class, 'template_vars' => ['language_level' => $article->getUser()->getLanguageLevel()]]), PunchlineList::class);

        $texts = self::nonEmpty($answer->punchlines);
        if (self::PUNCHLINES_PER_ROUND !== \count($texts)) {
            throw new \RuntimeException(\sprintf('The model suggested %d punchlines; %d are expected.', \count($texts), self::PUNCHLINES_PER_ROUND));
        }
        foreach ($texts as $text) {
            $this->em->persist(new Punchline($article, $message->round, $text, $message->writerIdea));
        }
        $this->em->flush();

        // Medium takes at most 5 topics. A round without topics keeps the ones article.md already has.
        $topics = \array_slice(self::nonEmpty(array_map(static fn (string $t) => ltrim($t, '#'), $answer->topics)), 0, self::MEDIUM_TOPICS);
        if ([] !== $topics) {
            $file = $this->folder->read($article->getFolder(), 'article.md');
            $this->folder->write($article->getFolder(), 'article.md', array_replace($file['frontMatter'], ['topics' => $topics]), $file['body']);
        }
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function nonEmpty(array $lines): array
    {
        return array_values(array_filter(array_map('trim', $lines), static fn (string $t) => '' !== $t));
    }
}
