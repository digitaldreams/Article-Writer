<?php

namespace App\Planning;

use App\Shared\Article\Article;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What every primary planning prompt gets: the plan input, the answered questions, the reference digests,
 * the structure profile and the language level. Rendered by templates/planning/_plan_context.md.twig.
 */
final readonly class PlanContext
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @return array{input: PlanInput, answers: list<Question>, digests: list<Reference>, structure_profile: array<string, mixed>, language_level: string}
     */
    public function of(Article $article): array
    {
        $user = $article->getUser();
        $questions = $this->em->getRepository(Question::class)->findBy(['article' => $article], ['position' => 'ASC']);

        return [
            'input' => $this->em->getRepository(PlanInput::class)->findOneBy(['article' => $article]) ?? throw new \RuntimeException('The plan input is gone.'),
            'answers' => array_values(array_filter($questions, static fn (Question $q) => !$q->isSkipped() && null !== $q->getAnswer())),
            'digests' => $this->em->getRepository(Reference::class)->findBy(['article' => $article, 'status' => ReferenceStatus::Done], ['id' => 'ASC']),
            'structure_profile' => $user->getWritingProfile()['structure'],
            'language_level' => $user->getLanguageLevel(),
        ];
    }
}
