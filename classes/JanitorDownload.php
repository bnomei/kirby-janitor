<?php

declare(strict_types=1);

namespace Bnomei;

final class JanitorDownload
{
    /**
     * @return array<int, string>|null
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

    /**
     * @param array<int, string> $arguments
     */
    public static function run(array $arguments): int
    {
        $arguments = array_values($arguments);

        if ($arguments === [] || function_exists('proc_open') === false) {
            return 1;
        }

        $pipes = [];
        $process = proc_open($arguments, [
            0 => ['pipe', 'r'],
            1 => ['file', 'php://stdout', 'w'],
            2 => ['file', 'php://stderr', 'w'],
        ], $pipes);

        if (is_resource($process) === false) {
            return 1;
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        return proc_close($process);
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
