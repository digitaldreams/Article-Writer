<?php

namespace App\Planning\DigestReferences;

use App\Planning\Reference;
use App\Planning\ReferencesAdded;
use App\Planning\ReferenceType;
use App\Shared\Llm\LlmJobs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** One job per added reference, in Brainstorm's transaction: images are described, links and docs extracted. */
final readonly class StartReferenceJobs
{
    public function __construct(private EntityManagerInterface $em, private LlmJobs $jobs)
    {
    }

    #[AsEventListener]
    public function __invoke(ReferencesAdded $event): void
    {
        foreach ($this->em->getRepository(Reference::class)->findBy(['id' => $event->referenceIds], ['id' => 'ASC']) as $reference) {
            $referenceId = (int) $reference->getId();
            ReferenceType::Image === $reference->getType()
                ? $this->jobs->start($reference->getArticle(), 'describe_image', 'vision', static fn (int $id) => new DescribeImage($id, $referenceId))
                : $this->jobs->start($reference->getArticle(), 'extract_reference', 'io', static fn (int $id) => new ExtractReference($id, $referenceId));
        }
    }
}
