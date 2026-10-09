<?php

namespace RRZE\CLI\Migration;

use RuntimeException;

/** Shared review text and the decisions to which a wizard approval applies. */
final class Plan
{
    public static function lines(array $plan): array
    {
        $lines = [
            'Source: ' . $plan['source'],
            'Destination: ' . $plan['destination'] . ' (new site only; no overwrite)',
            'Destination network: ' . $plan['destination_details']['network_id'],
            'Estimated site ID: ' . $plan['destination_details']['estimated_site_id'],
        ];
        foreach ($plan['tables'] as $from => $to) {
            $lines[] = 'Table: ' . $from . ' -> ' . $to;
        }
        foreach ($plan['users'] as $user) {
            $membership = ($user['site_member'] ?? true) ? $user['role'] : 'reference only; no site membership';
            $lines[] = 'User: ' . $user['login'] . ' -> ' . $user['action'] . ' (' . $membership . ')';
        }
        $lines[] = 'Numeric user-reference fields: ' . (implode(', ', $plan['user_reference_fields']) ?: 'none');
        $lines[] = 'Media inventory: ' . $plan['uploads']['files'] . ' files, ' . $plan['uploads']['bytes'] . ' bytes';
        $lines[] = 'Media transfer: external rsync; separate verification required';
        $lines[] = 'Media source: ' . ($plan['uploads']['source_directory'] ?? 'see manifest');
        $lines[] = 'Excluded upload directories: ' . (implode(', ', $plan['uploads']['excluded_directories'] ?? []) ?: 'none');
        $lines[] = 'Upload destination: ' . ($plan['destination_details']['uploads_directory'] ?? 'not resolved; configure manually');
        foreach ($plan['limitations'] as $limitation) {
            $lines[] = 'Note: ' . $limitation;
        }
        return $lines;
    }

    public static function assertUnchanged(array $approved, array $current): void
    {
        // Available bytes fluctuate while a person reviews. Both preflights still enforce capacity.
        unset($approved['destination_details']['storage']['available_bytes'], $current['destination_details']['storage']['available_bytes']);
        if ($approved !== $current) {
            throw new RuntimeException('The migration plan changed during review. No site was created. Restart the wizard and review the new plan.');
        }
    }
}
