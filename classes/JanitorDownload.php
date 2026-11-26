<?php

declare(strict_types=1);

namespace Bnomei;

final class JanitorDownload
{
    /**
     * @return list<string>|null
     */
    public static function wgetArguments(mixed $url, mixed $output = ''): ?array
    {
        if (! is_string($url) || self::validUrl($url) === false) {
            return null;
        }

        $arguments = ['wget'];

        if (is_string($output) && $output !== '') {
            $arguments[] = '-O';
            $arguments[] = $output;
        }

        $arguments[] = $url;

        return $arguments;
    }

    public static function run(mixed $url, mixed $output): bool
    {
        // wget truncates -O before it even receives the HTTP status.
        $temporary = null;
        if (is_string($output) && $output !== '' && $output !== '-') {
            $directory = dirname($output);
            if (! is_dir($directory) || ! is_writable($directory) || (file_exists($output) && ! is_file($output))) {
                return false;
            }
            $temporary = tempnam($directory, '.janitor-');
            if ($temporary === false) {
                return false;
            }
        }

        try {
            $arguments = self::wgetArguments($url, $temporary ?? $output);
            if ($arguments === null || ! function_exists('proc_open')) {
                return false;
            }
            $process = @proc_open($arguments, [], $pipes);
            if (! is_resource($process) || proc_close($process) !== 0) {
                return false;
            }

            if ($temporary === null) {
                return true;
            }

            // Keep the temporary private until complete. Copy only the ordinary
            // permission bits, never setuid/setgid, when replacing a file.
            clearstatcache(true, $output);
            $mode = file_exists($output) ? @fileperms($output) : (0666 & ~umask());

            return $mode !== false && @chmod($temporary, $mode & 0777) && @rename($temporary, $output);
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private static function validUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
    }
}
