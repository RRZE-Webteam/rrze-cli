<?php

// Executed by WP-CLI only inside a test-owned copy. Never run against the developer's site.
if (!defined('RRZE_CLI_TEST_RUN') || !preg_match('/^[a-f0-9]{24}$/D', RRZE_CLI_TEST_RUN)
    || !in_array(DB_NAME, ['rrze_cli_test_' . RRZE_CLI_TEST_RUN . '_source', 'rrze_cli_test_' . RRZE_CLI_TEST_RUN . '_target', 'rrze_cli_test_' . RRZE_CLI_TEST_RUN . '_single'], true)
    || @file_get_contents(dirname(rtrim(ABSPATH, '/')) . '/.owner') !== RRZE_CLI_TEST_RUN) {
    WP_CLI::error('Fixture refused: this is not a test-owned WordPress copy.');
}

function rrze_test_user(string $login, string $email): int
{
    $id = wp_insert_user(['user_login' => $login, 'user_email' => $email, 'user_pass' => 'synthetic-test-password', 'role' => '']);
    if (is_wp_error($id)) {
        WP_CLI::error($id->get_error_message());
    }
    return $id;
}

function rrze_test_seed(string $installation): array
{
    global $wpdb;
    switch_theme('rrze-test');
    $site = wp_insert_site(['domain' => $installation . '.test', 'path' => $installation === 'source' ? '/source/' : '/control/', 'network_id' => 1]);
    if (is_wp_error($site)) {
        WP_CLI::error($site->get_error_message());
    }
    if ($installation === 'target') {
        rrze_test_user('control01', 'control01@company.example');
        rrze_test_user('padding01', 'padding01@company.example');
    }
    $shared = rrze_test_user('sso0001', 'sso0001@company.example');
    $author = $installation === 'source' ? rrze_test_user('sso0002', 'sso0002@company.example') : $shared;
    add_user_to_blog($site, $shared, $installation === 'source' ? 'editor' : 'subscriber');
    if ($author !== $shared) {
        add_user_to_blog($site, $author, 'author');
    }
    update_user_meta($shared, 'saml_sp_idp', 'fixture-idp');
    update_user_meta($shared, 'first_name', $installation === 'source' ? 'Source profile' : 'Protected target profile');
    update_user_meta($shared, '_application_passwords', [['password' => 'synthetic-application-secret']]);
    update_user_meta($shared, 'session_tokens', ['synthetic-session' => ['token' => 'synthetic-session-secret']]);
    $wpdb->update($wpdb->users, ['user_activation_key' => 'synthetic-reset-secret'], ['ID' => $shared]);
    switch_to_blog($site);
    switch_theme('rrze-test');
    $parent = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Über uns', 'post_name' => 'about', 'post_author' => $shared]);
    $post = wp_insert_post([
        'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Migration fixture', 'post_name' => 'migration-fixture',
        'post_author' => $author, 'post_content' => 'Grüße! <a href="' . home_url('/about/') . '">Über uns</a>',
    ]);
    $child = wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Child', 'post_parent' => $parent, 'post_author' => $shared]);
    wp_set_object_terms($post, ['Test category'], 'category');
    update_post_meta($post, '_fixture_user', $shared);
    update_post_meta($post, 'fixture_serialized', ['link' => home_url('/about/'), 'nested' => ['text' => 'Grüße aus Köln', 'number' => 42]]);
    update_option('fixture_serialized', ['url' => home_url('/about/'), 'values' => [1, 'two', false]]);
    $upload = wp_upload_bits('fixture.png', null, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZuoAAAAASUVORK5CYII='));
    if ($upload['error']) {
        WP_CLI::error($upload['error']);
    }
    $attachment = wp_insert_attachment(['post_title' => 'Fixture image', 'post_mime_type' => 'image/png', 'post_author' => $shared, 'guid' => $upload['url']], $upload['file'], $post);
    set_post_thumbnail($post, $attachment);
    $table = $wpdb->prefix . 'rrze_fixture';
    $wpdb->query("CREATE TABLE `$table` (id bigint unsigned NOT NULL PRIMARY KEY, payload longtext NOT NULL)");
    $wpdb->insert($table, ['id' => 1, 'payload' => 'Custom table Grüße']);
    $fixture = ['site' => (int) $site, 'shared' => $shared, 'author' => $author, 'post' => $post, 'parent' => $parent, 'child' => $child, 'attachment' => $attachment];
    update_option('rrze_test_fixture', $fixture);
    restore_current_blog();
    return $fixture;
}

function rrze_test_snapshot(int $site): array
{
    global $wpdb;
    switch_to_blog($site);
    $tables = $wpdb->tables('blog');
    $tables[] = $wpdb->prefix . 'rrze_fixture';
    $rows = [];
    foreach ($tables as $table) {
        $data = $wpdb->get_results("SELECT * FROM `$table`", ARRAY_A);
        usort($data, fn ($a, $b) => strcmp(wp_json_encode($a), wp_json_encode($b)));
        $rows[$table] = hash('sha256', wp_json_encode($data));
    }
    // Report changed option names on failures without exposing their values.
    $options = [];
    foreach ($wpdb->get_results("SELECT * FROM {$wpdb->options}", ARRAY_A) as $row) {
        $options[$row['option_name']] = hash('sha256', wp_json_encode($row));
    }
    ksort($options);
    $files = [];
    $uploads = wp_upload_dir();
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads['basedir'], FilesystemIterator::SKIP_DOTS)) as $entry) {
        if ($entry->isFile()) {
            $files[substr($entry->getPathname(), strlen($uploads['basedir']))] = hash_file('sha256', $entry->getPathname());
        }
    }
    ksort($files);
    restore_current_blog();
    return ['tables' => $rows, 'options' => $options, 'files' => $files, 'site' => (array) get_site($site)];
}

function rrze_test_users(): array
{
    global $wpdb;
    $users = [];
    foreach ($wpdb->get_results("SELECT * FROM {$wpdb->users} ORDER BY ID", ARRAY_A) as $row) {
        // Hash all fields including passwords: compare them without exposing them in reports.
        $meta = get_user_meta((int) $row['ID']);
        ksort($meta);
        $users[$row['ID']] = ['row_hash' => hash('sha256', wp_json_encode($row)), 'meta' => $meta];
    }
    return $users;
}

function rrze_test_content(int $site): array
{
    global $wpdb;
    switch_to_blog($site);
    $fixture = get_option('rrze_test_fixture');
    $post = get_post($fixture['post']);
    $attachment = (int) get_post_thumbnail_id($post);
    $file = get_attached_file($attachment);
    $category = wp_get_object_terms($post->ID, 'category', ['fields' => 'names']);
    $result = [
        'site' => $site, 'post_id' => (int) $post->ID, 'title' => $post->post_title,
        'content' => $post->post_content, 'author' => (int) $post->post_author,
        'shared' => (int) get_post_meta($post->ID, '_fixture_user', true),
        'parent' => (int) get_post($fixture['child'])->post_parent,
        'expected_parent' => $fixture['parent'], 'categories' => $category,
        'meta' => get_post_meta($post->ID, 'fixture_serialized', true), 'option' => get_option('fixture_serialized'),
        'attachment' => $attachment, 'attachment_parent' => (int) get_post($attachment)->post_parent,
        'media_hash' => is_file($file) ? hash_file('sha256', $file) : null,
        'custom' => $wpdb->get_var("SELECT payload FROM {$wpdb->prefix}rrze_fixture WHERE id = 1"),
    ];
    restore_current_blog();
    return $result;
}

$action = $args[0] ?? '';
$result = match ($action) {
    'state' => (function () {
        global $wpdb;
        $result = [];
        foreach ($wpdb->get_col('SHOW TABLES') as $table) {
            $rows = $wpdb->get_results("SELECT * FROM `$table`", ARRAY_A);
            usort($rows, fn ($a, $b) => strcmp(wp_json_encode($a), wp_json_encode($b)));
            $result[$table] = hash('sha256', wp_json_encode($rows));
            if (str_ends_with($table, '_options')) {
                foreach ($rows as $row) {
                    if (isset($row['option_name'])) {
                        $result[$table . ':' . $row['option_name']] = hash('sha256', wp_json_encode($row));
                    }
                }
            }
        }
        return $result;
    })(),
    'delete-migration-site' => (function () use ($args) {
        $id = (int) $args[1];
        if ($id <= 2 || !get_site_meta($id, 'rrze_migration_run', true)) {
            throw new RuntimeException('Refusing to delete a non-migration fixture site.');
        }
        $deleted = wp_delete_site($id);
        if (is_wp_error($deleted)) {
            throw new RuntimeException('Fixture site deletion failed.');
        }
        return ['deleted' => !get_site($id)];
    })(),
    'lookup-user' => (function () use ($args) {
        $user = get_user_by('login', $args[1]);
        return $user ? ['id' => $user->ID, 'row_hash' => hash('sha256', wp_json_encode($user->data))] : [];
    })(),
    'leftover' => (function () use ($args) {
        global $wpdb;
        $id = (int) $wpdb->get_var($wpdb->prepare('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s', DB_NAME, $wpdb->blogs));
        $prefix = $wpdb->get_blog_prefix($id);
        $kind = $args[1];
        $create = $args[2] === 'create';
        if ($kind === 'table') {
            if ($create) {
                $wpdb->query("CREATE TABLE `{$prefix}options` (marker varchar(40))");
                $wpdb->query("INSERT INTO `{$prefix}options` VALUES ('protected orphan')");
            } else {
                $wpdb->query("DROP TABLE `{$prefix}options`");
            }
        } elseif ($kind === 'files') {
            $path = WP_CONTENT_DIR . '/uploads/sites/' . $id;
            if ($create) {
                mkdir($path, 0755, true);
                file_put_contents($path . '/protected.txt', 'protected orphan');
            } else {
                unlink($path . '/protected.txt');
                rmdir($path);
            }
        } elseif ($kind === 'membership') {
            if ($create) {
                update_user_meta(4, $prefix . 'capabilities', ['subscriber' => true]);
            } else {
                delete_user_meta(4, $prefix . 'capabilities');
            }
        } else {
            throw new RuntimeException('Unknown leftover fixture.');
        }
        return ['site_id' => $id];
    })(),
    'status' => (function () use ($args) {
        $field = $args[1];
        if (!in_array($field, ['archived', 'deleted', 'spam', 'public', 'mature'], true)) {
            throw new RuntimeException('Unsupported test status.');
        }
        $before = get_site(2)->$field;
        update_blog_status(2, $field, (int) $args[2]);
        return ['before' => $before];
    })(),
    'credentials' => (function () use ($args) {
        $user = get_user_by('login', $args[1]);
        return [
            'login' => $user->user_login, 'email' => $user->user_email,
            'legacy_password_works' => wp_check_password('legacy-known-password', $user->user_pass, $user->ID),
            'reset_key_empty' => $user->user_activation_key === '',
            'application_passwords_empty' => !get_user_meta($user->ID, '_application_passwords', true),
            'sessions_empty' => !get_user_meta($user->ID, 'session_tokens', true),
            'superadmin' => is_super_admin($user->ID),
        ];
    })(),
    'seed' => rrze_test_seed($args[1]),
    'snapshot' => rrze_test_snapshot((int) $args[1]),
    'users' => rrze_test_users(),
    'content' => rrze_test_content((int) $args[1]),
    'sites' => array_map(fn ($site) => ['id' => (int) $site->blog_id, 'domain' => $site->domain, 'path' => $site->path], get_sites(['number' => 0])),
    'mutate-control' => (function () {
        switch_to_blog(2);
        update_option('fixture_tampered', 'A deliberately changed control site.');
        restore_current_blog();
        return ['changed' => true];
    })(),
    'restore-control' => (function () {
        switch_to_blog(2);
        delete_option('fixture_tampered');
        restore_current_blog();
        return ['restored' => true];
    })(),
    default => throw new RuntimeException('Unknown fixture action.'),
};
WP_CLI::line('RRZE_TEST_JSON=' . wp_json_encode($result));
