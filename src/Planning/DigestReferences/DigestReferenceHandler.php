<?php

namespace App\Planning\DigestReferences;

use App\Shared\Llm\AgentAnswer;
use App\Planning\PlanInput;
use App\Planning\Reference;
use App\Shared\WriterProfile\WriterProfile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Twig\Environment;

/** Writes a short digest of the extracted text. Long text is digested in parts, then the part digests are merged. */
#[AsMessageHandler(fromTransport: 'llm_secondary')]
final readonly class DigestReferenceHandler
{
    // ponytail: fixed size that fits small local models; read the model's context size if one ever needs more
    public const PART_CHARS = 12_000;

    public function __construct(
        #[Autowire(service: 'ai.agent.secondary')]
        private AgentInterface $agent,
        private EntityManagerInterface $em,
        private Environment $twig,
        private SettleReferences $settle,
    ) {
    }

    public function __invoke(DigestReference $message): void
    {
        $reference = $this->em->find(Reference::class, $message->referenceId) ?? throw new \RuntimeException('The reference is gone.');
        $topic = $this->em->getRepository(PlanInput::class)->findOneBy(['article' => $reference->getArticle()])?->getTopic() ?? '';
        $text = $reference->getExtractedText() ?? throw new \RuntimeException('The reference has no extracted text.');

        $parts = mb_str_split($text, self::PART_CHARS);
        $digest = 1 === \count($parts)
            ? $this->digest($topic, $text, false)
            : $this->digest($topic, implode("\n\n", array_map(fn (string $part) => $this->digest($topic, $part, false), $parts)), true);

        $this->settle->finish($message->referenceId, $digest);
    }

    private function digest(string $topic, string $text, bool $mergingParts): string
    {
        return AgentAnswer::text($this->agent->call(new MessageBag(
            Message::forSystem($this->twig->render('planning/digest_references/digest_reference.md.twig', [
                'topic' => $topic,
                'merging_parts' => $mergingParts,
                'language_level' => $this->em->getRepository(WriterProfile::class)->findOneBy([])?->getLanguageLevel() ?? '',
            ])),
            Message::ofUser($text),
        )));
    }
}
