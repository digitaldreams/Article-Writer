<?php

namespace App\Author\AddFeeds;

use App\Shared\Llm\LlmJob;
use App\Shared\Llm\LlmJobs;
use App\Shared\Llm\LlmJobStatus;
use App\Shared\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class AddFeedsController extends AbstractController
{
    #[Route('/author/feeds', name: 'author_add_feeds', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, EntityManagerInterface $em, #[CurrentUser] User $user): Response
    {
        $form = $this->createForm(AddFeedType::class)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{url: string} $data */
            $data = $form->getData();
            if (null !== $em->getRepository(Feed::class)->findOneBy(['url' => $data['url']])) {
                $form->get('url')->addError(new FormError('This feed is already added.'));
            } else {
                $em->persist(new Feed($user, $data['url']));
                $em->flush();

                return $this->redirectToRoute('author_add_feeds', status: Response::HTTP_SEE_OTHER);
            }
        }

        $feeds = $em->getRepository(Feed::class)->findBy([], ['id' => 'ASC']);

        return $this->render('author/add_feeds/index.html.twig', [
            'form' => $form,
            'feeds' => array_map(fn (Feed $feed): array => ['feed' => $feed, 'job' => $this->jobsOf($em, $feed)[0] ?? null], $feeds),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/author/feeds/{id<\d+>}/refresh', name: 'author_refresh_feed', methods: ['POST'])]
    public function refresh(Feed $feed, EntityManagerInterface $em, LlmJobs $jobs): Response
    {
        $this->assertIdle($em, $feed);
        $feedId = (int) $feed->getId();
        $em->wrapInTransaction(static function () use ($jobs, $feed, $feedId): void {
            $jobs->start(null, 'fetch_feed', 'io', static fn (int $id) => new FetchFeed($id, $feedId), $feed->getUser());
        });

        return $this->redirectToRoute('author_add_feeds', status: Response::HTTP_SEE_OTHER);
    }

    /** Imported articles stay; only the feed goes. */
    #[Route('/author/feeds/{id<\d+>}/delete', name: 'author_delete_feed', methods: ['POST'])]
    public function delete(Feed $feed, EntityManagerInterface $em): Response
    {
        $this->assertIdle($em, $feed);
        foreach ($this->jobsOf($em, $feed) as $job) {
            $em->remove($job);
        }
        $em->remove($feed);
        $em->flush();

        return $this->redirectToRoute('author_add_feeds', status: Response::HTTP_SEE_OTHER);
    }

    private function assertIdle(EntityManagerInterface $em, Feed $feed): void
    {
        foreach ($this->jobsOf($em, $feed) as $job) {
            if (\in_array($job->getStatus(), [LlmJobStatus::Queued, LlmJobStatus::Working], true)) {
                throw new ConflictHttpException('The feed is being refreshed.');
            }
        }
    }

    /** @return list<LlmJob> newest first */
    private function jobsOf(EntityManagerInterface $em, Feed $feed): array
    {
        $jobs = $em->getRepository(LlmJob::class)->findBy(['step' => 'fetch_feed'], ['id' => 'DESC']);

        return array_values(array_filter($jobs, static function (LlmJob $job) use ($feed): bool {
            $message = $job->storedMessage();

            return $message instanceof FetchFeed && $message->feedId === $feed->getId();
        }));
    }
}
