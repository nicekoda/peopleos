<?php

namespace App\Enums;

enum RecruitmentJobStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case OnHold = 'on_hold';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /**
     * closed/cancelled are terminal — mirrors LifecycleProcessStatus.
     *
     * @return list<self>
     */
    public function allowedNextStates(): array
    {
        return match ($this) {
            self::Draft => [self::Open, self::Cancelled],
            self::Open => [self::OnHold, self::Closed, self::Cancelled],
            self::OnHold => [self::Open, self::Closed, self::Cancelled],
            self::Closed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNextStates(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Closed || $this === self::Cancelled;
    }
}
