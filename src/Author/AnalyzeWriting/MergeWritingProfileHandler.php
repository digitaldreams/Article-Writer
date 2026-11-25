<?php

namespace App\Author\AnalyzeWriting;

use App\Author\WritingNote;
use App\Shared\Llm\LlmJob;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'llm_secondary')]
final readonly class MergeWritingProfileHandler
{
    public function __construct(
        #[Autowire(service: 'ai.agent.writing_profile')]
        private AgentInterface $agent,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(MergeWritingProfile $message): void
    {
        $user = ($this->em->find(LlmJob::class, $message->llmJobId()) ?? throw new \RuntimeException('The job is gone.'))->getUser();
        // Workers have no owner filter: notes are read by the job's author.
        // Newest article first, with its date: the prompt follows the newest habits when old and new disagree.
        // ponytail: samples only from the newest 10 notes keep the prompt in a local context (num_ctx 16k); the other fields stay ~100 tokens a note.
        /** @var list<WritingNote> $notes */
        $notes = $this->em->createQuery('SELECT n, a, CASE WHEN a.publishedAt IS NULL THEN 1 ELSE 0 END AS HIDDEN undated
            FROM '.WritingNote::class.' n JOIN n.article a WHERE n.user = :user ORDER BY undated ASC, a.publishedAt DESC, n.id DESC')
            ->setParameter('user', $user)->getResult();
        $notes = array_map(static fn (WritingNote $n, int $i) => [
            'title' => $n->getArticle()->getTitle(),
            'published' => $n->getArticle()->getPublishedAt()?->format('Y-m'),
            'opening' => $n->openingType,
            'sections' => $n->sectionCount,
            'ordering' => $n->orderingPattern,
            'running_example' => $n->hasRunningExample,
            'closing' => $n->closing,
            'tone' => $n->tone,
            'sentence_style' => $n->sentenceStyle,
            'devices' => $n->devices,
            'code_habit' => $n->codeHabit,
            'samples' => $i < 10 ? $n->samples : [],
        ], $notes, array_keys($notes));

        $answer = $this->agent->call(new MessageBag(
            Message::ofUser(json_encode($notes, \JSON_THROW_ON_ERROR)),
        ), ['response_format' => WritingProfileAnswer::class, 'template_vars' => ['language_level' => $user->getLanguageLevel()]])->asObject(WritingProfileAnswer::class);

        $user->setWritingProfile([
            'openingType' => $answer->openingType,
            'orderingHabit' => $answer->orderingHabit,
            'sectionCount' => $answer->sectionCount,
            'runningExample' => $answer->runningExample,
            'closing' => $answer->closing,
        ], [
            'tone' => $answer->tone,
            'sentenceStyle' => $answer->sentenceStyle,
            'devices' => $answer->devices,
            'codeHabit' => $answer->codeHabit,
            'samples' => implode("\n\n", $answer->samples),
        ]);
        $this->em->flush();
    }
}
