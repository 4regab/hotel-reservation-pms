<?php
declare(strict_types=1);

namespace Portal\Web;

use Lib\ApiException;
use Lib\Dates;
use Lib\Uuid;
use Portal\Accounts;

/** Public pages, sign-in, and the guest booking flow: search, details, hold, payment, confirmation. */
final class PublicSite
{
    // ---- marketing and search ----------------------------------------------------------------------------

    public static function home(Ctx $c): array
    {
        $types = $c->svcAsPortal('booking', 'GET', '/internal/room-types')['roomTypes'];
        return View::page($c, 'home', ['types' => $types, 'mainClass' => ''], '');
    }

    public static function rooms(Ctx $c): array
    {
        return View::page($c, 'rooms', ['types' => $c->svcAsPortal('booking', 'GET', '/internal/room-types')['roomTypes']], 'Rooms and suites', 200);
    }

    public static function room(Ctx $c, string $slug): array
    {
        foreach ($c->svcAsPortal('booking', 'GET', '/internal/room-types')['roomTypes'] as $t) {
            if ($t['slug'] === $slug) {
                return View::page($c, 'room', ['t' => $t], $t['name'] . ' room');
            }
        }
        return View::error($c, 404, 'Room not found', 'We could not find that room type.');
    }

    /** Search form and results with live prices. Dates default to the next weekend-free pair of nights. */
    public static function search(Ctx $c): array
    {
        $in = $c->q('checkIn');
        $out = $c->q('checkOut');
        $adults = max(1, min(20, (int) ($c->q('adults', '2'))));
        $rooms = max(1, min(5, (int) ($c->q('rooms', '1'))));
        $res = null;
        $error = null;
        $searched = $in !== '' || $out !== '';
        if ($searched) {
            if (!Dates::isDate($in) || !Dates::isDate($out) || $out <= $in) {
                $error = 'Choose a check-in date and a later check-out date.';
            } elseif ($in < today()) {
                $error = 'Check-in cannot be in the past.';
            } else {
                [$st, $data] = $c->svcRaw('booking', 'GET', '/internal/availability?' . http_build_query(['checkIn' => $in, 'checkOut' => $out, 'quantity' => $rooms, 'adults' => $adults]));
                if ($st === 200) {
                    $res = $data;
                } else {
                    $error = (string) ($data['error']['message'] ?? 'We could not check availability just now. Please try again.');
                }
            }
        }
        $form = ['checkIn' => $in !== '' ? $in : plus_days(today(), 1), 'checkOut' => $out !== '' ? $out : plus_days(today(), 3), 'adults' => $adults, 'rooms' => $rooms];
        return View::page($c, 'search', ['form' => $form, 'res' => $res, 'error' => $error, 'searched' => $searched], 'Find a room', $error ? 422 : 200);
    }

    // ---- booking flow -------------------------------------------------------------------------------------

    /** POST /book: a room was chosen on the results page. Needs a guest account. */
    public static function bookStart(Ctx $c): array
    {
        $c->checkCsrf();
        $stay = ['checkIn' => $c->post('checkIn'), 'checkOut' => $c->post('checkOut'), 'adults' => $c->post('adults', '1'), 'rooms' => $c->post('rooms', '1')];
        $back = '/search?' . http_build_query($stay);
        $u = $c->user();
        if ($u === null) {
            return View::redirect('/login?next=' . rawurlencode($back));
        }
        if ($u['role'] !== 'guest') {
            $c->flash('error', 'Staff book guests from the Walk-in page.');
            return View::redirect('/staff');
        }
        $quote = $c->post('quoteId');
        $type = $c->post('roomTypeId');
        $info = null;
        foreach ($c->svcAsPortal('booking', 'GET', '/internal/room-types')['roomTypes'] as $t) {
            if ($t['roomTypeId'] === $type) {
                $info = $t;
            }
        }
        if ($info === null || $quote === '') {
            $c->flash('error', 'Please pick a room again.');
            return View::redirect($back);
        }
        $nights = \Portal\Fmt::nights($stay['checkIn'], $stay['checkOut']);
        return View::page($c, 'book_details', [
            't' => $info, 'stay' => $stay, 'quote' => $quote, 'nights' => $nights, 'total' => (int) $c->post('totalMinor'), 'idem' => Uuid::v4(), 'back' => $back, 'account' => $c->account(), 'errors' => [],
        ], 'Your details');
    }

    /** POST /book/hold: create the 10-minute hold, then go to the payment page. */
    public static function bookHold(Ctx $c): array
    {
        $c->checkCsrf();
        $u = $c->require('guest');
        $name = $c->post('fullName');
        $phone = $c->post('phone');
        $adults = (int) $c->post('adults', '1');
        $stay = ['checkIn' => $c->post('checkIn'), 'checkOut' => $c->post('checkOut'), 'adults' => (string) $adults, 'rooms' => $c->post('rooms', '1')];
        $back = '/search?' . http_build_query($stay);
        try {
            $c->accounts->updateProfile($u['accountId'], $name, $phone, (bool) ($c->account()['marketing_consent'] ?? 0), $c->req->ip());
            $a = $c->account();
            $body = [
                'quoteId' => $c->post('quoteId'), 'roomTypeId' => $c->post('roomTypeId'), 'checkIn' => $stay['checkIn'], 'checkOut' => $stay['checkOut'], 'quantity' => (int) $stay['rooms'],
                'adults' => $adults, 'specialRequests' => $c->post('specialRequests') ?: null,
                'guest' => ['fullName' => $name, 'email' => $a['email'], 'phone' => $phone],
            ];
            $idem = $c->post('idem');
            if (!Uuid::isValid($idem)) {
                throw new ApiException(422, 'INVALID_REQUEST', 'This form is out of date. Please pick the room again.');
            }
            $r = $c->svc('booking', 'POST', '/internal/reservations', $body, 'web-' . $idem);
            return View::redirect('/book/pay/' . $r['reservationId']);
        } catch (ApiException $e) {
            if ($e->status === 401) {
                throw $e;
            }
            $c->flash('error', Ctx::friendly($e));
            return View::redirect($back);
        }
    }

    public static function pay(Ctx $c, string $id): array
    {
        $c->require('guest');
        $r = $c->svc('booking', 'GET', '/internal/reservations/' . $id);
        if (in_array($r['status'], ['CONFIRMED', 'CHECKED_IN', 'CHECKED_OUT'], true)) {
            return View::redirect('/book/confirmation/' . $id);
        }
        return View::page($c, 'book_pay', ['r' => $r, 'secondsLeft' => max(0, strtotime((string) $r['holdExpiresAt']) - time())], 'Pay to confirm');
    }

    /** POST /book/pay/{id}: start the hosted checkout for the saved price and send the guest there. */
    public static function startPayment(Ctx $c, string $id): array
    {
        try {
            $c->checkCsrf();
            $co = $c->svc('billing', 'POST', '/internal/reservations/' . $id . '/checkout', [], 'web-pay-' . $id . '-' . $c->post('idem', Uuid::v4()));
            $url = (string) $co['checkoutUrl'];
            if (str_starts_with($url, \Portal\Fmt::baseUrl() . '/')) {
                $url = substr($url, strlen(\Portal\Fmt::baseUrl())) . '?reservation=' . $id;   // demo checkout lives on this site
            } elseif (!str_starts_with($url, 'https://')) {
                throw new ApiException(503, 'PAYMENT_PROVIDER_UNAVAILABLE', 'The payment page could not be opened.');
            }
            return [303, '', ['Location' => $url, 'Cache-Control' => 'no-store']];
        } catch (ApiException $e) {
            if ($e->status === 401) {
                throw $e;
            }
            $c->flash('error', Ctx::friendly($e));
            return View::redirect('/book/pay/' . $id);
        }
    }

    public static function confirmation(Ctx $c, string $id): array
    {
        $c->require('guest');
        $r = $c->svc('booking', 'GET', '/internal/reservations/' . $id);
        $waiting = $r['status'] === 'HELD' && $c->q('returned') !== '';
        return View::page($c, 'book_confirmation', ['r' => $r, 'waiting' => $waiting || ($r['status'] === 'HELD' && $r['guarantee'] !== 'NONE')], $r['status'] === 'CONFIRMED' ? 'Booking confirmed' : 'Your booking');
    }

    /** GET /book/status/{id}: tiny JSON for the confirmation page poller. */
    public static function status(Ctx $c, string $id): array
    {
        $c->require('guest');
        $r = $c->svc('booking', 'GET', '/internal/reservations/' . $id);
        return [200, json_encode(['status' => $r['status']]), ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']];
    }

    /** The stand-in for PayMongo's hosted page (mock mode only). Real card details are never typed here. */
    public static function mockCheckout(Ctx $c, string $sessionId): array
    {
        if ((getenv('PAYMONGO_MODE') ?: 'mock') !== 'mock') {
            return View::error($c, 404, 'Page not found', 'This page does not exist.');
        }
        $c->require('guest');
        return View::page($c, 'mock_checkout', ['sessionId' => $sessionId, 'reservationId' => $c->q('reservation')], 'Secure payment (demo)');
    }

    public static function mockPay(Ctx $c, string $sessionId): array
    {
        $rid = $c->post('reservationId');
        return $c->act(function () use ($c, $sessionId): void {
            $c->svc('billing', 'POST', '/internal/mock/paymongo/pay', ['sessionId' => $sessionId]);
        }, '', '/book/confirmation/' . $rid . '?returned=1', '/book/pay/' . $rid);
    }

    // ---- sign in, register, reset ------------------------------------------------------------------------

    public static function login(Ctx $c): array
    {
        if ($c->user() !== null) {
            return View::redirect(self::home4($c->role()));
        }
        return View::page($c, 'login', ['next' => $c->q('next'), 'email' => '', 'error' => null, 'otpNeeded' => false], 'Sign in');
    }

    public static function loginPost(Ctx $c): array
    {
        $c->checkCsrf();
        $email = strtolower($c->post('email'));
        $next = $c->post('next');
        try {
            $c->auth->throttle($c->req->ip());
            [, $d] = $c->auth->attempt($email, (string) ($_POST['password'] ?? ''), $c->post('otp'), $c->req->ip());
            $safe = self::safeNext($next);
            return View::redirect($safe ?? self::home4($d['role']));
        } catch (ApiException $e) {
            $status = $e->errorCode === 'RATE_LIMITED' || $e->errorCode === 'ACCOUNT_LOCKED' ? 429 : 401;
            return View::page($c, 'login', ['next' => $next, 'email' => $email, 'error' => $e->getMessage(), 'otpNeeded' => $e->errorCode === 'OTP_REQUIRED'], 'Sign in', $status);
        }
    }

    public static function logout(Ctx $c): array
    {
        $c->checkCsrf();
        $c->auth->logout();
        return View::redirect('/');
    }

    public static function register(Ctx $c): array
    {
        return View::page($c, 'register', ['v' => ['fullName' => '', 'email' => '', 'phone' => ''], 'error' => null, 'next' => $c->q('next')], 'Create your account');
    }

    public static function registerPost(Ctx $c): array
    {
        $c->checkCsrf();
        $v = ['fullName' => $c->post('fullName'), 'email' => strtolower($c->post('email')), 'phone' => $c->post('phone')];
        try {
            if (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) {
                throw new ApiException(422, 'INVALID_REQUEST', 'The two passwords do not match.');
            }
            if (!$c->postFlag('terms')) {
                throw new ApiException(422, 'INVALID_REQUEST', 'Please accept the privacy notice to continue.');
            }
            $c->accounts->register($v + ['password' => (string) ($_POST['password'] ?? ''), 'marketingConsent' => $c->postFlag('marketing')], $c->req->ip());
            $c->auth->attempt($v['email'], (string) $_POST['password'], '', $c->req->ip());
            $c->flash('ok', 'Welcome to HarborLight. Your account is ready.');
            return View::redirect(self::safeNext($c->post('next')) ?? '/account');
        } catch (ApiException $e) {
            return View::page($c, 'register', ['v' => $v, 'error' => Ctx::friendly($e), 'next' => $c->post('next')], 'Create your account', $e->status === 409 ? 409 : 422);
        }
    }

    public static function forgot(Ctx $c): array
    {
        return View::page($c, 'forgot', ['sent' => false, 'error' => null], 'Reset your password');
    }

    public static function forgotPost(Ctx $c): array
    {
        $c->checkCsrf();
        try {
            $c->accounts->requestReset($c->post('email'), $c->req->ip());
        } catch (ApiException $e) {
            return View::page($c, 'forgot', ['sent' => false, 'error' => Ctx::friendly($e)], 'Reset your password', 429);
        }
        return View::page($c, 'forgot', ['sent' => true, 'error' => null], 'Check your email');
    }

    public static function reset(Ctx $c): array
    {
        $token = $c->q('token');
        $ok = preg_match('/^[a-f0-9]{64}$/', $token) === 1 && $c->accounts->resetTokenValid($token);
        return View::page($c, 'reset', ['token' => $token, 'valid' => $ok, 'error' => null, 'done' => false], 'Choose a new password', $ok ? 200 : 410);
    }

    public static function resetPost(Ctx $c): array
    {
        $c->checkCsrf();
        $token = $c->post('token');
        try {
            if (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) {
                throw new ApiException(422, 'INVALID_REQUEST', 'The two passwords do not match.');
            }
            $c->accounts->completeReset($token, (string) ($_POST['password'] ?? ''), $c->req->ip());
            return View::page($c, 'reset', ['token' => '', 'valid' => true, 'error' => null, 'done' => true], 'Password changed');
        } catch (ApiException $e) {
            return View::page($c, 'reset', ['token' => $token, 'valid' => $e->status !== 410, 'error' => Ctx::friendly($e), 'done' => false], 'Choose a new password', $e->status);
        }
    }

    // ---- helpers ------------------------------------------------------------------------------------------

    public static function home4(string $role): string
    {
        return $role === 'guest' ? '/account' : '/staff';
    }

    /** Only same-site paths; blocks open redirects. */
    public static function safeNext(string $next): ?string
    {
        return $next !== '' && str_starts_with($next, '/') && !str_starts_with($next, '//') && !str_contains($next, "\n") && !str_contains($next, '\\') ? $next : null;
    }
}
