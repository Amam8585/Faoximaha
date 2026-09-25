<?php

declare(strict_types=1);

namespace Faoxima\Provisioning;

use PDO;
use Throwable;

final class PdoDatabaseAdmin implements DatabaseAdmin
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CommandRunner $commands,
        private readonly string $mysqlBinary = '/usr/bin/mysql',
        private readonly string $mysqldumpBinary = '/usr/bin/mysqldump',
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function provision(string $botName, ?array $ownedCredentials = null): array
    {
        [$name, $accountName] = $this->resourceNames($botName);
        $schemaExists = $this->schemaExists($name);
        $userExists = $this->userExists($accountName);
        if ($schemaExists || $userExists) {
            if (!$schemaExists || !$userExists || !$this->credentialsMatch($ownedCredentials, $name, $accountName)
                || !$this->passwordWorks($name, $accountName, $ownedCredentials['password']) || !$this->grantsAreRestricted($name, $accountName)) {
                throw new ProvisioningException("SAFE CONFLICT: database {$name} or user {$accountName} is not proven to belong to this bot record.");
            }
            return $ownedCredentials + ['status' => 'OWNED_EXISTING'];
        }
        $password = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $schema = $this->identifier($name);
        $user = $this->pdo->quote($accountName);
        $pass = $this->pdo->quote($password);
        $createdSchema = false;
        try {
            $this->pdo->exec("CREATE DATABASE {$schema} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $createdSchema = true;
            $this->pdo->exec("CREATE USER {$user}@'localhost' IDENTIFIED BY {$pass}");
            $this->pdo->exec("GRANT ALL PRIVILEGES ON {$schema}.* TO {$user}@'localhost'");
        } catch (Throwable $error) {
            try { $this->pdo->exec("DROP USER IF EXISTS {$user}@'localhost'"); } catch (Throwable) { }
            if ($createdSchema) {
                try { $this->pdo->exec("DROP DATABASE IF EXISTS {$schema}"); } catch (Throwable) { }
            }
            throw new ProvisioningException("Database provisioning failed for {$botName}: {$error->getMessage()}", 0, $error);
        }
        return ['name' => $name, 'user' => $accountName, 'password' => $password, 'status' => 'FRESH'];
    }

    public function exists(string $botName): bool
    {
        [$name, $accountName] = $this->resourceNames($botName);
        return $this->schemaExists($name) || $this->userExists($accountName);
    }

    public function dump(array $credentials, string $destination): void
    {
        $defaults = $this->defaultsFile($credentials['user'], $credentials['password']);
        $temporary = dirname($destination) . '/.' . basename($destination) . '.tmp-' . bin2hex(random_bytes(6));
        try {
            $this->commands->runToFile([
                $this->mysqldumpBinary, '--defaults-extra-file=' . $defaults, '--single-transaction', '--routines', '--triggers', '--hex-blob', $credentials['name'],
            ], $temporary, 300);
            if (!chmod($temporary, 0600) || !rename($temporary, $destination)) { throw new ProvisioningException('Cannot atomically publish database backup.'); }
        } finally {
            @unlink($defaults);
            @unlink($temporary);
        }
    }

    public function restore(string $botName, string $source, string $password): void
    {
        [$name, $accountName] = $this->resourceNames($botName);
        $schema = $this->identifier($name);
        $user = $this->pdo->quote($accountName);
        $pass = $this->pdo->quote($password);
        $this->pdo->exec("DROP DATABASE IF EXISTS {$schema}");
        $this->pdo->exec("CREATE DATABASE {$schema} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->pdo->exec("CREATE USER IF NOT EXISTS {$user}@'localhost' IDENTIFIED BY {$pass}");
        $this->pdo->exec("ALTER USER {$user}@'localhost' IDENTIFIED BY {$pass}");
        $this->pdo->exec("GRANT ALL PRIVILEGES ON {$schema}.* TO {$user}@'localhost'");
        $defaults = $this->defaultsFile($accountName, $password);
        try {
            $this->commands->runWithInput([$this->mysqlBinary, '--defaults-extra-file=' . $defaults, $name], $source, 300);
        } finally {
            @unlink($defaults);
        }
    }

    public function drop(string $botName, string $password): void
    {
        [$name, $accountName] = $this->resourceNames($botName);
        $schema = $this->identifier($name);
        $user = $this->pdo->quote($accountName);
        try {
            $this->pdo->exec("DROP DATABASE IF EXISTS {$schema}");
            $this->pdo->exec("DROP USER IF EXISTS {$user}@'localhost'");
        } catch (Throwable $error) {
            throw new ProvisioningException('Database/user deletion was interrupted.', 0, $error);
        }
    }

    private function schemaExists(string $name): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $statement->execute([$name]);
        return $statement->fetchColumn() !== false;
    }

    private function userExists(string $name): bool
    {
        $statement = $this->pdo->prepare("SELECT 1 FROM mysql.user WHERE User = ? AND Host = 'localhost'");
        $statement->execute([$name]);
        return $statement->fetchColumn() !== false;
    }

    /** @param array{name:string,user:string,password:string}|null $credentials */
    private function credentialsMatch(?array $credentials, string $name, string $accountName): bool
    {
        return is_array($credentials) && ($credentials['name'] ?? '') === $name
            && ($credentials['user'] ?? '') === $accountName && is_string($credentials['password'] ?? null)
            && $credentials['password'] !== '';
    }

    private function passwordWorks(string $name, string $accountName, string $password): bool
    {
        $defaults = $this->defaultsFile($accountName, $password);
        try { $this->commands->run([$this->mysqlBinary, '--defaults-extra-file=' . $defaults, $name, '--execute=SELECT 1'], null, 30); return true; }
        catch (Throwable) { return false; }
        finally { @unlink($defaults); }
    }

    private function grantsAreRestricted(string $name, string $accountName): bool
    {
        $statement = $this->pdo->query('SHOW GRANTS FOR ' . $this->pdo->quote($accountName) . "@'localhost'");
        $grants = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_COLUMN);
        $hasSchemaGrant = false;
        foreach ($grants as $grant) {
            $grant = (string) $grant;
            if (str_contains($grant, ' ON `' . $name . '`.* ')) { $hasSchemaGrant = true; continue; }
            if (str_contains($grant, ' ON *.* ') && str_starts_with($grant, 'GRANT USAGE ')) continue;
            if (str_starts_with($grant, 'GRANT ')) return false;
        }
        return $hasSchemaGrant;
    }

    /** @return array{0:string,1:string} */
    private function resourceNames(string $botName): array
    {
        if (!preg_match('/\Abot_([1-9][0-9]{0,8})\z/D', $botName, $match)) {
            throw new ProvisioningException('Invalid bot database resource name.');
        }
        $suffix = str_pad($match[1], 6, '0', STR_PAD_LEFT);
        return ['muteshop_bot_' . $suffix, 'mbot_' . $suffix];
    }
    private function identifier(string $value): string { return '`' . str_replace('`', '``', $value) . '`'; }

    private function defaultsFile(string $user, string $password): string
    {
        $path = sys_get_temp_dir() . '/faoxima-mysql-' . bin2hex(random_bytes(8)) . '.cnf';
        AtomicFilesystem::write($path, "[client]\nhost=localhost\nuser={$user}\npassword={$password}\n", 0600);
        return $path;
    }
}
