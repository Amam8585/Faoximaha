<?php

declare(strict_types=1);

foreach (['ProvisioningException.php', 'Contracts.php', 'CommandRunner.php', 'AtomicFilesystem.php', 'ConfigEditor.php', 'Provisioner.php'] as $file) {
    require dirname(__DIR__) . '/provisioning/' . $file;
}

use Faoxima\Provisioning\CommandRunner;
use Faoxima\Provisioning\DatabaseAdmin;
use Faoxima\Provisioning\Provisioner;
use Faoxima\Provisioning\ProvisioningException;
use Faoxima\Provisioning\TelegramGateway;

final class FakeDatabase implements DatabaseAdmin
{
    /** @var array<string,array{name:string,user:string,password:string}> */ public array $bots = [];
    public bool $failCreate = false; public bool $failDrop = false; public int $restores = 0;
    public function provision(string $botName, ?array $ownedCredentials = null): array {
        if ($this->failCreate) throw new ProvisioningException('injected DB create failure');
        if (isset($this->bots[$botName])) {
            if ($ownedCredentials === null || array_intersect_key($this->bots[$botName], $ownedCredentials) !== $this->bots[$botName]) throw new ProvisioningException('SAFE CONFLICT');
            return $this->bots[$botName] + ['status' => 'OWNED_EXISTING'];
        }
        return ($this->bots[$botName] = ['name' => 'muteshop_' . str_replace('bot_', 'bot_00000', $botName), 'user' => 'mbot_' . str_pad(substr($botName, 4), 6, '0', STR_PAD_LEFT), 'password' => 'test-password']) + ['status' => 'FRESH'];
    }
    public function exists(string $botName): bool { return isset($this->bots[$botName]); }
    public function dump(array $credentials, string $destination): void { file_put_contents($destination, 'mock sql'); chmod($destination, 0600); }
    public function restore(string $botName, string $source, string $password): void { $this->restores++; }
    public function drop(string $botName, string $password): void { if ($this->failDrop) throw new ProvisioningException('injected DB drop failure'); unset($this->bots[$botName]); }
}

final class FakeTelegram implements TelegramGateway
{
    /** @var array<string,string> */ public array $hooks = []; public bool $failSet = false; public int $getMeCalls = 0;
    public function getMe(string $token): array { $this->getMeCalls++; return ['id' => (int) strtok($token, ':'), 'username' => 'mock_bot']; }
    public function setWebhook(string $token, string $url, string $secret): void { if ($this->failSet) { $this->failSet = false; throw new ProvisioningException('injected webhook failure'); } $this->hooks[$token] = $url; }
    public function deleteWebhook(string $token): void { unset($this->hooks[$token]); }
    public function getWebhookInfo(string $token): array { return ['url' => $this->hooks[$token] ?? '', 'pending_update_count' => 0, 'last_error_message' => '']; }
}

final class FakeRunner extends CommandRunner
{
    public bool $failMigration = false; public int $migrationCalls = 0; /** @var list<list<string>> */ public array $commands = [];
    public function run(array $arguments, ?string $cwd = null, int $timeoutSeconds = 120): string
    {
        $this->commands[] = $arguments;
        if (in_array('table.php', array_map('basename', $arguments), true)) { $this->migrationCalls++; if ($this->failMigration) throw new ProvisioningException('injected migration failure'); }
        if (($arguments[0] ?? '') === '/usr/bin/curl') return '403';
        if (($arguments[0] ?? '') === '/usr/bin/id') throw new ProvisioningException('id: no such user');
        return '';
    }
}

function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function expectFailure(callable $operation, string $message): void { try { $operation(); } catch (Throwable) { return; } throw new RuntimeException($message); }

$tmp = sys_get_temp_dir() . '/faoxima-provisioning-test-' . bin2hex(random_bytes(5));
$source = $tmp . '/source'; $web = $tmp . '/www'; $state = $tmp . '/state'; $locks = $tmp . '/locks';
foreach ([$source . '/lib', $source . '/logs', $source . '/storage', $source . '/cron', $source . '/cronbot/.runtime', $web, $state, $locks] as $directory) mkdir($directory, 0750, true);
file_put_contents($source . '/index.php', '<?php http_response_code(403);');
file_put_contents($source . '/table.php', '<?php'); file_put_contents($source . '/lib/WebhookAuth.php', '<?php'); file_put_contents($source . '/cron/cron.php', '<?php');
file_put_contents($source . '/config.php', <<<'PHP'
<?php
$dbname = '';
$usernamedb = '';
$passworddb = '';
$dbhost = '';
$APIKEY = '';
$adminnumber = '';
$domainhosts = '';
$usernamebot = '';
PHP);

$db = new FakeDatabase(); $telegram = new FakeTelegram(); $runner = new FakeRunner();
$make = fn (): Provisioner => new Provisioner($db, $telegram, $runner, ['web_root' => $web, 'source_root' => $source,
    'state_root' => $state, 'lock_root' => $locks, 'domain' => 'kanamir.faoximabot.xyz', 'php_binary' => PHP_BINARY,
    'url_prefix' => '/faoxima', 'runtime_user' => 'www-data', 'enforce_ownership' => false]);
$token1 = '1000001:' . str_repeat('A', 35); $token2 = '1000002:' . str_repeat('B', 35);
$p = $make();

// The real command runner captures failures and enforces wall-clock timeouts.
$realRunner = new CommandRunner();
check(trim($realRunner->run([PHP_BINARY, '-r', 'echo "ok";'])) === 'ok', 'real command capture failed');
expectFailure(fn () => $realRunner->run([PHP_BINARY, '-r', 'sleep(3);'], null, 1), 'command timeout was not enforced');

// Fresh bot: independent source/config/database/user/tables (migration command), getMe, direct verified webhook.
$freshDatabase = $p->installBot('bot_3', '1.0.0', $token1, '123456');
check($freshDatabase['status'] === 'FRESH', 'fresh database was not classified as FRESH');
check(is_dir($web . '/bot_3') && !is_link($web . '/bot_3'), 'bot path is not a real directory');
check(!file_exists($web . '/.instances'), '.instances architecture still exists');
check(is_file($web . '/bot_3/index.php') && is_file($web . '/bot_3/config.php'), 'independent source is incomplete');
$config = file_get_contents($web . '/bot_3/config.php');
check(str_contains((string) $config, "\$dbname = 'muteshop_bot_000003'"), 'database config is incorrect');
check((fileperms($web . '/bot_3/config.php') & 0777) === 0640, 'config.php does not have shared-FPM-safe mode 0640');
check(isset($db->bots['bot_3']) && ($telegram->hooks[$token1] ?? '') === 'https://kanamir.faoximabot.xyz/faoxima/bot_3/index.php', 'DB or direct webhook missing');
check($runner->migrationCalls > 0 && $telegram->getMeCalls > 0 && $telegram->getWebhookInfo($token1)['url'] !== '', 'initialization/getMe/getWebhookInfo was not exercised');
$installedState = json_decode((string) file_get_contents($state . '/bot_3.json'), true, 32, JSON_THROW_ON_ERROR);
check(array_key_exists('webhook_pending', $installedState) && array_key_exists('webhook_last_error', $installedState), 'webhook diagnostics were not persisted');
check(array_filter($runner->commands, static fn (array $command): bool => in_array($command[0] ?? '', ['/usr/bin/sudo', '/usr/sbin/useradd', '/usr/sbin/userdel', '/usr/bin/systemctl'], true)) === [], 'daily provisioning invoked a root/per-bot identity operation');

foreach ([['paused', false], ['active', true], ['suspended', false], ['active', true], ['expired', false], ['active', true]] as [$target, $hooked]) {
    $p->transition('bot_3', $target); check(isset($telegram->hooks[$token1]) === $hooked, "transition {$target} has wrong webhook lifecycle");
}
$p->rotateToken('bot_3', $token2); check(!isset($telegram->hooks[$token1]) && isset($telegram->hooks[$token2]), 'token rotation did not move webhook');
$p->transition('bot_3', 'paused'); $p->rotateToken('bot_3', $token1);
check(!isset($telegram->hooks[$token1]) && !isset($telegram->hooks[$token2]), 'paused token rotation incorrectly enabled a webhook');
$p->transition('bot_3', 'active'); $p->rotateToken('bot_3', $token2);
mkdir($web . '/bot_3/storage/private', 0750, true); file_put_contents($web . '/bot_3/storage/private/api-token', 'persistent-secret');
file_put_contents($web . '/bot_3/cronbot/info', 'persistent-queue'); file_put_contents($web . '/bot_3/optimization_config.php', '<?php // runtime settings');
file_put_contents($source . '/release-marker.txt', '1.1.0'); $p->updateBot('bot_3', '1.1.0'); check(is_dir($web . '/bot_3') && !is_link($web . '/bot_3') && file_get_contents($web . '/bot_3/release-marker.txt') === '1.1.0', 'update did not replace the real directory');
check(file_get_contents($web . '/bot_3/storage/private/api-token') === 'persistent-secret'
    && file_get_contents($web . '/bot_3/cronbot/info') === 'persistent-queue'
    && str_contains((string) file_get_contents($web . '/bot_3/optimization_config.php'), 'runtime settings'), 'verified persistent paths were not preserved');

// Failed update restores public source and database.
$beforeFailure = file_get_contents($web . '/bot_3/release-marker.txt'); file_put_contents($source . '/release-marker.txt', 'broken'); $runner->failMigration = true;
expectFailure(fn () => $p->updateBot('bot_3', '1.2.0'), 'migration failure unexpectedly succeeded');
check(file_get_contents($web . '/bot_3/release-marker.txt') === $beforeFailure && $db->restores > 0, 'failed update did not restore source/database'); $runner->failMigration = false;

// Failed token rotation restores config and old webhook.
$oldConfig = file_get_contents($web . '/bot_3/config.php'); $telegram->failSet = true;
expectFailure(fn () => $p->rotateToken('bot_3', $token1), 'webhook failure unexpectedly succeeded');
check(file_get_contents($web . '/bot_3/config.php') === $oldConfig && isset($telegram->hooks[$token2]), 'failed token rotation did not roll back'); $telegram->failSet = false;

// Failed delete restores link/state/webhook; successful delete removes all tenant resources.
$db->failDrop = true; expectFailure(fn () => $p->deleteBot('bot_3'), 'DB delete failure unexpectedly succeeded');
check(is_dir($web . '/bot_3') && !is_link($web . '/bot_3') && isset($telegram->hooks[$token2]) && isset($db->bots['bot_3']), 'failed delete did not roll back');
$db->failDrop = false; $p->deleteBot('bot_3'); check(!file_exists($web . '/bot_3') && !isset($db->bots['bot_3']) && !isset($telegram->hooks[$token2]), 'delete left tenant resources');

// A post-DROP cleanup failure is explicitly resumable without requiring the deleted bot directory or DB.
$deleteQuarantine = $web . '/.bot_10.delete_deadbeef'; mkdir($deleteQuarantine);
$deleteBackup = $state . '/bot_10-delete-test.sql'; file_put_contents($deleteBackup, 'backup');
file_put_contents($state . '/bot_10.json', json_encode(['state'=>'delete_failed','database_deleted'=>true,'quarantine'=>$deleteQuarantine,'backup'=>$deleteBackup], JSON_THROW_ON_ERROR));
$p->deleteBot('bot_10');
check(!file_exists($deleteQuarantine) && !file_exists($deleteBackup) && !file_exists($state . '/bot_10.json'), 'failed delete cleanup was not resumable');

// Invalid token, DB creation, migration, webhook, copy and retry behavior.
expectFailure(fn () => $p->installBot('bot_4', '1.0.0', 'bad-token', '123456'), 'invalid token accepted');
$ownedBot4 = null;
$installBot4 = function () use ($p, $token1, &$ownedBot4): array {
    return $p->installBot('bot_4', '1.0.0', $token1, '123456', $ownedBot4, static function (array $credentials) use (&$ownedBot4): void {
        $ownedBot4 = array_intersect_key($credentials, array_flip(['name', 'user', 'password']));
    });
};
$db->failCreate = true; expectFailure(fn () => $p->installBot('bot_4', '1.0.0', $token1, '123456'), 'DB failure accepted'); $db->failCreate = false;
$runner->failMigration = true; expectFailure($installBot4, 'migration failure accepted'); $runner->failMigration = false;
check($ownedBot4 !== null && isset($db->bots['bot_4']), 'failed install did not retain owned DB credentials');
$telegram->failSet = true; expectFailure($installBot4, 'webhook failure accepted'); $telegram->failSet = false;
$ownedResult = $installBot4(); check($ownedResult['status'] === 'OWNED_EXISTING', 'owned database retry was not classified as OWNED_EXISTING');
check(is_dir($web . '/bot_4') && !is_link($web . '/bot_4'), 'retry with owned DB did not create a real directory');

$db->bots['bot_9'] = ['name'=>'muteshop_bot_000009','user'=>'mbot_000009','password'=>'unknown'];
expectFailure(fn () => $p->installBot('bot_9', '1.0.0', $token2, '123456'), 'unknown existing database was adopted');
check(!file_exists($web . '/bot_9') && isset($db->bots['bot_9']), 'unknown conflict was modified');

// The real central scheduler runs Faoxima's existing cron/cron.php directly, without sudo/root delegation.
$cronMarker = $tmp . '/cron-ran'; $cronMarker2 = $tmp . '/cron-ran-2';
file_put_contents($web . '/bot_4/cron/cron.php', '<?php sleep(1); file_put_contents(' . var_export($cronMarker, true) . ", 'yes');");
file_put_contents($state . '/bot_4.json', json_encode(['state' => 'active'], JSON_THROW_ON_ERROR)); chmod($state . '/bot_4.json', 0640);
$bot11 = $web . '/bot_11'; mkdir($bot11 . '/cron', 0750, true);
file_put_contents($bot11 . '/cron/cron.php', '<?php sleep(1); file_put_contents(' . var_export($cronMarker2, true) . ", 'yes');");
file_put_contents($state . '/bot_11.json', json_encode(['state' => 'active'], JSON_THROW_ON_ERROR)); chmod($state . '/bot_11.json', 0640);
$schedulerStarted = microtime(true);
$realRunner->run(['/usr/bin/env', 'FAOXIMA_STATE_ROOT=' . $state, 'FAOXIMA_WEB_ROOT=' . $web, 'FAOXIMA_LOCK_ROOT=' . $locks,
    'FAOXIMA_PHP_BINARY=' . PHP_BINARY, 'FAOXIMA_SCHEDULER_CONCURRENCY=2', PHP_BINARY, dirname(__DIR__) . '/provisioning/scheduler.php']);
check(file_get_contents($cronMarker) === 'yes' && file_get_contents($cronMarker2) === 'yes', 'central scheduler did not execute real cron entrypoints');
check(microtime(true) - $schedulerStarted < 1.8, 'scheduler did not run independent bots concurrently');

// Concurrent operation is rejected by the same per-bot flock used by scheduler/provisioner.
$held = fopen($locks . '/bot_5.lock', 'c'); flock($held, LOCK_EX | LOCK_NB);
expectFailure(fn () => $p->installBot('bot_5', '1.0.0', $token2, '123456'), 'simultaneous install was not rejected'); flock($held, LOCK_UN); fclose($held);

// A template symlink is rejected and leaves no partial DB or public directory.
symlink('/etc/passwd', $source . '/unsafe-link');
expectFailure(fn () => $p->installBot('bot_6', '1.0.0', $token2, '123456'), 'template symlink was copied');
check(!isset($db->bots['bot_6']) && !file_exists($web . '/bot_6'), 'copy failure left partial resources');
unlink($source . '/unsafe-link');

// A hard-linked template file is rejected to close source-swap/cross-tree copy attacks.
link($source . '/index.php', $source . '/unsafe-hardlink');
expectFailure(fn () => $p->installBot('bot_8', '1.0.0', $token2, '123456'), 'hard-linked template file was copied');
check(!isset($db->bots['bot_8']) && !file_exists($web . '/bot_8'), 'hard-link copy failure left partial resources');
unlink($source . '/unsafe-hardlink');

$nginx = (string) file_get_contents(dirname(__DIR__) . '/ops/nginx/faoxima-bots.conf');
check(str_contains($nginx, 'fastcgi_pass unix:/run/php/php8.3-fpm.sock;'), 'nginx does not use the shared FPM socket');
check(!str_contains($nginx, 'php8.3-fpm-$faoxima_bot'), 'nginx still selects per-bot sockets');
check(str_contains($nginx, '^/faoxima/(bot_'), 'nginx route is not under /faoxima/bot_N');

$mainBot = (string) file_get_contents(dirname(__DIR__) . '/bot.php');
foreach (['->installBot(', '->updateBot(', '->transition(', '->rotateToken(', '->deleteBot('] as $call) {
    check(str_contains($mainBot, $call), "main bot lifecycle is not connected to {$call}");
}
check(!str_contains($mainBot, "'?child='.\$sid"), 'new customer webhook still targets childDispatch');
$repository = dirname(__DIR__);
$legacyInjection = shell_exec('rg -n ' . escapeshellarg('MUTESHOP_FAOXIMA_RUNTIME|MUTESHOP_FAOXIMA_UPDATE_B64|muteshop-runtime') . ' ' . escapeshellarg($repository) . ' --glob ' . escapeshellarg('*.php') . ' --glob ' . escapeshellarg('!tests/**') . ' --glob ' . escapeshellarg('!vendor/**'));
check(trim((string) $legacyInjection) === '', 'legacy runtime/update injection remains in the template');
check(str_contains((string) file_get_contents($repository . '/botapi.php'), "file_get_contents('php://input')"), 'botapi.php no longer consumes the real HTTP update body');

echo "provisioning lifecycle simulation passed\n";
