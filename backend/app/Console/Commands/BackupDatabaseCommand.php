<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class BackupDatabaseCommand extends Command
{
    protected $signature = 'db:backup {--path= : Destination file (default: storage/backups/backup-<timestamp>.json.gz)}';

    protected $description = 'Write a portable gzip JSON snapshot (schema + rows) of the default database.';

    public function handle(): int
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $path = $this->option('path')
            ?: 'backups/backup-'.now()->format('Ymd-His').'.json.gz';

        $tables = $this->tables($driver);
        $snapshot = [
            'meta' => [
                'database' => $connection->getDatabaseName(),
                'driver' => $driver,
                'created_at' => now()->toIso8601String(),
                'tables' => count($tables),
            ],
            'tables' => [],
        ];

        foreach ($tables as $table) {
            $snapshot['tables'][$table] = [
                'schema' => $this->schema($driver, $table),
                'rows' => array_map(
                    fn (object $row) => (array) $row,
                    DB::table($table)->get()->all(),
                ),
            ];
        }

        $json = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Unable to encode database snapshot.');
        }

        Storage::disk('local')->put($path, gzencode($json, 6));

        $this->info(sprintf(
            'Backup written to %s (%d tables, %.1f KiB).',
            Storage::disk('local')->path($path),
            count($tables),
            Storage::disk('local')->size($path) / 1024,
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function tables(string $driver): array
    {
        if ($driver === 'sqlite') {
            return DB::table('sqlite_master')
                ->where('type', 'table')
                ->where('name', 'not like', 'sqlite_%')
                ->orderBy('name')
                ->pluck('name')
                ->all();
        }

        return array_map(
            fn (object $row) => array_values((array) $row)[0],
            DB::select('SHOW TABLES'),
        );
    }

    private function schema(string $driver, string $table): string
    {
        if ($driver === 'sqlite') {
            return (string) DB::table('sqlite_master')->where('name', $table)->value('sql');
        }

        $result = DB::select('SHOW CREATE TABLE `'.$table.'`');

        return array_values((array) $result[0])[1];
    }
}
