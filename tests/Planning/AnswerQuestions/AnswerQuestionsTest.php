<?php

namespace App\Tests\Planning\AnswerQuestions;

use App\Planning\Question;
use App\Planning\QuestionRole;
use App\Planning\QuestionsDone;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use App\Shared\Llm\LlmJob;
use App\Tests\LogsInAuthor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class AnswerQuestionsTest extends WebTestCase
{
    use LogsInAuthor;

    public function testAnswersSurviveAReload(): void
    {
        [$client, $article, [$first]] = $this->planWithQuestions();

        $client->request('POST', $this->questionUrl($article, $first), ['answer' => 'It removes boilerplate.']);
        self::assertResponseStatusCodeSame(204);
        $client->request('GET', '/plans/'.$article->getId().'/questions');

        self::assertSelectorTextSame('#answer-'.$first->getId(), 'It removes boilerplate.');
    }

    public function testAMultipleChoiceQuestionSavesTheChosenOption(): void
    {
        [$client, $article, [, $second]] = $this->planWithQuestions();
        $client->request('GET', '/plans/'.$article->getId().'/questions');
        self::assertSelectorCount(2, 'input[type=radio][name=answer]');

        $client->request('POST', $this->questionUrl($article, $second), ['answer' => 'Safer types']);
        $client->request('GET', '/plans/'.$article->getId().'/questions');

        self::assertSelectorExists('input[type=radio][value="Safer types"][checked]');
    }

    public function testSkipMarksTheQuestionSkipped(): void
    {
        [$client, $article, [, $second]] = $this->planWithQuestions();

        $client->request('POST', $this->questionUrl($article, $second), ['skip' => '1']);

        self::assertResponseRedirects('/plans/'.$article->getId().'/questions');
        self::assertTrue($this->em()->find(Question::class, $second->getId())?->isSkipped());
    }

    public function testNextWorksWithEveryQuestionSkipped(): void
    {
        [$client, $article, $questions] = $this->planWithQuestions();
        $fired = [];
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(QuestionsDone::class, static function (QuestionsDone $e) use (&$fired): void {
            $fired[] = $e->articleId;
        });
        foreach ($questions as $question) {
            $client->request('POST', $this->questionUrl($article, $question), ['skip' => '1']);
        }

        $client->request('POST', '/plans/'.$article->getId().'/questions/next');

        self::assertResponseRedirects('/plans/'.$article->getId());
        self::assertSame(ArticleState::Flows, $this->em()->find(Article::class, $article->getId())?->getState());
        self::assertSame([$article->getId()], $fired);
    }

    public function testNextInTheWrongStateIsAConflict(): void
    {
        [$client, $article] = $this->planWithQuestions();
        $client->request('POST', '/plans/'.$article->getId().'/questions/next');

        $client->request('POST', '/plans/'.$article->getId().'/questions/next');

        self::assertResponseStatusCodeSame(409);
    }

    public function testAnswerAfterTheQuestionsStepIsAConflict(): void
    {
        [$client, $article, [$first]] = $this->planWithQuestions();
        $client->request('POST', '/plans/'.$article->getId().'/questions/next');

        $client->request('POST', $this->questionUrl($article, $first), ['answer' => 'late']);

        self::assertResponseStatusCodeSame(409);
    }

    /** @return array{KernelBrowser, Article, list<Question>} */
    private function planWithQuestions(): array
    {
        $client = static::createClient();
        $this->logIn($client);
        $client->disableReboot();
        $crawler = $client->request('GET', '/plans/new');
        $client->submit($crawler->selectButton('Create')->form(['create_plan[topic]' => 'PHP 8 features '.uniqid()]));

        $em = $this->em();
        $article = $em->getRepository(Article::class)->findOneBy([]) ?? throw new \LogicException('No article.');
        $client->request('POST', '/plans/'.$article->getId().'/brainstorm/next');
        $em->clear();
        $article = $em->find(Article::class, $article->getId()) ?? throw new \LogicException('No article.');
        $em->getRepository(LlmJob::class)->findOneBy(['step' => 'ask_questions'])?->markDone();
        $questions = [
            new Question($article, QuestionRole::Interviewer, 'Which problem does it solve?', 0),
            new Question($article, QuestionRole::Reader, 'Which hook works for you?', 1, ['Less boilerplate', 'Safer types']),
        ];
        foreach ($questions as $question) {
            $em->persist($question);
        }
        $em->flush();

        return [$client, $article, $questions];
    }

    private function questionUrl(Article $article, Question $question): string
    {
        return '/plans/'.$article->getId().'/questions/'.$question->getId();
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
