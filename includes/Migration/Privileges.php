<?php

namespace RRZE\CLI\Migration;

/** Conservative proof from explicit grants; role-only and table-only grants need separate support. */
final class Privileges
{
    public const REQUIRED = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX', 'LOCK TABLES'];

    public static function missing(array $grants, string $database, bool $literalSchemas = false): array
    {
        $allowed = [];
        foreach ($grants as $grant) {
            if (str_starts_with($grant, 'REVOKE ')) {
                return self::REQUIRED;
            }
            if (!preg_match('/^GRANT (.+) ON (\*|`(?:``|[^`])+`)\.\* TO /', $grant, $match)) {
                continue;
            }
            $pattern = $match[2] === '*' ? '%' : str_replace('``', '`', substr($match[2], 1, -1));
            if ($literalSchemas && $match[2] !== '*') {
                if ($pattern !== $database) {
                    continue;
                }
                $pattern = '';
            }
            $regex = '';
            for ($i = 0; $i < strlen($pattern); $i++) {
                if ($pattern[$i] === '\\' && isset($pattern[$i + 1])) {
                    $regex .= preg_quote($pattern[++$i], '~');
                } else {
                    $regex .= match ($pattern[$i]) { '%' => '.*', '_' => '.', default => preg_quote($pattern[$i], '~') };
                }
            }
            if ($pattern !== '' && !preg_match('~^' . $regex . '$~D', $database)) {
                continue;
            }
            $permissions = explode(', ', $match[1]);
            $allowed = array_merge($allowed, in_array('ALL PRIVILEGES', $permissions, true) ? self::REQUIRED : $permissions);
        }
        return array_values(array_diff(self::REQUIRED, $allowed));
    }
}
