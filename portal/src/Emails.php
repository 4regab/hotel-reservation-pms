<?php
declare(strict_types=1);

namespace Portal;

/**
 * Email templates: booking confirmation, cancellation, refund and password reset. Each returns [subject, html, text].
 * Email clients ignore stylesheets, so the HTML is a plain table layout with inline styles and a plain-text twin.
 */
final class Emails
{
    /** @param array $r reservation view from Booking */
    public static function bookingConfirmation(array $r): array
    {
        $nights = Fmt::nights($r['checkIn'], $r['checkOut']);
        $rows = [
            'Confirmation code' => $r['code'],
            'Room' => $r['roomTypeName'] . ($r['quantity'] > 1 ? ' x ' . $r['quantity'] : ''),
            'Check-in' => Fmt::date($r['checkIn']) . ' from 2:00 PM',
            'Check-out' => Fmt::date($r['checkOut']) . ' by 12:00 noon',
            'Nights' => (string) $nights,
            'Total' => Fmt::php((int) $r['amountMinor']) . ($r['guarantee'] === 'PAY_AT_HOTEL' ? ' (pay at the hotel)' : ' (paid)'),
        ];
        $link = Fmt::baseUrl() . '/account/bookings/' . $r['reservationId'];
        $intro = 'Hello ' . $r['guestName'] . ', your stay at HarborLight Hotels & Resorts is confirmed. We look forward to welcoming you.';
        $outro = 'Free cancellation until ' . (int) $r['freeCancelHours'] . ' hours before arrival. After that the first night is charged. Bring a valid photo ID to check in.';
        return self::build('Your HarborLight booking ' . $r['code'] . ' is confirmed', 'Booking confirmed', $intro, $rows, $outro, 'View or print your booking', $link);
    }

    public static function cancellation(array $r): array
    {
        $rows = [
            'Confirmation code' => $r['code'],
            'Room' => $r['roomTypeName'],
            'Dates' => Fmt::date($r['checkIn']) . ' to ' . Fmt::date($r['checkOut']),
            'Cancellation charge' => Fmt::php((int) $r['penaltyMinor']),
        ];
        $intro = 'Hello ' . $r['guestName'] . ', your booking has been cancelled.';
        $outro = (int) $r['penaltyMinor'] > 0
            ? 'The first night is kept under our cancellation terms. Any other amount you paid is refunded to your original payment method.'
            : 'No cancellation charge applies. If you paid online, the refund is on its way to your original payment method.';
        return self::build('Your HarborLight booking ' . $r['code'] . ' was cancelled', 'Booking cancelled', $intro, $rows, $outro, 'View your booking', Fmt::baseUrl() . '/account/bookings/' . $r['reservationId']);
    }

    public static function refund(array $r, int $amountMinor): array
    {
        $rows = ['Confirmation code' => $r['code'], 'Refund amount' => Fmt::php($amountMinor), 'Refunded to' => 'Your original payment method'];
        $intro = 'Hello ' . $r['guestName'] . ', we have processed a refund for your booking.';
        $outro = 'Banks and e-wallets can take a few working days to show the money. Reply to this email if it has not arrived after 7 days.';
        return self::build('Your HarborLight refund of ' . Fmt::php($amountMinor), 'Refund processed', $intro, $rows, $outro, 'View your receipt', Fmt::baseUrl() . '/account/bookings/' . $r['reservationId'] . '/receipt');
    }

    public static function passwordReset(string $name, string $token): array
    {
        $link = Fmt::baseUrl() . '/reset-password?token=' . rawurlencode($token);
        $intro = 'Hello ' . $name . ', we received a request to reset your HarborLight password. The link works once and expires in 1 hour.';
        $outro = 'If you did not ask for this, ignore this email. Your password stays the same.';
        return self::build('Reset your HarborLight password', 'Reset your password', $intro, [], $outro, 'Choose a new password', $link);
    }

    /** @param array<string,string> $rows */
    private static function build(string $subject, string $heading, string $intro, array $rows, string $outro, string $button, string $link): array
    {
        $e = [Fmt::class, 'e'];
        $tr = '';
        $textRows = '';
        foreach ($rows as $k => $v) {
            $tr .= '<tr><td style="padding:8px 0;color:#475569;font-size:14px;border-bottom:1px solid #e2e8f0">' . $e($k) . '</td><td style="padding:8px 0;color:#0f172a;font-size:14px;font-weight:600;text-align:right;border-bottom:1px solid #e2e8f0">' . $e($v) . '</td></tr>';
            $textRows .= "{$k}: {$v}\n";
        }
        $html = '<!doctype html><html lang="en"><body style="margin:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden">'
            . '<tr><td style="background:#0c4a6e;padding:20px 28px;color:#ffffff;font-size:18px;font-weight:700">HarborLight Hotels &amp; Resorts</td></tr>'
            . '<tr><td style="padding:28px">'
            . '<h1 style="margin:0 0 12px;font-size:22px;color:#0f172a">' . $e($heading) . '</h1>'
            . '<p style="margin:0 0 20px;font-size:15px;line-height:1.5;color:#334155">' . $e($intro) . '</p>'
            . ($tr !== '' ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px">' . $tr . '</table>' : '')
            . '<p style="margin:0 0 24px;font-size:14px;line-height:1.5;color:#475569">' . $e($outro) . '</p>'
            . '<a href="' . $e($link) . '" style="display:inline-block;background:#0369a1;color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;padding:12px 22px;border-radius:8px">' . $e($button) . '</a>'
            . '</td></tr>'
            . '<tr><td style="padding:16px 28px;background:#f8fafc;color:#64748b;font-size:12px;line-height:1.5">HarborLight Hotels &amp; Resorts, Metro Manila, Philippines. This message was sent because of activity on your booking or account.</td></tr>'
            . '</table></td></tr></table></body></html>';
        $text = "{$heading}\n\n{$intro}\n\n{$textRows}\n{$outro}\n\n{$button}: {$link}\n";
        return [$subject, $html, $text];
    }
}
