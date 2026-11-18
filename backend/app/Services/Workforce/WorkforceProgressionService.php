<?php

namespace App\Services\Workforce;

use App\Models\StockTakerProfile;

/**
 * Configurable, conservative progression rules. Levels only move up
 * automatically on verified evidence; managers may override with an audit reason.
 */
final class WorkforceProgressionService
{
    public const REQUIRED_TRAINING = ['core_induction'];

    private const CORE_LEVELS = ['applicant', 'trainee', 'verified'];

    /**
     * @return array{from: string, to: string, reason: string}|null
     */
    public function evaluate(StockTakerProfile $profile): ?array
    {
        $from = $profile->level;
        $trainingComplete = $profile->training_completed_at !== null
            && $profile->trainings()->whereIn('module', self::REQUIRED_TRAINING)->where('status', 'completed')->exists();

        $to = $from;

        if ($profile->verification_status === 'verified' && $trainingComplete) {
            $to = match (true) {
                $this->atLeast($from, 'professional') => $from,
                $profile->completed_jobs_count >= 15 && (float) $profile->verified_accuracy >= 97 => 'expert',
                $profile->completed_jobs_count >= 5 && (float) $profile->verified_accuracy >= 95 => 'professional',
                default => 'verified',
            };
        } elseif ($trainingComplete && in_array($from, ['applicant', 'trainee'], true)) {
            $to = 'trainee';
        }

        if ($to === $from) {
            return null;
        }

        $profile->level = $to;
        $profile->save();

        return [
            'from' => $from,
            'to' => $to,
            'reason' => match ($to) {
                'verified' => 'Identity, phone and core training verified.',
                'trainee' => 'Core induction completed; identity verification outstanding.',
                'professional' => 'Verified record with at least 5 completed jobs and 95% verified accuracy.',
                'expert' => 'Verified record with at least 15 completed jobs and 97% verified accuracy.',
                default => 'Progression criteria met.',
            },
        ];
    }

    public function trainingComplete(StockTakerProfile $profile): bool
    {
        return $profile->training_completed_at !== null
            && $profile->trainings()->whereIn('module', self::REQUIRED_TRAINING)->where('status', 'completed')->exists();
    }

    private function atLeast(string $current, string $target): bool
    {
        $rank = array_flip(StockTakerProfile::LEVELS);

        return ($rank[$current] ?? 0) >= ($rank[$target] ?? 0) && ! in_array($current, self::CORE_LEVELS, true);
    }
}
