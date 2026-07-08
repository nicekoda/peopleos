<?php

namespace App\Enums;

enum ApplicationStage: string
{
    case Applied = 'applied';
    case Screening = 'screening';
    case Interview = 'interview';
    case Offer = 'offer';
    case Rejected = 'rejected';
    case Hired = 'hired';
    case Withdrawn = 'withdrawn';

    /**
     * The pipeline can move forward or be exited (rejected/withdrawn) at
     * any non-terminal point; hired/rejected/withdrawn are terminal —
     * nothing transitions out of them. Mirrors LifecycleProcessStatus's
     * "single source of truth, checked server-side" shape.
     *
     * @return list<self>
     */
    public function allowedNextStates(): array
    {
        return match ($this) {
            self::Applied => [self::Screening, self::Rejected, self::Withdrawn],
            self::Screening => [self::Interview, self::Rejected, self::Withdrawn],
            self::Interview => [self::Offer, self::Rejected, self::Withdrawn],
            self::Offer => [self::Hired, self::Rejected, self::Withdrawn],
            self::Rejected, self::Hired, self::Withdrawn => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNextStates(), true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Rejected, self::Hired, self::Withdrawn], true);
    }
}
