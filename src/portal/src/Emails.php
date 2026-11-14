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
        $intro = 'Hello ' . $r['guestName'] . ', your stay at MA Hotel is confirmed. We look forward to welcoming you.';
        $outro = 'Free cancellation until ' . (int) $r['freeCancelHours'] . ' hours before arrival. After that the first night is charged. Bring a valid photo ID to check in.';
        return self::build('Your MA Hotel booking ' . $r['code'] . ' is confirmed', 'Booking confirmed', $intro, $rows, $outro, 'View or print your booking', $link);
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
        return self::build('Your MA Hotel booking ' . $r['code'] . ' was cancelled', 'Booking cancelled', $intro, $rows, $outro, 'View your booking', Fmt::baseUrl() . '/account/bookings/' . $r['reservationId']);
    }

    public static function refund(array $r, int $amountMinor): array
    {
        $rows = ['Confirmation code' => $r['code'], 'Refund amount' => Fmt::php($amountMinor), 'Refunded to' => 'Your original payment method'];
        $intro = 'Hello ' . $r['guestName'] . ', we have processed a refund for your booking.';
        $outro = 'Banks and e-wallets can take a few working days to show the money. Reply to this email if it has not arrived after 7 days.';
        return self::build('Your MA Hotel refund of ' . Fmt::php($amountMinor), 'Refund processed', $intro, $rows, $outro, 'View your receipt', Fmt::baseUrl() . '/account/bookings/' . $r['reservationId'] . '/receipt');
    }

    public static function passwordReset(string $name, string $token): array
    {
        $link = Fmt::baseUrl() . '/reset-password?token=' . rawurlencode($token);
        $intro = 'Hello ' . $name . ', we received a request to reset your MA Hotel password. The link works once and expires in 1 hour.';
        $outro = 'If you did not ask for this, ignore this email. Your password stays the same.';
        return self::build('Reset your MA Hotel password', 'Reset your password', $intro, [], $outro, 'Choose a new password', $link);
    }

    /** @param array<string,string> $rows */
    private static function build(string $subject, string $heading, string $intro, array $rows, string $outro, string $button, string $link): array
    {
        $e = [Fmt::class, 'e'];
        $tr = '';
        $textRows = '';
        foreach ($rows as $k => $v) {
            $tr .= '<tr><td style="padding:10px 16px 10px 0;color:#454f6e;font-size:14px;white-space:nowrap;vertical-align:top;border-bottom:1px solid #e6e8ef">' . $e($k) . '</td><td style="padding:10px 0;color:#1f2b4d;font-size:14px;font-weight:600;text-align:right;border-bottom:1px solid #e6e8ef">' . $e($v) . '</td></tr>';
            $textRows .= "{$k}: {$v}\n";
        }
        // Colours and fonts follow docs/BRAND.md. Email clients ignore web fonts and many block SVG, so the logo is the PNG mark plus text.
        $logo = $e(Fmt::baseUrl() . '/assets/brand/apple-touch-icon-180.png');
        $html = '<!doctype html><html lang="en"><body style="margin:0;background:#f6f5f1;font-family:Inter,Arial,Helvetica,sans-serif">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f5f1"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:20px;overflow:hidden;border:1px solid #e6e8ef">'
            . '<tr><td style="background:#1f2b4d;padding:20px 28px"><table role="presentation" cellpadding="0" cellspacing="0"><tr>'
            . '<td style="padding-right:12px"><img src="' . $logo . '" width="36" height="36" alt="" style="display:block;border-radius:9px"></td>'
            . '<td style="color:#ffffff;font-size:18px;letter-spacing:1px"><strong>MA</strong> HOTEL</td></tr></table></td></tr>'
            . '<tr><td style="padding:32px 28px">'
            . '<h1 style="margin:0 0 12px;font-size:26px;line-height:1.2;font-weight:600;color:#121a30">' . $e($heading) . '</h1>'
            . '<p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#33405f">' . $e($intro) . '</p>'
            . ($tr !== '' ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px">' . $tr . '</table>' : '')
            . '<p style="margin:0 0 24px;font-size:14px;line-height:1.6;color:#454f6e">' . $e($outro) . '</p>'
            . '<a href="' . $e($link) . '" style="display:inline-block;background:#f7941d;color:#121a30;text-decoration:none;font-weight:600;font-size:15px;padding:13px 24px;border-radius:999px">' . $e($button) . '</a>'
            . '</td></tr>'
            . '<tr><td style="padding:16px 28px;background:#f2f3f7;color:#454f6e;font-size:12px;line-height:1.5">MA Hotel, Metro Manila, Philippines. This message was sent because of activity on your booking or account.</td></tr>'
            . '</table></td></tr></table></body></html>';
        $text = "{$heading}\n\n{$intro}\n\n{$textRows}\n{$outro}\n\n{$button}: {$link}\n";
        return [$subject, $html, $text];
    }
}
