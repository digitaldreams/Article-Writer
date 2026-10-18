<?php

namespace App\Planning\SuggestTitles;

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

/** Adds one round of title + subtitle suggestions. The system prompt is prompts/title_agent.md, set on the agent in ai.yaml. */
#[AsMessageHandler(fromTransport: 'llm_primary')]
final readonly class SuggestTitlesHandler
{
    public const TITLES_PER_ROUND = 5;

    public function __construct(
        #[Autowire(service: 'ai.agent.title')]
        private AgentInterface $agent,
        private EntityManagerInterface $em,
        private ArticleFolder $folder,
        private Environment $twig,
    ) {
    }

    public function __invoke(SuggestTitles $message): void
    {
        $article = $this->em->find(Article::class, $message->articleId) ?? throw new \RuntimeException('The article is gone.');
        $suggestions = $this->em->getRepository(TitleSuggestion::class);
        if ($suggestions->count(['article' => $article, 'round' => $message->round]) > 0) {
            return;
        }

        $answer = AgentAnswer::object($this->agent->call(new MessageBag(
            Message::ofUser($this->twig->render('planning/suggest_titles/_request.md.twig', [
                'input' => $this->em->getRepository(PlanInput::class)->findOneBy(['article' => $article]) ?? throw new \RuntimeException('The plan input is gone.'),
                'seen' => $suggestions->findBy(['article' => $article], ['id' => 'ASC']),
                'writer_idea' => $message->writerIdea,
                // Code adds many tokens and little to a title; on a local CPU model reading the prompt is the slow part.
                'body' => preg_replace('/^(```|~~~).*?^\1[ \t]*$/ms', '(code omitted)', $this->folder->read($article->getFolder(), 'article.md')['body']),
            ])),
        ), ['response_format' => TitleList::class, 'template_vars' => ['language_level' => $article->getUser()->getLanguageLevel()]]), TitleList::class);

        $titles = array_values(array_filter($answer->titles, static fn (ProposedTitle $t) => '' !== trim($t->title) && '' !== trim($t->subtitle)));
        if (self::TITLES_PER_ROUND !== \count($titles)) {
            throw new \RuntimeException(\sprintf('The model suggested %d title + subtitle pairs; %d are expected.', \count($titles), self::TITLES_PER_ROUND));
        }
        foreach ($titles as $proposed) {
            $this->em->persist(new TitleSuggestion($article, $message->round, trim($proposed->title), trim($proposed->subtitle), $message->writerIdea));
        }
        $this->em->flush();
    }
}
