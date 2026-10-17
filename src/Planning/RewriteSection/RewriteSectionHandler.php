<?php

namespace App\Planning\RewriteSection;

use App\Shared\Llm\AgentAnswer;
use App\Shared\Storage\ArticleFolder;
use App\Shared\WriterProfile\WriterProfile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Twig\Environment;

/** Rewrites one section with the writer's instruction. The answer waits for Accept; the file is not touched here. */
#[AsMessageHandler(fromTransport: 'llm_primary')]
final readonly class RewriteSectionHandler
{
    public function __construct(
        #[Autowire(service: 'ai.agent.primary')]
        private AgentInterface $agent,
        private EntityManagerInterface $em,
        private ArticleFolder $folder,
        private Environment $twig,
    ) {
    }

    public function __invoke(RewriteSection $message): void
    {
        // A newer request for the same section replaced this one.
        $rewrite = $this->em->find(SectionRewrite::class, $message->rewriteId);
        if (null === $rewrite) {
            return;
        }
        $profile = $this->em->getRepository(WriterProfile::class)->findOneBy(['user' => $rewrite->getArticle()->getUser()]);

        $after = AgentAnswer::text($this->agent->call(new MessageBag(
            Message::forSystem($this->twig->render('planning/rewrite_section/rewrite_section.md.twig', [
                'language_level' => $profile?->getLanguageLevel() ?? '',
            ])),
            Message::ofUser($this->twig->render('planning/rewrite_section/_request.md.twig', [
                'body' => $this->folder->read($rewrite->getArticle()->getFolder(), 'article.md')['body'],
                'rewrite' => $rewrite,
            ])),
        )));
        $after = trim(preg_replace('/\A## .*(\n|\z)/', '', $after) ?? $after);
        if ('' === $after) {
            throw new \RuntimeException('The model wrote an empty section.');
        }

        $rewrite->suggest($after);
        $this->em->flush();
    }
}
