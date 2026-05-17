<?php
declare(strict_types=1);

require_once __DIR__ . '/../Notification.php';
require_once __DIR__ . '/../Senior.php';
require_once __DIR__ . '/../FamilyProxy.php';

interface VisitObserver {
    /**
     * @param array<string,mixed> $context
     */
    public function update(string $event, array $context): void;
}

final class VisitSubject {
    /** @var list<VisitObserver> */
    private array $observers = [];

    public function attach(VisitObserver $observer): void {
        $this->observers[] = $observer;
    }

    /**
     * @param array<string,mixed> $context
     */
    public function notify(string $event, array $context): void {
        foreach ($this->observers as $observer) {
            $observer->update($event, $context);
        }
    }
}

final class VisitNotificationObserver implements VisitObserver {
    /**
     * @param array<string,mixed> $context
     */
    public function update(string $event, array $context): void {
        $visitId = (int) ($context['visit_id'] ?? 0);
        $seniorId = (int) ($context['senior_id'] ?? 0);

        if ($visitId <= 0) {
            return;
        }

        switch ($event) {
            case 'BOOKED':
                $palUserId = (int) ($context['pal_user_id'] ?? 0);
                if ($palUserId > 0) {
                    Notification::enqueue($palUserId, 'Visit_Reminder', 'New visit request', 'New CareNest booking request #' . $visitId . '.');
                }
                foreach (FamilyProxy::proxiesLinkedToSeniorSeniorId($seniorId) as $proxyUserId) {
                    Notification::enqueue(
                        (int) $proxyUserId,
                        'Visit_Reminder',
                        'Senior visit booked',
                        'A visit was booked for your linked senior (visit #' . $visitId . ').'
                    );
                }
                return;

            case 'ACCEPTED':
                $seniorUserId = Senior::seniorUserIdFromSeniorRow($seniorId);
                if ($seniorUserId !== null) {
                    Notification::enqueue($seniorUserId, 'Visit_Confirmed', 'Visit confirmed', 'Your visit #' . $visitId . ' was accepted!');
                }
                foreach (FamilyProxy::proxiesLinkedToSeniorSeniorId($seniorId) as $proxyUserId) {
                    Notification::enqueue((int) $proxyUserId, 'Visit_Confirmed', 'Visit confirmed', 'Visit #' . $visitId . ' was accepted.');
                }
                return;

            case 'REJECTED':
                $seniorUserId = Senior::seniorUserIdFromSeniorRow($seniorId);
                if ($seniorUserId !== null) {
                    Notification::enqueue($seniorUserId, 'Visit_Cancelled', 'Visit update', 'A Pal declined visit #' . $visitId . '.');
                }
                return;

            case 'COMPLETED':
                $seniorUserId = Senior::seniorUserIdFromSeniorRow($seniorId);
                if ($seniorUserId !== null) {
                    Notification::enqueue($seniorUserId, 'Visit_Completed', 'Visit finished', 'Your visit #' . $visitId . ' is marked complete.');
                }
                foreach (FamilyProxy::proxiesLinkedToSeniorSeniorId($seniorId) as $proxyUserId) {
                    Notification::enqueue((int) $proxyUserId, 'Visit_Completed', 'Visit finished', 'Visit #' . $visitId . ' was completed.');
                }
                return;
        }
    }
}
