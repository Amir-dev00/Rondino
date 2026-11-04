<?php

declare(strict_types=1);

final class Http
{
    /**
     * @return array{ok: bool, status: int, body: string, error: ?string, final_url: string}
     */
    public static function get(string $url, int $timeout = 25): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'افزونه cURL در PHP فعال نیست.', 'final_url' => $url];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => (string) config('http_user_agent', 'RondinoSimple/1.0'),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                'Accept-Language: fa-IR,fa;q=0.9,en;q=0.8',
            ],
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

        if ($errno !== 0 || $body === false) {
            $msg = $err !== '' ? $err : ('cURL error ' . $errno);
            if ($errno === CURLE_OPERATION_TIMEDOUT || $errno === 28) {
                $msg = 'زمان انتظار تمام شد (timeout).';
            }
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $msg, 'final_url' => $final ?: $url];
        }

        if ($status >= 400) {
            return ['ok' => false, 'status' => $status, 'body' => (string) $body, 'error' => 'HTTP ' . $status, 'final_url' => $final ?: $url];
        }

        return ['ok' => true, 'status' => $status, 'body' => (string) $body, 'error' => null, 'final_url' => $final ?: $url];
    }
}
