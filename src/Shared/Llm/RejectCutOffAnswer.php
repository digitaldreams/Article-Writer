<?php

namespace App\Shared\Llm;

use Symfony\AI\Platform\Event\ResultConvertedEvent;
use Symfony\AI\Platform\FinishReason\FinishReason;
use Symfony\AI\Platform\FinishReason\FinishReasonCase;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A model stopped by its length limit answers with partial text. Fail the job rather than save it as a finished
 * article, section or digest. A cut structured answer already fails earlier, when its JSON can't be read.
 */
final class RejectCutOffAnswer
{
    #[AsEventListener]
    public function __invoke(ResultConvertedEvent $event): void
    {
        $reason = $event->getResult()->getMetadata()->get('finish_reason');
        if ($reason instanceof FinishReason && $reason->is(FinishReasonCase::LENGTH)) {
            throw new \RuntimeException('The model hit its length limit, so the answer is cut off.');
        }
    }
}
