<?php

namespace App\Shared\User;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/** On for the whole web request, off after it, so code sharing the entity manager (tests, workers) sees all rows. */
final readonly class EnableOwnerFilter
{
    public function __construct(private EntityManagerInterface $em, private Security $security)
    {
    }

    /** Priority below the firewall (8), so the logged-in author is known. */
    #[AsEventListener(priority: 5)]
    public function enable(RequestEvent $event): void
    {
        $user = $this->security->getUser();
        if (!$event->isMainRequest() || !$user instanceof User) {
            return;
        }

        $this->em->getFilters()->enable('owner')->setParameter('user', $user->getId());
    }

    #[AsEventListener]
    public function disable(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest() && $this->em->getFilters()->isEnabled('owner')) {
            $this->em->getFilters()->disable('owner');
        }
    }
}
