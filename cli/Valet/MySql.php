<?php

namespace Valet;

use PDO;

class MySql
{
    public CONST DEFAULT_USER = 'valet';
    public CONST SYSTEM_DATABASES = [
        'information_schema',
        'mysql',
        'performance_schema',
        'sys',
        'mysql_temp',
        'phpmyadmin',
    ];

    private ?PDO $pdoConnection = null;

    public function __construct(
        public Brew $brew,
        public CommandLine $cli,
        public Filesystem $files,
        public Configuration $configuration,
        public Site $site
    ) {}
    public function install(): void
    {
        if (!$this->brew->hasInstalledMySql()) {
            $this->brew->installOrFail('mysql', []);

            $this->createValetUser('');
        }
    }

    /**
     * Restart the mysql service.
     */
    public function restart(): void
    {
        $this->brew->restartService('mysql');
    }

    /**
     * Stop the mysql service.
     */
    public function stop(): void
    {
        $this->brew->stopService('mysql');
    }

    /**
     * Uninstall the mysql formula.
     */
    public function uninstall(): void
    {
        $this->brew->stopService('mysql');
        $this->brew->uninstallFormula('mysql');
        $this->files->unlink(BREW_PREFIX.'/etc/my.cnf');
    }

    /**
     * Configure database user for valet.
     */

    public function isConfigured(): bool
    {
        /** @var array<string, string> $config */
        $config = $this->configuration->get('mysql', []);

        return isset($config['user']);
    }

    public function configureUser(string $user, string|null $password): bool
    {
        /** @var array<string, string> $config */
        $config = $this->configuration->get('mysql', []);

        $connection = $this->validateCredentials($user, $password);
        if ($connection) {
            $config['user'] = $user;
            $config['password'] = $password;
            $this->configuration->set('mysql', $config);

            return true;
        }

        return false;
    }

    public function listDatabases()
    {
        $systemDatabases = static::SYSTEM_DATABASES;
        // Ignore system databases.
        $query = $this->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME NOT IN ('".implode("','", $systemDatabases)."')");
        $query->execute();

        return $query->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Create a new mysql database.
     */
    public function createDatabase(string $name): bool
    {
        if ($this->isDatabaseExists($name)) {
            warning("Database `$name` already exists.");

            return false;
        }

        if ($this->isSystemDatabase($name)) {
            warning("Database `$name` is a system database.");
            return false;
        }

        $isCreated = (bool)$this->query("CREATE DATABASE `$name`");

        if (!$isCreated){
            warning("Failed to create database `$name`.");

            return false;
        }

        return true;
    }

    /**
     * Drop a mysql database.
     */
    public function dropDatabase(string $name): bool
    {
        if (!$this->isDatabaseExists($name)) {
            warning("Database `$name` does not exist.");

            return false;
        }

        if ($this->isSystemDatabase($name)) {
            warning("Database `$name` is a system database.");
            return false;
        }

        $isDropped = (bool)$this->query("DROP DATABASE `$name`");

        if (!$isDropped){
            warning("Failed to drop database `$name`.");

            return false;
        }

        return true;
    }

    /**
     * Reset a mysql database.
     */
    public function resetDatabase($name): bool
    {
        if (!$this->dropDatabase($name)) {
            warning("Failed to reset database `$name`.");
            return false;
        }

        if (!$this->createDatabase($name)) {
            warning("Failed to reset database `$name`.");
            return false;
        }

        return true;
    }

    /**
     * Import a mysql database.
     */
    public function importDatabase($name, $file): bool
    {
        if (!$this->isDatabaseExists($name)) {
            $this->createDatabase($name);
        }

        if ($this->isSystemDatabase($name)) {
            warning("Database `$name` is a system database.");
            return false;
        }

        $gzip = '';
        $sqlFile = '';
        if (\stristr($file, '.gz')) {
            $file = escapeshellarg($file);
            $gzip = "zcat {$file} | ";
        } else {
            $file = escapeshellarg($file);
            $sqlFile = " < {$file}";
        }
        $database = escapeshellarg($name);
        $credentials = $this->getCredentials();
        $this->cli->run(
            \sprintf(
                '%smysql -u %s -p%s %s %s',
                $gzip,
                $credentials['user'],
                $credentials['password'],
                $database,
                $sqlFile
            )
        );

        return true;
    }

    public function exportDatabase($name, bool $exportAsSql = false): array
    {
        $filename = $name.'-'.\date('Y-m-d-H-i-s', \time());
        $filename = $exportAsSql ? $filename.'.sql' : $filename.'.sql.gz';

        $credentials = $this->getCredentials();
        $command = \sprintf(
            'mysqldump -u %s -p%s %s %s > %s',
            $credentials['user'],
            $credentials['password'],
            $name,
            $exportAsSql ? '' : '| gzip',
            escapeshellarg($filename)
        );

        $this->cli->runAsUser($command);

        return [
            'database' => $name,
            'filename' => $filename,
        ];
    }

    public function getDefaultUser(): string
    {
        return static::DEFAULT_USER;
    }

    public function isDatabaseExists($name): bool
    {
        $query = $this->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$name'");
        $query->execute();

        return (bool) $query->rowCount();
    }

    public function isSystemDatabase($name): bool
    {
        return in_array($name, static::SYSTEM_DATABASES);
    }

    /**
     * Run Mysql query.
     *
     * @return bool|\PDOStatement|void
     */
    private function query(string $query)
    {
        $link = $this->getConnection();

        try {
            return $link->query($query);
        } catch (\PDOException $e) {
            warning($e->getMessage());
        }
    }

    /**
     * Validate Username & Password.
     */
    private function validateCredentials(string $username, string|null $password = null): bool
    {
        try {
            // Create connection
            $connection = new PDO(
                'mysql:host=localhost',
                $username,
                $password
            );
            $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return true;
        } catch (\PDOException $e) {
            warning('Invalid database credentials');

            return false;
        }
    }

    /**
     * Set root password of Mysql.
     */
    private function createValetUser(string|null $password = null): void
    {
        $success = true;
        $query = "sudo mysql -e \"CREATE USER '".static::DEFAULT_USER."'@'localhost' IDENTIFIED WITH mysql_native_password BY '".$password."';GRANT ALL PRIVILEGES ON *.* TO '".static::DEFAULT_USER."'@'localhost' WITH GRANT OPTION;FLUSH PRIVILEGES;\"";
        $this->cli->run(
            $query,
            function ($statusCode, $error) use (&$success) {
                warning('Setting password for valet user failed due to `['.$statusCode.'] '.$error.'`');
                $success = false;
            }
        );

        if ($success !== false) {
            /** @var array<string, string> $config */
            $config = $this->configuration->get('mysql', []);

            $config['user'] = static::DEFAULT_USER;
            $config['password'] = $password;
            $this->configuration->set('mysql', $config);
        }
    }

    /**
     * Get the mysql connection.
     */
    public function getConnection(): PDO
    {
        // if connection already exists return it early.
        if ($this->pdoConnection) {
            return $this->pdoConnection;
        }

        try {
            // Create connection
            $credentials = $this->getCredentials();
            $this->pdoConnection = new PDO(
                'mysql:host=localhost',
                $credentials['user'],
                $credentials['password']
            );
            $this->pdoConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return $this->pdoConnection;
        } catch (\PDOException $e) {
            warning('Failed to connect MySQL due to :`'.$e->getMessage().'`');
            exit;
        }
    }

    /**
     * Returns the stored password from the config. If not configured returns the default root password.
     * @return array{user: string, password: string}
     * @throws \JsonException
     */
    private function getCredentials(): array
    {
        /** @var array<string, string> $config */
        $config = $this->configuration->get('mysql', []);
        if (!isset($config['password']) && $config['password'] !== null) {
            warning('Valet database user is not configured!');
            exit;
        }

        // For previously installed user.
        if (empty($config['user'])) {
            $config['user'] = 'root';
        }

        return ['user' => $config['user'], 'password' => $config['password']];
    }
}
