<?php

namespace App\Tests\Planning\AnswerQuestions;

use App\Planning\Question;
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
        $client->request('GET', '/plans/'.$article->getId().'/questions?q=2');
        self::assertSelectorCount(2, 'input[type=radio][name=answer]');

        $client->request('POST', $this->questionUrl($article, $second), ['answer' => 'Safer types']);
        $client->request('GET', '/plans/'.$article->getId().'/questions?q=2');

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

    public function testNextOutlineSkipsFlowsAndQueuesTheOutlineJob(): void
    {
        [$client, $article] = $this->planWithQuestions();

        $client->request('POST', '/plans/'.$article->getId().'/questions/next', ['to' => 'outline']);

        self::assertResponseRedirects('/plans/'.$article->getId());
        $this->em()->clear();
        self::assertSame(ArticleState::Outline, $this->em()->find(Article::class, $article->getId())?->getState());
        self::assertNotNull($this->em()->getRepository(LlmJob::class)->findOneBy(['article' => $article->getId(), 'step' => 'generate_outline']));
        self::assertNull($this->em()->getRepository(LlmJob::class)->findOneBy(['article' => $article->getId(), 'step' => 'generate_flows']));
    }

    public function testQuestionsPageOffersOutlineAsTheMainNextStep(): void
    {
        [$client, $article] = $this->planWithQuestions();

        $client->request('GET', '/plans/'.$article->getId().'/questions');

        self::assertSelectorTextContains('button.button-primary[name="to"][value="outline"]', 'Next: outline');
        self::assertSelectorTextContains('button.button-secondary[name="to"][value="flows"]', 'Show flows first');
    }

    public function testQuestionsShowOneAtATimeWithProgress(): void
    {
        [$client, $article] = $this->planWithQuestions();

        $client->request('GET', '/plans/'.$article->getId().'/questions');
        self::assertSelectorCount(1, 'article.question');
        self::assertSelectorTextContains('.question-progress', 'Question 1 / 3');
        self::assertSelectorTextContains('article.question', 'Which problem does it solve?');
        self::assertSelectorNotExists('.question-nav a:contains("Previous")');

        $client->clickLink('Next question →');
        self::assertSelectorTextContains('.question-progress', 'Question 2 / 3');
        self::assertSelectorTextContains('article.question', 'Which hook works for you?');

        $client->clickLink('Next question →');
        self::assertSelectorTextContains('.question-progress', 'Question 3 / 3');
        self::assertSelectorNotExists('.question-nav a:contains("Next question")');
    }

    public function testASeveralChoicesQuestionSavesEveryPickedOption(): void
    {
        [$client, $article, [, , $third]] = $this->planWithQuestions();
        $client->request('GET', '/plans/'.$article->getId().'/questions?q=3');
        self::assertSelectorCount(3, 'input[type=checkbox][name="answer[]"]');
        self::assertSelectorTextContains('legend', 'Pick all that fit.');

        $client->request('POST', $this->questionUrl($article, $third), ['answer' => ['Benchmarks', 'Laravel']]);
        $client->request('GET', '/plans/'.$article->getId().'/questions?q=3');

        self::assertSame('Benchmarks; Laravel', $this->em()->find(Question::class, $third->getId())?->getAnswer());
        self::assertSelectorExists('input[value="Benchmarks"][checked]');
        self::assertSelectorExists('input[value="Laravel"][checked]');
        self::assertSelectorNotExists('input[value="Old PHP versions"][checked]');
    }

    public function testSkipShowsTheNextQuestion(): void
    {
        [$client, $article, $questions] = $this->planWithQuestions();

        $client->request('POST', $this->questionUrl($article, $questions[0]), ['skip' => '1', 'q' => '2']);

        self::assertResponseRedirects('/plans/'.$article->getId().'/questions?q=2');
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
            new Question($article, 'Which problem does it solve?', 0),
            new Question($article, 'Which hook works for you?', 1, ['Less boilerplate', 'Safer types']),
            new Question($article, 'What stays out?', 2, ['Benchmarks', 'Old PHP versions', 'Laravel'], multiple: true),
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
