<?php

namespace App\Setup\ImportArticles;

use App\Shared\Llm\AgentAnswer;
use App\Shared\WriterProfile\WriterProfile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Twig\Environment;

#[AsMessageHandler(fromTransport: 'llm_secondary')]
final readonly class MergeStructureProfileHandler
{
    public function __construct(
        #[Autowire(service: 'ai.agent.secondary')]
        private AgentInterface $agent,
        private EntityManagerInterface $em,
        private Environment $twig,
    ) {
    }

    public function __invoke(MergeStructureProfile $message): void
    {
        $profile = $this->em->getRepository(WriterProfile::class)->findOneBy([]) ?? throw new \RuntimeException('There is no writer profile.');
        $notes = array_map(static fn (StructureNote $n) => [
            'opening' => $n->openingType,
            'sections' => $n->sectionCount,
            'ordering' => $n->orderingPattern,
            'running_example' => $n->hasRunningExample,
            'closing' => $n->closing,
        ], $this->em->getRepository(StructureNote::class)->findAll());

        $answer = AgentAnswer::object($this->agent->call(new MessageBag(
            Message::forSystem($this->twig->render('setup/import_articles/merge_structure_profile.md.twig', ['language_level' => $profile->getLanguageLevel()])),
            Message::ofUser(json_encode($notes, \JSON_THROW_ON_ERROR)),
        ), ['response_format' => StructureProfileAnswer::class]), StructureProfileAnswer::class);

        $profile->setStructureProfile([
            'openingType' => $answer->openingType,
            'orderingHabit' => $answer->orderingHabit,
            'sectionCount' => $answer->sectionCount,
            'runningExample' => $answer->runningExample,
            'closing' => $answer->closing,
        ]);
        $this->em->flush();
    }
}
