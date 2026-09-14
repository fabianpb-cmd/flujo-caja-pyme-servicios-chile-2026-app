<?php

namespace App\Http\Controllers;

use App\Services\AssistantOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantController extends Controller
{
    public function __construct(private readonly AssistantOrchestrator $assistant)
    {
    }

    public function ask(Request $request): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'route' => ['nullable', 'string', 'max:150'],
            'resource' => ['nullable', 'string', 'max:100'],
            'focused_field' => ['nullable', 'string', 'max:100'],
            'form' => ['nullable', 'array'],
            'history' => ['nullable', 'array', 'max:6'],
            'history.*.question' => ['nullable', 'string', 'max:2000'],
            'history.*.answer' => ['nullable', 'string', 'max:2000'],
        ]);

        $screenRoute = (string) ($data['route'] ?? $request->route()?->getName());

        return response()->json($this->assistant->ask($request->user(), $data, $screenRoute));
    }
}
