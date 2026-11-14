<?php
declare(strict_types=1);

use Lib\ApiException;
use Lib\ReservationState as S;

return [
    'legal transitions are allowed' => function (): void {
        foreach ([
            [S::HELD, S::CONFIRMED], [S::HELD, S::EXPIRED], [S::HELD, S::CANCELLED],
            [S::CONFIRMED, S::CHECKED_IN], [S::CONFIRMED, S::CANCELLED], [S::CONFIRMED, S::NO_SHOW],
            [S::CHECKED_IN, S::CHECKED_OUT],
        ] as [$from, $to]) {
            check(S::canTransition($from, $to), "{$from} -> {$to} should be legal");
        }
    },
    'illegal transitions are refused' => function (): void {
        foreach ([
            [S::HELD, S::CHECKED_IN], [S::HELD, S::CHECKED_OUT], [S::HELD, S::NO_SHOW],
            [S::CONFIRMED, S::HELD], [S::CONFIRMED, S::EXPIRED], [S::CONFIRMED, S::CHECKED_OUT],
            [S::CHECKED_IN, S::CANCELLED], [S::CHECKED_IN, S::CONFIRMED], [S::CHECKED_IN, S::NO_SHOW],
            [S::CHECKED_OUT, S::CHECKED_IN], [S::CHECKED_OUT, S::CANCELLED],
            [S::NO_SHOW, S::CONFIRMED], [S::EXPIRED, S::CANCELLED], [S::EXPIRED, S::HELD],
        ] as [$from, $to]) {
            check(!S::canTransition($from, $to), "{$from} -> {$to} should be illegal");
        }
    },
    'a cancelled reservation can never reopen' => function (): void {
        foreach ([S::HELD, S::CONFIRMED, S::EXPIRED, S::CHECKED_IN] as $to) {
            check(!S::canTransition(S::CANCELLED, $to), "CANCELLED -> {$to}");
            check(!S::canTransition(S::CANCELLED, $to, true), "CANCELLED -> {$to} even with fresh allocation");
        }
    },
    'an expired hold needs a fresh allocation before it can be confirmed' => function (): void {
        check(!S::canTransition(S::EXPIRED, S::CONFIRMED), 'no confirm without fresh allocation');
        check(S::canTransition(S::EXPIRED, S::CONFIRMED, true), 'confirm with fresh allocation');
    },
    'assert() raises 409 ILLEGAL_TRANSITION' => function (): void {
        throws(fn () => S::assert(S::CANCELLED, S::CONFIRMED), ApiException::class, 409);
        S::assert(S::HELD, S::CONFIRMED);
    },
];
