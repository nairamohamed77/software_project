<?php
declare(strict_types=1);

abstract class VisitStatusRule {
    abstract public function status(): string;

    abstract public function allows(string $nextStatus): bool;
}

final class PendingStatusRule extends VisitStatusRule {
    public function status(): string {
        return 'pending';
    }

    public function allows(string $nextStatus): bool {
        return in_array($nextStatus, ['accepted', 'cancelled'], true);
    }
}

final class AcceptedStatusRule extends VisitStatusRule {
    public function status(): string {
        return 'accepted';
    }

    public function allows(string $nextStatus): bool {
        return in_array($nextStatus, ['live', 'cancelled'], true);
    }
}

final class LiveStatusRule extends VisitStatusRule {
    public function status(): string {
        return 'live';
    }

    public function allows(string $nextStatus): bool {
        return $nextStatus === 'completed';
    }
}

final class CompletedStatusRule extends VisitStatusRule {
    public function status(): string {
        return 'completed';
    }

    public function allows(string $nextStatus): bool {
        return false;
    }
}

final class CancelledStatusRule extends VisitStatusRule {
    public function status(): string {
        return 'cancelled';
    }

    public function allows(string $nextStatus): bool {
        return false;
    }
}

final class UnknownStatusRule extends VisitStatusRule {
    public function status(): string {
        return 'unknown';
    }

    public function allows(string $nextStatus): bool {
        return false;
    }
}

final class VisitStatusRuleFactory {
    public static function fromDbStatus(string $status): VisitStatusRule {
        $normalized = strtolower(trim(str_replace('_', '-', $status)));
        if ($normalized === 'pending') {
            return new PendingStatusRule();
        }
        if ($normalized === 'accepted') {
            return new AcceptedStatusRule();
        }
        if ($normalized === 'live') {
            return new LiveStatusRule();
        }
        if ($normalized === 'completed') {
            return new CompletedStatusRule();
        }
        if ($normalized === 'cancelled') {
            return new CancelledStatusRule();
        }
        return new UnknownStatusRule();
    }
}
