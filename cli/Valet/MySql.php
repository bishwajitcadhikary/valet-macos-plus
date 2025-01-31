<?php

namespace Valet;

use DomainException;
use JsonException;
use PDO;
use PDOException;
use PDOStatement;
use function date;
use function getcwd;
use function is_writable;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;
use function Laravel\Prompts\info;
use function sprintf;
use function stristr;
use function time;

class MySql
{
    public const DEFAULT_USER = 'valet';
    public const SYSTEM_DATABASES = [
        'information_schema',
        'mysql',
        'performance_schema',
        'sys',
        'mysql_temp',
    ];

    private ?PDO $pdoConnection = null;

    public function __construct(
        public Brew          $brew,
        public Filesystem    $files,
        public Configuration $configuration,
        public CommandLine   $cli,
    )
    {
    }

    public function install(): void
    {
        if (!$this->brew->hasInstalledMySql()) {
            $this->brew->installOrFail('mysql');

            $this->brew->restartService('mysql');

            $password = text(sprintf("Please enter new password for [%s] database user:", static::DEFAULT_USER));
            $this->createValetUser($password);
        } else {
            $this->configure();
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
        $this->brew->cleanupBrew();
        $this->files->unlink(BREW_PREFIX . '/etc/my.cnf');
    }

    /**
     * Create a new mysql user.
     */
    private function createValetUser(string|null $password = null): void
    {
        $success = true;
        $query = "sudo mysql -e \"CREATE USER '" . static::DEFAULT_USER . "'@'localhost' IDENTIFIED WITH mysql_native_password BY '" . $password . "';GRANT ALL PRIVILEGES ON *.* TO '" . static::DEFAULT_USER . "'@'localhost' WITH GRANT OPTION;FLUSH PRIVILEGES;\"";
        $this->cli->run(
            $query,
            function ($statusCode, $error) use (&$success) {
                warning('Setting password for valet user failed due to `[' . $statusCode . '] ' . $error . '`');
                $success = false;
            }
        );

        if ($success !== false) {
            /** @var array<string, string> $config */
            $config = $this->configuration->get('mysql', []);

            $config['user'] = static::DEFAULT_USER;
            $config['password'] = $password;
            $this->configuration->set('mysql', $config);

            info('Valet database user configured successfully');
        }
    }

    public function configure(bool $force = false): void
    {
        /** @var array<string, string> $config */
        $config = $this->configuration->get('mysql', []);
        if (!$force && isset($config['password'])) {
            info('Valet database user is already configured. Use --force to reconfigure database user.');
            return;
        }

        $defaultUser = null;
        if (!empty($config['user'])) {
            $defaultUser = $config['user'];
        }

        $user = text(
            label: 'Please enter MySQL user:',
            default: $defaultUser,
            required: true
        );
        $password = text('Please enter MySQL password:');

        $connection = $this->validateCredentials($user, $password);
        if (!$connection) {
            $confirm = confirm('Would you like to try again?', true);
            if (!$confirm) {
                warning('Valet database user is not configured');
                return;
            }
            $this->configure($force);
            return;
        }
        $config['user'] = $user;
        $config['password'] = $password;
        $this->configuration->set('mysql', $config);
        info('Database user configured successfully');
    }

    public function listDatabases(): bool|array
    {
        $systemDatabases = static::SYSTEM_DATABASES;
        // Ignore system databases.
        $query = $this->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME NOT IN ('" . implode("','", $systemDatabases) . "')");
        $query->execute();

        return $query->fetchAll(PDO::FETCH_COLUMN);
    }

    public function listDatabasesWithFullInfo()
    {
        /**
         * We need to fetch the databases with full information.
         * | Database | Tables | Rows | Size | Collation |
         * |----------|--------|------|------|-----------|
         */

        $query = $this->query("
            SELECT
                s.schema_name AS 'Database',
                COUNT(t.table_name) AS 'Tables',
                SUM(t.table_rows) AS 'Rows',
                SUM(t.data_length + t.index_length) AS 'Size',
                s.default_collation_name AS 'Collation'
            FROM
                information_schema.schemata s
            LEFT JOIN
                information_schema.tables t
            ON
                s.schema_name = t.table_schema
            WHERE
                s.schema_name NOT IN ('information_schema', 'performance_schema', 'mysql', 'sys')
            GROUP BY
                s.schema_name, s.default_collation_name
        ");
        $query->execute();

        return $query->fetchAll();
    }

    /**
     * Create a new mysql database.
     */
    public function createDatabase(string|null $database = null): bool
    {
        if ($database === null) {
            $database = text('Enter the name of the database:');
            if (!$database) {
                warning('No new MySQL database was created.');
                return false;
            }
        }

        if ($this->isDatabaseExists($database)) {
            warning("Database `$database` already exists.");

            return false;
        }

        if ($this->isSystemDatabase($database)) {
            warning("Database `$database` is a system database.");
            return false;
        }

        $isCreated = (bool)$this->query("CREATE DATABASE `$database`");

        if (!$isCreated) {
            warning("Failed to create database `$database`.");

            return false;
        }

        info("Database `$database` created successfully.");

        return true;
    }

    /**
     * Drop a mysql database.
     */
    public function dropDatabase(string|null $database, bool $yes = false): bool
    {
        if (!$database) {
            $databases = MySql::listDatabases();

            if (empty($databases)) {
                warning('No MySQL databases found.');
                return false;
            }

            $database = select(
                label: 'Which database would you like to drop?',
                options: $databases,
                hint: 'Use arrow keys to navigate, press ↵ to drop.'
            );
        }

        if (!$yes) {
            $confirm = confirm('Are you sure you want to drop the database?');
            if (!$confirm) {
                warning('No MySQL databases were dropped.');
                return false;
            }
        }

        if (!$this->isDatabaseExists($database)) {
            warning("Database `$database` does not exist.");

            return false;
        }

        if ($this->isSystemDatabase($database)) {
            warning("Database `$database` is a system database.");
            return false;
        }

        $isDropped = (bool)$this->query("DROP DATABASE `$database`");

        if (!$isDropped) {
            warning("Failed to drop database `$database`.");

            return false;
        }

        info("Database `$database` dropped successfully.");

        return true;
    }

    /**
     * Reset a mysql database.
     */
    public function resetDatabase(string|null $database = null, bool $yes = false): bool
    {
        if ($database == null) {
            $databases = MySql::listDatabases();

            if (empty($databases)) {
                warning('No MySQL databases found.');
                return false;
            }

            $database = select(
                label: 'Which database would you like to reset?',
                options: $databases,
                hint: 'Use arrow keys to navigate, press ↵ to reset.'
            );
        }

        if (!$yes) {
            $confirm = confirm('Are you sure you want to reset the database?');
            if (!$confirm) {
                warning('No MySQL databases were reset.');
                return false;
            }
        }

        if (!$this->query("DROP DATABASE IF EXISTS `$database`")) {
            warning("Failed to reset database `$database`.");
            return false;
        }

        if (!$this->query("CREATE DATABASE `$database`")) {
            warning("Failed to reset database `$database`.");
            return false;
        }

        info("Database `$database` reset successfully.");

        return true;
    }

    /**
     * Import a mysql database.
     */
    public function importDatabase(string|null $database = null, string|null $file = null, bool $force = false): bool
    {
        if (!$database) {
            $databases = MySql::listDatabases();

            if (empty($databases)) {
                warning('No MySQL databases found.');
                return false;
            }

            $database = select(
                label: 'Which database would you like to import to?',
                options: $databases,
                hint: 'Use arrow keys to navigate, press ↵ to select.'
            );
        }

        if (!$file) {
            $file = text(
                label: 'Enter the path to the SQL file:',
                placeholder: 'e.g. /path/to/file.sql'
            );
        }

        if (!$this->files->exists($file)) {
            warning("The file `$file` does not exist.");
            return false;
        }

        if ($this->isDatabaseExists($database)) {
            if (!$force) {
                $question = confirm('The database already exists. Do you want to overwrite it?');

                if (!$question) {
                    warning('No MySQL databases were imported.');
                    return false;
                }
            }
        } else {
            $this->createDatabase($database);
        }

        if ($this->isSystemDatabase($database)) {
            warning("Database `$database` is a system database.");
            return false;
        }

        $gzip = '';
        $sqlFile = '';
        if (stristr($file, '.gz')) {
            $file = escapeshellarg($file);
            $gzip = "zcat {$file} | ";
        } else {
            $file = escapeshellarg($file);
            $sqlFile = " < $file";
        }
        $database = escapeshellarg($database);
        $credentials = $this->getCredentials();
        $this->cli->run(
            sprintf(
                '%smysql -u %s -p%s %s %s',
                $gzip,
                $credentials['user'],
                $credentials['password'],
                $database,
                $sqlFile
            )
        );

        info("The $file file has been imported to the $database database.");
        return true;
    }

    public function exportDatabase($database, bool $exportAsSql = false): bool
    {
        if (!$database) {
            $databases = MySql::listDatabases();

            if (empty($databases)) {
                warning('No MySQL databases found.');
                return false;
            }

            $database = select(
                label: 'Which database would you like to export?',
                options: $databases,
                hint: 'Use arrow keys to navigate, press ↵ to select.'
            );
        }

        $exportDir = getcwd();

        $isExportToCurrentDir = confirm(
            label: "Would you like to export the database to the current directory?",
            hint: "Directory: $exportDir"
        );

        if (!$isExportToCurrentDir) {
            $exportDir = text(
                label: 'Enter the path to the directory where the database will be exported:',
                placeholder: 'e.g. /path/to/directory',
                required: true
            );
        }

        $this->files->ensureDirExists($exportDir);

        if (!is_writable($exportDir)) {
            warning("The current directory is not writable.");
            return false;
        }

        $filename = $database . '-' . date('Y-m-d-H-i-s', time());
        $filename = $exportAsSql ? $filename . '.sql' : $filename . '.sql.gz';
        $filename = $exportDir . '/' . $filename;

        $credentials = $this->getCredentials();
        $command = sprintf(
            'mysqldump -u %s -p%s %s %s > %s',
            $credentials['user'],
            $credentials['password'],
            $database,
            $exportAsSql ? '' : '| gzip',
            escapeshellarg($filename)
        );

        $this->cli->runAsUser($command);

        info("The $database database has been exported to the `$filename` file.");

        return true;
    }

    public function isDatabaseExists($database): bool
    {
        $query = $this->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$database'");
        $query->execute();

        return (bool)$query->rowCount();
    }

    public function isSystemDatabase($database): bool
    {
        return in_array($database, static::SYSTEM_DATABASES);
    }

    /**
     * Run Mysql query.
     *
     * @return bool|PDOStatement|void
     */
    private function query(string $query)
    {
        $link = $this->getConnection();

        try {
            return $link->query($query);
        } catch (PDOException $e) {
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
        } catch (PDOException $e) {
            warning('Invalid database credentials');

            return false;
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
        } catch (PDOException $e) {
            warning('Failed to connect MySQL due to :`' . $e->getMessage() . '`');
            exit;
        }
    }

    /**
     * Returns the stored password from the config. If not configured returns the default root password.
     * @return array{user: string, password: string}
     * @throws JsonException
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
