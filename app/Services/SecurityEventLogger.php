<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SecurityEventLogger
{
    public function record(string $event, Request $request, ?User $user = null, ?string $email = null): void
    {
        $context = [
            'event' => $event,
            'ip' => $this->sanitize((string) $request->ip(), 64),
            'user_agent' => $this->sanitize((string) $request->userAgent(), 255),
        ];

        if ($user) {
            $context['user_id'] = $user->getKey();
        }

        if ($email !== null) {
            $context['email_hash'] = hash('sha256', Str::lower(trim($email)));
        }

        Log::info('security_event', $context);
    }

    private function sanitize(string $value, int $limit): string
    {
        return mb_substr((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value), 0, $limit);
    }
}
