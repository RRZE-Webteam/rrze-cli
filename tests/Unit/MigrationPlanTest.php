<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RRZE\CLI\Migration\Plan;
use RRZE\CLI\Migration\Preflight;

final class MigrationPlanTest extends TestCase
{
    public function testAvailableSpaceMayChangeButResourcesRemainBoundToApproval(): void
    {
        $approved = ['destination_details' => ['estimated_site_id' => 3, 'storage' => ['required_bytes' => 10, 'available_bytes' => 100]]];
        $current = $approved;
        $current['destination_details']['storage']['available_bytes'] = 99;
        Plan::assertUnchanged($approved, $current);
        $this->addToAssertionCount(1);
        $current['destination_details']['estimated_site_id'] = 4;
        $this->expectExceptionMessage('plan changed during review');
        Plan::assertUnchanged($approved, $current);
    }

    public function testChangedUserActionCannotInheritAnEarlierApproval(): void
    {
        $this->expectExceptionMessage('plan changed during review');
        Plan::assertUnchanged(['users' => [['action' => 'create_wordpress_user', 'target_id' => null]]], ['users' => [['action' => 'add_site_membership', 'target_id' => 12]]]);
    }

    public function testManualTransferDecisionCannotChangeAfterApproval(): void
    {
        $approved = ['destination_details' => ['storage' => null], 'uploads' => ['skipped' => true]];
        Plan::assertUnchanged($approved, $approved);
        $this->addToAssertionCount(1);
        $current = $approved;
        $current['uploads']['skipped'] = false;
        $this->expectExceptionMessage('plan changed during review');
        Plan::assertUnchanged($approved, $current);
    }

    public function testStringFalseCannotBeMistakenForConsentToSkipUploads(): void
    {
        $this->expectExceptionMessage('flag without a value');
        Preflight::build([], '', ['skip-uploads' => 'false']);
    }
}
