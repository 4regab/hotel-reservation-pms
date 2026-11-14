<?php
declare(strict_types=1);

namespace Portal\Web;

use Portal\Fmt;

/** Renders PHP templates from portal/views inside the shared layout. Pages ship no inline script or style (strict CSP). */
final class View
{
    private const CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'";

    /** @return array{0:int,1:string,2:array<string,string>} */
    public static function page(Ctx $c, string $template, array $vars = [], string $title = '', int $status = 200, string $layout = 'layout'): array
    {
        $vars += ['c' => $c, 'title' => $title];
        $content = self::capture($template, $vars);
        $html = self::capture($layout, ['c' => $c, 'title' => $title, 'content' => $content, 'flash' => $c->takeFlash(), 'description' => $vars['description'] ?? '', 'mainClass' => $vars['mainClass'] ?? '']);
        return [$status, $html, self::headers()];
    }

    /** @return array{0:int,1:string,2:array<string,string>} */
    public static function redirect(string $to): array
    {
        if (!str_starts_with($to, '/') || str_starts_with($to, '//') || str_contains($to, "\n")) {
            $to = '/';   // never redirect off-site
        }
        return [303, '', ['Location' => $to, 'Cache-Control' => 'no-store']];
    }

    /** A file download (confirmation, CSV, JSON). */
    public static function download(string $body, string $filename, string $type): array
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        return [200, $body, ['Content-Type' => $type, 'Content-Disposition' => 'attachment; filename="' . $safe . '"', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store']];
    }

    public static function error(Ctx $c, int $status, string $heading, string $message): array
    {
        return self::page($c, 'error', ['heading' => $heading, 'message' => $message, 'status' => $status], $heading, $status);
    }

    /** @return array<string,string> */
    public static function headers(): array
    {
        return [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => self::CSP,
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Cache-Control' => 'no-store',
        ];
    }

    private static function capture(string $template, array $vars): string
    {
        require_once __DIR__ . '/helpers.php';
        $file = dirname(__DIR__, 2) . '/views/' . $template . '.php';
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            require $file;
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
