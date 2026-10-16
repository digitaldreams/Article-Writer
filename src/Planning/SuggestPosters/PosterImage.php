<?php

namespace App\Planning\SuggestPosters;

use App\Shared\Storage\ArticleFolder;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The poster picture of an idea: images/poster-{id}.png in the article folder, drawn by AI_POSTER_IMAGE_MODEL.
 * An image model takes a plain prompt, not a MessageBag, so it runs on the poster platform directly rather than through an agent.
 */
final readonly class PosterImage
{
    public function __construct(
        #[Autowire(service: 'app.ai.poster_platform')]
        private PlatformInterface $platform,
        #[Autowire(env: 'AI_POSTER_IMAGE_MODEL')]
        private string $model,
        private ArticleFolder $folder,
    ) {
    }

    /** False when AI_POSTER_IMAGE_MODEL is empty or names a model that can't draw; then the page shows no button. */
    public function canDraw(): bool
    {
        try {
            return '' !== $this->model && $this->platform->getModelCatalog()->getModel($this->model)->supports(Capability::OUTPUT_IMAGE);
        } catch (\Throwable) {
            return false;
        }
    }

    public function draw(PosterIdea $idea, string $prompt): void
    {
        $path = $this->pathOf($idea);
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o777, true);
        }
        $this->platform->invoke($this->model, $prompt)->asFile($path);
    }

    public function pathOf(PosterIdea $idea): string
    {
        return $this->folder->pathOf($idea->getArticle()->getFolder(), 'images/poster-'.$idea->getId().'.png');
    }
}
