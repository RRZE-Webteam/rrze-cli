<?php

declare(strict_types=1);

namespace RRZE\CLI\Tests;

use FilesystemIterator;
use mysqli;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/** Copies the installed core; owns two new databases on the local database server. */
final class Sandbox
{
    public readonly string $root;
    private string $token;
    private string $repository;
    private string $template;
    private string $wpCli;
    private string $php;
    private array $environment;
    private ?mysqli $database = null;
    private array $createdDatabases = [];
    private bool $closed = false;
    private ?string $exportedPackage = null;

    public function __construct()
    {
        $this->repository = dirname(__DIR__, 2);
        $this->template = realpath(getenv('RRZE_TEST_WP_ROOT') ?: dirname($this->repository, 3)) ?: '';
        if (!is_file($this->template . '/wp-load.php') || !is_file($this->template . '/wp-config.php')) {
            throw new RuntimeException('Set RRZE_TEST_WP_ROOT to the local WordPress installation (core and wp-config.php required).');
        }
        $this->php = getenv('RRZE_TEST_PHP_BINARY') ?: PHP_BINARY;
        $this->wpCli = WpCli::findExecutable(getenv('RRZE_TEST_WP_CLI') ?: 'wp', getenv('PATH') ?: '');
        $this->token = bin2hex(random_bytes(12));
        $directory = sys_get_temp_dir() . '/rrze-cli-tests-' . $this->token;
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('Cannot create private test directory.');
        }
        $this->root = realpath($directory);
        file_put_contents($this->root . '/.owner', $this->token);
        register_shutdown_function(function (): void {
            try {
                $this->close();
            } catch (\Throwable $error) {
                fwrite(STDERR, 'Test cleanup failed: ' . $error->getMessage() . PHP_EOL);
            }
        });
        $mysqlBin = getenv('RRZE_TEST_MYSQL_BIN') ?: dirname(self::findExecutable('mysql'));
        foreach (['mysql', 'mysqldump'] as $binary) {
            if (!is_executable($mysqlBin . '/' . $binary)) {
                throw new RuntimeException('MySQL clients required; set RRZE_TEST_MYSQL_BIN to their bin directory.');
            }
        }
        $this->environment = [
            'PATH' => $mysqlBin . ':' . dirname($this->php) . ':/usr/bin:/bin:/usr/sbin',
            'WP_CLI_CONFIG_PATH' => $this->repository . '/tests/wp-cli.yml',
            'WP_CLI_PACKAGES_DIR' => $this->root . '/packages',
            'WP_CLI_CACHE_DIR' => $this->root . '/cache',
            'WP_CLI_PHP' => $this->php,
            'TMPDIR' => $this->root,
            'MYSQL_HOME' => $this->root,
            'MYSQL_TEST_LOGIN_FILE' => $this->root . '/no-login-file',
        ];
    }

    public function start(): void
    {
        $this->assertOwned();
        // config get parses constants without booting the existing installation.
        // Capture credentials internally; never put them in a command line, log or test report.
        $settings = [];
        $settingsFile = getenv('RRZE_TEST_DB_CONFIG') ?: $this->repository . '/tests/.local.json';
        if (is_file($settingsFile)) {
            $settings = json_decode(file_get_contents($settingsFile), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($settings) || array_diff(array_keys($settings), ['DB_HOST', 'DB_USER', 'DB_PASSWORD'])) {
                throw new RuntimeException('Local test config accepts only DB_HOST, DB_USER and DB_PASSWORD.');
            }
            foreach ($settings as $value) {
                if (!is_string($value)) {
                    throw new RuntimeException('Local test connection settings must be strings.');
                }
            }
        } elseif (getenv('RRZE_TEST_DB_CONFIG') !== false) {
            throw new RuntimeException('The specified local test connection file does not exist.');
        }
        $credentials = [];
        foreach (['DB_HOST', 'DB_USER', 'DB_PASSWORD'] as $constant) {
            $override = getenv('RRZE_TEST_' . $constant);
            if ($override !== false) {
                $credentials[$constant] = $override;
                continue;
            }
            if (array_key_exists($constant, $settings)) {
                $credentials[$constant] = $settings[$constant];
                continue;
            }
            $credentials[$constant] = rtrim(Process::checked([
                $this->php, '-d', 'display_errors=stderr', $this->wpCli, '--path=' . $this->template, '--skip-packages', '--no-color',
                'config', 'get', $constant, '--type=constant',
            ], $this->template, $this->environment), "\r\n");
        }
        $address = $credentials['DB_HOST'];
        if (!preg_match('~^(localhost|127\.0\.0\.1)(?::([0-9]+|/.*))?$~', $address, $match)) {
            throw new RuntimeException('Only a local MySQL server (localhost or 127.0.0.1) is allowed.');
        }
        $port = isset($match[2]) && ctype_digit($match[2]) ? (int) $match[2] : 3306;
        $socket = isset($match[2]) && str_starts_with($match[2], '/') ? $match[2] : null;
        $this->database = new mysqli($match[1], $credentials['DB_USER'], $credentials['DB_PASSWORD'], null, $port, $socket);

        foreach (['source' => 'src_', 'target' => 'dst_', 'single' => 'sgl_'] as $name => $prefix) {
            $databaseName = 'rrze_cli_test_' . $this->token . '_' . $name;
            // Never reuse an existing schema, even if it happens to have the generated name.
            $this->database->query('CREATE DATABASE `' . $databaseName . '`');
            $this->createdDatabases[] = $databaseName;
            file_put_contents($this->root . '/databases.json', json_encode($this->createdDatabases, JSON_PRETTY_PRINT));
            $path = $this->root . '/' . $name;
            mkdir($path, 0700);
            foreach (['wp-admin', 'wp-includes'] as $folder) {
                self::copyDirectory($this->template . '/' . $folder, $path . '/' . $folder);
            }
            foreach (['index.php', 'wp-activate.php', 'wp-blog-header.php', 'wp-comments-post.php', 'wp-cron.php', 'wp-links-opml.php', 'wp-load.php', 'wp-login.php', 'wp-mail.php', 'wp-settings.php', 'wp-signup.php', 'wp-trackback.php', 'xmlrpc.php'] as $file) {
                if (!copy($this->template . '/' . $file, $path . '/' . $file)) {
                    throw new RuntimeException('Cannot copy WordPress core.');
                }
            }
            mkdir($path . '/wp-content/plugins', 0700, true);
            mkdir($path . '/wp-content/mu-plugins', 0700, true);
            file_put_contents($path . '/wp-content/mu-plugins/disable-mail.php', "<?php\nadd_filter('pre_wp_mail', '__return_true');\n");
            mkdir($path . '/wp-content/themes/rrze-test', 0700, true);
            file_put_contents($path . '/wp-content/themes/rrze-test/style.css', "/*\nTheme Name: RRZE Test Fixture\n*/\n");
            file_put_contents($path . '/wp-content/themes/rrze-test/index.php', "<?php // Test fixture.\n");
            $config = "<?php\n";
            $defines = $credentials + [
                'DB_NAME' => $databaseName, 'DB_CHARSET' => 'utf8mb4', 'DB_COLLATE' => '',
                'WP_ENVIRONMENT_TYPE' => 'local', 'DISABLE_WP_CRON' => true,
                'WP_HTTP_BLOCK_EXTERNAL' => true, 'WP_AUTO_UPDATE_CORE' => false,
                'RRZE_CLI_TEST_RUN' => $this->token,
                'RRZE_MIGRATION_RUN_DIR' => $this->root . '/runs-' . $name,
            ];
            foreach ($defines as $constant => $value) {
                $config .= 'define(' . var_export($constant, true) . ', ' . var_export($value, true) . ");\n";
            }
            $config .= '$table_prefix = ' . var_export($prefix, true) . ";\n";
            $config .= "/* That's all, stop editing! Happy publishing. */\n";
            $config .= "if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }\nrequire_once ABSPATH . 'wp-settings.php';\n";
            file_put_contents($path . '/wp-config.php', $config);
            chmod($path . '/wp-config.php', 0600);
            $this->wp($name, [
                'core', $name === 'single' ? 'install' : 'multisite-install', '--url=http://' . $name . '.test', '--title=Migration ' . $name,
                '--admin_user=fixtureadmin', '--admin_password=local-test-only-8pZ!',
                '--admin_email=admin@company.example', '--skip-email',
            ]);
            $plugin = $path . '/wp-content/plugins/rrze-cli';
            mkdir($plugin, 0700);
            foreach (['includes', 'vendor'] as $folder) {
                self::copyDirectory($this->repository . '/' . $folder, $plugin . '/' . $folder);
            }
            copy($this->repository . '/rrze-cli.php', $plugin . '/rrze-cli.php');
            copy($this->repository . '/migration-bootstrap.php', $plugin . '/migration-bootstrap.php');
            $this->wp($name, ['plugin', 'activate', 'rrze-cli', ...($name === 'single' ? [] : ['--network'])]);
            if ($name === 'single') {
                $this->wp($name, ['theme', 'activate', 'rrze-test']);
                $this->wp($name, ['eval', 'return;']);
                continue;
            }
            $this->fixture($name, 'seed', [$name]);
            // switch_to_blog() during seeding does not run the site's init hooks.
            // Complete theme/widget/cron initialization before taking any baseline.
            $sitePath = $name === 'source' ? '/source/' : '/control/';
            $this->wp($name, ['eval', 'return;', '--url=http://' . $name . '.test' . $sitePath]);
        }
    }

    /** @return array{code: int, stdout: string, stderr: string} */
    public function command(string $installation, array $arguments): array
    {
        $this->assertOwned();
        if (!in_array($installation, ['source', 'target', 'single'], true)) {
            throw new RuntimeException('Unknown disposable installation.');
        }
        $path = $this->root . '/' . $installation;
        if (is_link($path) || realpath($path) !== $path) {
            throw new RuntimeException('WordPress path escaped the sandbox.');
        }
        $result = Process::run([
            $this->php, '-d', 'display_errors=stderr', $this->wpCli, '--path=' . $path, '--skip-packages', '--no-color', ...$arguments,
        ], $path, $this->environment);
        file_put_contents($this->root . '/commands.log', implode(' ', $arguments) . "\nexit=" . $result['code'] . "\n" . $result['stdout'] . $result['stderr'] . "\n", FILE_APPEND);
        return $result;
    }

    public function background(string $installation, array $arguments): array
    {
        $this->assertOwned();
        if (!in_array($installation, ['source', 'target', 'single'], true)) {
            throw new RuntimeException('Unknown disposable installation.');
        }
        $path = $this->root . '/' . $installation;
        return Process::start([
            $this->php, '-d', 'display_errors=stderr', $this->wpCli, '--path=' . $path, '--skip-packages', '--no-color', ...$arguments,
        ], $path, $this->environment);
    }

    public function terminal(string $installation, array $arguments, array $dialogue, bool $rich = false): array
    {
        $this->assertOwned();
        if (!in_array($installation, ['source', 'target', 'single'], true)) {
            throw new RuntimeException('Unknown disposable installation.');
        }
        $path = $this->root . '/' . $installation;
        $result = Process::terminal([
            $this->php, '-d', 'display_errors=stderr', $this->wpCli, '--path=' . $path, '--skip-packages', $rich ? '--color' : '--no-color', ...$arguments,
        ], $path, ['TERM' => 'xterm', 'COLUMNS' => '120', 'LINES' => '40'] + $this->environment, $dialogue);
        file_put_contents($this->root . '/commands.log', implode(' ', $arguments) . "\nexit=" . $result['code'] . "\n" . $result['stdout'] . $result['stderr'] . "\n", FILE_APPEND);
        return $result;
    }

    public function wp(string $installation, array $arguments): string
    {
        $result = $this->command($installation, $arguments);
        if ($result['code'] !== 0) {
            throw new RuntimeException(implode(' ', $arguments) . "\n" . $result['stdout'] . $result['stderr']);
        }
        return $result['stdout'];
    }

    public function fixture(string $installation, string $action, array $arguments = []): array
    {
        $output = $this->wp($installation, ['eval-file', $this->repository . '/tests/fixtures/site.php', $action, ...$arguments]);
        if (!preg_match('/^RRZE_TEST_JSON=(.+)$/m', $output, $matches)) {
            throw new RuntimeException('Fixture returned no JSON: ' . $output);
        }
        return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    }

    public function exportPackage(): string
    {
        if ($this->exportedPackage === null) {
            $this->wp('source', ['rrze-migration', 'export', 'all', 'fixture.zip', '--url=http://source.test/source/']);
            $packages = glob($this->root . '/runs-source/export-*/fixture.zip');
            if (count($packages) !== 1) {
                throw new RuntimeException('Expected exactly one initial private export package.');
            }
            $this->exportedPackage = $packages[0];
        }
        $target = $this->root . '/runs-target';
        if (!is_dir($target)) {
            mkdir($target, 0700);
        }
        if (!is_file($this->exportedPackage) || !copy($this->exportedPackage, $target . '/fixture.zip')) {
            throw new RuntimeException('Export package was not created or transferred.');
        }
        chmod($target . '/fixture.zip', 0600);
        return $this->exportedPackage;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->assertOwned();
        while ($this->createdDatabases !== []) {
            $name = $this->createdDatabases[0];
            if (!preg_match('/^rrze_cli_test_' . $this->token . '_(source|target|single)$/D', $name)) {
                throw new RuntimeException('Refusing to remove a database not created by this run.');
            }
            $this->database->query('DROP DATABASE `' . $name . '`');
            array_shift($this->createdDatabases);
        }
        $this->database?->close();
        $this->database = null;
        $destination = $this->repository . '/tests/.artifacts/integration';
        if (!is_dir($destination)) {
            mkdir($destination, 0700, true);
        }
        if (is_file($this->root . '/commands.log')) {
            copy($this->root . '/commands.log', $destination . '/commands.log');
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
        $this->closed = true;
    }

    private function assertOwned(): void
    {
        if (is_link($this->root) || !is_file($this->root . '/.owner') || file_get_contents($this->root . '/.owner') !== $this->token) {
            throw new RuntimeException('Refusing to use or clean up a directory not owned by this test run.');
        }
    }

    private static function findExecutable(string $name): string
    {
        foreach (str_contains($name, '/') ? [''] : explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
            $candidate = $directory === '' ? $name : $directory . '/' . $name;
            if (is_executable($candidate)) {
                return realpath($candidate);
            }
        }
        throw new RuntimeException('Missing executable: ' . $name);
    }

    private static function copyDirectory(string $source, string $target): void
    {
        mkdir($target, 0700);
        foreach (new FilesystemIterator($source) as $entry) {
            if ($entry->isLink()) {
                throw new RuntimeException('Symlinks are not copied into the disposable WordPress installation.');
            }
            $destination = $target . '/' . $entry->getFilename();
            if ($entry->isDir()) {
                self::copyDirectory($entry->getPathname(), $destination);
            } elseif (!copy($entry->getPathname(), $destination)) {
                throw new RuntimeException('Could not copy WordPress fixture.');
            }
        }
    }
}
