<?php

namespace IndieHD\MysqlDbBackup;

use mysqli;
use Exception;

/**
 * MySQL Database Backup Tool
 *
 * Automates the routine export of MySQL/MariaDB databases with intelligent
 * deduplication and compression.
 *
 * @author Ben Johnson (ben@indiehd.com)
 * @copyright Copyright (c) 2012-2026, Ben Johnson
 * @license GNU General Public License, Version 3 (GPLv3)
 */
class DbBackup
{
    /**
     * @var array Configuration settings
     */
    private array $config;

    /**
     * @var mysqli Database connection
     */
    private mysqli $mysqli;

    /**
     * @var array System databases to exclude from backups
     */
    private const SYSTEM_DATABASES = [
        'information_schema',
        'performance_schema',
        'mysql',
        'sys'
    ];

    /**
     * Constructor
     *
     * @param string|null $configFile Path to configuration file
     * @throws Exception If configuration cannot be loaded or database connection fails
     */
    public function __construct(?string $configFile = null)
    {
        $this->loadConfiguration($configFile);
        $this->connectToDatabase();
    }

    /**
     * Load configuration from file or environment variables
     *
     * @param string|null $configFile Path to configuration file
     * @throws Exception If configuration is invalid
     */
    private function loadConfiguration(?string $configFile): void
    {
        $config = [];

        // Try to load from INI file if provided
        if ($configFile !== null && file_exists($configFile)) {
            $config = parse_ini_file($configFile, true);
            if ($config === false) {
                throw new Exception(
                    'Configuration values could not be read from "' . $configFile . '"; ' .
                    'ensure that the file exists and contains valid configuration parameters'
                );
            }
        }

        // Use environment variables as fallback or override
        $this->config = [
            'hostname' => $config['connection']['hostname'] ?? getenv('DB_HOST') ?: 'localhost',
            'port' => (int) ($config['connection']['port'] ?? getenv('DB_PORT') ?: 3306),
            'username' => $config['connection']['username'] ?? getenv('DB_USERNAME') ?: '',
            'password' => $config['connection']['password'] ?? getenv('DB_PASSWORD') ?: null,
            // Path to a CA bundle. When set, both the mysqli connection and mysqldump
            // require TLS and verify the server's certificate against it — what a
            // managed/hosted database (DigitalOcean, RDS, ...) expects.
            'ssl_ca' => $config['connection']['ssl_ca'] ?? getenv('DB_SSL_CA') ?: null,
            'dumpdir' => $config['backup']['dumpdir'] ?? getenv('BACKUP_DIR') ?: '/backups',
            // Optional comma-separated allow-list of databases. Empty means "every
            // non-system database the user can see", the historical behaviour.
            'databases' => self::parseList($config['backup']['databases'] ?? getenv('DB_DATABASES') ?: ''),
            // Extra options appended verbatim (whitespace-split) to mysqldump, for
            // server- or client-specific flags such as --set-gtid-purged=OFF.
            'dump_options' => self::parseOptions($config['backup']['dump_options'] ?? getenv('MYSQLDUMP_EXTRA_OPTIONS') ?: ''),
        ];

        // Ensure password is null if empty (for socket-based auth)
        if (empty($this->config['password'])) {
            $this->config['password'] = null;
        }

        // Validate required configuration
        if (empty($this->config['username'])) {
            throw new Exception('Database username must be specified in config file or DB_USERNAME environment variable');
        }

        if (empty($this->config['dumpdir'])) {
            throw new Exception('Backup directory must be specified in config file or BACKUP_DIR environment variable');
        }

        if ($this->config['port'] < 1 || $this->config['port'] > 65535) {
            throw new Exception('Database port must be between 1 and 65535 (config file or DB_PORT environment variable)');
        }

        if ($this->config['ssl_ca'] !== null && !is_readable($this->config['ssl_ca'])) {
            throw new Exception('The CA bundle "' . $this->config['ssl_ca'] . '" (DB_SSL_CA) does not exist or is not readable');
        }

        foreach ($this->config['databases'] as $database) {
            // Names are used as directory names under dumpdir, so anything that
            // could escape that directory is refused outright.
            if (preg_match('/^[A-Za-z0-9_$-]+$/', $database) !== 1) {
                throw new Exception('Refusing to back up database with unsafe name "' . $database . '"');
            }
        }
    }

    /**
     * Split a comma-separated list into trimmed, non-empty items
     *
     * @param string $value Comma-separated list
     * @return string[]
     */
    private static function parseList(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
    }

    /**
     * Split a whitespace-separated option string into individual options
     *
     * @param string $value Whitespace-separated options
     * @return string[]
     */
    private static function parseOptions(string $value): array
    {
        return preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Connect to the MySQL database
     *
     * @throws Exception If connection fails
     */
    private function connectToDatabase(): void
    {
        // PHP >= 8.1 makes mysqli throw by default; older versions return false and
        // set connect_error. Report both the same way.
        mysqli_report(MYSQLI_REPORT_OFF);

        $this->mysqli = mysqli_init();
        $flags = 0;

        if ($this->config['ssl_ca'] !== null) {
            $this->mysqli->ssl_set(null, null, $this->config['ssl_ca'], null, null);
            $flags |= MYSQLI_CLIENT_SSL;
        }

        $connected = @$this->mysqli->real_connect(
            $this->config['hostname'],
            $this->config['username'],
            $this->config['password'],
            null,
            $this->config['port'],
            null,
            $flags
        );

        if (!$connected) {
            throw new Exception(
                'Connect Error (' . mysqli_connect_errno() . ') ' . mysqli_connect_error()
            );
        }

        $this->mysqli->set_charset('utf8mb4');
    }

    /**
     * Run the backup process for all databases
     *
     * @throws Exception If backup process fails
     */
    public function run(): void
    {
        $databases = $this->getDatabases();

        foreach ($databases as $database) {
            if ($this->isSystemDatabase($database)) {
                echo 'Skipping system database: ' . $database . PHP_EOL;
                continue;
            }

            echo 'Processing database: ' . $database . PHP_EOL;

            try {
                $this->backupDatabase($database);
            } catch (Exception $e) {
                echo 'Error backing up database "' . $database . '": ' . $e->getMessage() . PHP_EOL;
                throw $e; // Re-throw to exit on error
            }
        }

        // Connection will be closed automatically by destructor
    }

    /**
     * Get list of all databases
     *
     * @return array List of database names
     * @throws Exception If query fails
     */
    private function getDatabases(): array
    {
        if ($this->config['databases'] !== []) {
            return $this->config['databases'];
        }

        $databases = [];
        $result = $this->mysqli->query('SHOW DATABASES');

        if ($result === false) {
            throw new Exception('Failed to query databases: ' . $this->mysqli->error);
        }

        while ($row = $result->fetch_assoc()) {
            $databases[] = $row['Database'];
        }

        return $databases;
    }

    /**
     * Check if a database is a system database
     *
     * @param string $database Database name
     * @return bool True if system database
     */
    private function isSystemDatabase(string $database): bool
    {
        return in_array($database, self::SYSTEM_DATABASES, true);
    }

    /**
     * Backup a single database
     *
     * @param string $database Database name
     * @throws Exception If backup fails
     */
    private function backupDatabase(string $database): void
    {
        $targetDir = $this->config['dumpdir'] . DIRECTORY_SEPARATOR . $database;
        $this->ensureDirectoryExists($targetDir);

        $dumpFileName = $targetDir . DIRECTORY_SEPARATOR . date('YmdHi') . '.sql';

        // Build mysqldump command
        // --skip-comments: Ensures hash checks work correctly (comments include timestamps)
        // --single-transaction: Ensures consistency without locking tables
        // --default-character-set=utf8mb4: "utf8" is an alias for the 3-byte utf8mb3 on
        //   both MySQL and MariaDB, so the server transcodes results to it on the wire
        //   and every 4-byte character (emoji, many CJK ideographs) leaves the dump as
        //   a literal "?". The data is only corrupt in the backup, which is the worst
        //   place to discover it. utf8mb4 is lossless for anything the server stores.
        // --no-tablespaces: Skips the tablespace dump, which on MySQL 8.0 requires the
        //   global PROCESS privilege. Without it, unprivileged backup users get a
        //   non-fatal "Access denied; you need (at least one of) the PROCESS
        //   privilege(s)" error. Tablespace metadata isn't needed to restore a
        //   standard InnoDB database, so skipping it is safe and silences the error.
        $cmd = sprintf(
            'mysqldump --skip-comments --add-drop-table --default-character-set=utf8mb4 ' .
            '--extended-insert --host=%s --port=%d --no-tablespaces --quick --quote-names --routines ' .
            '--set-charset --single-transaction --triggers --tz-utc --verbose --user=%s',
            escapeshellarg($this->config['hostname']),
            $this->config['port'],
            escapeshellarg($this->config['username'])
        );

        if ($this->config['ssl_ca'] !== null) {
            $cmd .= ' --ssl-ca=' . escapeshellarg($this->config['ssl_ca']);
            // The two client families spell "verify the server certificate" differently
            // and each rejects the other's flag, so pick by the binary actually present.
            $cmd .= $this->dumpClientIsMariaDb() ? ' --ssl-verify-server-cert' : ' --ssl-mode=VERIFY_CA';
        }

        foreach ($this->config['dump_options'] as $option) {
            $cmd .= ' ' . escapeshellarg($option);
        }

        if (!empty($this->config['password'])) {
            $cmd .= ' --password=' . escapeshellarg($this->config['password']);
        }

        $cmd .= ' ' . escapeshellarg($database) . ' > ' . escapeshellarg($dumpFileName);

        // Execute dump command
        $output = [];
        $retVal = 0;
        exec($cmd, $output, $retVal);

        // Verify dump succeeded
        if ($retVal !== 0 || !file_exists($dumpFileName)) {
            throw new Exception(
                'The database "' . $database . '" could not be dumped. ' .
                'Exit code: ' . $retVal . '. ' .
                'Output: ' . implode("\n", $output)
            );
        }

        echo 'The database "' . $database . '" was dumped successfully to ' . $dumpFileName . PHP_EOL;

        // Check if this backup is identical to the previous one
        if ($this->isDuplicateBackup($targetDir, $dumpFileName)) {
            echo 'This dump matches the previous dump exactly (checked by SHA1 hash); ' .
                 'discarding this backup, as it contains nothing new' . PHP_EOL;
            unlink($dumpFileName);
            return;
        }

        // Compress the backup file
        $gzipped = $this->gzipFile($dumpFileName);

        if ($gzipped === false) {
            echo 'gzipping "' . $dumpFileName . '" failed; keeping uncompressed file as a fall-back' . PHP_EOL;
        } else {
            echo 'File was gzipped successfully to ' . $gzipped . PHP_EOL;
        }
    }

    /**
     * Whether the mysqldump on PATH is MariaDB's client rather than MySQL's
     *
     * @return bool
     */
    private function dumpClientIsMariaDb(): bool
    {
        $output = [];
        exec('mysqldump --version 2>&1', $output);

        return stripos(implode(' ', $output), 'mariadb') !== false;
    }

    /**
     * Ensure a directory exists, creating it if necessary
     *
     * @param string $directory Directory path
     * @throws Exception If directory cannot be created
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory)) {
            $created = mkdir($directory, 0777, true);
            if ($created === false) {
                throw new Exception(
                    'The directory "' . $directory . '" does not exist and could not be created; ' .
                    'please check the permissions'
                );
            }
        }
    }

    /**
     * Check if the new backup is identical to the previous backup
     *
     * @param string $targetDir Directory containing backups
     * @param string $newFile Path to new backup file
     * @return bool True if backup is a duplicate
     */
    private function isDuplicateBackup(string $targetDir, string $newFile): bool
    {
        // Get existing files, sorted descending by name (newest first)
        $existing = array_diff(scandir($targetDir, SCANDIR_SORT_DESCENDING), ['.', '..']);

        // Need at least 2 files to compare (the new one and a previous one)
        if (count($existing) < 2) {
            return false;
        }

        // Find the most recent .gz file (the previous backup)
        $oldFile = null;
        foreach (array_slice($existing, 0, 2) as $file) {
            $fullPath = $targetDir . DIRECTORY_SEPARATOR . $file;
            if (pathinfo($fullPath, PATHINFO_EXTENSION) === 'gz') {
                $oldFile = $fullPath;
                break;
            }
        }

        if ($oldFile === null) {
            return false;
        }

        echo 'More than one backup exists; checking hashes to see if this backup differs from the last...' . PHP_EOL;

        // Get hash of previous backup (uncompressed)
        $cmd = 'gunzip ' . escapeshellarg($oldFile) . ' --to-stdout | sha1sum -';
        $oldHash = system($cmd, $oldRetVal);

        if ($oldRetVal !== 0) {
            echo 'Warning: Could not get hash of previous backup' . PHP_EOL;
            return false;
        }

        // Get hash of new backup
        $cmd = 'sha1sum ' . escapeshellarg($newFile);
        $newHash = system($cmd, $newRetVal);

        if ($newRetVal !== 0) {
            echo 'Warning: Could not get hash of new backup' . PHP_EOL;
            return false;
        }

        // Compare first 40 characters (the actual hash, excluding filename)
        if (substr($oldHash, 0, 40) === substr($newHash, 0, 40)) {
            return true;
        }

        echo 'The two files are not the same; this backup contains new information' . PHP_EOL;
        return false;
    }

    /**
     * Compress a file using gzip
     *
     * @param string $file Path to file to compress
     * @return string|false Path to compressed file, or false on failure
     */
    private function gzipFile(string $file): string|false
    {
        $cmd = 'gzip ' . escapeshellarg($file);

        $output = [];
        $retVal = 0;
        exec($cmd, $output, $retVal);

        if ($retVal !== 0) {
            return false;
        }

        return $file . '.gz';
    }

    /**
     * Destructor - ensures database connection is closed
     */
    public function __destruct()
    {
        if (isset($this->mysqli)) {
            $this->mysqli->close();
        }
    }
}
