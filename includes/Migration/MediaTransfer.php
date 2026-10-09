<?php

namespace RRZE\CLI\Migration;

use RRZE\CLI\Utils;
use RuntimeException;

/** Plans an operator-run transfer and checks WordPress's effective media location. */
final class MediaTransfer
{
    public static function layout(string $url, int $id): array
    {
        $layout = self::inSite($url, 'wp_upload_dir(null, false, true)');
        $expected = Destination::uploadPath($id);
        if (!empty($layout['error']) || ($layout['basedir'] ?? null) !== $expected || empty($layout['baseurl'])) {
            throw new RuntimeException('The effective upload root is not the dedicated directory of the new site. A destination adapter is required.');
        }
        MediaManifest::directory($expected);
        return ['directory' => $expected, 'baseurl' => rtrim(SiteAddress::parse($layout['baseurl'])['url'], '/')];
    }

    private static function inSite(string $url, string $expression): array
    {
        $code = 'try { $result = ["data" => ' . $expression . ']; } catch (\\RuntimeException $error) { $result = ["error" => $error->getMessage()]; }'
            . ' echo "RRZE_MEDIA_JSON=" . json_encode($result, JSON_THROW_ON_ERROR) . "\n";';
        $output = Utils::checked_command('eval', [$code], [], ['url' => $url]);
        if (!preg_match('/^RRZE_MEDIA_JSON=(.+)$/m', $output, $match)) {
            throw new RuntimeException('Could not inspect media in the destination website context.');
        }
        $result = json_decode($match[1], true, 32, JSON_THROW_ON_ERROR);
        if (isset($result['error'])) {
            throw new RuntimeException('Destination media check: ' . Terminal::safe($result['error']));
        }
        if (!is_array($result['data'] ?? null)) {
            throw new RuntimeException('Invalid destination media inspection result.');
        }
        return $result['data'];
    }

    public static function prepare(array $manifest, array $layout, Run $run): void
    {
        MediaManifest::directory($layout['directory']);
        if (file_exists($layout['directory']) || !mkdir($layout['directory'], 0755, true)) {
            throw new RuntimeException('The new media destination already exists or cannot be reserved.');
        }
        foreach (['media.json' => json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'media-files.txt' => MediaManifest::fileList($manifest)] as $name => $contents) {
            $handle = Files::output($run->directory . '/' . $name);
            try {
                if ((fileperms($run->directory . '/' . $name) & 0077) !== 0 || fwrite($handle, $contents) !== strlen($contents) || !fflush($handle) || !fsync($handle)) {
                    throw new RuntimeException('Cannot securely persist the external media plan.');
                }
            } finally {
                fclose($handle);
            }
        }
        $run->mediaPlan($layout, self::requiresAccessCheck($manifest));
    }

    public static function requiresAccessCheck(array $manifest): bool
    {
        foreach ($manifest['files'] as $name => $_) {
            if (UploadExclusions::contains('wp-content/uploads/' . $name, $manifest['protected_directories'])) {
                return true;
            }
        }
        return false;
    }

    /** Mask media URLs before the general website replacement, avoiding cascading replacements. */
    public static function replaceUrls(array $tables, array $manifest, array $layout, array $source, array $target): void
    {
        $tokens = [];
        $marker = 'rrze-media-' . bin2hex(random_bytes(16)) . '-';
        foreach (self::urlRules($manifest, $layout) as $index => [$pattern, $replacement]) {
            $token = $marker . $index . '/';
            Utils::checked_command('search-replace', [$pattern, $token, ...$tables], ['precise' => true, 'regex' => true, 'regex-delimiter' => '~'], ['url' => $target['url']]);
            $tokens[$token] = $replacement;
        }
        Utils::checked_command('search-replace', [Utils::parse_url_for_search_replace($source['url']), Utils::parse_url_for_search_replace($target['url']), ...$tables], ['precise' => true], ['url' => $target['url']]);
        foreach ($tokens as $token => $replacement) {
            Utils::checked_command('search-replace', [$token, $replacement, ...$tables], ['precise' => true], ['url' => $target['url']]);
        }
    }

    public static function urlRules(array $manifest, array $layout): array
    {
        $from = rtrim($manifest['source_baseurl'], '/') . '/';
        $to = rtrim($layout['baseurl'], '/') . '/';
        $fromPath = parse_url($from, PHP_URL_PATH);
        $toPath = parse_url($to, PHP_URL_PATH);
        $rules = [];
        foreach ([false, true] as $escaped) {
            $encode = static fn ($value) => $escaped ? str_replace('/', '\\/', $value) : $value;
            // Explicit directory boundaries prevent site 5 from matching site 50.
            $omissions = array_map(static fn ($dir) => preg_quote($encode($dir . '/'), '~'), $manifest['excluded_directories']);
            $exclude = $omissions ? '(?!(?:' . implode('|', $omissions) . '))' : '';
            $absolute = preg_quote($encode(preg_replace('~^https?:~', '', $from)), '~');
            $rules[] = ['(?:https?:)?' . $absolute . $exclude, $encode($to)];
            $rules[] = ['(?<![A-Za-z0-9_:./\\\\-])' . preg_quote($encode($fromPath), '~') . $exclude, $encode($toPath)];
        }
        return $rules;
    }

    public static function host(string $host): string
    {
        if (!preg_match('/^(?:[A-Za-z0-9_][A-Za-z0-9_.-]*@)?[A-Za-z0-9][A-Za-z0-9.-]*$/D', $host)) {
            throw new RuntimeException('Use --source-host=user@hostname without a port, path or SSH options. Configure SSH aliases for those settings.');
        }
        return $host;
    }

    public static function commands(array $manifest, array $layout, string $list, ?string $host = null, ?string $sourceDirectory = null, bool $multiline = false): array
    {
        if ($host !== null) {
            self::host($host);
        }
        $source = MediaManifest::absolute($sourceDirectory ?? $manifest['source_directory']) . '/';
        // Without an explicit local snapshot, require the operator to supply the source host.
        $source = ($host ?? ($sourceDirectory === null ? 'SOURCE_USER@SOURCE_HOST' : '')) . (($host !== null || $sourceDirectory === null) ? ':' : '') . $source;
        $arguments = ['rsync', '-rt', '--checksum', '--itemize-changes', '--ignore-existing', '--from0', '--files-from=' . $list];
        if ($host !== null || $sourceDirectory === null) {
            $arguments[] = '--protect-args';
        }
        $tail = ['--', $source, $layout['directory'] . '/'];
        return [
            'preview' => Console::shell([...$arguments, '--dry-run', ...$tail], $multiline),
            'transfer' => Console::shell([...$arguments, ...$tail], $multiline),
        ];
    }

    public static function verifySite(array $state, string $directory): array
    {
        return self::inSite($state['destination'], '\\RRZE\\CLI\\Migration\\MediaTransfer::attachments(' . var_export($directory . '/media.json', true) . ')');
    }

    /** Runs in a fresh WP-CLI process booted for the new website, including its filters. */
    public static function attachments(string $manifestFile): array
    {
        global $wpdb;
        $manifest = MediaManifest::read(file_get_contents($manifestFile));
        $layout = wp_upload_dir(null, false, true);
        $rows = $wpdb->get_results("SELECT p.ID, m.meta_value AS file FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file' WHERE p.post_type = 'attachment'", ARRAY_A);
        if ($wpdb->last_error) {
            throw new RuntimeException('Cannot inspect attachment references.');
        }
        $excluded = 0;
        foreach ($rows as $row) {
            $file = $row['file'];
            if (!is_string($file) || $file === '') {
                throw new RuntimeException('An attachment has no local file reference: ' . $row['ID']);
            }
            Package::path('wp-content/uploads/' . $file);
            if (UploadExclusions::contains('wp-content/uploads/' . $file, $manifest['excluded_directories'])) {
                $excluded++;
                continue;
            }
            if (!isset($manifest['files'][$file]) || get_attached_file((int) $row['ID']) !== $layout['basedir'] . '/' . $file
                || wp_get_attachment_url((int) $row['ID']) !== $layout['baseurl'] . '/' . $file) {
                throw new RuntimeException('Attachment file or URL does not resolve to the media inventory: ' . $row['ID']);
            }
            $metadata = wp_get_attachment_metadata((int) $row['ID']);
            foreach (($metadata['sizes'] ?? []) as $size) {
                $relative = (dirname($file) === '.' ? '' : dirname($file) . '/') . ($size['file'] ?? '');
                if (!isset($manifest['files'][$relative])) {
                    throw new RuntimeException('An attachment derivative is missing from the media inventory: ' . $row['ID']);
                }
            }
        }
        if (self::requiresAccessCheck($manifest)) {
            if (!class_exists('RRZE\\AccessControl\\Media\\Files') || !class_exists('RRZE\\AccessControl\\Options')
                || !in_array(\RRZE\AccessControl\Media\Files::protectedUploadDir(), $manifest['protected_directories'], true)
                || !get_site_option(\RRZE\AccessControl\Options::getEnabledOptionName())) {
                throw new RuntimeException('Protected media require rrze-ac with the matching protected directory and configured access rules on the destination.');
            }
        }
        return ['attachments' => count($rows) - $excluded, 'excluded_attachments' => $excluded];
    }
}
