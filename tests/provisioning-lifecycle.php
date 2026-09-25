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
    public function create(string $botName): array { if ($this->failCreate) throw new ProvisioningException('injected DB create failure'); return $this->bots[$botName] = ['name' => 'fx_' . $botName, 'user' => 'fx_' . $botName, 'password' => 'test-password']; }
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
    public function getWebhookInfo(string $token): array { return ['url' => $this->hooks[$token] ?? '', 'pending_update_count' => 0]; }
}

final class FakeRunner extends CommandRunner
{
    public bool $failMigration = false; public int $migrationCalls = 0;
    public function run(array $arguments, ?string $cwd = null, int $timeoutSeconds = 120): string
    {
        if (in_array('table.php', array_map('basename', $arguments), true)) { $this->migrationCalls++; if ($this->failMigration) throw new ProvisioningException('injected migration failure'); }
        if (($arguments[0] ?? '') === '/usr/bin/curl') return '403';
        if (($arguments[0] ?? '') === '/usr/bin/id') throw new ProvisioningException('id: no such user');
        return '';
    }
}

function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function expectFailure(callable $operation, string $message): void { try { $operation(); } catch (Throwable) { return; } throw new RuntimeException($message); }

$tmp = sys_get_temp_dir() . '/faoxima-provisioning-test-' . bin2hex(random_bytes(5));
$source = $tmp . '/source'; $web = $tmp . '/www'; $state = $tmp . '/state'; $locks = $tmp . '/locks'; $pools = $tmp . '/pools';
foreach ([$source . '/lib', $source . '/logs', $source . '/storage', $source . '/cron', $source . '/cronbot/.runtime', $web, $state, $locks, $pools] as $directory) mkdir($directory, 0750, true);
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
    'fpm_pool_root' => $pools, 'fpm_service' => 'php8.3-fpm.service', 'enforce_ownership' => false]);
$token1 = '1000001:' . str_repeat('A', 35); $token2 = '1000002:' . str_repeat('B', 35);
$p = $make();

// The real command runner captures failures and enforces wall-clock timeouts.
$realRunner = new CommandRunner();
check(trim($realRunner->run([PHP_BINARY, '-r', 'echo "ok";'])) === 'ok', 'real command capture failed');
expectFailure(fn () => $realRunner->run([PHP_BINARY, '-r', 'sleep(3);'], null, 1), 'command timeout was not enforced');

// Fresh bot: independent source/config/database/user/tables (migration command), getMe, direct verified webhook.
$p->installBot('bot_3', '1.0.0', $token1, '123456');
check(is_link($web . '/bot_3'), 'public bot path is not an atomic symlink');
check(is_file($web . '/bot_3/index.php') && is_file($web . '/bot_3/config.php'), 'independent source is incomplete');
$config = file_get_contents($web . '/bot_3/config.php');
check(str_contains((string) $config, "\$dbname = 'fx_bot_3'"), 'database config is incorrect');
check((fileperms($web . '/bot_3/config.php') & 0777) === 0600, 'config.php is not owner-readable and private');
check(isset($db->bots['bot_3']) && ($telegram->hooks[$token1] ?? '') === 'https://kanamir.faoximabot.xyz/bot_3/index.php', 'DB or direct webhook missing');
check($runner->migrationCalls > 0 && $telegram->getMeCalls > 0 && $telegram->getWebhookInfo($token1)['url'] !== '', 'initialization/getMe/getWebhookInfo was not exercised');

foreach ([['paused', false], ['active', true], ['suspended', false], ['active', true], ['expired', false], ['active', true]] as [$target, $hooked]) {
    $p->transition('bot_3', $target); check(isset($telegram->hooks[$token1]) === $hooked, "transition {$target} has wrong webhook lifecycle");
}
$p->rotateToken('bot_3', $token2); check(!isset($telegram->hooks[$token1]) && isset($telegram->hooks[$token2]), 'token rotation did not move webhook');
$p->transition('bot_3', 'paused'); $p->rotateToken('bot_3', $token1);
check(!isset($telegram->hooks[$token1]) && !isset($telegram->hooks[$token2]), 'paused token rotation incorrectly enabled a webhook');
$p->transition('bot_3', 'active'); $p->rotateToken('bot_3', $token2);
$oldTarget = realpath($web . '/bot_3'); $p->updateBot('bot_3', '1.1.0'); check(realpath($web . '/bot_3') !== $oldTarget, 'update did not atomically replace source');

// Failed update restores public source and database.
$beforeFailure = realpath($web . '/bot_3'); $runner->failMigration = true;
expectFailure(fn () => $p->updateBot('bot_3', '1.2.0'), 'migration failure unexpectedly succeeded');
check(realpath($web . '/bot_3') === $beforeFailure && $db->restores > 0, 'failed update did not restore source/database'); $runner->failMigration = false;

// Failed token rotation restores config and old webhook.
$oldConfig = file_get_contents($web . '/bot_3/config.php'); $telegram->failSet = true;
expectFailure(fn () => $p->rotateToken('bot_3', $token1), 'webhook failure unexpectedly succeeded');
check(file_get_contents($web . '/bot_3/config.php') === $oldConfig && isset($telegram->hooks[$token2]), 'failed token rotation did not roll back'); $telegram->failSet = false;

// Failed delete restores link/state/webhook; successful delete removes all tenant resources.
$db->failDrop = true; expectFailure(fn () => $p->deleteBot('bot_3'), 'DB delete failure unexpectedly succeeded');
check(is_link($web . '/bot_3') && isset($telegram->hooks[$token2]) && isset($db->bots['bot_3']), 'failed delete did not roll back');
$db->failDrop = false; $p->deleteBot('bot_3'); check(!file_exists($web . '/bot_3') && !isset($db->bots['bot_3']) && !isset($telegram->hooks[$token2]), 'delete left tenant resources');

// Invalid token, DB creation, migration, webhook, copy and retry behavior.
expectFailure(fn () => $p->installBot('bot_4', '1.0.0', 'bad-token', '123456'), 'invalid token accepted');
$db->failCreate = true; expectFailure(fn () => $p->installBot('bot_4', '1.0.0', $token1, '123456'), 'DB failure accepted'); $db->failCreate = false;
$runner->failMigration = true; expectFailure(fn () => $p->installBot('bot_4', '1.0.0', $token1, '123456'), 'migration failure accepted'); $runner->failMigration = false;
$telegram->failSet = true; expectFailure(fn () => $p->installBot('bot_4', '1.0.0', $token1, '123456'), 'webhook failure accepted'); $telegram->failSet = false;
$p->installBot('bot_4', '1.0.0', $token1, '123456'); check(is_link($web . '/bot_4'), 'retry after failed install did not succeed');

// Concurrent operation is rejected by the same per-bot flock used by scheduler/provisioner.
$held = fopen($locks . '/bot_5.lock', 'c'); flock($held, LOCK_EX | LOCK_NB);
expectFailure(fn () => $p->installBot('bot_5', '1.0.0', $token2, '123456'), 'simultaneous install was not rejected'); flock($held, LOCK_UN); fclose($held);

// A template symlink is rejected and leaves no partial DB or public directory.
symlink('/etc/passwd', $source . '/unsafe-link');
expectFailure(fn () => $p->installBot('bot_6', '1.0.0', $token2, '123456'), 'template symlink was copied');
check(!isset($db->bots['bot_6']) && !file_exists($web . '/bot_6'), 'copy failure left partial resources');

echo "provisioning lifecycle simulation passed\n";
