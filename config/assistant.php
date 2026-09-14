<?php

return [
    'enabled' => env('AI_ASSISTANT_ENABLED', false),
    'provider' => env('AI_ASSISTANT_PROVIDER', 'openai'),
    'model' => env('AI_ASSISTANT_MODEL', 'gpt-5.6-luna'),
    'reasoning' => env('AI_ASSISTANT_REASONING', 'low'),
    'api_key' => env('AI_ASSISTANT_API_KEY'),
    'base_url' => env('AI_ASSISTANT_BASE_URL', 'https://api.openai.com/v1'),
    'timeout' => (int) env('AI_ASSISTANT_TIMEOUT', 30),
    'max_output_tokens' => (int) env('AI_ASSISTANT_MAX_OUTPUT_TOKENS', 600),
    'per_minute' => (int) env('AI_ASSISTANT_PER_MINUTE', 10),
    'per_day' => (int) env('AI_ASSISTANT_PER_DAY', 200),
];
