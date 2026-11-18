<?php

namespace Database\Seeders;

use App\Models\ProcedureTemplate;
use App\Models\ProcedureTemplateVersion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Local/staging demo data ONLY. Shared with every local environment.
 */
class SopSeeder extends Seeder
{
    public function run(): void
    {
        $creator = User::where('email', 'superadmin@brathamatu.test')->value('id');

        DB::transaction(function () use ($creator): void {
            $template = ProcedureTemplate::firstOrCreate(
                ['slug' => 'standard-retail-stock-count'],
                [
                    'tenant_id' => null,
                    'name' => 'Standard retail stock count SOP',
                    'description' => 'Baseline procedure for quantity-only retail stock counts performed by BRATHAMATU stock takers.',
                    'category' => 'stocktake',
                    'status' => 'active',
                    'created_by' => $creator,
                ],
            );

            if ($template->versions()->exists()) {
                return;
            }

            $version = ProcedureTemplateVersion::create([
                'template_id' => $template->id,
                'version' => 1,
                'title' => 'Standard retail stock count SOP',
                'summary' => 'Read before arriving on site. Count physical quantities only, never expected quantities or tenant pricing.',
                'steps' => $this->steps(),
                'checklists' => $this->checklists(),
                'require_acknowledgement' => true,
                'status' => 'published',
                'published_at' => now(),
                'published_by' => $creator,
            ]);

            $template->update([
                'status' => 'active',
                'current_version_id' => $version->id,
            ]);
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function steps(): array
    {
        return [
            [
                'title' => 'Review the job scope and site safety',
                'instructions' => 'Confirm locations, zones, count method and the site safety briefing before touching any stock.',
                'required' => true,
                'evidence_required' => false,
            ],
            [
                'title' => 'Verify team check-in and zone allocation',
                'instructions' => 'Every counter must be checked in on site and assigned a zone by the lead before counting starts.',
                'required' => true,
                'evidence_required' => false,
            ],
            [
                'title' => 'Count physical quantities only',
                'instructions' => 'Record what is physically present. Never edit the catalogue, expected quantities, tenant charges or pricing.',
                'required' => true,
                'evidence_required' => false,
            ],
            [
                'title' => 'Report exceptions as you find them',
                'instructions' => 'Raise an exception for damaged, expired, unidentified or misplaced stock instead of silently adjusting counts.',
                'required' => true,
                'evidence_required' => false,
            ],
            [
                'title' => 'Capture evidence and hand over',
                'instructions' => 'Capture required evidence for sampled zones and hand over completed sheets or device records to the supervisor.',
                'required' => true,
                'evidence_required' => true,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function checklists(): array
    {
        return [
            [
                'name' => 'Pre-count readiness',
                'kind' => 'pre_start',
                'required' => true,
                'items' => [
                    ['label' => 'Store manager or authorised representative is present', 'response_type' => 'boolean', 'required' => true],
                    ['label' => 'Counting zones and team allocations confirmed', 'response_type' => 'boolean', 'required' => true],
                    ['label' => 'Safety briefing completed (exits, hazards, PPE)', 'response_type' => 'boolean', 'required' => true],
                    ['label' => 'Mobile device charged and count mode ready', 'response_type' => 'boolean', 'required' => true],
                    ['label' => 'Site access notes or restrictions', 'response_type' => 'text', 'required' => false],
                ],
            ],
            [
                'name' => 'Site close-out',
                'kind' => 'close_out',
                'required' => false,
                'items' => [
                    ['label' => 'All assigned zones counted and submitted', 'response_type' => 'boolean', 'required' => true],
                    ['label' => 'Exceptions raised for damaged or unknown items', 'response_type' => 'boolean', 'required' => true],
                    ['label' => 'Records reconciled with the supervisor', 'response_type' => 'boolean', 'required' => true],
                    ['label' => 'Handover notes', 'response_type' => 'text', 'required' => false],
                ],
            ],
        ];
    }
}
