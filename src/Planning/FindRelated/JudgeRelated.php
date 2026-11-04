<?php

namespace App\Planning\FindRelated;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * Related articles by sub-agents: one small, separate model call per candidate. Each call sees the article in progress and
 * ONE older article (with its headings), never the other candidates, and answers fits yes/no. Code merges the answers.
 * Meant for a short list from CandidateRetriever. System prompt: prompts/related_judge_agent.md.
 */
// ponytail: calls are sequential; run them in parallel (Messenger or fibers) only if the wait ever matters. Public for the tests and the eval.
#[Autoconfigure(public: true)]
final readonly class JudgeRelated
{
    public function __construct(
        #[Autowire(service: 'ai.agent.related_judge')]
        private AgentInterface $agent,
        private Environment $twig,
    ) {
    }

    /**
     * @param list<string>                                                                    $headings
     * @param list<array{id: int, title: string, subtitle?: string, headings?: list<string>}> $candidates in the order of preference
     *
     * @return list<RelatedLink> at most MAX_LINKS; stops asking once it has them
     */
    public function ask(string $title, string $subtitle, array $headings, array $candidates, string $languageLevel = 'B1'): array
    {
        $links = [];
        foreach ($candidates as $candidate) {
            $verdict = $this->agent->call(new MessageBag(
                Message::ofUser($this->twig->render('planning/find_related/_judge.md.twig', compact('title', 'subtitle', 'headings', 'candidate'))),
            ), ['response_format' => FitVerdict::class, 'template_vars' => ['language_level' => $languageLevel]])->asObject(FitVerdict::class);

            if ($verdict->fits) {
                $links[] = new RelatedLink($candidate['id'], trim($verdict->reason));
                if (\count($links) === FindRelated::MAX_LINKS) {
                    break;
                }
            }
        }

        return $links;
    }
}
