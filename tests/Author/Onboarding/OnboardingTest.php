<?php

namespace App\Tests\Author\Onboarding;

use App\Author\AnalyzeWriting\ExtractWritingNotes;
use App\Author\AnalyzeWriting\MergeWritingProfile;
use App\Author\ImportArticle;
use App\Shared\Article\Article;
use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobs;
use App\Shared\User\User;
use App\Tests\LogsInAuthor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class OnboardingTest extends WebTestCase
{
    use LogsInAuthor;

    public function testANewAuthorOpeningPlansIsSentToTheWizard(): void
    {
        $client = $this->newAuthorClient();

        $client->request('GET', '/plans');

        self::assertResponseRedirects('/welcome');
    }

    public function testTheWizardStartsWithTheImportStep(): void
    {
        $client = $this->newAuthorClient();

        $client->request('GET', '/welcome');

        self::assertSelectorTextContains('[aria-current="step"]', 'Import past articles');
        self::assertSelectorExists('form[action="/author/import"]');
    }

    public function testAfterAnUploadTheWizardShowsTheBioStep(): void
    {
        $client = $this->newAuthorClient();
        $path = (string) tempnam(sys_get_temp_dir(), 'md');
        file_put_contents($path, "# Hello\n\nBody.\n");

        $client->request('POST', '/author/import', [], ['files' => [new UploadedFile($path, 'hello.md', 'text/markdown', null, true)]]);
        $client->followRedirect();

        self::assertResponseRedirects('/welcome');
        $client->followRedirect();
        self::assertSelectorTextContains('[aria-current="step"]', 'Bio');
        self::assertSelectorExists('form[action="/author"]');
    }

    public function testAfterSavingTheBioTheWizardShowsTheAnalyzeStep(): void
    {
        $client = $this->newAuthorClient();
        $this->importJob(done: true);
        $client->request('GET', '/welcome');

        $client->submit($client->getCrawler()->selectButton('Save')->form([
            'edit_bio[shortBio]' => 'Short',
            'edit_bio[longBio]' => 'Long',
            'edit_bio[languageLevel]' => 'B2',
        ]));
        $client->followRedirect();
        $client->followRedirect();

        self::assertSelectorTextContains('[aria-current="step"]', 'Analyze your writing');
    }

    public function testAnalyzeIsBlockedWhileImportsAreStillRunning(): void
    {
        $client = $this->newAuthorClient();
        $this->importJob(done: true);
        $this->importJob(done: false);
        $this->author->updateProfile('Short', 'Long', 'B2');
        $this->em()->flush();

        $client->request('GET', '/welcome');
        self::assertSelectorExists('button[disabled]');

        $client->request('POST', '/author/profile/analyze');
        self::assertResponseStatusCodeSame(409);
    }

    public function testWhenEveryImportFailedTheWizardShowsTheImportStepAgain(): void
    {
        $client = $this->newAuthorClient();
        $this->importJob(done: false)->markFailed('Not a post.');
        $this->author->updateProfile('Short', 'Long', 'B2');
        $this->em()->flush();

        $client->request('GET', '/welcome');

        self::assertSelectorTextContains('[aria-current="step"]', 'Import past articles');
        self::assertSelectorTextContains('.job-error', 'Not a post.');
    }

    public function testWhileTheAnalysisRunsTheWizardShowsProgressAndPlansStayLocked(): void
    {
        $client = $this->newAuthorClient();
        $this->readyForAnalysis();
        $this->notesJob();

        $client->request('GET', '/welcome');
        self::assertSelectorTextContains('[aria-current="step"]', 'Approve your writing profile');
        self::assertSelectorExists('[data-controller="page-poll"]');
        self::assertSelectorExists('.structure-job turbo-frame');

        $client->request('GET', '/plans');
        self::assertResponseRedirects('/welcome');
    }

    public function testApproveBeforeTheProfileIsReadyIs409(): void
    {
        $client = $this->newAuthorClient();
        $this->readyForAnalysis();
        $this->notesJob();

        $client->request('POST', '/welcome/approve');

        self::assertResponseStatusCodeSame(409);
    }

    public function testApproveAfterTheProfileIsReadyOnboardsTheAuthor(): void
    {
        $client = $this->newAuthorClient();
        $this->readyForAnalysis();
        $this->notesJob()->markDone();
        $this->profileMerged();

        $client->request('GET', '/welcome');
        self::assertSelectorExists('form[action="/author/profile"]');
        $client->submit($client->getCrawler()->selectButton('Approve and start writing')->form());

        self::assertResponseRedirects('/plans');
        self::assertTrue($this->em()->find(User::class, $this->author->getId())?->isOnboarded());
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testAnOnboardedAuthorIsNeverRedirected(): void
    {
        $client = static::createClient();
        $this->logIn($client);

        $client->request('GET', '/plans');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/welcome');
        self::assertResponseRedirects('/plans');
    }

    public function testJobCardsAndRetryWorkDuringTheWizard(): void
    {
        $client = $this->newAuthorClient();
        $job = $this->importJob(done: false);
        $job->markFailed('Boom.');
        $this->em()->flush();

        $client->request('GET', '/jobs/'.$job->getId().'/card');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/jobs/'.$job->getId().'/retry');
        self::assertResponseRedirects('/jobs/'.$job->getId().'/card');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    private function newAuthorClient(): KernelBrowser
    {
        $client = static::createClient();
        $this->logIn($client, onboarded: false);

        return $client;
    }

    private function importJob(bool $done): LlmJob
    {
        $job = $this->jobs()->start(null, 'import_file', 'io', static fn (int $id) => new ImportArticle($id, 'a.md', "# A\n"), $this->author);
        if ($done) {
            $this->em()->persist(Article::imported($this->author, 'a-'.$job->getId(), 'A'));
            $job->markDone();
        }
        $this->em()->flush();

        return $job;
    }

    private function readyForAnalysis(): void
    {
        $this->importJob(done: true);
        $this->author->updateProfile('Short', 'Long', 'B2');
        $this->em()->flush();
    }

    private function notesJob(): LlmJob
    {
        $article = $this->em()->getRepository(Article::class)->findOneBy(['user' => $this->author]) ?? throw new \LogicException();
        $articleId = (int) $article->getId();

        return $this->jobs()->start($article, 'extract_writing_notes', 'secondary', static fn (int $id) => new ExtractWritingNotes($id, $articleId));
    }

    private function profileMerged(): void
    {
        $this->jobs()->start(null, 'merge_writing_profile', 'secondary', static fn (int $id) => new MergeWritingProfile($id), $this->author)->markDone();
        $this->author->setWritingProfile(
            ['openingType' => 'a story', 'orderingHabit' => 'by feature', 'sectionCount' => '5', 'runningExample' => 'yes', 'closing' => 'summary'],
            [],
        );
        $this->em()->flush();
    }

    private function jobs(): LlmJobs
    {
        return self::getContainer()->get(LlmJobs::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
