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
        #[Autowire(env: 'AI_VISION_MODEL')]
        private string $visionModel,
        #[Autowire('%app.ai.title_platform%')]
        private string $titlePlatform,
        #[Autowire(env: 'AI_TITLE_MODEL')]
        private string $titleModel,
        #[Autowire('%app.ai.poster_platform%')]
        private string $posterPlatform,
        #[Autowire(env: 'AI_POSTER_MODEL')]
        private string $posterModel,
        #[Autowire(env: 'AI_POSTER_IMAGE_MODEL')]
        private string $posterImageModel,
    ) {
    }

    /**
     * @param 'primary'|'secondary'|'vision'|'title'|'poster'|'poster_image'|'io' $role
     * @param \Closure(int): LlmJobMessage $message builds the message from the job id
     * @param User|null $user only for a job without an article; otherwise the article's user owns it
     */
    public function start(?Article $article, string $step, string $role, \Closure $message, ?User $user = null): LlmJob
    {
        [$platform, $model] = match ($role) {
            'primary' => [$this->primaryPlatform, $this->primaryModel],
            'secondary' => [$this->secondaryPlatform, $this->secondaryModel],
            'vision' => [$this->secondaryPlatform, $this->visionModel],
            'title' => [$this->titlePlatform, $this->titleModel],
            'poster' => [$this->posterPlatform, $this->posterModel],
            'poster_image' => [$this->posterPlatform, $this->posterImageModel],
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
