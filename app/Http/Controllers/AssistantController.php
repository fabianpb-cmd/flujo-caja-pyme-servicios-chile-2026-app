<?php

namespace App\Http\Controllers;

use App\Services\AssistantOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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

        $screenRoute = $this->screenRouteFromReferer($request) ?? '';

        return response()->json($this->assistant->ask($request->user(), $data, $screenRoute));
    }

    private function screenRouteFromReferer(Request $request): ?string
    {
        $referer = (string) $request->headers->get('referer');
        if ($referer === '') {
            return null;
        }

        $path = parse_url($referer, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        try {
            return Route::getRoutes()->match(Request::create($path, 'GET'))->getName();
        } catch (\Throwable) {
            return null;
        }
    }
}
