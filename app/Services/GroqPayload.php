<?php

namespace App\Services;

class GroqPayload
{
    /** Translate the existing internal text/image parts; private photos stay inline. */
    public static function fromParts(string $model, array $payload): array
    {
        $content = [];
        $encodedBytes = 0;
        foreach ($payload['contents'][0]['parts'] as $part) {
            if (isset($part['text'])) {
                $content[] = ['type' => 'text', 'text' => $part['text']];
            } else {
                $inline = $part['inline_data'] ?? $part['inlineData'];
                $encodedBytes += strlen($inline['data']);
                $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:'.($inline['mime_type'] ?? $inline['mimeType']).';base64,'.$inline['data']]];
            }
        }
        if ($encodedBytes > (int) config('services.groq.max_inline_bytes', 3000000)) {
            throw new \RuntimeException('Groq inline image payload exceeds policy');
        }
        if ($encodedBytes && $model !== 'qwen/qwen3.8-27b') {
            throw new \RuntimeException('Selected Groq model does not support images');
        }
        $messages = [];
        if (isset($payload['systemInstruction'])) {
            $messages[] = ['role' => 'system', 'content' => implode("\n", array_column($payload['systemInstruction']['parts'], 'text'))];
        }
        $messages[] = ['role' => 'user', 'content' => $content];
        $config = $payload['generationConfig'];
        $wire = ['model' => $model, 'messages' => $messages, 'stream' => false, 'n' => 1,
            'temperature' => $config['temperature'],
            'max_completion_tokens' => $model === 'openai/gpt-oss-20b' ? 1024 : $config['maxOutputTokens'],
            'reasoning_effort' => $model === 'qwen/qwen3.8-27b' ? 'none' : 'low'];
        if (isset($config['responseJsonSchema'])) {
            // Existing geometry schemas contain optional fields; best-effort preserves them.
            // Application geometry validation remains mandatory after JSON decoding.
            $wire['response_format'] = ['type' => 'json_schema', 'json_schema' => ['name' => 'room_result', 'strict' => false, 'schema' => $config['responseJsonSchema']]];
        } elseif (($config['responseMimeType'] ?? '') === 'application/json') {
            $wire['response_format'] = ['type' => 'json_object'];
        }

        return $wire;
    }
}
