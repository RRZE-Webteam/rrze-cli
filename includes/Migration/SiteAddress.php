<?php

namespace RRZE\CLI\Migration;

use RuntimeException;

final class SiteAddress
{
    public static function parse(string $url): array
    {
        if (!str_contains($url, '://')) {
            $url = 'http://' . $url;
        }
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Provide an unambiguous HTTP or HTTPS site URL without credentials, query or fragment.');
        }
        $path = '/' . trim($parts['path'] ?? '', '/') . '/';
        $path = $path === '//' ? '/' : $path;
        if (str_contains($path, '//') || preg_match('~(?:^|/)\.{1,2}(?:/|$)|%|\\\\~', $path)) {
            throw new RuntimeException('The site URL contains an ambiguous path.');
        }
        $domain = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        return ['domain' => $domain, 'path' => $path, 'url' => strtolower($parts['scheme']) . '://' . $domain . $path];
    }
}
