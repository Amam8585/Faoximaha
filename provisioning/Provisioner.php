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
    private const STOPPED = ['paused', 'suspended', 'expired'];
    /** Runtime-written paths verified from Faoxima file operations. */
    private const PERSISTENT_PATHS = [
        'logs', 'storage', 'cronbot/.runtime', 'cronbot/users.json', 'cronbot/users.txt',
        'cronbot/users.txt.new', 'cronbot/info', 'cronbot/gift', 'cronbot/username.json',
        'users.json', 'optimization_config.php', 'error_log', 'resetbot_error.log', 'log.txt',
    ];

    private readonly string $webRoot;
    private readonly string $stateRoot;
    private readonly string $lockRoot;
    private readonly string $sourceRoot;
    private readonly string $domain;
    private readonly string $urlPrefix;
    private readonly string $phpBinary;
    private readonly string $runtimeUser;
    private readonly bool $enforceOwnership;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly DatabaseAdmin $database,
        private readonly TelegramGateway $telegram,
        private readonly CommandRunner $commands,
        array $config,
    ) {
        $this->webRoot = $this->absolutePath($config['web_root'] ?? '/var/www/faoxima', 'web_root');
        $this->stateRoot = $this->absolutePath($config['state_root'] ?? '/var/lib/faoxima-provisioner', 'state_root');
        $this->lockRoot = $this->absolutePath($config['lock_root'] ?? '/run/lock/faoxima-provisioner', 'lock_root');
        $this->sourceRoot = $this->existingDirectory($config['source_root'] ?? '/opt/muteshop/template', 'source_root');
        $this->domain = $this->domain((string) ($config['domain'] ?? ''));
        $this->urlPrefix = $this->urlPrefix((string) ($config['url_prefix'] ?? '/faoxima'));
        $this->phpBinary = $this->absolutePath($config['php_binary'] ?? '/usr/bin/php8.3', 'php_binary');
        $this->runtimeUser = $this->account((string) ($config['runtime_user'] ?? 'www-data'), 'runtime_user');
        $this->enforceOwnership = (bool) ($config['enforce_ownership'] ?? true);
        foreach ([$this->webRoot, $this->stateRoot, $this->lockRoot] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) throw new ProvisioningException("Cannot create {$directory}");
            if (is_link($directory) || realpath($directory) !== $directory) throw new ProvisioningException("Directory must be real and normalized: {$directory}");
        }
        chmod($this->stateRoot, 0750); chmod($this->lockRoot, 0750); chmod($this->webRoot, 0750);
        if (!is_file($this->phpBinary) || !is_executable($this->phpBinary)) throw new ProvisioningException("PHP CLI is not executable: {$this->phpBinary}");
    }

    public function preflight(): void
    {
        foreach (['index.php', 'config.php', 'table.php', 'lib/WebhookAuth.php', 'cron/cron.php'] as $file) {
            if (!is_file($this->sourceRoot . '/' . $file) || is_link($this->sourceRoot . '/' . $file)) throw new ProvisioningException("Source template is missing or unsafe: {$file}");
        }
        $this->assertRuntimeIdentity();
        $this->commands->run([$this->phpBinary, '-r', 'if (PHP_VERSION_ID < 80300) { exit(3); }']);
        $this->commands->run(['/usr/sbin/php-fpm8.3', '-tt'], null, 30);
        $this->commands->run(['/usr/sbin/nginx', '-t'], null, 30);
    }

    /**
     * @param array{name:string,user:string,password:string}|null $ownedDatabase
     * @param null|callable(array{name:string,user:string,password:string,status:string}):void $databaseReady
     * @return array{name:string,user:string,password:string,status:string}
     */
    public function installBot(string $name, string $version, string $token, string $adminId, ?array $ownedDatabase = null, ?callable $databaseReady = null): array
    {
        $name = $this->name($name); $version = $this->version($version); $token = $this->token($token); $adminId = $this->adminId($adminId);
        return $this->withLock($name, function () use ($name, $version, $token, $adminId, $ownedDatabase, $databaseReady): array {
            $root = $this->botPath($name);
            if (file_exists($root) || is_link($root) || $this->temporaryPaths($name) !== []) {
                throw new ProvisioningException("{$name} already exists or has residual resources; refusing destructive retry.");
            }
            $identity = $this->telegram->getMe($token);
            $stage = $this->temporaryPath($name, 'new');
            $database = null;
            $published = false;
            try {
                $this->copyCleanSource($stage);
                $database = $this->database->provision($name, $ownedDatabase);
                if ($databaseReady !== null) $databaseReady($database);
                $this->configure($stage, $name, $database, $token, $adminId, $identity['username']);
                $this->initialize($stage);
                $this->assertInitialized($stage, $database);
                $this->applyPermissions($stage);
                if (!rename($stage, $root)) throw new ProvisioningException('Cannot atomically publish prepared bot directory.');
                $published = true;
                $this->verifyLocalPhp($name);
                $this->telegram->setWebhook($token, $this->webhookUrl($name), $this->webhookSecret($token));
                $this->writeState($name, array_merge(['state' => 'active', 'version' => $version, 'telegram_id' => $identity['id'], 'username' => $identity['username']], $this->webhookDiagnostics($token)));
                return $database;
            } catch (Throwable $error) {
                try { $this->telegram->deleteWebhook($token); } catch (Throwable) { }
                if ($published && is_dir($root) && !is_link($root)) { try { AtomicFilesystem::removeTree($root, $this->webRoot); } catch (Throwable) { } }
                if (is_dir($stage) && !is_link($stage)) { try { AtomicFilesystem::removeTree($stage, $this->webRoot); } catch (Throwable) { } }
                // The database is deliberately retained: the control-plane record owns
                // its sealed credentials and a retry must rerun migrations idempotently.
                @unlink($this->stateFile($name));
                throw new ProvisioningException("Install rolled back: {$error->getMessage()}", 0, $error);
            }
        });
    }

    public function updateBot(string $name, string $version): void
    {
        $name = $this->name($name); $version = $this->version($version);
        $this->withLock($name, function () use ($name, $version): void {
            $root = $this->requireCurrent($name); $state = $this->readState($name);
            if (($state['version'] ?? '') === $version) return;
            $credentials = ConfigEditor::read($root . '/config.php');
            $backupSql = $this->stateRoot . '/' . $name . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.sql';
            $stage = $this->temporaryPath($name, 'new'); $backupDir = $this->temporaryPath($name, 'backup');
            $swapped = false; $webhookDisabled = false;
            $this->writeState($name, array_merge($state, ['state' => 'updating']));
            try {
                $this->database->dump(['name' => $credentials['name'], 'user' => $credentials['user'], 'password' => $credentials['password']], $backupSql);
                $identity = $this->telegram->getMe($credentials['token']);
                if (($state['state'] ?? '') === 'active') { $this->telegram->deleteWebhook($credentials['token']); $webhookDisabled = true; }
                $this->copyCleanSource($stage);
                $this->copyPersistentData($root, $stage);
                $this->configure($stage, $name, ['name' => $credentials['name'], 'user' => $credentials['user'], 'password' => $credentials['password']], $credentials['token'], $credentials['admin'], $identity['username']);
                $this->initialize($stage); $this->assertInitialized($stage, ['name' => $credentials['name'], 'user' => $credentials['user'], 'password' => $credentials['password']]);
                $this->applyPermissions($stage);
                if (!rename($root, $backupDir)) throw new ProvisioningException('Cannot move current bot to update backup.');
                if (!rename($stage, $root)) { rename($backupDir, $root); throw new ProvisioningException('Cannot publish prepared update.'); }
                $swapped = true;
                $this->verifyLocalPhp($name);
                if ($webhookDisabled) $this->telegram->setWebhook($credentials['token'], $this->webhookUrl($name), $this->webhookSecret($credentials['token']));
                $this->writeState($name, array_merge($state, ['version' => $version], $this->webhookDiagnostics($credentials['token'])));
                AtomicFilesystem::removeTree($backupDir, $this->webRoot);
            } catch (Throwable $error) {
                if ($swapped) {
                    $broken = $this->temporaryPath($name, 'failed');
                    if (is_dir($root) && !is_link($root)) rename($root, $broken);
                    if (is_dir($backupDir) && !is_link($backupDir)) rename($backupDir, $root);
                    if (is_dir($broken) && !is_link($broken)) { try { AtomicFilesystem::removeTree($broken, $this->webRoot); } catch (Throwable) { } }
                }
                if (is_file($backupSql)) { try { $this->database->restore($name, $backupSql, $credentials['password']); } catch (Throwable) { } }
                foreach ([$stage, $backupDir] as $path) if (is_dir($path) && !is_link($path)) { try { AtomicFilesystem::removeTree($path, $this->webRoot); } catch (Throwable) { } }
                if ($webhookDisabled) { try { $this->telegram->setWebhook($credentials['token'], $this->webhookUrl($name), $this->webhookSecret($credentials['token'])); } catch (Throwable) { } }
                $this->writeState($name, $state);
                throw new ProvisioningException("Update rolled back: {$error->getMessage()}", 0, $error);
            } finally { @unlink($backupSql); }
        });
    }

    public function transition(string $name, string $target): void
    {
        $name = $this->name($name);
        if (!in_array($target, array_merge(['active'], self::STOPPED), true)) throw new ProvisioningException("Unknown state {$target}");
        $this->withLock($name, function () use ($name, $target): void {
            $root = $this->requireCurrent($name); $state = $this->readState($name); $from = (string) ($state['state'] ?? '');
            $allowed = ['active' => self::STOPPED, 'paused' => ['active', 'suspended', 'expired'], 'suspended' => ['active', 'paused', 'expired'], 'expired' => ['active', 'suspended']];
            if ($from === $target) return;
            if (!in_array($target, $allowed[$from] ?? [], true)) throw new ProvisioningException("Invalid transition {$from} -> {$target}");
            $config = ConfigEditor::read($root . '/config.php');
            try {
                $target === 'active' ? $this->telegram->setWebhook($config['token'], $this->webhookUrl($name), $this->webhookSecret($config['token'])) : $this->telegram->deleteWebhook($config['token']);
                $this->writeState($name, array_merge($state, ['state' => $target], $this->webhookDiagnostics($config['token'])));
            } catch (Throwable $error) {
                try { $from === 'active' ? $this->telegram->setWebhook($config['token'], $this->webhookUrl($name), $this->webhookSecret($config['token'])) : $this->telegram->deleteWebhook($config['token']); } catch (Throwable) { }
                throw new ProvisioningException("State transition rolled back: {$error->getMessage()}", 0, $error);
            }
        });
    }

    public function rotateToken(string $name, string $newToken): void
    {
        $name = $this->name($name); $newToken = $this->token($newToken);
        $this->withLock($name, function () use ($name, $newToken): void {
            $root = $this->requireCurrent($name); $old = ConfigEditor::read($root . '/config.php'); $oldConfig = file_get_contents($root . '/config.php');
            if ($oldConfig === false) throw new ProvisioningException('Cannot backup config.php.');
            $identity = $this->telegram->getMe($newToken); $state = $this->readState($name); $active = ($state['state'] ?? '') === 'active';
            try {
                $this->writeConfig($root, $name, ['name'=>$old['name'],'user'=>$old['user'],'password'=>$old['password']], $newToken, $old['admin'], $identity['username']);
                $active ? $this->telegram->setWebhook($newToken, $this->webhookUrl($name), $this->webhookSecret($newToken)) : $this->telegram->deleteWebhook($newToken);
                $this->telegram->deleteWebhook($old['token']);
                $this->writeState($name, array_merge($state, ['telegram_id'=>$identity['id'],'username'=>$identity['username']], $this->webhookDiagnostics($newToken)));
            } catch (Throwable $error) {
                AtomicFilesystem::write($root . '/config.php', $oldConfig, 0640, $this->enforceOwnership ? $this->runtimeUser : null, $this->enforceOwnership ? $this->runtimeUser : null);
                try { $this->telegram->deleteWebhook($newToken); } catch (Throwable) { }
                try { $active ? $this->telegram->setWebhook($old['token'], $this->webhookUrl($name), $this->webhookSecret($old['token'])) : $this->telegram->deleteWebhook($old['token']); } catch (Throwable) { }
                throw new ProvisioningException("Token rotation rolled back: {$error->getMessage()}", 0, $error);
            }
        });
    }

    public function deleteBot(string $name): void
    {
        $name = $this->name($name);
        $this->withLock($name, function () use ($name): void {
            $existingState = is_file($this->stateFile($name)) && !is_link($this->stateFile($name)) ? $this->readState($name) : [];
            if (($existingState['state'] ?? '') === 'delete_failed' && ($existingState['operation'] ?? '') === 'database-restore') {
                $quarantine = (string) ($existingState['quarantine'] ?? ''); $sql = (string) ($existingState['backup'] ?? '');
                $expectedPrefix = $this->webRoot . '/.' . $name . '.delete_';
                if (!str_starts_with($quarantine, $expectedPrefix) || dirname($quarantine) !== $this->webRoot || is_link($quarantine)
                    || !is_dir($quarantine) || dirname($sql) !== $this->stateRoot || !is_file($sql)) throw new ProvisioningException('Unsafe delete recovery metadata.');
                $config = ConfigEditor::read($quarantine . '/config.php'); $previous = $existingState['previous_state'] ?? [];
                if (!is_array($previous)) throw new ProvisioningException('Delete recovery has no previous state.');
                $this->database->restore($name, $sql, $config['password']);
                if (!rename($quarantine, $this->botPath($name))) throw new ProvisioningException('Cannot restore quarantined bot directory.');
                $this->restoreWebhookAndState($name, $config, $previous); @unlink($sql);
                throw new ProvisioningException('Previous delete was recovered; retry delete to start a new clean attempt.');
            }
            if (($existingState['state'] ?? '') === 'delete_failed' && ($existingState['database_deleted'] ?? false) === true) {
                $quarantine = (string) ($existingState['quarantine'] ?? '');
                $expectedPrefix = $this->webRoot . '/.' . $name . '.delete_';
                if (!str_starts_with($quarantine, $expectedPrefix) || dirname($quarantine) !== $this->webRoot || is_link($quarantine)) {
                    throw new ProvisioningException('Unsafe delete cleanup metadata.');
                }
                if (is_dir($quarantine)) AtomicFilesystem::removeTree($quarantine, $this->webRoot);
                $backup = (string) ($existingState['backup'] ?? '');
                if ($backup !== '' && dirname($backup) === $this->stateRoot) @unlink($backup);
                @unlink($this->stateFile($name));
                return;
            }
            $root = $this->requireCurrent($name); $config = ConfigEditor::read($root . '/config.php'); $state = $this->readState($name);
            $sql = $this->stateRoot . '/' . $name . '-delete-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.sql';
            $quarantine = $this->temporaryPath($name, 'delete');
            $this->database->dump(['name'=>$config['name'],'user'=>$config['user'],'password'=>$config['password']], $sql);
            $this->telegram->deleteWebhook($config['token']);
            $this->writeState($name, array_merge($state, ['state'=>'deleting']));
            if (!rename($root, $quarantine)) { $this->restoreWebhookAndState($name, $config, $state); throw new ProvisioningException('Cannot quarantine bot directory.'); }
            try { $this->database->drop($name, $config['password']); }
            catch (Throwable $error) {
                try { $this->database->restore($name, $sql, $config['password']); }
                catch (Throwable $restoreError) {
                    $this->writeState($name, ['state'=>'delete_failed','operation'=>'database-restore','database_deleted'=>'unknown','backup'=>$sql,'quarantine'=>$quarantine,'previous_state'=>$state,'error'=>$restoreError->getMessage()]);
                    throw new ProvisioningException("Delete stopped and database restore failed: {$restoreError->getMessage()}", 0, $error);
                }
                rename($quarantine, $root); $this->restoreWebhookAndState($name, $config, $state);
                throw new ProvisioningException("Delete rolled back: {$error->getMessage()}", 0, $error);
            }
            try { AtomicFilesystem::removeTree($quarantine, $this->webRoot); @unlink($sql); @unlink($this->stateFile($name)); }
            catch (Throwable $error) { $this->writeState($name, ['state'=>'delete_failed','operation'=>'delete-cleanup','database_deleted'=>true,'backup'=>$sql,'quarantine'=>$quarantine,'error'=>$error->getMessage()]); throw new ProvisioningException("Database deleted; cleanup can be retried safely: {$error->getMessage()}", 0, $error); }
        });
    }

    private function restoreWebhookAndState(string $name, array $config, array $state): void
    { if (($state['state'] ?? '') === 'active') $this->telegram->setWebhook($config['token'], $this->webhookUrl($name), $this->webhookSecret($config['token'])); $this->writeState($name, $state); }

    private function copyCleanSource(string $destination): void
    {
        if (!mkdir($destination, 0750, false)) throw new ProvisioningException("Cannot create exclusive staging directory {$destination}");
        $exclude = ['.git','.env','logs','storage/cache','provisioning/local.json','users.json'];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->sourceRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($this->sourceRoot) + 1);
            if ($item->isLink()) throw new ProvisioningException("Template contains forbidden symlink: {$relative}");
            if (preg_match('~(?:^|/)(?:cron\.lock|\.cron_internal_auth|\.compiled\.(?:php|map))$~', $relative)) continue;
            foreach ($exclude as $blocked) if ($relative === $blocked || str_starts_with($relative, $blocked.'/')) continue 2;
            $target = $destination.'/'.$relative;
            if ($item->isDir()) { if (!mkdir($target, 0750) && !is_dir($target)) throw new ProvisioningException("Cannot copy directory {$relative}"); }
            elseif ($item->isFile()) $this->copyRegularFile($item->getPathname(), $target, $relative);
            else throw new ProvisioningException("Unsupported template entry: {$relative}");
        }
        if (is_dir($destination.'/installer')) AtomicFilesystem::removeTree($destination.'/installer', $destination);
        foreach (['logs','storage','cron','cronbot','cronbot/.runtime'] as $runtime) { $path=$destination.'/'.$runtime; if (!is_dir($path) && !mkdir($path,0750,true)) throw new ProvisioningException("Cannot create runtime directory {$runtime}"); }
    }

    private function copyPersistentData(string $old, string $new): void
    {
        foreach (self::PERSISTENT_PATHS as $relative) {
            $source=$old.'/'.$relative; $target=$new.'/'.$relative;
            if (!file_exists($source) || is_link($source)) continue;
            if (is_file($source)) {
                if (file_exists($target) && !unlink($target)) throw new ProvisioningException("Cannot replace persistent file {$relative}");
                $this->copyRegularFile($source, $target, $relative);
                continue;
            }
            if (!is_dir($source)) throw new ProvisioningException("Persistent path is a special file: {$relative}");
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
                $suffix=substr($item->getPathname(),strlen($source)+1); $dest=$target.'/'.$suffix;
                if ($item->isLink()) throw new ProvisioningException("Persistent data contains forbidden symlink: {$relative}/{$suffix}");
                if ($item->isDir()) { if (!is_dir($dest) && !mkdir($dest,0750,true)) throw new ProvisioningException('Cannot preserve runtime directory.'); }
                elseif ($item->isFile()) { if (file_exists($dest)) @unlink($dest); $this->copyRegularFile($item->getPathname(),$dest,$relative.'/'.$suffix); }
                else throw new ProvisioningException('Persistent data contains a special file.');
            }
        }
    }

    private function copyRegularFile(string $source, string $target, string $relative): void
    {
        $before=lstat($source); if ($before===false || ($before['mode']&0170000)!==0100000 || $before['nlink']!==1) throw new ProvisioningException("File is not a safe regular file: {$relative}");
        $input=fopen($source,'rb'); $output=fopen($target,'xb');
        if ($input===false || $output===false) { if(is_resource($input))fclose($input);if(is_resource($output))fclose($output);@unlink($target);throw new ProvisioningException("Cannot securely copy {$relative}"); }
        try { if(stream_copy_to_stream($input,$output)===false||!fflush($output))throw new ProvisioningException("Cannot copy {$relative}");if(function_exists('fsync'))fsync($output);$opened=fstat($input);$after=lstat($source);if($opened===false||$after===false||$opened['dev']!==$before['dev']||$opened['ino']!==$before['ino']||$after['dev']!==$before['dev']||$after['ino']!==$before['ino']||$after['size']!==$before['size']||$after['mtime']!==$before['mtime'])throw new ProvisioningException("Source changed while copying {$relative}"); }
        catch(Throwable $error){fclose($input);fclose($output);@unlink($target);throw$error;} fclose($input);fclose($output);chmod($target,0640);
    }

    private function configure(string $root,string $name,array $database,string $token,string $adminId,string $username):void
    { $this->writeConfig($root,$name,$database,$token,$adminId,$username); foreach(['logs','storage','cron','cronbot','cronbot/.runtime'] as $dir)chmod($root.'/'.$dir,0750); }
    private function writeConfig(string $root,string $name,array $database,string $token,string $adminId,string $username):void
    { $rendered=ConfigEditor::render($root.'/config.php',$database,$token,$adminId,$this->domain.$this->urlPrefix.'/'.$name,$username);AtomicFilesystem::write($root.'/config.php',$rendered,0640,$this->enforceOwnership?$this->runtimeUser:null,$this->enforceOwnership?$this->runtimeUser:null); }
    private function initialize(string $root):void { $this->commands->run([$this->phpBinary,$root.'/table.php'],$root,300); }
    private function assertInitialized(string $root,array $database):void { foreach(['users','setting'] as $table){$script='$c=require '.var_export($root.'/config.php',true).'; $s=$GLOBALS["pdo"]->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?"); $s->execute(['.var_export($database['name'],true).','.var_export($table,true).']); exit($s->fetchColumn()?0:9);';$this->commands->run([$this->phpBinary,'-r',$script],$root,30);} }
    private function applyPermissions(string $root): void
    {
        $account = $this->enforceOwnership && function_exists('posix_getpwnam') ? posix_getpwnam($this->runtimeUser) : null;
        if ($this->enforceOwnership && !is_array($account)) throw new ProvisioningException("Runtime user {$this->runtimeUser} does not exist.");
        $paths = [$root];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
            if ($item->isLink()) throw new ProvisioningException('Runtime tree unexpectedly contains a symlink.');
            $paths[] = $item->getPathname();
        }
        foreach ($paths as $path) {
            if (!chmod($path, is_dir($path) ? 0750 : 0640)) throw new ProvisioningException("Cannot set runtime mode on {$path}");
            if (!$this->enforceOwnership) continue;
            $stat = lstat($path);
            if ($stat === false) throw new ProvisioningException("Cannot inspect runtime owner on {$path}");
            if ((int) $stat['uid'] !== (int) $account['uid'] && !chown($path, $this->runtimeUser)) throw new ProvisioningException("Cannot set runtime owner on {$path}");
            if ((int) $stat['gid'] !== (int) $account['gid'] && !chgrp($path, $this->runtimeUser)) throw new ProvisioningException("Cannot set runtime group on {$path}");
        }
    }
    private function verifyLocalPhp(string $name):void { $status=trim($this->commands->run(['/usr/bin/curl','--silent','--show-error','--output','/dev/null','--write-out','%{http_code}','--connect-timeout','3','--max-time','10','--resolve',$this->domain.':443:127.0.0.1',$this->webhookUrl($name)],null,15));if(!in_array($status,['403','405'],true))throw new ProvisioningException("Unexpected local PHP health status: {$status}"); }
    private function requireCurrent(string $name):string { $path=$this->botPath($name);if(is_link($path)||!is_dir($path)||realpath($path)!==$path||dirname($path)!==$this->webRoot)throw new ProvisioningException("{$name} installation is missing or unsafe.");return$path; }
    private function temporaryPath(string $name,string $kind):string{return$this->webRoot.'/.'.$name.'.'.$kind.'_'.bin2hex(random_bytes(8));}
    /** @return list<string> */ private function temporaryPaths(string $name):array{return glob($this->webRoot.'/.'.$name.'.*_*',GLOB_NOSORT)?:[];}
    private function botPath(string $name):string{return$this->webRoot.'/'.$name;}
    private function webhookSecret(string $token):string{return hash('sha256',$token.'_faoxima_webhook_secret');}
    private function webhookUrl(string $name):string{return'https://'.$this->domain.$this->urlPrefix.'/'.$name.'/index.php';}
    /** @return array{webhook_pending:int,webhook_last_error:string} */
    private function webhookDiagnostics(string $token): array { $info=$this->telegram->getWebhookInfo($token);return['webhook_pending'=>$info['pending_update_count'],'webhook_last_error'=>$info['last_error_message']]; }
    private function stateFile(string $name):string{return$this->stateRoot.'/'.$name.'.json';}
    private function writeState(string $name,array $state):void{$state['updated_at']=gmdate(DATE_ATOM);AtomicFilesystem::write($this->stateFile($name),json_encode($state,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n",0640,$this->enforceOwnership?$this->runtimeUser:null,$this->enforceOwnership?$this->runtimeUser:null);}
    private function readState(string $name):array{$file=$this->stateFile($name);if(is_link($file)||!is_file($file))throw new ProvisioningException("State missing for {$name}");$decoded=json_decode((string)file_get_contents($file),true,32,JSON_THROW_ON_ERROR);if(!is_array($decoded)||!is_string($decoded['state']??null))throw new ProvisioningException("Invalid state for {$name}");return$decoded;}
    private function withLock(string $name,callable $operation):mixed{$file=$this->lockRoot.'/'.$name.'.lock';if(is_link($file))throw new ProvisioningException('Unsafe lock path.');$handle=fopen($file,'c');if($handle===false||!flock($handle,LOCK_EX|LOCK_NB)){if(is_resource($handle))fclose($handle);throw new ProvisioningException("Concurrent operation rejected for {$name}");}try{return$operation();}finally{flock($handle,LOCK_UN);fclose($handle);}}
    private function assertRuntimeIdentity():void{if($this->enforceOwnership&&function_exists('posix_geteuid')&&function_exists('posix_getpwnam')){$account=posix_getpwnam($this->runtimeUser);if(!is_array($account)||(int)$account['uid']!==posix_geteuid())throw new ProvisioningException("Provisioner must run as {$this->runtimeUser} after one-time setup.");}}
    private function name(string $name):string{if(!preg_match(self::NAME_PATTERN,$name))throw new ProvisioningException('Bot name must match bot_[1-9][0-9]{0,8}.');return$name;}
    private function token(string $token):string{if(!preg_match(self::TOKEN_PATTERN,$token))throw new ProvisioningException('Telegram token format is invalid.');return$token;}
    private function adminId(string $id):string{if(!preg_match('/\A-?[1-9][0-9]{4,19}\z/D',$id))throw new ProvisioningException('Admin ID is invalid.');return$id;}
    private function version(string $version):string{if(!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D',$version))throw new ProvisioningException('Version is invalid.');return$version;}
    private function account(string $value,string $key):string{if(!preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D',$value))throw new ProvisioningException("{$key} is invalid.");return$value;}
    private function domain(string $domain):string{$domain=strtolower(rtrim($domain,'.'));if(filter_var($domain,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)===false)throw new ProvisioningException('Domain is invalid.');return$domain;}
    private function urlPrefix(string $prefix):string{$prefix='/'.trim($prefix,'/');if($prefix==='/'||!preg_match('~\A/[a-z0-9/_-]+\z~D',$prefix)||str_contains($prefix,'//'))throw new ProvisioningException('url_prefix is invalid.');return$prefix;}
    private function absolutePath(mixed $path,string $key):string{$path=rtrim((string)$path,'/');if($path===''||$path[0]!=='/'||str_contains($path,"\0")||preg_match('~/(?:\.|\.\.)(?:/|$)~',$path))throw new ProvisioningException("{$key} must be a normalized absolute path.");return$path;}
    private function existingDirectory(mixed $path,string $key):string{$path=$this->absolutePath($path,$key);$real=realpath($path);if($real===false||!is_dir($real)||is_link($path))throw new ProvisioningException("{$key} does not exist or is unsafe.");return$real;}
}
