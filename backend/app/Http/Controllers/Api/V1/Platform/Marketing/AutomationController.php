<?php

namespace App\Http\Controllers\Api\V1\Platform\Marketing;

use App\Exceptions\MarketingIntegrationException;
use App\Http\Controllers\Controller;
use App\Models\Marketing\AutomationStep;
use App\Models\Marketing\AutomationWorkflow;
use App\Models\Marketing\MarketingLead;
use App\Services\Marketing\AutomationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AutomationController extends Controller
{
    public function __construct(private readonly AutomationService $automation) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'settings' => $this->automation->catalogue(),
            'workflows' => $this->automation->workflows()
                ->map(fn (AutomationWorkflow $workflow) => $this->automation->present($workflow))
                ->all(),
        ]);
    }

    public function show(AutomationWorkflow $workflow): JsonResponse
    {
        return ApiResponse::success(['workflow' => $this->automation->present($workflow)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $workflow = $this->automation->store($data, $request->user());

        return ApiResponse::success(['workflow' => $this->automation->present($workflow)], 201);
    }

    public function update(AutomationWorkflow $workflow, Request $request): JsonResponse
    {
        $data = $this->validated($request, false);

        try {
            $workflow = $this->automation->update($workflow, $data, $request->user());
        } catch (MarketingIntegrationException $exception) {
            return ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->status);
        }

        return ApiResponse::success(['workflow' => $this->automation->present($workflow)]);
    }

    public function activate(AutomationWorkflow $workflow, Request $request): JsonResponse
    {
        try {
            $workflow = $this->automation->activate($workflow, $request->user());
        } catch (MarketingIntegrationException $exception) {
            return ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->status);
        }

        return ApiResponse::success(['workflow' => $this->automation->present($workflow)]);
    }

    public function pause(AutomationWorkflow $workflow, Request $request): JsonResponse
    {
        return ApiResponse::success([
            'workflow' => $this->automation->present($this->automation->pause($workflow, $request->user())),
        ]);
    }

    public function executions(AutomationWorkflow $workflow, Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 50);

        return ApiResponse::success($this->automation->executions($workflow, $limit));
    }

    public function killSwitch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $enabled = $this->automation->setEnabled((bool) $data['enabled'], $request->user());

        return ApiResponse::success([
            'settings' => $this->automation->catalogue(),
            'enabled' => $enabled,
        ]);
    }

    public function runDue(): JsonResponse
    {
        $processed = $this->automation->processDue();

        return ApiResponse::success([
            'processed' => $processed,
            'enabled' => $this->automation->enabled(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $requireSteps = true): array
    {
        $data = $request->validate([
            'name' => [$requireSteps ? 'required' : 'sometimes', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:2000'],
            'trigger' => [$requireSteps ? 'required' : 'sometimes', Rule::in(array_keys(AutomationWorkflow::TRIGGERS))],
            'conditions' => ['nullable', 'array'],
            'conditions.lead_type' => ['nullable', 'array'],
            'conditions.lead_type.*' => [Rule::in(MarketingLead::TYPES)],
            'conditions.source' => ['nullable', 'string', 'max:40'],
            'conditions.utm_campaign' => ['nullable', 'string', 'max:190'],
            'steps' => [$requireSteps ? 'required' : 'sometimes', 'array', 'min:1', 'max:10'],
            'steps.*.type' => ['required', Rule::in(AutomationStep::TYPES)],
            'steps.*.config' => ['nullable', 'array'],
        ]);

        if (! array_key_exists('steps', $data)) {
            return $data;
        }

        $errors = [];

        foreach ($data['steps'] as $index => $step) {
            $config = $step['config'] ?? [];

            switch ($step['type']) {
                case 'wait':
                    $minutes = $config['minutes'] ?? null;
                    if (! is_numeric($minutes) || (int) $minutes < 1 || (int) $minutes > 43200) {
                        $errors["steps.{$index}.config.minutes"] = 'A wait step needs whole minutes between 1 and 43200 (30 days).';
                    } else {
                        $config['minutes'] = (int) $minutes;
                    }
                    break;

                case 'notify_internal':
                    if (! in_array($config['recipients'] ?? 'lead_managers', ['lead_owner', 'lead_managers'], true)) {
                        $errors["steps.{$index}.config.recipients"] = 'Choose the lead owner or the lead managers as recipients.';
                    }
                    if (trim((string) ($config['title'] ?? '')) === '' || mb_strlen((string) $config['title']) > 160) {
                        $errors["steps.{$index}.config.title"] = 'An in-app notification needs a title of at most 160 characters.';
                    }
                    if (isset($config['body']) && mb_strlen((string) $config['body']) > 1000) {
                        $errors["steps.{$index}.config.body"] = 'The notification body must be at most 1000 characters.';
                    }
                    break;

                case 'notify_external':
                    if (! in_array($config['channel'] ?? null, AutomationStep::EXTERNAL_CHANNELS, true)) {
                        $errors["steps.{$index}.config.channel"] = 'Choose email, SMS or WhatsApp.';
                    }
                    if (trim((string) ($config['title'] ?? '')) === '' || mb_strlen((string) $config['title']) > 160) {
                        $errors["steps.{$index}.config.title"] = 'A message needs a subject of at most 160 characters.';
                    }
                    if (isset($config['body']) && mb_strlen((string) $config['body']) > 2000) {
                        $errors["steps.{$index}.config.body"] = 'The message body must be at most 2000 characters.';
                    }
                    break;

                case 'add_activity':
                    $body = trim((string) ($config['body'] ?? ''));
                    if ($body === '' || mb_strlen($body) > 2000) {
                        $errors["steps.{$index}.config.body"] = 'An activity step needs a note of at most 2000 characters.';
                    }
                    break;
            }

            $data['steps'][$index]['config'] = $config;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }
}
