<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RRZE\CLI\Migration\{Files, Run};

final class MigrationRunTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = Files::workspace();
    }

    protected function tearDown(): void
    {
        Files::remove($this->root);
    }

    public function testPrivateRunPreservesPackageAndDurableStepStates(): void
    {
        $source = $this->root . '/original.zip';
        file_put_contents($source, 'synthetic package');
        $run = Run::create($this->root);
        try {
            $run->begin('prepare');
            $copy = $run->package($source);
            $run->done();
            $run->begin('create_site');
            $run->site(42);
            $run->done();
            $run->begin('import_users');
            $run->userIntent(3);
            $pending = Run::read($this->root, $run->id);
            self::assertTrue($pending['active']);
            self::assertSame(3, $pending['pending_user']);
            $run->user(3, 90, true);
            $run->baseline([7 => str_repeat('a', 64)]);
            $run->failure();
            $run->finish('failed');
            file_put_contents($source, 'changed source');
            $state = Run::read($this->root, $run->id);
            self::assertSame('failed', $state['observed_status']);
            self::assertFalse($state['active']);
            self::assertSame('import_users', $state['failure_step']);
            self::assertSame('completed', $state['steps']['create_site']['status']);
            self::assertSame('started', $state['steps']['import_users']['status']);
            self::assertSame(42, $state['site_id']);
            self::assertSame(['target_id' => 90, 'created' => true], $state['users'][3]);
            self::assertSame('synthetic package', file_get_contents($copy));
            self::assertSame(hash_file('sha256', $copy), $state['package_sha256']);
            self::assertSame(0700, fileperms($run->directory) & 0777);
            foreach (['run.json', 'package.zip', 'active.lock'] as $name) {
                self::assertSame(0600, fileperms($run->directory . '/' . $name) & 0777);
            }
        } finally {
            unset($run);
        }
    }

    public function testJournalCannotBePlacedInsideWebRoot(): void
    {
        $this->expectExceptionMessage('outside the web roots');
        Run::root($this->root . '/runs', [$this->root], true);
    }

    public function testPublicRunDirectoryIsRejectedWithoutChangingPermissions(): void
    {
        mkdir($this->root . '/public', 0755);
        try {
            Run::root($this->root . '/public', [], false);
            self::fail('A public journal directory must be rejected.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('private permissions', $error->getMessage());
            self::assertSame(0755, fileperms($this->root . '/public') & 0777);
        }
    }

    public function testStatusDoesNotCreateMissingRoot(): void
    {
        $this->expectException(RuntimeException::class);
        try {
            Run::root($this->root . '/missing', [], false);
        } finally {
            self::assertDirectoryDoesNotExist($this->root . '/missing');
        }
    }

    public function testRunIdCannotEscapeTheJournalRoot(): void
    {
        $this->expectExceptionMessage('Invalid migration run ID');
        Run::read($this->root, '../outside');
    }

    public function testSymlinkedJournalCannotBeRead(): void
    {
        $id = str_repeat('a', 32);
        symlink($this->root, $this->root . '/' . $id);
        $this->expectExceptionMessage('unsafe migration journal');
        Run::read($this->root, $id);
    }

    public function testUnknownOrRepeatedStepsAreRejected(): void
    {
        $run = Run::create($this->root);
        try {
            $run->begin('prepare');
            $run->done();
            $this->expectExceptionMessage('Invalid or repeated');
            $run->begin('prepare');
        } finally {
            $run->finish('failed');
        }
    }

    public function testSuccessCannotBeRecordedWithoutVerificationAndCleanup(): void
    {
        $run = Run::create($this->root);
        try {
            $this->expectExceptionMessage('Verification and cleanup');
            $run->finish('completed');
        } finally {
            $run->finish('failed');
        }
    }

    public function testCorruptedSuccessCheckpointIsNotTrusted(): void
    {
        $run = Run::create($this->root);
        $run->finish('failed');
        $path = $run->directory . '/run.json';
        $state = json_decode(file_get_contents($path), true);
        $state['status'] = 'completed';
        file_put_contents($path, json_encode($state));
        $this->expectExceptionMessage('Incomplete journal');
        Run::read($this->root, $run->id);
    }

    public function testDeadProcessCannotBeReportedAsCompleted(): void
    {
        $run = Run::create($this->root);
        $run->begin('import_tables');
        $run->finish('interrupted');
        // Simulate a hard kill between the last started checkpoint and shutdown recording.
        $path = $run->directory . '/run.json';
        $state = json_decode(file_get_contents($path), true);
        $state['status'] = 'running';
        file_put_contents($path, json_encode($state));
        $observed = Run::read($this->root, $run->id);
        self::assertSame('interrupted_or_unfinished', $observed['observed_status']);
        self::assertSame('started', $observed['steps']['import_tables']['status']);
        self::assertSame('running', json_decode(file_get_contents($path), true)['status'], 'Status reads must not rewrite the checkpoint.');
    }
}
