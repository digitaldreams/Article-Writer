<?php

namespace App\Author\Onboarding;

use App\Shared\User\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Keeps an author who has not finished onboarding inside the wizard: every page they open sends them to /welcome.
 * POSTs pass, so the wizard forms reach the Author slices; the redirect after the save then lands here.
 */
final readonly class RedirectToOnboarding
{
    /** The wizard, login/logout/register, job cards and Retry, and the profiler. */
    private const ALLOWED = ['author_onboarding', 'user_', 'llm_', '_'];

    public function __construct(private Security $security, private UrlGeneratorInterface $urls)
    {
    }

    /** Priority below the firewall (8), so the logged-in author is known. */
    #[AsEventListener(priority: 4)]
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $user = $this->security->getUser();
        if (!$event->isMainRequest() || !$request->isMethod('GET') || !$user instanceof User || $user->isOnboarded()) {
            return;
        }

        $route = $request->attributes->getString('_route');
        foreach (self::ALLOWED as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return;
            }
        }

        $event->setResponse(new RedirectResponse($this->urls->generate('author_onboarding')));
    }
}
