<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDO;

final class RestoreDatabaseCommand extends Command
{
    protected $signature = 'db:restore
        {file : Snapshot path relative to storage/app/private or absolute}
        {--database= : Restore into this database (created when missing; use for restore drills)}
        {--table-prefix= : Restore into prefixed staging tables in the active database (non-destructive drill)}
        {--verify : Compare restored row counts against the snapshot}
        {--drop : Drop staged tables after verification}
        {--force : Allow restoring over the active/production database}';

    protected $description = 'Restore a db:backup snapshot into the default or a scratch database.';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        $absolute = str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)
            ? $path
            : Storage::disk('local')->path($path);

        if (! is_file($absolute)) {
            $this->error("Snapshot not found: {$absolute}");

            return self::FAILURE;
        }

        $snapshot = json_decode((string) gzdecode((string) file_get_contents($absolute)), true);
        if (! is_array($snapshot) || ! isset($snapshot['tables'])) {
            $this->error('Snapshot is not a valid db:backup file.');

            return self::FAILURE;
        }

        $prefix = (string) ($this->option('table-prefix') ?? '');
        if ($prefix !== '' && ! preg_match('/^[A-Za-z0-9_]+$/', $prefix)) {
            $this->error('Table prefix may only contain letters, digits and underscores.');

            return self::FAILURE;
        }

        $targetDatabase = $this->option('database');
        $connectionName = 'restore_target';

        if ($targetDatabase !== null && ! preg_match('/^[A-Za-z0-9_]+$/', $targetDatabase)) {
            $this->error('Database name may only contain letters, digits and underscores.');

            return self::FAILURE;
        }

        if ($targetDatabase === null) {
            $targetDatabase = DB::connection()->getDatabaseName();
            $overwritingActive = true;
        } else {
            $overwritingActive = $targetDatabase === DB::connection()->getDatabaseName();
        }

        $staging = $prefix !== '';
        if (! $staging && ($overwritingActive || app()->environment('production')) && ! $this->option('force')) {
            $this->error('Refusing to overwrite an active database without --force.');

            return self::FAILURE;
        }

        $config = config('database.connections.'.config('database.default'));
        if ($config['driver'] !== 'mysql') {
            $this->error('db:restore currently supports MySQL targets only.');

            return self::FAILURE;
        }

        if (! $staging) {
            $this->createDatabaseIfMissing($config, $targetDatabase);
        }

        $config['database'] = $targetDatabase;
        config(['database.connections.'.$connectionName => $config]);
        DB::purge($connectionName);

        $connection = DB::connection($connectionName);
        $connection->statement('SET FOREIGN_KEY_CHECKS=0');

        $failures = [];
        foreach ($snapshot['tables'] as $table => $payload) {
            $target = $prefix.$table;
            $schema = $payload['schema'];
            if ($staging) {
                $schema = (string) preg_replace('/^CREATE TABLE `[^`]+`/', 'CREATE TABLE `'.$target.'`', $schema, 1);
                // Constraint names are database-global in MySQL, so staged copies must drop FKs.
                $schema = (string) preg_replace(
                    '/,\s*\n\s*CONSTRAINT `[^`]+` FOREIGN KEY \([^)]*\) REFERENCES `[^`]+` \([^)]*\)(?: ON DELETE [A-Z ]+)?(?: ON UPDATE [A-Z ]+)?/i',
                    '',
                    $schema,
                );
            }

            $connection->statement('DROP TABLE IF EXISTS `'.$target.'`');
            $connection->statement($schema);

            foreach (array_chunk($payload['rows'], 500) as $chunk) {
                $connection->table($target)->insert($chunk);
            }

            if ($this->option('verify') && $connection->table($target)->count() !== count($payload['rows'])) {
                $failures[] = $table;
            }

            if ($staging && $this->option('drop')) {
                $connection->statement('DROP TABLE IF EXISTS `'.$target.'`');
            }
        }

        $connection->statement('SET FOREIGN_KEY_CHECKS=1');
        DB::purge($connectionName);

        $counts = collect($snapshot['tables'])
            ->map(fn (array $payload) => count($payload['rows']))
            ->sum();

        if ($failures !== []) {
            $this->error('Row-count mismatch after restore: '.implode(', ', $failures));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Restored %d tables / %d rows into `%s`%s from %s%s.',
            count($snapshot['tables']),
            $counts,
            $targetDatabase,
            $staging ? ' as `'.$prefix.'*` staging tables' : '',
            basename($absolute),
            $this->option('verify') ? ' (verified)' : '',
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function createDatabaseIfMissing(array $config, string $database): void
    {
        $dsn = sprintf('mysql:host=%s;port=%s', $config['host'], $config['port']);
        $pdo = new PDO($dsn, (string) $config['username'], (string) $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $pdo->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $database,
        ));
    }
}
