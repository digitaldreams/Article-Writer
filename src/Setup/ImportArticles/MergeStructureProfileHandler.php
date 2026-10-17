<?php

namespace App\Setup\ImportArticles;

use App\Shared\Llm\AgentAnswer;
use App\Shared\Llm\LlmJob;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'llm_secondary')]
final readonly class MergeStructureProfileHandler
{
    public function __construct(
        #[Autowire(service: 'ai.agent.structure_profile')]
        private AgentInterface $agent,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(MergeStructureProfile $message): void
    {
        $user = ($this->em->find(LlmJob::class, $message->llmJobId()) ?? throw new \RuntimeException('The job is gone.'))->getUser();
        $notes = array_map(static fn (StructureNote $n) => [
            'opening' => $n->openingType,
            'sections' => $n->sectionCount,
            'ordering' => $n->orderingPattern,
            'running_example' => $n->hasRunningExample,
            'closing' => $n->closing,
        ], $this->em->getRepository(StructureNote::class)->findBy(['user' => $user]));

        $answer = AgentAnswer::object($this->agent->call(new MessageBag(
            Message::ofUser(json_encode($notes, \JSON_THROW_ON_ERROR)),
        ), ['response_format' => StructureProfileAnswer::class, 'template_vars' => ['language_level' => $user->getLanguageLevel()]]), StructureProfileAnswer::class);

        $user->setStructureProfile([
            'openingType' => $answer->openingType,
            'orderingHabit' => $answer->orderingHabit,
            'sectionCount' => $answer->sectionCount,
            'runningExample' => $answer->runningExample,
            'closing' => $answer->closing,
        ]);
        $this->em->flush();
    }
}
