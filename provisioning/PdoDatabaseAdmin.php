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

    public function create(string $botName): array
    {
        $name = $this->resourceName($botName);
        if ($this->schemaExists($name) || $this->userExists($name)) {
            throw new ProvisioningException("Database or user {$name} already exists.");
        }
        $password = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $schema = $this->identifier($name);
        $user = $this->pdo->quote($name);
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
        return ['name' => $name, 'user' => $name, 'password' => $password];
    }

    public function exists(string $botName): bool
    {
        $name = $this->resourceName($botName);
        return $this->schemaExists($name) || $this->userExists($name);
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
        $name = $this->resourceName($botName);
        $schema = $this->identifier($name);
        $user = $this->pdo->quote($name);
        $pass = $this->pdo->quote($password);
        $this->pdo->exec("DROP DATABASE IF EXISTS {$schema}");
        $this->pdo->exec("CREATE DATABASE {$schema} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->pdo->exec("CREATE USER IF NOT EXISTS {$user}@'localhost' IDENTIFIED BY {$pass}");
        $this->pdo->exec("ALTER USER {$user}@'localhost' IDENTIFIED BY {$pass}");
        $this->pdo->exec("GRANT ALL PRIVILEGES ON {$schema}.* TO {$user}@'localhost'");
        $defaults = $this->defaultsFile($name, $password);
        try {
            $this->commands->runWithInput([$this->mysqlBinary, '--defaults-extra-file=' . $defaults, $name], $source, 300);
        } finally {
            @unlink($defaults);
        }
    }

    public function drop(string $botName, string $password): void
    {
        $name = $this->resourceName($botName);
        $schema = $this->identifier($name);
        $user = $this->pdo->quote($name);
        $this->pdo->exec("DROP USER IF EXISTS {$user}@'localhost'");
        try {
            $this->pdo->exec("DROP DATABASE IF EXISTS {$schema}");
        } catch (Throwable $error) {
            $pass = $this->pdo->quote($password);
            $this->pdo->exec("CREATE USER IF NOT EXISTS {$user}@'localhost' IDENTIFIED BY {$pass}");
            $this->pdo->exec("GRANT ALL PRIVILEGES ON {$schema}.* TO {$user}@'localhost'");
            throw new ProvisioningException('Database deletion failed; application user was restored.', 0, $error);
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

    private function resourceName(string $botName): string { return 'fx_' . $botName; }
    private function identifier(string $value): string { return '`' . str_replace('`', '``', $value) . '`'; }

    private function defaultsFile(string $user, string $password): string
    {
        $path = sys_get_temp_dir() . '/faoxima-mysql-' . bin2hex(random_bytes(8)) . '.cnf';
        AtomicFilesystem::write($path, "[client]\nhost=localhost\nuser={$user}\npassword={$password}\n", 0600);
        return $path;
    }
}
