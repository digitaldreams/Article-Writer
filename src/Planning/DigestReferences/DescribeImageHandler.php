<?php

namespace App\Planning\DigestReferences;

use App\Planning\PlanInput;
use App\Planning\Reference;
use App\Shared\Llm\AgentAnswer;
use App\Shared\Llm\LlmJobs;
use App\Shared\Storage\ArticleFolder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Twig\Environment;

/** Describes an uploaded image with the vision agent, saves the description as its text, then queues its digest. */
#[AsMessageHandler(fromTransport: 'llm_secondary')]
final readonly class DescribeImageHandler
{
    public function __construct(
        #[Autowire(service: 'ai.agent.vision')]
        private AgentInterface $agent,
        private EntityManagerInterface $em,
        private ArticleFolder $articleFolder,
        private Environment $twig,
        private LlmJobs $jobs,
    ) {
    }

    public function __invoke(DescribeImage $message): void
    {
        $reference = $this->em->find(Reference::class, $message->referenceId) ?? throw new \RuntimeException('The reference is gone.');
        $article = $reference->getArticle();

        $description = trim(AgentAnswer::text($this->agent->call(new MessageBag(
            Message::forSystem($this->twig->render('planning/digest_references/describe_image.md.twig', [
                'topic' => $this->em->getRepository(PlanInput::class)->findOneBy(['article' => $article])?->getTopic() ?? '',
            ])),
            Message::ofUser(Image::fromFile($this->articleFolder->pathOf($article->getFolder(), $reference->getLocation()))),
        ))));
        if ('' === $description) {
            throw new \RuntimeException('The model did not describe the image.');
        }

        $this->em->wrapInTransaction(function () use ($reference, $description): void {
            $reference->saveExtractedText($description);
            $referenceId = (int) $reference->getId();
            $this->jobs->start($reference->getArticle(), 'digest_reference', 'secondary', static fn (int $id) => new DigestReference($id, $referenceId));
        });
    }
}
