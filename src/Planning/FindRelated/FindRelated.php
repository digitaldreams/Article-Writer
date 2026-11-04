<?php

namespace App\Planning\FindRelated;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * Which older articles of the writer should the article in progress link to? All candidate titles go into one call.
 * The system prompt is prompts/related_agent.md, set on the agent in ai.yaml.
 */
// ponytail: no Messenger job, route or screen yet (a board task). Public so the test container and app:eval:related can fetch it.
#[Autoconfigure(public: true)]
final readonly class FindRelated
{
    public const MAX_LINKS = 3;

    public function __construct(
        #[Autowire(service: 'ai.agent.related')]
        private AgentInterface $agent,
        private Environment $twig,
    ) {
    }

    /**
     * @param list<string>                         $headings   the section headings of the article in progress
     * @param list<array{id: int, title: string}>  $candidates the writer's older articles (never the article itself)
     *
     * @return list<RelatedLink> only ids from $candidates, each once, the model's order
     */
    public function ask(string $title, string $subtitle, array $headings, array $candidates, string $languageLevel = 'B1'): array
    {
        $answer = $this->agent->call(new MessageBag(
            Message::ofUser($this->twig->render('planning/find_related/_request.md.twig', compact('title', 'subtitle', 'headings', 'candidates'))),
        ), ['response_format' => RelatedReport::class, 'template_vars' => ['max' => self::MAX_LINKS, 'language_level' => $languageLevel]])->asObject(RelatedReport::class);

        $known = array_column($candidates, 'id');
        $links = [];
        foreach ($answer->links as $link) {
            if (\in_array($link->id, $known, true) && !isset($links[$link->id])) {
                $links[$link->id] = new RelatedLink($link->id, trim($link->reason));
            }
        }

        return \array_slice(array_values($links), 0, self::MAX_LINKS);
    }
}
