<?php

namespace App\Planning\SuggestPosters;

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

/** Adds one round of poster ideas. The system prompt is prompts/poster_agent.md, set on the agent in ai.yaml. */
#[AsMessageHandler(fromTransport: 'llm_primary')]
final readonly class SuggestPostersHandler
{
    public const IDEAS_PER_ROUND = 3;
    private const MAX_SKETCH_BYTES = 10_240;

    public function __construct(
        #[Autowire(service: 'ai.agent.poster')]
        private AgentInterface $agent,
        private EntityManagerInterface $em,
        private ArticleFolder $folder,
        private Environment $twig,
    ) {
    }

    public function __invoke(SuggestPosters $message): void
    {
        $article = $this->em->find(Article::class, $message->articleId) ?? throw new \RuntimeException('The article is gone.');
        $ideas = $this->em->getRepository(PosterIdea::class);
        if ($ideas->count(['article' => $article, 'round' => $message->round]) > 0) {
            return;
        }

        $answer = AgentAnswer::object($this->agent->call(new MessageBag(
            Message::ofUser($this->twig->render('planning/suggest_posters/_request.md.twig', [
                'input' => $this->em->getRepository(PlanInput::class)->findOneBy(['article' => $article]) ?? throw new \RuntimeException('The plan input is gone.'),
                'seen' => $ideas->findBy(['article' => $article], ['id' => 'ASC']),
                'writer_idea' => $message->writerIdea,
                'body' => $this->folder->read($article->getFolder(), 'article.md')['body'],
            ])),
        ), ['response_format' => PosterList::class, 'template_vars' => ['language_level' => $article->getUser()->getLanguageLevel()]]), PosterList::class);

        $posters = array_values(array_filter($answer->posters, static fn (ProposedPoster $p) => '' !== trim($p->headline) && '' !== trim($p->picture)));
        if (self::IDEAS_PER_ROUND !== \count($posters)) {
            throw new \RuntimeException(\sprintf('The model suggested %d poster ideas; %d are expected.', \count($posters), self::IDEAS_PER_ROUND));
        }
        foreach ($posters as $proposed) {
            $this->em->persist(new PosterIdea($article, $message->round, trim($proposed->headline), trim($proposed->picture), trim($proposed->layout), trim($proposed->colors), $message->writerIdea, self::sketch($proposed->sketch)));
        }
        $this->em->flush();
    }

    /** The sketch is a bonus: one that isn't a small SVG is dropped, never failing the round. */
    private static function sketch(string $sketch): ?string
    {
        $sketch = trim($sketch);

        return str_starts_with($sketch, '<svg') && \strlen($sketch) <= self::MAX_SKETCH_BYTES ? $sketch : null;
    }
}
