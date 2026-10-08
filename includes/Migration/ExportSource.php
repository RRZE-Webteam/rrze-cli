<?php

namespace RRZE\CLI\Migration;

use RuntimeException;
use WP_CLI;

/** Resolve site IDs before exporting; load site-specific plugins in a fresh WP-CLI process. */
final class ExportSource
{
    public static function id(mixed $value): int
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]*$/D', (string) $value)
            || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new RuntimeException('Provide a positive numeric website ID.');
        }
        return (int) $value;
    }

    public static function resolve(mixed $value): array
    {
        $id = self::id($value);
        if (!is_multisite()) {
            throw new RuntimeException('Selecting an export source by website ID requires Multisite.');
        }
        $site = get_site($id);
        if ($site === null) {
            throw new RuntimeException('No website exists with ID ' . $id . '.');
        }
        $home = SiteAddress::parse(get_home_url($id));
        // Bootstrap through the network's site record, including subdirectory paths and ports.
        $routing = SiteAddress::parse(parse_url($home['url'], PHP_URL_SCHEME) . '://' . $site->domain . $site->path);
        return ['id' => $id, 'url' => $home['url'], 'routing_url' => $routing['url']];
    }

    /** Null means the requested site is already the bootstrapped WordPress context. */
    public static function command(string $command, array $args, array $options): ?string
    {
        $source = self::resolve($options['site-id']);
        if (get_current_blog_id() === $source['id']) {
            return null;
        }
        $currentUrl = WP_CLI::get_config('url');
        if ($currentUrl && SiteAddress::parse($currentUrl)['url'] === $source['routing_url']) {
            throw new RuntimeException('The resolved source URL did not load the requested website ID. Check the Multisite routing before exporting.');
        }
        $options['site-id'] = $source['id'];
        $options['url'] = $source['routing_url'];
        // WP-CLI serializes the inherited false value as --color=''. Preserve the
        // explicit negative flag so a context switch cannot change prompt mode.
        if (WP_CLI::get_config('color') === false) {
            $options['no-color'] = true;
        }
        return $command . ' ' . implode(' ', array_map('escapeshellarg', $args)) . WP_CLI\Utils\assoc_args_to_str($options);
    }
}
