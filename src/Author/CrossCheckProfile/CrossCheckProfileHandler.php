<?php

namespace App\Author\CrossCheckProfile;

use App\Shared\Llm\AgentAnswer;
use App\Shared\Llm\LlmJob;
use App\Shared\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Twig\Environment;

/** Checks the saved writing profile against the research rules. Stores the points only: the profile stays as it is. */
#[AsMessageHandler(fromTransport: 'llm_primary')]
final readonly class CrossCheckProfileHandler
{
    public const GENERAL = 'general';

    public function __construct(
        #[Autowire(service: 'ai.agent.cross_check_profile')]
        private AgentInterface $agent,
        private EntityManagerInterface $em,
        private Environment $twig,
    ) {
    }

    public function __invoke(CrossCheckProfile $message): void
    {
        $user = ($this->em->find(LlmJob::class, $message->llmJobId()) ?? throw new \RuntimeException('The job is gone.'))->getUser();

        $answer = AgentAnswer::object($this->agent->call(new MessageBag(
            Message::ofUser($this->twig->render('author/cross_check_profile/_profile.md.twig', $user->getWritingProfile())),
        ), ['response_format' => CrossCheckAnswer::class, 'template_vars' => ['language_level' => $user->getLanguageLevel()]]), CrossCheckAnswer::class);

        $fields = [...User::WRITING_STRUCTURE, ...User::WRITING_VOICE];
        $user->setWritingProfileCheck(array_map(static fn (CrossCheckPoint $point): array => [
            // An unknown field from a weak model must not lose the point, and never reaches the page as an id.
            'field' => \in_array(trim($point->field), $fields, true) ? trim($point->field) : self::GENERAL,
            'issue' => trim($point->issue),
            'suggestion' => trim($point->suggestion),
        ], $answer->points));
        $this->em->flush();
    }
}
