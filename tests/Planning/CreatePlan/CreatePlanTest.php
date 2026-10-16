<?php

namespace App\Tests\Planning\CreatePlan;

use App\Planning\PlanInput;
use App\Planning\Reference;
use App\Planning\ReferencesSettled;
use App\Planning\ReferenceType;
use App\Shared\Article\Article;
use App\Shared\Article\ArticleState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class CreatePlanTest extends WebTestCase
{
    public function testSubmittedPlanIsSavedInStatePlanWithUploadsInRefs(): void
    {
        $client = static::createClient();
        $dir = sys_get_temp_dir();
        file_put_contents($dir.'/notes.md', '# notes');
        file_put_contents($dir.'/shot.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='));

        $crawler = $client->request('GET', '/plans/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Create')->form([
            'create_plan[topic]' => 'Symfony AI Agents '.uniqid(),
            'create_plan[audience]' => 'PHP developers',
            'create_plan[links]' => "https://symfony.com/doc\n\n",
        ]);
        $form['create_plan[docs]'][0]->upload($dir.'/notes.md');
        $form['create_plan[images]'][0]->upload($dir.'/shot.png');
        $client->submit($form);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $article = $em->getRepository(Article::class)->findOneBy([]);
        self::assertInstanceOf(Article::class, $article);
        self::assertResponseRedirects('/plans/'.$article->getId());
        self::assertSame(ArticleState::Plan, $article->getState());
        self::assertNotNull($em->getRepository(PlanInput::class)->findOneBy(['article' => $article]));

        $references = $em->getRepository(Reference::class)->findBy(['article' => $article], ['id' => 'ASC']);
        self::assertSame(
            [ReferenceType::Link, ReferenceType::Doc, ReferenceType::Image],
            array_map(fn (Reference $r) => $r->getType(), $references),
        );
        self::assertSame(['https://symfony.com/doc', 'refs/notes.md', 'refs/shot.png'], array_map(fn (Reference $r) => $r->getLocation(), $references));
        $root = self::getContainer()->getParameter('kernel.project_dir').'/var/test-articles/'.$article->getFolder();
        self::assertFileExists($root.'/refs/notes.md');
        self::assertFileExists($root.'/refs/shot.png');
    }

    public function testMissingTopicGives422AndSavesNothing(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/plans/new');
        $client->submit($crawler->selectButton('Create')->form(['create_plan[topic]' => '']));

        self::assertResponseStatusCodeSame(422);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame([], $em->getRepository(Article::class)->findAll());
        self::assertSame([], $em->getRepository(PlanInput::class)->findAll());
    }

    public function testPlanWithNoReferencesFiresReferencesSettledAtOnce(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $fired = $this->listenForReferencesSettled();

        $this->submitTopic($client, 'No refs '.uniqid());
        self::assertCount(1, $fired);
        self::assertResponseRedirects();
    }

    public function testPlanWithAReferenceWaitsForItToSettle(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $fired = $this->listenForReferencesSettled();

        $this->submitTopic($client, 'One ref '.uniqid(), 'https://symfony.com');
        self::assertResponseRedirects();
        self::assertCount(0, $fired);
    }

    private function listenForReferencesSettled(): \Countable
    {
        $fired = new \SplObjectStorage();
        self::getContainer()->get(EventDispatcherInterface::class)
            ->addListener(ReferencesSettled::class, static fn (ReferencesSettled $e) => $fired->attach($e));

        return $fired;
    }

    private function submitTopic(KernelBrowser $client, string $topic, string $links = ''): void
    {
        $crawler = $client->request('GET', '/plans/new');
        $client->submit($crawler->selectButton('Create')->form(['create_plan[topic]' => $topic, 'create_plan[links]' => $links]));
    }
}
