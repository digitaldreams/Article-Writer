<?php

namespace App\Shared;

use Twig\FileExtensionEscapingStrategy;

/** Prompt templates (*.md.twig) go to the model as plain text: never escape them. */
final class PromptEscaping
{
    public function __invoke(string $name): string|false
    {
        return str_ends_with($name, '.md.twig') ? false : FileExtensionEscapingStrategy::guess($name);
    }
}
