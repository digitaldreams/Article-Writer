<?php

namespace App\Author\ShowPrompts;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShowPromptsController extends AbstractController
{
    /** Read-only: the system prompt files as they are on disk, {placeholders} left raw. */
    #[Route('/author/prompts', name: 'author_show_prompts', methods: ['GET'])]
    public function __invoke(#[Autowire('%kernel.project_dir%/prompts')] string $dir): Response
    {
        $prompts = [];
        foreach (glob($dir.'/*.md') ?: [] as $file) {
            $prompts[basename($file)] = (string) file_get_contents($file);
        }

        return $this->render('author/show_prompts/index.html.twig', ['prompts' => $prompts]);
    }
}
