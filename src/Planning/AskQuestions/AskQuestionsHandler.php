<?php

namespace App\Planning\AskQuestions;

use App\Shared\Llm\AgentAnswer;
use App\Planning\PlanInput;
use App\Planning\Question;
use App\Planning\Reference;
use App\Planning\ReferenceStatus;
use App\Shared\Article\Article;
use App\Shared\WriterProfile\WriterProfile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Twig\Environment;

/** Both personas ask their questions in one primary call. A replay replaces the questions. */
#[AsMessageHandler(fromTransport: 'llm_primary')]
final readonly class AskQuestionsHandler
{
    public const MAX_QUESTIONS = 5;

    public function __construct(
        #[Autowire(service: 'ai.agent.primary')]
        private AgentInterface $agent,
        private EntityManagerInterface $em,
        private Environment $twig,
        #[Autowire('%kernel.project_dir%/personas')]
        private string $personasDir,
    ) {
    }

    public function __invoke(AskQuestions $message): void
    {
        $article = $this->em->find(Article::class, $message->articleId) ?? throw new \RuntimeException('The article is gone.');
        $input = $this->em->getRepository(PlanInput::class)->findOneBy(['article' => $article]) ?? throw new \RuntimeException('The plan input is gone.');
        $profile = $this->em->getRepository(WriterProfile::class)->findOneBy([]);

        $answer = AgentAnswer::object($this->agent->call(new MessageBag(
            Message::forSystem($this->twig->render('planning/ask_questions/ask_questions.md.twig', [
                'interviewer' => $this->persona('interviewer.md'),
                'reader' => strtr($this->persona('reader.md'), [
                    '{audience}' => '' !== $input->getAudience() ? $input->getAudience() : 'not given',
                    '{reader_level}' => $input->getReaderLevel() ?? 'not given',
                ]),
                'max' => self::MAX_QUESTIONS,
                'language_level' => $profile?->getLanguageLevel() ?? '',
            ])),
            Message::ofUser($this->twig->render('planning/ask_questions/_plan.md.twig', [
                'short_bio' => $profile?->getShortBio() ?? '',
                'input' => $input,
                'digests' => $this->em->getRepository(Reference::class)->findBy(['article' => $article, 'status' => ReferenceStatus::Done], ['id' => 'ASC']),
            ])),
        ), ['response_format' => QuestionList::class]), QuestionList::class);
        if (\count($answer->questions) > self::MAX_QUESTIONS) {
            throw new \RuntimeException(\sprintf('The model asked %d questions; at most %d are allowed.', \count($answer->questions), self::MAX_QUESTIONS));
        }

        $this->em->wrapInTransaction(function () use ($article, $answer): void {
            $this->em->createQuery('DELETE FROM '.Question::class.' q WHERE q.article = :article')->setParameter('article', $article)->execute();
            foreach ($answer->questions as $position => $asked) {
                $this->em->persist(new Question($article, $asked->role, $asked->text, $position, $asked->options));
            }
        });
    }

    private function persona(string $file): string
    {
        $content = file_get_contents($this->personasDir.'/'.$file);

        return false === $content ? throw new \RuntimeException(\sprintf('The persona file "%s" is missing.', $file)) : $content;
    }
}
