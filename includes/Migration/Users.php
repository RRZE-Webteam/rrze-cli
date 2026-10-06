<?php

namespace RRZE\CLI\Migration;

use RuntimeException;

/** CSV validation and SSO identity matching, shared by all migration paths. */
final class Users
{
    public const HEADERS = ['ID', 'user_login', 'user_nicename', 'user_email', 'user_url', 'user_registered', 'role', 'display_name', 'first_name', 'last_name', 'nickname', 'description'];

    public static function forbidden(string $field): bool
    {
        return in_array(strtolower($field), ['user_pass', 'user_activation_key', '_application_passwords', 'session_tokens', 'primary_blog', 'source_domain', 'user_status', 'spam', 'deleted', 'site_admins'], true)
            || (bool) preg_match('/(?:^|_)(?:capabilities|user_level)$/i', $field);
    }

    public static function headers(array $custom = []): array
    {
        foreach ($custom as $field) {
            if (!is_string($field) || !preg_match('/^[A-Za-z0-9_.-]+$/D', $field)) {
                throw new RuntimeException('Invalid custom user field name.');
            }
        }
        return array_values(array_filter(array_unique(array_merge(self::HEADERS, $custom)), static fn ($field) => !self::forbidden($field)));
    }

    public static function read(string $filename, int $maxRows = 10000): array
    {
        $handle = @fopen($filename, 'rb');
        if (!$handle) {
            throw new RuntimeException('Cannot read the user CSV.');
        }
        try {
            $headers = fgetcsv($handle, 0, ',', '"', '\\');
            if (!$headers || count($headers) !== count(array_unique($headers))
                || array_diff(['ID', 'user_login', 'user_email', 'role'], $headers)) {
                throw new RuntimeException('Missing or duplicate user CSV headers.');
            }
            $rows = [];
            $ids = $logins = $emails = [];
            while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                if (count($rows) >= $maxRows) {
                    throw new RuntimeException('The user CSV exceeds the supported user count limit.');
                }
                if (count($data) !== count($headers)) {
                    throw new RuntimeException('Malformed user CSV row.');
                }
                $row = array_combine($headers, $data);
                $login = $row['user_login'];
                $email = $row['user_email'];
                if (!ctype_digit($row['ID']) || (int) $row['ID'] < 1 || $login === '' || trim($login) !== $login
                    || strlen($login) > 60 || !filter_var($email, FILTER_VALIDATE_EMAIL) || $row['role'] === '') {
                    throw new RuntimeException('Invalid user ID, SSO login, company email or role in the package.');
                }
                if (isset($ids[(int) $row['ID']]) || isset($logins[strtolower($login)]) || isset($emails[strtolower($email)])) {
                    throw new RuntimeException('Duplicate user ID, SSO login or company email in the package.');
                }
                $ids[(int) $row['ID']] = $logins[strtolower($login)] = $emails[strtolower($email)] = true;
                $rows[] = array_filter($row, static fn ($field) => !self::forbidden($field), ARRAY_FILTER_USE_KEY);
            }
            if (!feof($handle)) {
                throw new RuntimeException('Could not completely read the user CSV.');
            }
            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /** Candidates are all target users matching either login or email, not just the first hit. */
    public static function resolve(array $source, array $candidates): ?int
    {
        if ($candidates === []) {
            return null;
        }
        if (count($candidates) !== 1 || $candidates[0]['user_login'] !== $source['user_login']
            || strcasecmp($candidates[0]['user_email'], $source['user_email']) !== 0) {
            throw new RuntimeException('SSO identity conflict: login and company email must identify the same existing user.');
        }
        return (int) $candidates[0]['ID'];
    }

    public static function plan(array $rows): array
    {
        global $wpdb;
        $roles = wp_roles()->roles;
        foreach ($rows as &$row) {
            if (sanitize_user($row['user_login'], true) !== $row['user_login'] || !isset($roles[$row['role']])) {
                throw new RuntimeException('The package contains an unsupported SSO login or an unknown target role.');
            }
            $candidates = $wpdb->get_results($wpdb->prepare("SELECT ID, user_login, user_email FROM {$wpdb->users} WHERE user_login = %s OR user_email = %s", $row['user_login'], $row['user_email']), ARRAY_A);
            if ($wpdb->last_error) {
                throw new RuntimeException('Could not check target user identities.');
            }
            $row['target_id'] = self::resolve($row, $candidates);
        }
        return $rows;
    }
}
