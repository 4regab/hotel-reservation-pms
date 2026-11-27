<?php
declare(strict_types=1);

namespace Booking;

use Lib\ApiException;
use Lib\Audit;
use Lib\Dates;
use Lib\Db;
use Lib\InsufficientStock;
use Lib\Inventory;
use Lib\Log;
use Lib\Outbox;
use Lib\ReservationState as S;
use Lib\Tx;
use Lib\Uuid;

/**
 * Holds, confirmations, expirations and cancellations. Each method is ONE transaction that saves
 * the business change, the stock counters and the outbox event together. Lock order is always:
 * reservation row first, then inventory nights in date order. Nothing here calls another service
 * while locks are held.
 */
final class BookingWorkflow
{
    public const HOLD_SECONDS = 600;

    public function __construct(private \PDO $pdo, private BookingRepository $repo, private InventoryAllocator $inv)
    {
    }

    /** Create a 10-minute priced hold. Saves all nights or none. */
    public function hold(string $accountId, array $quote, ?string $onBehalfGuestId, array $guest, int $now, array $extra = []): array
    {
        return Tx::run($this->pdo, function () use ($accountId, $quote, $onBehalfGuestId, $guest, $now, $extra): array {
            $nights = Dates::nights($quote['checkIn'], $quote['checkOut']);
            if ($onBehalfGuestId !== null) {
                if (!$this->repo->guestExists($onBehalfGuestId)) {
                    throw new ApiException(422, 'UNKNOWN_GUEST', 'onBehalfOfGuestId does not exist');
                }
                $guestId = $onBehalfGuestId;
            } else {
                $guestId = $this->repo->upsertGuest($accountId, $guest);
            }
            $rows = Inventory::reserve($this->inv->lock($quote['roomTypeId'], $nights), $nights, $quote['quantity'], 'held');
            $this->inv->save($quote['roomTypeId'], $rows, $nights);

            $id = Uuid::v4();
            $expires = $now + self::HOLD_SECONDS;
            $this->repo->insert([
                'id' => $id, 'guestId' => $guestId, 'roomTypeId' => $quote['roomTypeId'], 'checkIn' => $quote['checkIn'], 'checkOut' => $quote['checkOut'],
                'quantity' => $quote['quantity'], 'quoteMinor' => $quote['totalMinor'], 'nightlyMinor' => $quote['nightlyMinor'], 'currency' => $quote['currency'],
                'freeCancelHours' => CancellationPolicy::FREE_HOURS, 'holdExpiresAt' => Dates::sqlUtc($expires),
                'source' => $onBehalfGuestId !== null ? 'WALK_IN' : 'DIRECT', 'adults' => $extra['adults'] ?? 1, 'specialRequests' => $extra['specialRequests'] ?? null,
            ]);
            $this->repo->insertAllocations($id, $quote['roomTypeId'], $nights, $quote['quantity'], 'HELD');
            return [201, [
                'reservationId' => $id, 'status' => S::HELD, 'holdExpiresAt' => Dates::iso($expires), 'amountMinor' => $quote['totalMinor'], 'currency' => $quote['currency'],
                'roomTypeId' => $quote['roomTypeId'], 'checkIn' => $quote['checkIn'], 'checkOut' => $quote['checkOut'], 'quantity' => $quote['quantity'],
            ]];
        });
    }

    /** Release one expired hold exactly once. Returns false when it was not due (e.g. paid meanwhile). */
    public function expire(string $reservationId, int $now): bool
    {
        return (bool) Tx::run($this->pdo, function () use ($reservationId, $now): bool {
            $r = $this->repo->lock($reservationId);
            if ($r === null || $r['status'] !== S::HELD || strtotime($r['hold_expires_at'] . ' UTC') > $now) {
                return false;
            }
            $nights = Dates::nights($r['check_in'], $r['check_out']);
            $rows = Inventory::release($this->inv->lock($r['room_type_id'], $nights), $nights, (int) $r['quantity'], 'held');
            $this->inv->save($r['room_type_id'], $rows, $nights);
            $this->repo->setAllocationState($reservationId, 'RELEASED');
            $this->repo->setStatus($r, S::EXPIRED);
            return true;
        });
    }

    /** Manager-approved pay-at-hotel guarantee confirms without a payment event. */
    public function approveGuarantee(string $reservationId, int $now, ?array $user = null): array
    {
        return Tx::run($this->pdo, function () use ($reservationId, $now, $user): array {
            $r = $this->repo->lock($reservationId) ?? throw new ApiException(404, 'NOT_FOUND');
            S::assert($r['status'], S::CONFIRMED);
            if (strtotime($r['hold_expires_at'] . ' UTC') <= $now) {
                throw new ApiException(409, 'HOLD_EXPIRED', 'The hold has run out; make a new booking');
            }
            $this->confirmLocked($r, 'PAY_AT_HOTEL', false);
            if ($user !== null) {
                Audit::record($this->pdo, $user, 'reservation.guarantee_approved', 'reservation', $reservationId, ['code' => $r['code']]);
            }
            return [200, ['reservationId' => $reservationId, 'status' => S::CONFIRMED, 'guarantee' => 'PAY_AT_HOTEL']];
        });
    }

    /** Cancel a held or confirmed reservation following the saved terms. Cancelled can never reopen. */
    public function cancel(string $reservationId, bool $waivePenalty, int $now): array
    {
        return Tx::run($this->pdo, function () use ($reservationId, $waivePenalty, $now): array {
            $r = $this->repo->lock($reservationId) ?? throw new ApiException(404, 'NOT_FOUND');
            S::assert($r['status'], S::CANCELLED);
            $nights = Dates::nights($r['check_in'], $r['check_out']);
            $bucket = $r['status'] === S::HELD ? 'held' : 'confirmed';
            $rows = Inventory::release($this->inv->lock($r['room_type_id'], $nights), $nights, (int) $r['quantity'], $bucket);
            $this->inv->save($r['room_type_id'], $rows, $nights);
            $this->repo->setAllocationState($reservationId, 'RELEASED');
            $version = $this->repo->setStatus($r, S::CANCELLED);

            $penalty = 0;
            if ($r['status'] === S::CONFIRMED) {
                $penalty = $waivePenalty ? 0 : CancellationPolicy::penaltyMinor($r['check_in'], $now, (int) $r['free_cancel_hours'], (int) $r['nightly_minor'], (int) $r['quantity']);
                $penalty = min($penalty, (int) $r['quote_minor']);
                Outbox::add($this->pdo, 'ReservationCancelled', $reservationId, $version, $reservationId, ['reservationId' => $reservationId, 'penaltyMinor' => $penalty, 'currency' => $r['currency']]);
                $refund = (int) $r['quote_minor'] - $penalty;
                Db::call($this->pdo, 'usp_reservation_markCancelled', ['reservation_id' => $reservationId, 'penalty_minor' => $penalty]);
                if ($r['guarantee'] === 'PAID' && $refund > 0) {
                    Outbox::add($this->pdo, 'RefundRequested', $reservationId, $version, $reservationId, ['reservationId' => $reservationId, 'amountMinor' => $refund, 'currency' => $r['currency'], 'reason' => 'CANCELLATION']);
                }
            }
            if ($r['status'] === S::HELD) {
                Db::call($this->pdo, 'usp_reservation_markCancelled', ['reservation_id' => $reservationId, 'penalty_minor' => null]);
            }
            return [200, ['reservationId' => $reservationId, 'status' => S::CANCELLED, 'penaltyMinor' => $penalty, 'currency' => $r['currency']]];
        });
    }

    /** PaymentCaptured from Billing: confirm if stock is secured, otherwise ask Billing for a refund. */
    public function onPaymentCaptured(array $d, ?string $occurredAt = null): void
    {
        Tx::run($this->pdo, function () use ($d, $occurredAt): void {
            $r = $this->repo->lock($d['reservationId']);
            if ($r === null) {
                Log::info('payment for unknown reservation', ['reservationId' => $d['reservationId']]);
                return;
            }
            $outcome = PaymentOutcome::forStatus($r['status']);
            if ($outcome === PaymentOutcome::IGNORE) {
                return;
            }
            $refund = fn (string $reason) => Outbox::add($this->pdo, 'RefundRequested', $r['reservation_id'], (int) $r['version'], $r['reservation_id'], [
                'reservationId' => $r['reservation_id'], 'paymentId' => $d['paymentId'], 'amountMinor' => (int) $d['amountMinor'], 'currency' => $d['currency'], 'reason' => $reason,
            ]);
            if ($outcome === PaymentOutcome::REFUND) {
                $refund('LATE_AFTER_CANCEL');
                return;
            }
            if ((int) $d['amountMinor'] !== (int) $r['quote_minor'] || $d['currency'] !== $r['currency']) {
                $refund('AMOUNT_MISMATCH');
                return;
            }
            if ($outcome === PaymentOutcome::CONFIRM) {
                $this->confirmLocked($r, 'PAID', false, $occurredAt);
                return;
            }
            try {
                $this->confirmLocked($r, 'PAID', true, $occurredAt);   // expired: needs a fresh allocation
            } catch (InsufficientStock) {
                $refund('LATE_NO_STOCK');                 // stays EXPIRED, exactly one refund request
            }
        });
    }

    /** StayCheckedIn / StayCheckedOut from Room Operations. */
    public function onStay(string $to, string $reservationId): void
    {
        Tx::run($this->pdo, function () use ($to, $reservationId): void {
            $r = $this->repo->lock($reservationId);
            if ($r === null || $r['status'] === $to) {
                return;
            }
            S::assert($r['status'], $to);
            $this->repo->setStatus($r, $to);
        });
    }

    /** Room Operations asks for a dated capacity block (one room) for maintenance. Same requestId = same answer. */
    public function reserveMaintenance(string $requestId, string $roomTypeId, string $start, string $end): array
    {
        return Tx::run($this->pdo, function () use ($requestId, $roomTypeId, $start, $end): array {
            $nights = Dates::nights($start, $end);
            $saved = (int) Db::call($this->pdo, 'usp_maintenance_recordBlock', ['request_id' => $requestId, 'room_type_id' => $roomTypeId, 'start_date' => $start, 'end_date' => $end])->fetchColumn();
            if ($saved === 0) {
                return [200, ['requestId' => $requestId, 'status' => 'BLOCKED', 'replayed' => true]];
            }
            $rows = Inventory::reserve($this->inv->lock($roomTypeId, $nights), $nights, 1, 'blocked');
            $this->inv->save($roomTypeId, $rows, $nights);
            return [200, ['requestId' => $requestId, 'status' => 'BLOCKED']];
        });
    }

    /** Reception asks the manager to approve a pay-at-hotel guarantee for a live hold. */
    public function requestGuarantee(string $reservationId, array $user, int $now): array
    {
        return Tx::run($this->pdo, function () use ($reservationId, $user, $now): array {
            $r = $this->repo->lock($reservationId) ?? throw new ApiException(404, 'NOT_FOUND');
            if ($r['status'] !== S::HELD || strtotime($r['hold_expires_at'] . ' UTC') <= $now) {
                throw new ApiException(409, 'RESERVATION_NOT_PAYABLE', 'Only an unexpired hold can ask for a guarantee');
            }
            Db::call($this->pdo, 'usp_reservation_flagGuaranteeRequested', ['reservation_id' => $reservationId]);
            Audit::record($this->pdo, $user, 'reservation.guarantee_requested', 'reservation', $reservationId, ['code' => $r['code']]);
            return [200, ['reservationId' => $reservationId, 'guaranteeRequested' => true]];
        });
    }

    /**
     * Mark a confirmed reservation that never arrived as NO_SHOW. The first night is kept (unless a manager waives it),
     * unused later nights go back on sale, and a paid booking asks Billing to refund the rest.
     */
    public function noShow(string $reservationId, array $user, bool $waive, int $now): array
    {
        return Tx::run($this->pdo, function () use ($reservationId, $user, $waive, $now): array {
            $r = $this->repo->lock($reservationId) ?? throw new ApiException(404, 'NOT_FOUND');
            S::assert($r['status'], S::NO_SHOW);
            if (!CancellationPolicy::noShowDue($r['check_in'], $now)) {
                throw new ApiException(409, 'NOT_DUE', 'A no-show can be recorded after ' . CancellationPolicy::NO_SHOW_HOUR . ':00 Manila time on the arrival date');
            }
            $today = Dates::manilaToday($now);
            $nights = array_values(array_filter(Dates::nights($r['check_in'], $r['check_out']), fn ($n) => $n >= $today));
            if ($nights !== []) {
                $rows = Inventory::release($this->inv->lock($r['room_type_id'], $nights), $nights, (int) $r['quantity'], 'confirmed');
                $this->inv->save($r['room_type_id'], $rows, $nights);
            }
            $this->repo->setAllocationState($reservationId, 'RELEASED');
            $version = $this->repo->setStatus($r, S::NO_SHOW);
            $penalty = $waive ? 0 : min((int) $r['quote_minor'], (int) $r['nightly_minor'] * (int) $r['quantity']);
            Db::call($this->pdo, 'usp_reservation_markNoShow', ['reservation_id' => $reservationId, 'penalty_minor' => $penalty]);
            Outbox::add($this->pdo, 'ReservationNoShow', $reservationId, $version, $reservationId, ['reservationId' => $reservationId, 'penaltyMinor' => $penalty, 'currency' => $r['currency']]);
            $refund = (int) $r['quote_minor'] - $penalty;
            if ($r['guarantee'] === 'PAID' && $refund > 0) {
                Outbox::add($this->pdo, 'RefundRequested', $reservationId, $version, $reservationId, ['reservationId' => $reservationId, 'amountMinor' => $refund, 'currency' => $r['currency'], 'reason' => 'NO_SHOW']);
            }
            Audit::record($this->pdo, $user, 'reservation.no_show', 'reservation', $reservationId, ['code' => $r['code'], 'penaltyMinor' => $penalty, 'waived' => $waive]);
            return [200, ['reservationId' => $reservationId, 'status' => S::NO_SHOW, 'penaltyMinor' => $penalty, 'currency' => $r['currency']]];
        });
    }

    /** Move held stock to confirmed (or take fresh confirmed stock for an expired hold), mark CONFIRMED, queue the event. */
    private function confirmLocked(array $r, string $guarantee, bool $fresh, ?string $paidAt = null): void
    {
        $nights = Dates::nights($r['check_in'], $r['check_out']);
        $qty = (int) $r['quantity'];
        $rows = $this->inv->lock($r['room_type_id'], $nights);
        $rows = $fresh ? Inventory::reserve($rows, $nights, $qty, 'confirmed') : Inventory::move($rows, $nights, $qty, 'held', 'confirmed');
        S::assert($r['status'], S::CONFIRMED, $fresh);
        $this->inv->save($r['room_type_id'], $rows, $nights);
        $this->repo->insertAllocations($r['reservation_id'], $r['room_type_id'], $nights, $qty, 'CONFIRMED');
        $version = $this->repo->setStatus($r, S::CONFIRMED, $guarantee);
        $this->repo->markConfirmed($r['reservation_id'], $paidAt !== null ? Dates::sqlUtc((int) strtotime($paidAt)) : null);
        Outbox::add($this->pdo, 'ReservationConfirmed', $r['reservation_id'], $version, $r['reservation_id'], [
            'reservationId' => $r['reservation_id'], 'roomTypeId' => $r['room_type_id'], 'checkIn' => $r['check_in'], 'checkOut' => $r['check_out'],
            'quantity' => $qty, 'amountMinor' => (int) $r['quote_minor'], 'currency' => $r['currency'],
        ]);
    }
}
