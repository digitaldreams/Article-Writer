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

/** Adds one round of share punchlines. The system prompt is prompts/share_agent.md, set on the agent in ai.yaml. */
#[AsMessageHandler(fromTransport: 'llm_primary')]
final readonly class SuggestPunchlinesHandler
{
    public const PUNCHLINES_PER_ROUND = 5;

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
        ), ['response_format' => PunchlineList::class]), PunchlineList::class);

        $texts = array_values(array_filter(array_map('trim', $answer->punchlines), static fn (string $t) => '' !== $t));
        if (self::PUNCHLINES_PER_ROUND !== \count($texts)) {
            throw new \RuntimeException(\sprintf('The model suggested %d punchlines; %d are expected.', \count($texts), self::PUNCHLINES_PER_ROUND));
        }
        foreach ($texts as $text) {
            $this->em->persist(new Punchline($article, $message->round, $text, $message->writerIdea));
        }
        $this->em->flush();
    }
}
