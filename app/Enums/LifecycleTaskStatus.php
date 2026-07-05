<?php

namespace App\Enums;

enum LifecycleTaskStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Skipped = 'skipped';

    /**
     * @return list<self>
     */
    public function allowedNextStates(): array
    {
        return match ($this) {
            self::Pending => [self::InProgress, self::Completed, self::Skipped],
            self::InProgress => [self::Completed, self::Skipped],
            self::Completed, self::Skipped => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNextStates(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Skipped;
    }
}
