<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PurgeRetentionCommand extends Command
{
    protected $signature = 'retention:purge {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Delete records past their privacy retention window (audit logs are never purged).';

    public function handle(AuditLogger $audit): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $counts = [
            'notifications' => $this->notifications($dryRun),
            'webhook_events' => $this->aged('integration_webhook_events', config('privacy.webhook_events_days'), $dryRun),
            'report_runs' => $this->aged('report_runs', config('privacy.report_runs_days'), $dryRun),
            'closed_tickets' => $this->closedTickets($dryRun),
            'expired_tokens' => $this->expiredTokens($dryRun),
            'marketing_analytics_events' => $this->agedBy('analytics_events', 'occurred_at', config('privacy.marketing_analytics_days'), $dryRun),
            'marketing_conversion_events' => $this->agedBy('conversion_events', 'occurred_at', config('privacy.marketing_conversion_days'), $dryRun),
            'marketing_tracking_preferences' => $this->agedBy('tracking_preferences', 'updated_at', config('privacy.marketing_tracking_days'), $dryRun),
            'automation_runs' => $this->aged('automation_runs', config('privacy.automation_runs_days'), $dryRun),
        ];

        $this->table(
            ['Table', $dryRun ? 'Would delete' : 'Deleted'],
            collect($counts)->map(fn (int $count, string $table) => [$table, $count])->values()->all(),
        );

        if (! $dryRun) {
            $audit->log(
                'retention.purged',
                null,
                'Scheduled privacy retention run.',
                null,
                $counts,
                null,
                null,
            );
        }

        return self::SUCCESS;
    }

    private function notifications(bool $dryRun): int
    {
        $cutoff = now()->subDays((int) config('privacy.notifications_days'));
        $readCutoff = now()->subDays((int) config('privacy.read_notifications_days'));

        $query = DB::table('notifications')->where(function ($q) use ($cutoff, $readCutoff): void {
            $q->where('created_at', '<', $cutoff)
                ->orWhere(fn ($read) => $read->whereNotNull('read_at')->where('read_at', '<', $readCutoff));
        });

        return $dryRun ? $query->count() : $query->delete();
    }

    private function aged(string $table, int $days, bool $dryRun): int
    {
        return $this->agedBy($table, 'created_at', $days, $dryRun);
    }

    private function agedBy(string $table, string $column, int $days, bool $dryRun): int
    {
        $query = DB::table($table)->where($column, '<', now()->subDays($days));

        return $dryRun ? $query->count() : $query->delete();
    }

    private function closedTickets(bool $dryRun): int
    {
        $query = DB::table('support_tickets')
            ->where('status', 'closed')
            ->where('updated_at', '<', now()->subDays((int) config('privacy.closed_tickets_days')));

        return $dryRun ? $query->count() : $query->delete();
    }

    private function expiredTokens(bool $dryRun): int
    {
        $query = DB::table('personal_access_tokens')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now()->subDays((int) config('privacy.expired_tokens_days')));

        return $dryRun ? $query->count() : $query->delete();
    }
}
