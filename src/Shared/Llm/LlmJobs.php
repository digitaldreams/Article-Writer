<?php

namespace App\Shared\Llm;

use App\Shared\Article\Article;
use App\Shared\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/** Creates the LlmJob(queued) and dispatches its message. The caller owns the transaction. */
final readonly class LlmJobs
{
    public function __construct(
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        #[Autowire('%app.ai.primary_platform%')]
        private string $primaryPlatform,
        #[Autowire(env: 'AI_PRIMARY_MODEL')]
        private string $primaryModel,
        #[Autowire('%app.ai.secondary_platform%')]
        private string $secondaryPlatform,
        #[Autowire(env: 'AI_SECONDARY_MODEL')]
        private string $secondaryModel,
        #[Autowire('%app.ai.image_platform%')]
        private string $imagePlatform,
        #[Autowire(env: 'AI_IMAGE_MODEL')]
        private string $imageModel,
    ) {
    }

    /** A queued or working job of this step: a second request would take the same round and do nothing. */
    public function isRunning(Article $article, string $step): bool
    {
        return $this->em->getRepository(LlmJob::class)->count(['article' => $article, 'step' => $step, 'status' => [LlmJobStatus::Queued, LlmJobStatus::Working]]) > 0;
    }

    /**
     * @param 'primary'|'secondary'|'image'|'io' $role
     * @param \Closure(int): LlmJobMessage $message builds the message from the job id
     * @param User|null $user only for a job without an article; otherwise the article's user owns it
     */
    public function start(?Article $article, string $step, string $role, \Closure $message, ?User $user = null): LlmJob
    {
        [$platform, $model] = match ($role) {
            'primary' => [$this->primaryPlatform, $this->primaryModel],
            'secondary' => [$this->secondaryPlatform, $this->secondaryModel],
            'image' => [$this->imagePlatform, $this->imageModel],
            'io' => ['local', '-'],
        };
        $job = new LlmJob($article, $step, $role, str_replace('ai.platform.', '', $platform), $model, $user);
        $this->em->persist($job);
        $this->em->flush();

        $built = $message((int) $job->getId());
        $job->storeMessage($built);
        $this->em->flush();
        $this->bus->dispatch($built);

        return $job;
    }
}
