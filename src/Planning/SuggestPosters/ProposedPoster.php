<?php

namespace App\Planning\SuggestPosters;

final readonly class ProposedPoster
{
    /**
     * @param string $headline the text printed on the poster, at most 8 words
     * @param string $picture  what the picture shows, concrete enough for a designer to draw it
     * @param string $layout   where the headline and the picture sit, and the style of the picture
     * @param string $colors   the main colors, for example "deep navy, warm orange accent, off-white text"
     * @param string $sketch   a rough SVG sketch of the poster: <svg viewBox="0 0 1200 630">, flat shapes and the headline only, at most 6 KB
     */
    public function __construct(
        public string $headline,
        public string $picture,
        public string $layout,
        public string $colors,
        public string $sketch,
    ) {
    }
}
