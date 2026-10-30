<?php

namespace Templite\Cms\Services\Media;

use Illuminate\Http\UploadedFile;

/**
 * Скачивание файла по внешней ссылке с защитой от SSRF.
 *
 * Рядом с CMS в docker-сети живут db и redis, поэтому запрос по произвольному
 * URL без проверок недопустим.
 */
class RemoteFileFetcher
{
    public const MAX_REDIRECTS = 3;
    public const TIMEOUT = 15;

    /**
     * @return array{ok: bool, file?: UploadedFile, error?: string, message?: string}
     */
    public function fetch(string $url, int $maxBytes): array
    {
        $current = $url;

        for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++) {
            $check = $this->validateUrl($current);

            if (! ($check['ok'] ?? false)) {
                return $check;
            }

            $response = $this->request($current, $maxBytes);

            if (isset($response['redirect'])) {
                $current = $response['redirect'];

                continue;
            }

            return $response;
        }

        return ['ok' => false, 'error' => 'too_many_redirects', 'message' => 'Слишком много перенаправлений.'];
    }

    /** @return array{ok: bool, error?: string, message?: string} */
    private function validateUrl(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        if (! in_array($scheme, ['http', 'https'], true)) {
            return ['ok' => false, 'error' => 'unsupported_scheme', 'message' => 'Разрешены только http и https.'];
        }

        if ($host === '') {
            return ['ok' => false, 'error' => 'invalid_url', 'message' => 'Некорректная ссылка.'];
        }

        foreach ($this->resolve($host) as $ip) {
            if (! $this->isPublicIp($ip)) {
                return [
                    'ok' => false,
                    'error' => 'blocked_host',
                    'message' => 'Адрес ведёт во внутреннюю сеть — загрузка запрещена.',
                ];
            }
        }

        return ['ok' => true];
    }

    /** @return array<int,string> */
    private function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA) ?: [];
        $ips = [];

        foreach ($records as $record) {
            $ips[] = $record['ip'] ?? $record['ipv6'] ?? null;
        }

        $ips = array_values(array_filter($ips));

        // Имя не резолвится в публичный адрес — считаем небезопасным, чтобы не полагаться
        // на резолвер curl (иначе docker-имена вроде templite-db прошли бы проверку).
        return $ips ?: ['0.0.0.0'];
    }

    private function isPublicIp(string $ip): bool
    {
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    /**
     * @return array{ok: bool, file?: UploadedFile, redirect?: string, error?: string, message?: string}
     */
    private function request(string $url, int $maxBytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cms-remote-');
        $handle = fopen($tmp, 'wb');
        $exceeded = false;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            // Редиректы обрабатываем сами: иначе ссылка с публичного адреса
            // на 127.0.0.1 обошла бы проверку.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FILE => $handle,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function ($res, $dlTotal, $dlNow) use ($maxBytes, &$exceeded) {
                if ($dlNow > $maxBytes || $dlTotal > $maxBytes) {
                    $exceeded = true;

                    return 1; // прерывает скачивание
                }

                return 0;
            },
        ]);

        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $mime = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($handle);

        if ($exceeded) {
            @unlink($tmp);

            return ['ok' => false, 'error' => 'file_too_large', 'message' => 'Файл больше допустимого размера.'];
        }

        if ($status >= 300 && $status < 400 && $location !== '') {
            @unlink($tmp);

            return ['ok' => false, 'redirect' => $location];
        }

        if ($status !== 200 || $error !== '') {
            @unlink($tmp);

            return ['ok' => false, 'error' => 'download_failed', 'message' => $error ?: "HTTP {$status}"];
        }

        return [
            'ok' => true,
            'file' => new UploadedFile($tmp, $this->guessName($url, $mime), $mime ?: null, null, true),
        ];
    }

    private function guessName(string $url, string $mime): string
    {
        $name = basename((string) (parse_url($url, PHP_URL_PATH) ?: ''));

        if ($name !== '' && str_contains($name, '.')) {
            return $name;
        }

        $ext = match (true) {
            str_contains($mime, 'jpeg') => 'jpg',
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'gif') => 'gif',
            str_contains($mime, 'pdf') => 'pdf',
            default => 'bin',
        };

        return 'download-'.substr(md5($url), 0, 8).'.'.$ext;
    }
}
