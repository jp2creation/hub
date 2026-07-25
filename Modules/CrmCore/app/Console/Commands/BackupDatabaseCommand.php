<?php

namespace Modules\CrmCore\Console\Commands;

use App\Models\CrmNotificationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Throwable;

class BackupDatabaseCommand extends Command
{
    protected $signature = 'backup:run
        {--connection= : Connexion base de donnees a sauvegarder}
        {--disk= : Disque Laravel de destination}
        {--path= : Dossier de destination sur le disque}
        {--keep= : Nombre de sauvegardes quotidiennes a conserver}
        {--keep-weekly= : Nombre de sauvegardes hebdomadaires a conserver}
        {--keep-monthly= : Nombre de sauvegardes mensuelles a conserver}
        {--dump-binary= : Chemin vers mariadb-dump ou mysqldump}
        {--encrypt : Chiffrer la sauvegarde}
        {--no-encrypt : Desactiver le chiffrement pour cette execution}
        {--verify : Verifier localement l archive gzip avant envoi}';

    protected $description = 'Create a streamed, compressed SQL backup of the HUB database.';

    public function handle(): int
    {
        $connectionName = (string) ($this->option('connection') ?: config('database.default'));
        $disk = (string) ($this->option('disk') ?: config('crm.backup.disk', 'local'));
        $basePath = trim((string) ($this->option('path') ?: config('crm.backup.path', 'backups/database')), '/');
        $dailyKeep = max(1, (int) ($this->option('keep') ?: config('crm.backup.keep', 14)));
        $weeklyKeep = max(0, (int) ($this->option('keep-weekly') ?: config('crm.backup.keep_weekly', 8)));
        $monthlyKeep = max(0, (int) ($this->option('keep-monthly') ?: config('crm.backup.keep_monthly', 12)));
        $encrypt = $this->shouldEncrypt();
        $verify = (bool) ($this->option('verify') || config('crm.backup.verify', false));
        $compressionLevel = max(1, min(9, (int) config('crm.backup.gzip_level', 6)));
        $temporaryDirectory = $this->temporaryDirectory();
        $fileName = $connectionName.'-'.now()->format('Ymd-His').'.sql.gz';
        $gzipPath = $temporaryDirectory.'/'.$fileName;
        $finalLocalPath = $gzipPath;

        if ($encrypt) {
            $finalLocalPath .= '.enc';
        }

        try {
            $this->dumpConnectionToGzip($connectionName, $gzipPath, $compressionLevel);

            if ($verify) {
                $this->verifyGzipArchive($gzipPath);
            }

            if ($encrypt) {
                $this->encryptFile($gzipPath, $finalLocalPath);
                $fileName .= '.enc';
            }

            $storedPaths = $this->storeRetentionCopies($disk, $basePath, $fileName, $finalLocalPath, $weeklyKeep, $monthlyKeep);

            $this->pruneOldBackups($disk, $basePath, $dailyKeep);
            $this->pruneOldBackups($disk, $this->joinStoragePath($basePath, 'weekly'), $weeklyKeep);
            $this->pruneOldBackups($disk, $this->joinStoragePath($basePath, 'monthly'), $monthlyKeep);
        } catch (Throwable $exception) {
            $this->recordBackupFailure($exception, $connectionName, $disk, $basePath);
            $this->error('Sauvegarde impossible : '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            $this->deleteTemporaryFiles($gzipPath, $finalLocalPath);
        }

        $this->info('Sauvegarde creee : '.$disk.':'.implode(', '.$disk.':', $storedPaths));

        return self::SUCCESS;
    }

    private function shouldEncrypt(): bool
    {
        if ($this->option('encrypt')) {
            return true;
        }

        if ($this->option('no-encrypt')) {
            return false;
        }

        return (bool) config('crm.backup.encrypt', false);
    }

    private function temporaryDirectory(): string
    {
        $directory = storage_path('app/private/tmp/backups');

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Impossible de creer le dossier temporaire des sauvegardes.');
        }

        return $directory;
    }

    private function dumpConnectionToGzip(string $connectionName, string $path, int $compressionLevel): void
    {
        $connection = DB::connection($connectionName);
        $driver = $connection->getDriverName();
        $database = $connection->getDatabaseName();
        $gzip = $this->openGzip($path, $compressionLevel);

        try {
            $this->writeHeader($gzip, $database, $driver);

            match ($driver) {
                'mysql', 'mariadb' => $this->dumpMysqlWithNativeBinary($connectionName, $gzip),
                'sqlite' => $this->dumpSqliteToGzip($connection->getPdo(), $gzip),
                default => throw new RuntimeException("Driver non supporte : {$driver}"),
            };
        } finally {
            gzclose($gzip);
        }
    }

    /**
     * @return resource
     */
    private function openGzip(string $path, int $compressionLevel)
    {
        $gzip = gzopen($path, 'wb'.$compressionLevel);

        if ($gzip === false) {
            throw new RuntimeException('Compression gzip impossible.');
        }

        return $gzip;
    }

    /**
     * @param  resource  $gzip
     */
    private function writeHeader($gzip, string $database, string $driver): void
    {
        $this->writeLine($gzip, '-- Martin Sols HUB database backup');
        $this->writeLine($gzip, '-- Driver: '.$driver);
        $this->writeLine($gzip, '-- Database: '.$database);
        $this->writeLine($gzip, '-- Created at: '.now()->toDateTimeString());
        $this->writeLine($gzip);
    }

    /**
     * @param  resource  $gzip
     */
    private function dumpMysqlWithNativeBinary(string $connectionName, $gzip): void
    {
        $command = $this->mysqlDumpCommand($connectionName);
        $environment = $this->mysqlDumpEnvironment($connectionName);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, base_path(), $environment ?: null);

        if (! is_resource($process)) {
            throw new RuntimeException('Impossible de lancer la commande de dump native.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stderr = '';
        $startedAt = time();
        $timeoutSeconds = max(0, (int) config('crm.backup.timeout_seconds', 0));

        try {
            while (true) {
                $read = [];

                if (! feof($pipes[1])) {
                    $read[] = $pipes[1];
                }

                if (! feof($pipes[2])) {
                    $read[] = $pipes[2];
                }

                if ($read === []) {
                    break;
                }

                $write = null;
                $except = null;
                $changed = @stream_select($read, $write, $except, 5);

                if ($changed === false) {
                    throw new RuntimeException('Lecture du flux mysqldump impossible.');
                }

                if ($changed === 0) {
                    if ($timeoutSeconds > 0 && time() - $startedAt >= $timeoutSeconds) {
                        proc_terminate($process);

                        throw new RuntimeException("Timeout sauvegarde depasse ({$timeoutSeconds}s).");
                    }

                    continue;
                }

                foreach ($read as $stream) {
                    $buffer = fread($stream, 8192);

                    if ($buffer === false || $buffer === '') {
                        continue;
                    }

                    if ($stream === $pipes[1]) {
                        $this->write($gzip, $buffer);
                    } elseif (strlen($stderr) < 20000) {
                        $stderr .= $buffer;
                    }
                }
            }
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
        }

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException('mysqldump/mariadb-dump a echoue : '.Str::limit(trim($stderr) ?: 'code '.$exitCode, 1000));
        }
    }

    /**
     * @return array<int, string>
     */
    private function mysqlDumpCommand(string $connectionName): array
    {
        $config = DB::connection($connectionName)->getConfig();
        $database = (string) ($config['database'] ?? DB::connection($connectionName)->getDatabaseName());

        if ($database === '') {
            throw new RuntimeException('Nom de base de donnees manquant pour la sauvegarde.');
        }

        $command = [
            $this->resolveDumpBinary(),
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--events',
            '--default-character-set='.(string) ($config['charset'] ?? 'utf8mb4'),
        ];

        $socket = (string) ($config['unix_socket'] ?? '');

        if ($socket !== '') {
            $command[] = '--socket='.$socket;
        } else {
            $command[] = '--host='.(string) ($config['host'] ?? '127.0.0.1');
            $command[] = '--port='.(string) ($config['port'] ?? '3306');
        }

        $username = (string) ($config['username'] ?? '');

        if ($username !== '') {
            $command[] = '--user='.$username;
        }

        foreach ((array) config('crm.backup.dump_options', []) as $option) {
            if (is_string($option) && $option !== '') {
                $command[] = $option;
            }
        }

        $command[] = '--databases';
        $command[] = $database;

        return $command;
    }

    /**
     * @return array<string, string>
     */
    private function mysqlDumpEnvironment(string $connectionName): array
    {
        $password = (string) (DB::connection($connectionName)->getConfig('password') ?? '');

        return $password === '' ? [] : ['MYSQL_PWD' => $password];
    }

    private function resolveDumpBinary(): string
    {
        $configured = (string) ($this->option('dump-binary') ?: config('crm.backup.dump_binary', ''));

        if ($configured !== '') {
            return $this->resolveBinaryPath($configured)
                ?? throw new RuntimeException("Binaire de dump introuvable : {$configured}");
        }

        foreach (['mariadb-dump', 'mysqldump'] as $binary) {
            $resolved = $this->resolveBinaryPath($binary);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        throw new RuntimeException('Installez mariadb-dump ou mysqldump, ou configurez CRM_BACKUP_DUMP_BINARY.');
    }

    private function resolveBinaryPath(string $binary): ?string
    {
        if (str_contains($binary, DIRECTORY_SEPARATOR)) {
            return is_executable($binary) ? $binary : null;
        }

        $resolved = shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null');
        $resolved = is_string($resolved) ? trim($resolved) : '';

        return $resolved !== '' ? $resolved : null;
    }

    /**
     * @param  resource  $gzip
     */
    private function dumpSqliteToGzip(PDO $pdo, $gzip): void
    {
        $this->writeLine($gzip, 'PRAGMA foreign_keys=OFF;');

        $tablesStatement = $pdo->query("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");

        if ($tablesStatement === false) {
            throw new RuntimeException('Lecture du schema SQLite impossible.');
        }

        while (($table = $tablesStatement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (! is_array($table)) {
                continue;
            }

            $name = (string) $table['name'];
            $identifier = $this->sqliteIdentifier($name);

            $this->writeLine($gzip);
            $this->writeLine($gzip, 'DROP TABLE IF EXISTS '.$identifier.';');
            $this->writeLine($gzip, rtrim((string) $table['sql'], ';').';');
            $this->appendInsertsToGzip($pdo, $gzip, $name, fn (string $value): string => $this->sqliteIdentifier($value));
        }

        $this->writeLine($gzip, 'PRAGMA foreign_keys=ON;');
    }

    /**
     * @param  resource  $gzip
     */
    private function appendInsertsToGzip(PDO $pdo, $gzip, string $table, callable $identifier): void
    {
        $tableIdentifier = $identifier($table);
        $statement = $pdo->query('SELECT * FROM '.$tableIdentifier);

        if ($statement === false) {
            throw new RuntimeException("Lecture de la table {$table} impossible.");
        }

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (! is_array($row)) {
                continue;
            }

            $columns = array_keys($row);
            $values = array_map(fn (mixed $value): string => $this->sqlValue($pdo, $value), array_values($row));

            $this->writeLine(
                $gzip,
                'INSERT INTO '.$tableIdentifier.' ('
                .implode(', ', array_map($identifier, $columns))
                .') VALUES ('.implode(', ', $values).');'
            );
        }
    }

    private function sqlValue(PDO $pdo, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $pdo->quote((string) $value) ?: "''";
    }

    /**
     * @param  resource  $gzip
     */
    private function writeLine($gzip, string $line = ''): void
    {
        $this->write($gzip, $line."\n");
    }

    /**
     * @param  resource  $gzip
     */
    private function write($gzip, string $contents): void
    {
        if (gzwrite($gzip, $contents) === false) {
            throw new RuntimeException('Ecriture gzip impossible.');
        }
    }

    private function sqliteIdentifier(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }

    private function verifyGzipArchive(string $path): void
    {
        $gzip = gzopen($path, 'rb');

        if ($gzip === false) {
            throw new RuntimeException('Verification gzip impossible.');
        }

        try {
            $sample = gzread($gzip, 1024);

            if (! is_string($sample) || $sample === '') {
                throw new RuntimeException('Archive gzip vide ou illisible.');
            }
        } finally {
            gzclose($gzip);
        }
    }

    private function encryptFile(string $inputPath, string $outputPath): void
    {
        if (! extension_loaded('sodium')) {
            throw new RuntimeException('Extension sodium requise pour chiffrer les sauvegardes.');
        }

        $keyMaterial = (string) config('crm.backup.encryption_key', '');

        if ($keyMaterial === '') {
            throw new RuntimeException('CRM_BACKUP_ENCRYPTION_KEY est requis quand le chiffrement est active.');
        }

        $input = fopen($inputPath, 'rb');
        $output = fopen($outputPath, 'wb');

        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }

            if (is_resource($output)) {
                fclose($output);
            }

            throw new RuntimeException('Preparation du fichier chiffre impossible.');
        }

        $key = sodium_crypto_generichash($keyMaterial, '', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);

        try {
            if (fwrite($output, $header) === false) {
                throw new RuntimeException('Ecriture de l en-tete chiffre impossible.');
            }

            while (! feof($input)) {
                $chunk = fread($input, 1024 * 1024);

                if ($chunk === false) {
                    throw new RuntimeException('Lecture du fichier a chiffrer impossible.');
                }

                if ($chunk === '' && ! feof($input)) {
                    continue;
                }

                $tag = feof($input)
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

                $encrypted = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);

                if (fwrite($output, $encrypted) === false) {
                    throw new RuntimeException('Ecriture du fichier chiffre impossible.');
                }
            }
        } finally {
            sodium_memzero($key);
            fclose($input);
            fclose($output);
        }
    }

    /**
     * @return array<int, string>
     */
    private function storeRetentionCopies(
        string $disk,
        string $basePath,
        string $fileName,
        string $localPath,
        int $weeklyKeep,
        int $monthlyKeep
    ): array {
        $paths = [
            $this->joinStoragePath($basePath, $fileName),
        ];

        if ($weeklyKeep > 0 && now()->isMonday()) {
            $paths[] = $this->joinStoragePath($basePath, 'weekly', $fileName);
        }

        if ($monthlyKeep > 0 && now()->day === 1) {
            $paths[] = $this->joinStoragePath($basePath, 'monthly', $fileName);
        }

        foreach ($paths as $path) {
            $this->putFileStream($disk, $path, $localPath);
        }

        return $paths;
    }

    private function putFileStream(string $disk, string $remotePath, string $localPath): void
    {
        $stream = fopen($localPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Lecture de la sauvegarde locale impossible.');
        }

        try {
            $stored = Storage::disk($disk)->put($remotePath, $stream);
        } finally {
            fclose($stream);
        }

        if ($stored === false) {
            throw new RuntimeException("Envoi de la sauvegarde impossible vers {$disk}:{$remotePath}.");
        }
    }

    private function pruneOldBackups(string $disk, string $path, int $keep): void
    {
        if ($keep < 1) {
            return;
        }

        $files = collect(Storage::disk($disk)->files($path))
            ->filter(fn (string $file): bool => preg_match('/\.sql\.gz(\.enc)?$/', $file) === 1)
            ->sortDesc()
            ->values();

        foreach ($files->slice($keep) as $file) {
            Storage::disk($disk)->delete($file);
        }
    }

    private function joinStoragePath(string ...$parts): string
    {
        return collect($parts)
            ->map(fn (string $part): string => trim($part, '/'))
            ->filter(fn (string $part): bool => $part !== '')
            ->implode('/');
    }

    private function recordBackupFailure(Throwable $exception, string $connectionName, string $disk, string $path): void
    {
        try {
            Log::channel('crm')->error('Sauvegarde HUB echouee', [
                'connection' => $connectionName,
                'disk' => $disk,
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);

            if (! Schema::hasTable('notification_logs')) {
                return;
            }

            CrmNotificationLog::query()->create([
                'channel' => 'monitoring',
                'recipient' => 'backup:database',
                'subject' => 'Echec sauvegarde HUB',
                'template_key' => 'backup.database.failed',
                'locale' => (string) config('crm.notifications.locale', 'fr'),
                'status' => CrmNotificationLog::STATUS_FAILED,
                'error_message' => Str::limit($exception->getMessage(), 1000),
                'payload' => [
                    'connection' => $connectionName,
                    'disk' => $disk,
                    'path' => $path,
                    'encrypted' => $this->shouldEncrypt(),
                ],
                'failed_at' => now(),
            ]);
        } catch (Throwable $loggingException) {
            report($loggingException);
        }
    }

    private function deleteTemporaryFiles(string ...$paths): void
    {
        foreach (array_unique($paths) as $path) {
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
    }
}
