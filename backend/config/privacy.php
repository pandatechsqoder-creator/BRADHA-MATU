<?php

return [
    // Retention windows in days. Audit logs are immutable and deliberately excluded.
    'notifications_days' => (int) env('RETENTION_NOTIFICATIONS_DAYS', 180),
    'read_notifications_days' => (int) env('RETENTION_READ_NOTIFICATIONS_DAYS', 90),
    'webhook_events_days' => (int) env('RETENTION_WEBHOOK_EVENTS_DAYS', 90),
    'report_runs_days' => (int) env('RETENTION_REPORT_RUNS_DAYS', 365),
    'closed_tickets_days' => (int) env('RETENTION_CLOSED_TICKETS_DAYS', 730),
    'expired_tokens_days' => (int) env('RETENTION_EXPIRED_TOKENS_DAYS', 7),

    // Marketing data. Analytics events carry no raw PII; matching Google Analytics 4's
    // maximum window keeps year-over-year comparisons available.
    'marketing_analytics_days' => (int) env('RETENTION_MARKETING_ANALYTICS_DAYS', 400),
    'marketing_conversion_days' => (int) env('RETENTION_MARKETING_CONVERSION_DAYS', 730),
    'marketing_tracking_days' => (int) env('RETENTION_MARKETING_TRACKING_DAYS', 180),
    'automation_runs_days' => (int) env('RETENTION_AUTOMATION_RUNS_DAYS', 365),
];
