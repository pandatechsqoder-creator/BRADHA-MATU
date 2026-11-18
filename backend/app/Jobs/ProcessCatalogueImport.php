<?php

namespace App\Jobs;

use App\Models\CatalogueImport;
use App\Services\Catalogue\CatalogueImportProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessCatalogueImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $importId) {}

    public function handle(CatalogueImportProcessor $processor): void
    {
        $import = CatalogueImport::withoutGlobalScopes()->find($this->importId);
        if ($import === null) {
            return;
        }

        $processor->process($import);
    }

    public function failed(?\Throwable $exception): void
    {
        CatalogueImport::withoutGlobalScopes()
            ->whereKey($this->importId)
            ->update([
                'status' => 'failed',
                'error_message' => $exception?->getMessage() ?? 'The import could not be processed.',
                'updated_at' => now(),
            ]);
    }
}
