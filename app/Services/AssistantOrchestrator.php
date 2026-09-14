<?php

namespace App\Services;

use App\Contracts\AiProvider;
use App\Exceptions\AssistantProviderException;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

class AssistantOrchestrator
{
    public function __construct(
        private readonly AssistantContextService $context,
        private readonly AssistantScreenGuideService $screenGuide,
        private readonly AssistantKnowledgeService $knowledge,
        private readonly AssistantBusinessContextService $business,
        private readonly AssistantResponseGuard $guard,
        private readonly AiProvider $provider,
    ) {
    }

    public function ask(User $user, array $input, string $route): array
    {
        if (! config('assistant.enabled')) {
            return ['status' => 'NOT_DEFINED', 'answer' => 'El Ayudante TDAT todavía no está habilitado.', 'source_ids' => [], 'required_information' => [], 'warnings' => []];
        }

        $this->rateLimit($user);
        $form = $this->context->build($input, $route);
        $guide = $this->screenGuide->build($form, $user);
        $rules = $this->knowledge->select($input['question'], $form);
        if ($guide === null && ! collect($rules)->contains(fn (array $rule): bool => ($rule['module'] ?? 'global') !== 'global')) {
            return $this->guard->notDefined();
        }
        $business = $this->business->forUser($user, $form['ids']);
        $allowed = array_merge(
            array_column($rules, 'id'),
            $guide ? [$guide['source_id']] : [],
            data_get($business, 'milestone_preview.source_id') ? ['CONTEXT-MILESTONE-PREVIEW'] : [],
        );

        try {
            return $this->guard->guard($this->provider->ask([
                'question' => mb_substr((string) $input['question'], 0, 2000),
                'KNOWLEDGE_RULES' => $rules,
                'FORM_CONTEXT' => $form,
                'FORM_GUIDE' => $guide,
                'BUSINESS_CONTEXT' => $business,
            ]), $allowed);
        } catch (AssistantProviderException $exception) {
            $message = $exception->getMessage() === 'timeout'
                ? 'No pude consultar el ayudante en este momento. Intenta nuevamente.'
                : 'El servicio del ayudante está temporalmente limitado.';
            return ['status' => 'NOT_DEFINED', 'answer' => $message, 'source_ids' => [], 'required_information' => [], 'warnings' => []];
        }
    }

    private function rateLimit(User $user): void
    {
        $minute = 'assistant:minute:'.$user->id;
        $day = 'assistant:day:'.$user->id;
        if (RateLimiter::tooManyAttempts($minute, (int) config('assistant.per_minute')) || RateLimiter::tooManyAttempts($day, (int) config('assistant.per_day'))) {
            abort(429, 'El Ayudante TDAT alcanzó su límite temporal de consultas.');
        }
        RateLimiter::hit($minute, 60);
        RateLimiter::hit($day, 86400);
    }
}
