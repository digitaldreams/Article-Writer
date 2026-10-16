<?php

namespace App\Planning\SuggestPosters;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Twig\Environment;

/** Draws one poster idea; a new picture replaces the old one. */
#[AsMessageHandler(fromTransport: 'llm_primary')]
final readonly class GeneratePosterHandler
{
    public function __construct(
        private PosterImage $image,
        private EntityManagerInterface $em,
        private Environment $twig,
    ) {
    }

    public function __invoke(GeneratePoster $message): void
    {
        $idea = $this->em->find(PosterIdea::class, $message->ideaId) ?? throw new \RuntimeException('The poster idea is gone.');

        $this->image->draw($idea, $this->twig->render('planning/suggest_posters/_image_prompt.txt.twig', ['idea' => $idea]));
    }
}
