<?php

namespace App\Planning\AskQuestions;

use App\Shared\Llm\AgentAnswer;
use App\Planning\PlanInput;
use App\Planning\Question;
use App\Planning\Reference;
use App\Planning\ReferenceStatus;
use App\Shared\Article\Article;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Twig\Environment;

/** The model plays the article's reader and asks the writer its questions in one call. The system prompt is prompts/questions_agent.md, set on the agent in ai.yaml. A replay replaces the questions. */
#[AsMessageHandler(fromTransport: 'llm_primary')]
final readonly class AskQuestionsHandler
{
    public const MAX_QUESTIONS = 5;

    public function __construct(
        #[Autowire(service: 'ai.agent.questions')]
        private AgentInterface $agent,
        private EntityManagerInterface $em,
        private Environment $twig,
    ) {
    }

    public function __invoke(AskQuestions $message): void
    {
        $article = $this->em->find(Article::class, $message->articleId) ?? throw new \RuntimeException('The article is gone.');
        $input = $this->em->getRepository(PlanInput::class)->findOneBy(['article' => $article]) ?? throw new \RuntimeException('The plan input is gone.');
        $user = $article->getUser();

        $answer = AgentAnswer::object($this->agent->call(new MessageBag(
            Message::ofUser($this->twig->render('planning/ask_questions/_request.md.twig', [
                'short_bio' => $user->getShortBio(),
                'input' => $input,
                'digests' => $this->em->getRepository(Reference::class)->findBy(['article' => $article, 'status' => ReferenceStatus::Done], ['id' => 'ASC']),
            ])),
        ), ['response_format' => QuestionList::class, 'template_vars' => [
            'max' => self::MAX_QUESTIONS,
            'audience' => '' !== $input->getAudience() ? $input->getAudience() : 'not given',
            'reader_level' => $input->getReaderLevel() ?? 'not given',
            'language_level' => $user->getLanguageLevel(),
        ]]), QuestionList::class);
        if (\count($answer->questions) > self::MAX_QUESTIONS) {
            throw new \RuntimeException(\sprintf('The model asked %d questions; at most %d are allowed.', \count($answer->questions), self::MAX_QUESTIONS));
        }

        $this->em->wrapInTransaction(function () use ($article, $answer): void {
            $this->em->createQuery('DELETE FROM '.Question::class.' q WHERE q.article = :article')->setParameter('article', $article)->execute();
            foreach ($answer->questions as $position => $asked) {
                $this->em->persist(new Question($article, $asked->text, $position, $asked->options, $asked->multiple));
            }
        });
    }
}
