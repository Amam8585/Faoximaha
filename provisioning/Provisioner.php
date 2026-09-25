<?php

declare(strict_types=1);

namespace Faoxima\Provisioning;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

final class Provisioner
{
    private const NAME_PATTERN = '/\Abot_[1-9][0-9]{0,8}\z/D';
    private const TOKEN_PATTERN = '/\A[0-9]{6,15}:[A-Za-z0-9_-]{30,}\z/D';
    private const ACTIVE = ['active'];
    private const STOPPED = ['paused', 'suspended', 'expired'];

    private readonly string $webRoot;
    private readonly string $instancesRoot;
    private readonly string $stateRoot;
    private readonly string $lockRoot;
    private readonly string $sourceRoot;
    private readonly string $domain;
    private readonly string $phpBinary;
    private readonly string $fpmPoolRoot;
    private readonly string $fpmService;
    private readonly bool $enforceOwnership;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly DatabaseAdmin $database,
        private readonly TelegramGateway $telegram,
        private readonly CommandRunner $commands,
        array $config,
    ) {
        $this->webRoot = $this->absolutePath($config['web_root'] ?? '/var/www/faoxima', 'web_root');
        $this->instancesRoot = $this->webRoot . '/.instances';
        $this->stateRoot = $this->absolutePath($config['state_root'] ?? '/var/lib/faoxima-provisioner', 'state_root');
        $this->lockRoot = $this->absolutePath($config['lock_root'] ?? '/run/lock/faoxima-provisioner', 'lock_root');
        $this->sourceRoot = $this->existingDirectory($config['source_root'] ?? dirname(__DIR__), 'source_root');
        $this->domain = $this->domain((string) ($config['domain'] ?? ''));
        $this->phpBinary = $this->absolutePath($config['php_binary'] ?? '/usr/bin/php8.3', 'php_binary');
        $this->fpmPoolRoot = $this->absolutePath($config['fpm_pool_root'] ?? '/etc/php/8.3/fpm/pool.d', 'fpm_pool_root');
        $this->fpmService = $this->account((string) ($config['fpm_service'] ?? 'php8.3-fpm.service'), 'fpm_service', true);
        $this->enforceOwnership = (bool) ($config['enforce_ownership'] ?? true);
        foreach ([$this->webRoot, $this->instancesRoot, $this->stateRoot, $this->lockRoot] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
                throw new ProvisioningException("Cannot create {$directory}");
            }
            if (realpath($directory) !== $directory) { throw new ProvisioningException("Directory must not be a symlink: {$directory}"); }
        }
        chmod($this->stateRoot, 0700); chmod($this->lockRoot, 0700);
        if (!is_file($this->phpBinary) || !is_executable($this->phpBinary)) {
            throw new ProvisioningException("PHP CLI is not executable: {$this->phpBinary}");
        }
    }

    public function preflight(): void
    {
        foreach (['index.php', 'config.php', 'table.php', 'lib/WebhookAuth.php'] as $file) {
            if (!is_file($this->sourceRoot . '/' . $file)) {
                throw new ProvisioningException("Source template is missing {$file}");
            }
        }
        $this->commands->run([$this->phpBinary, '-r', 'if (PHP_VERSION_ID < 80300) { exit(3); }']);
        $this->commands->run(['/usr/sbin/php-fpm8.3', '-tt'], null, 30);
        $this->commands->run(['/usr/sbin/nginx', '-t'], null, 30);
        $this->commands->run(['/usr/bin/setfacl', '--version'], null, 15);
        $this->commands->run(['/usr/bin/systemctl', 'is-active', '--quiet', $this->fpmService], null, 15);
        $this->commands->run(['/usr/bin/systemctl', 'is-active', '--quiet', 'nginx.service'], null, 15);
    }

    public function installBot(string $name, string $version, string $token, string $adminId): void
    {
        $name = $this->name($name); $version = $this->version($version); $token = $this->token($token); $adminId = $this->adminId($adminId);
        $this->withLock($name, function () use ($name, $version, $token, $adminId): void {
            $this->ensureSharedRootPermissions();
            if (is_link($this->publicPath($name)) || file_exists($this->publicPath($name)) || is_file($this->poolFile($name)) || (glob($this->instancesRoot . '/' . $name . '-*', GLOB_ONLYDIR) ?: []) !== [] || $this->database->exists($name)) {
                throw new ProvisioningException("{$name} already exists or has residual resources; refusing destructive retry.");
            }
            $identity = $this->telegram->getMe($token);
            $staging = $this->instancesRoot . '/.stage-' . $name . '-' . bin2hex(random_bytes(8));
            $database = null;
            $published = false;
            $identityCreated = false;
            try {
                $this->createRuntimeIdentity($name);
                $identityCreated = true;
                $this->copyCleanSource($staging);
                $database = $this->database->create($name);
                $this->configure($staging, $name, $database, $token, $adminId, $identity['username']);
                $this->applyOwnership($staging, $name);
                $this->initialize($staging);
                $this->assertInitialized($staging, $database);
                $this->applyOwnership($staging, $name);
                $instance = $this->instancesRoot . '/' . $name . '-' . bin2hex(random_bytes(6));
                if (!rename($staging, $instance)) { throw new ProvisioningException('Cannot publish prepared instance.'); }
                $this->atomicPublicLink($name, $instance);
                $published = true;
                $this->verifyLocalPhp($name);
                $this->telegram->setWebhook($token, $this->webhookUrl($name), $this->webhookSecret($token));
                $this->writeState($name, ['state' => 'active', 'version' => $version, 'instance' => basename($instance), 'telegram_id' => $identity['id'], 'username' => $identity['username']]);
            } catch (Throwable $error) {
                if ($published) { @unlink($this->publicPath($name)); }
                try { $this->telegram->deleteWebhook($token); } catch (Throwable) { }
                if (isset($instance) && is_dir($instance)) { AtomicFilesystem::removeTree($instance, $this->instancesRoot); }
                if (is_dir($staging)) { AtomicFilesystem::removeTree($staging, $this->instancesRoot); }
                if (is_array($database)) { $this->database->drop($name, $database['password']); }
                if ($identityCreated) { $this->removeRuntimeIdentity($name, false); }
                @unlink($this->stateFile($name));
                throw new ProvisioningException("Install rolled back: {$error->getMessage()}", 0, $error);
            }
        });
    }

    public function updateBot(string $name, string $version): void
    {
        $name = $this->name($name); $version = $this->version($version);
        $this->withLock($name, function () use ($name, $version): void {
            $current = $this->requireCurrent($name); $state = $this->readState($name); $credentials = ConfigEditor::read($current . '/config.php');
            if (($state['version'] ?? '') === $version) { return; }
            $backup = $this->stateRoot . '/' . $name . '-' . gmdate('YmdHis') . '.sql';
            $stage = $this->instancesRoot . '/.update-' . $name . '-' . bin2hex(random_bytes(8));
            $oldInstance = $current;
            $newInstance = $this->instancesRoot . '/' . $name . '-' . bin2hex(random_bytes(6));
            $switched = false;
            $webhookDisabled = false;
            $this->writeState($name, array_merge($state, ['state' => 'updating']));
            try {
                $this->database->dump(['name' => $credentials['name'], 'user' => $credentials['user'], 'password' => $credentials['password']], $backup);
                $identity = $this->telegram->getMe($credentials['token']);
                if (($state['state'] ?? '') === 'active') { $this->telegram->deleteWebhook($credentials['token']); $webhookDisabled = true; }
                $this->copyCleanSource($stage);
                $this->configure($stage, $name, ['name' => $credentials['name'], 'user' => $credentials['user'], 'password' => $credentials['password']], $credentials['token'], $credentials['admin'], $identity['username']);
                $this->applyOwnership($stage, $name);
                $this->initialize($stage);
                $this->assertInitialized($stage, ['name' => $credentials['name'], 'user' => $credentials['user'], 'password' => $credentials['password']]);
                $this->applyOwnership($stage, $name);
                if (!rename($stage, $newInstance)) { throw new ProvisioningException('Cannot publish prepared update directory.'); }
                $this->atomicPublicLink($name, $newInstance);
                $switched = true;
                $this->verifyLocalPhp($name);
                if ($webhookDisabled) { $this->telegram->setWebhook($credentials['token'], $this->webhookUrl($name), $this->webhookSecret($credentials['token'])); }
                $this->writeState($name, array_merge($state, ['version' => $version, 'instance' => basename($newInstance)]));
                try { AtomicFilesystem::removeTree($oldInstance, $this->instancesRoot); } catch (Throwable) { }
            } catch (Throwable $error) {
                if ($switched) {
                    $this->atomicPublicLink($name, $oldInstance);
                    try { AtomicFilesystem::removeTree($newInstance, $this->instancesRoot); } catch (Throwable) { }
                }
                if (!$switched && is_dir($newInstance)) { try { AtomicFilesystem::removeTree($newInstance, $this->instancesRoot); } catch (Throwable) { } }
                if (is_file($backup)) {
                    $this->database->restore($name, $backup, $credentials['password']);
                }
                if (is_dir($stage)) { AtomicFilesystem::removeTree($stage, $this->instancesRoot); }
                if ($webhookDisabled) { try { $this->telegram->setWebhook($credentials['token'], $this->webhookUrl($name), $this->webhookSecret($credentials['token'])); } catch (Throwable) { } }
                $this->writeState($name, $state);
                throw new ProvisioningException("Update rolled back: {$error->getMessage()}", 0, $error);
            } finally {
                @unlink($backup);
            }
        });
    }

    public function transition(string $name, string $target): void
    {
        $name = $this->name($name);
        if (!in_array($target, array_merge(self::ACTIVE, self::STOPPED), true)) { throw new ProvisioningException("Unknown state {$target}"); }
        $this->withLock($name, function () use ($name, $target): void {
            $current = $this->requireCurrent($name); $state = $this->readState($name); $from = (string) ($state['state'] ?? '');
            $allowed = ['active' => self::STOPPED, 'paused' => ['active', 'suspended'], 'suspended' => ['active'], 'expired' => ['active']];
            if ($from !== $target && !in_array($target, $allowed[$from] ?? [], true)) { throw new ProvisioningException("Invalid transition {$from} -> {$target}"); }
            if ($from === $target) { return; }
            $config = ConfigEditor::read($current . '/config.php');
            try {
                if ($target === 'active') { $this->telegram->setWebhook($config['token'], $this->webhookUrl($name), $this->webhookSecret($config['token'])); }
                else { $this->telegram->deleteWebhook($config['token']); }
                $this->writeState($name, array_merge($state, ['state' => $target]));
            } catch (Throwable $error) {
                try {
                    if ($from === 'active') { $this->telegram->setWebhook($config['token'], $this->webhookUrl($name), $this->webhookSecret($config['token'])); }
                    else { $this->telegram->deleteWebhook($config['token']); }
                } catch (Throwable) { }
                throw new ProvisioningException("State transition rolled back: {$error->getMessage()}", 0, $error);
            }
        });
    }

    public function rotateToken(string $name, string $newToken): void
    {
        $name = $this->name($name); $newToken = $this->token($newToken);
        $this->withLock($name, function () use ($name, $newToken): void {
            $root = $this->requireCurrent($name); $old = ConfigEditor::read($root . '/config.php'); $oldConfig = file_get_contents($root . '/config.php');
            if ($oldConfig === false) { throw new ProvisioningException('Cannot backup config.php.'); }
            $identity = $this->telegram->getMe($newToken);
            $database = ['name' => $old['name'], 'user' => $old['user'], 'password' => $old['password']];
            $state = $this->readState($name);
            $wasActive = ($state['state'] ?? '') === 'active';
            try {
                $this->writeConfig($root, $name, $database, $newToken, $old['admin'], $identity['username']);
                if ($wasActive) { $this->telegram->setWebhook($newToken, $this->webhookUrl($name), $this->webhookSecret($newToken)); }
                else { $this->telegram->deleteWebhook($newToken); }
                $this->telegram->deleteWebhook($old['token']);
                $this->writeState($name, array_merge($state, ['telegram_id' => $identity['id'], 'username' => $identity['username']]));
            } catch (Throwable $error) {
                AtomicFilesystem::write($root . '/config.php', $oldConfig, 0600, $this->enforceOwnership ? $this->osUser($name) : null, $this->enforceOwnership ? $this->osUser($name) : null);
                try { $this->telegram->deleteWebhook($newToken); } catch (Throwable) { }
                try {
                    if ($wasActive) { $this->telegram->setWebhook($old['token'], $this->webhookUrl($name), $this->webhookSecret($old['token'])); }
                    else { $this->telegram->deleteWebhook($old['token']); }
                } catch (Throwable) { }
                throw new ProvisioningException("Token rotation rolled back: {$error->getMessage()}", 0, $error);
            }
        });
    }

    public function deleteBot(string $name): void
    {
        $name = $this->name($name);
        $this->withLock($name, function () use ($name): void {
            $root = $this->requireCurrent($name); $config = ConfigEditor::read($root . '/config.php'); $state = $this->readState($name);
            $backup = $this->stateRoot . '/' . $name . '-delete-' . gmdate('YmdHis') . '.sql';
            $quarantine = $this->instancesRoot . '/.delete-' . $name . '-' . bin2hex(random_bytes(6));
            $this->database->dump(['name' => $config['name'], 'user' => $config['user'], 'password' => $config['password']], $backup);
            $this->telegram->deleteWebhook($config['token']);
            $this->writeState($name, array_merge($state, ['state' => 'deleting']));
            if (!unlink($this->publicPath($name)) || !rename($root, $quarantine)) {
                if (!is_link($this->publicPath($name)) && is_dir($root)) { $this->atomicPublicLink($name, $root); }
                $this->telegram->setWebhook($config['token'], $this->webhookUrl($name), $this->webhookSecret($config['token']));
                $this->writeState($name, $state);
                throw new ProvisioningException('Delete publication failed and was rolled back.');
            }
            try {
                $this->removePool($name);
                $this->database->drop($name, $config['password']);
            } catch (Throwable $error) {
                $this->writePool($name);
                rename($quarantine, $root); $this->atomicPublicLink($name, $root);
                $this->telegram->setWebhook($config['token'], $this->webhookUrl($name), $this->webhookSecret($config['token']));
                $this->writeState($name, $state);
                throw new ProvisioningException("Delete rolled back: {$error->getMessage()}", 0, $error);
            }
            try {
                $this->commands->run(['/usr/sbin/userdel', $this->osUser($name)], null, 30);
                AtomicFilesystem::removeTree($quarantine, $this->instancesRoot);
                @unlink($this->stateFile($name));
                // Retain the root-only backup after destructive deletion.
            } catch (Throwable $cleanupError) {
                $this->writeState($name, ['state' => 'failed', 'operation' => 'delete-cleanup', 'database_deleted' => true,
                    'quarantine' => $quarantine, 'backup' => $backup, 'error' => $cleanupError->getMessage()]);
                throw new ProvisioningException("Database was deleted safely, but local delete cleanup failed: {$cleanupError->getMessage()}", 0, $cleanupError);
            }
        });
    }

    private function copyCleanSource(string $destination): void
    {
        if (!mkdir($destination, 0750, true)) { throw new ProvisioningException("Cannot create staging directory {$destination}"); }
        $exclude = ['.git', '.env', '.instances', 'logs', 'storage/cache', 'provisioning/local.json', 'users.json'];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->sourceRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($this->sourceRoot) + 1);
            if ($item->isLink()) { throw new ProvisioningException("Template contains forbidden symlink: {$relative}"); }
            if (preg_match('~(?:^|/)(?:cron\.lock|\.cron_internal_auth|\.compiled\.(?:php|map))$~', $relative)) { continue; }
            foreach ($exclude as $blocked) { if ($relative === $blocked || str_starts_with($relative, $blocked . '/')) { continue 2; } }
            $target = $destination . '/' . $relative;
            if ($item->isDir()) { if (!is_dir($target) && !mkdir($target, 0750, true)) { throw new ProvisioningException("Cannot copy directory {$relative}"); } }
            elseif ($item->isFile()) { if (!copy($item->getPathname(), $target)) { throw new ProvisioningException("Cannot copy file {$relative}"); } chmod($target, 0640); }
            else { throw new ProvisioningException("Unsupported template entry: {$relative}"); }
        }
        if (is_dir($destination . '/installer')) { AtomicFilesystem::removeTree($destination . '/installer', $destination); }
        foreach (['logs', 'storage', 'cron', 'cronbot', 'cronbot/.runtime'] as $runtime) {
            $path = $destination . '/' . $runtime; if (!is_dir($path) && !mkdir($path, 0750, true)) { throw new ProvisioningException("Cannot create runtime directory {$runtime}"); }
        }
    }

    private function ensureSharedRootPermissions(): void
    {
        $this->commands->run(['/usr/bin/chown', 'root:www-data', $this->instancesRoot], null, 30);
        if (!chmod($this->instancesRoot, 0711)) { throw new ProvisioningException('Cannot secure shared instance directory.'); }
    }

    /** @param array{name:string,user:string,password:string} $database */
    private function configure(string $root, string $name, array $database, string $token, string $adminId, string $username): void
    {
        $this->writeConfig($root, $name, $database, $token, $adminId, $username);
        foreach (['logs', 'storage', 'cron', 'cronbot', 'cronbot/.runtime'] as $directory) { chmod($root . '/' . $directory, 0770); }
    }

    /** @param array{name:string,user:string,password:string} $database */
    private function writeConfig(string $root, string $name, array $database, string $token, string $adminId, string $username): void
    {
        $rendered = ConfigEditor::render($root . '/config.php', $database, $token, $adminId, $this->domain . '/' . $name, $username);
        AtomicFilesystem::write($root . '/config.php', $rendered, 0600, $this->enforceOwnership ? $this->osUser($name) : null, $this->enforceOwnership ? $this->osUser($name) : null);
    }

    private function initialize(string $root): void
    {
        $name = $this->botNameFromDatabase(ConfigEditor::read($root . '/config.php')['name']);
        $this->commands->run(['/usr/bin/sudo', '-n', '-u', $this->osUser($name), '--', $this->phpBinary, $root . '/table.php'], $root, 300);
    }

    /** @param array{name:string,user:string,password:string} $database */
    private function assertInitialized(string $root, array $database): void
    {
        foreach (['users', 'setting'] as $table) {
            $script = '$c=require ' . var_export($root . '/config.php', true) . '; $s=$GLOBALS["pdo"]->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?"); $s->execute([' . var_export($database['name'], true) . ',' . var_export($table, true) . ']); exit($s->fetchColumn()?0:9);';
            $this->commands->run(['/usr/bin/sudo', '-n', '-u', $this->osUser($this->botNameFromDatabase($database['name'])), '--', $this->phpBinary, '-r', $script], $root, 30);
        }
    }

    private function verifyLocalPhp(string $name): void
    {
        $status = trim($this->commands->run(['/usr/bin/curl', '--silent', '--show-error', '--output', '/dev/null', '--write-out', '%{http_code}', '--connect-timeout', '3', '--max-time', '10', '--resolve', $this->domain . ':443:127.0.0.1', 'https://' . $this->domain . '/' . $name . '/index.php'], null, 15));
        if (!in_array($status, ['403', '405'], true)) { throw new ProvisioningException("Unexpected local PHP health status: {$status}"); }
    }

    private function applyOwnership(string $root, string $name): void
    {
        $user = $this->osUser($name);
        $this->commands->run(['/usr/bin/chown', '-R', 'root:' . $user, $root], null, 120);
        $this->commands->run(['/usr/bin/find', $root, '-type', 'd', '-exec', 'chmod', '0750', '{}', '+'], null, 120);
        $this->commands->run(['/usr/bin/find', $root, '-type', 'f', '-exec', 'chmod', '0640', '{}', '+'], null, 120);
        foreach (['logs', 'storage', 'cron', 'cronbot'] as $directory) {
            $this->commands->run(['/usr/bin/chown', '-R', $user . ':' . $user, $root . '/' . $directory], null, 120);
            chmod($root . '/' . $directory, 0750);
        }
        $this->commands->run(['/usr/bin/setfacl', '-R', '-m', 'u:www-data:rX', $root], null, 120);
        foreach (['logs', 'storage', 'cron'] as $privateDirectory) {
            $this->commands->run(['/usr/bin/setfacl', '-R', '-m', 'u:www-data:---', $root . '/' . $privateDirectory], null, 120);
        }
        $this->commands->run(['/usr/bin/chown', $user . ':' . $user, $root . '/config.php'], null, 30);
        $this->commands->run(['/usr/bin/setfacl', '-m', 'u:www-data:---', $root . '/config.php'], null, 30);
        chmod($root . '/config.php', 0600);
    }

    private function createRuntimeIdentity(string $name): void
    {
        $user = $this->osUser($name);
        try { $this->commands->run(['/usr/bin/id', '-u', $user], null, 10); throw new ProvisioningException("OS user {$user} already exists."); }
        catch (ProvisioningException $error) { if (str_contains($error->getMessage(), 'already exists')) throw $error; }
        $this->commands->run(['/usr/sbin/useradd', '--system', '--user-group', '--home-dir', '/nonexistent', '--shell', '/usr/sbin/nologin', $user], null, 30);
        try { $this->writePool($name); } catch (Throwable $error) { try { $this->commands->run(['/usr/sbin/userdel', $user]); } catch (Throwable) { } throw $error; }
    }

    private function writePool(string $name): void
    {
        $user = $this->osUser($name); $pool = $this->poolFile($name);
        $content = "[{$name}]\nuser = {$user}\ngroup = {$user}\nlisten = /run/php/php8.3-fpm-{$name}.sock\nlisten.owner = www-data\nlisten.group = www-data\nlisten.mode = 0660\npm = ondemand\npm.max_children = 4\npm.process_idle_timeout = 20s\npm.max_requests = 500\nchdir = /var/www/faoxima/{$name}\nclear_env = yes\nsecurity.limit_extensions = .php\nphp_admin_value[open_basedir] = /var/www/faoxima:/tmp\nphp_admin_flag[display_errors] = off\nphp_admin_flag[log_errors] = on\nphp_admin_value[error_log] = /var/www/faoxima/{$name}/logs/php-fpm.log\nphp_admin_value[opcache.validate_timestamps] = 1\nphp_admin_value[opcache.revalidate_freq] = 0\n";
        AtomicFilesystem::write($pool, $content, 0644);
        try {
            $this->commands->run(['/usr/sbin/php-fpm8.3', '-tt'], null, 30);
            $this->commands->run(['/usr/bin/systemctl', 'reload', $this->fpmService], null, 30);
        } catch (Throwable $error) { @unlink($pool); throw $error; }
    }

    private function removePool(string $name): void
    {
        $pool = $this->poolFile($name); $backup = $pool . '.delete-' . bin2hex(random_bytes(4));
        if (!is_file($pool) || !rename($pool, $backup)) { throw new ProvisioningException("Cannot quarantine FPM pool for {$name}"); }
        try {
            $this->commands->run(['/usr/sbin/php-fpm8.3', '-tt'], null, 30);
            $this->commands->run(['/usr/bin/systemctl', 'reload', $this->fpmService], null, 30);
            @unlink($backup);
        } catch (Throwable $error) { rename($backup, $pool); throw $error; }
    }

    private function removeRuntimeIdentity(string $name, bool $throw): void
    {
        try { if (is_file($this->poolFile($name))) $this->removePool($name); $this->commands->run(['/usr/sbin/userdel', $this->osUser($name)], null, 30); }
        catch (Throwable $error) { if ($throw) throw $error; }
    }

    private function atomicPublicLink(string $name, string $target): void
    {
        $link = $this->publicPath($name); $temporary = $this->webRoot . '/.' . $name . '.new-' . bin2hex(random_bytes(6));
        $relative = '.instances/' . basename($target);
        if (!symlink($relative, $temporary) || !rename($temporary, $link)) { @unlink($temporary); throw new ProvisioningException("Cannot publish {$name} symlink."); }
    }

    private function requireCurrent(string $name): string
    {
        $link = $this->publicPath($name); $actual = realpath($link); $prefix = realpath($this->instancesRoot) . '/' . $name . '-';
        if (!is_link($link) || $actual === false || !str_starts_with($actual, $prefix) || dirname($actual) !== realpath($this->instancesRoot)) { throw new ProvisioningException("{$name} installation is missing or link is unsafe."); }
        return $actual;
    }

    /** @param array<string,mixed> $state */
    private function writeState(string $name, array $state): void
    {
        $state['updated_at'] = gmdate(DATE_ATOM); AtomicFilesystem::write($this->stateFile($name), json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n", 0600);
    }

    /** @return array<string,mixed> */
    private function readState(string $name): array
    {
        $decoded = json_decode((string) file_get_contents($this->stateFile($name)), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_string($decoded['state'] ?? null)) { throw new ProvisioningException("Invalid state for {$name}"); }
        return $decoded;
    }

    private function withLock(string $name, callable $operation): mixed
    {
        $handle = fopen($this->lockRoot . '/' . $name . '.lock', 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) { if (is_resource($handle)) fclose($handle); throw new ProvisioningException("Concurrent operation rejected for {$name}"); }
        try { return $operation(); } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    private function webhookSecret(string $token): string { return hash('sha256', $token . '_faoxima_webhook_secret'); }
    private function webhookUrl(string $name): string { return 'https://' . $this->domain . '/' . $name . '/index.php'; }
    private function publicPath(string $name): string { return $this->webRoot . '/' . $name; }
    private function stateFile(string $name): string { return $this->stateRoot . '/' . $name . '.json'; }

    private function name(string $name): string { if (!preg_match(self::NAME_PATTERN, $name)) throw new ProvisioningException('Bot name must match bot_[1-9][0-9]{0,8}.'); return $name; }
    private function token(string $token): string { if (!preg_match(self::TOKEN_PATTERN, $token)) throw new ProvisioningException('Telegram token format is invalid.'); return $token; }
    private function adminId(string $id): string { if (!preg_match('/\A-?[1-9][0-9]{4,19}\z/D', $id)) throw new ProvisioningException('Admin ID is invalid.'); return $id; }
    private function version(string $version): string { if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $version)) throw new ProvisioningException('Version is invalid.'); return $version; }
    private function osUser(string $name): string { return 'fx_' . $name; }
    private function poolFile(string $name): string { return $this->fpmPoolRoot . '/faoxima-' . $name . '.conf'; }
    private function botNameFromDatabase(string $database): string { $name = str_starts_with($database, 'fx_') ? substr($database, 3) : ''; return $this->name($name); }
    private function account(string $account, string $key, bool $dot = false): string { $pattern = $dot ? '/\A[a-z0-9_.@-]{1,80}\z/D' : '/\A[a-z_][a-z0-9_-]{0,31}\z/D'; if (!preg_match($pattern, $account)) throw new ProvisioningException("{$key} is invalid."); return $account; }
    private function domain(string $domain): string { $domain = strtolower(rtrim($domain, '.')); if (filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) throw new ProvisioningException('Domain is invalid.'); return $domain; }
    private function absolutePath(mixed $path, string $key): string { $path = rtrim((string) $path, '/'); if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || preg_match('~/(?:\.|\.\.)(?:/|$)~', $path)) throw new ProvisioningException("{$key} must be a normalized absolute path."); return $path; }
    private function existingDirectory(mixed $path, string $key): string { $path = $this->absolutePath($path, $key); $real = realpath($path); if ($real === false || !is_dir($real)) throw new ProvisioningException("{$key} does not exist."); return $real; }
}
