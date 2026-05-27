<?php

namespace App\Services;

use App\Models\Capture;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class OpenAiLeadExtractor
{
    public function extract(string $imagePath): array
    {
        if (! config('services.openai.key')) {
            return $this->normalize([
                'raw_text' => '',
                'confidence' => ['overall' => 0],
                'warnings' => ['OpenAI API key is not configured.'],
            ]);
        }

        $absolutePath = Storage::disk('local')->path($imagePath);
        $mimeType = mime_content_type($absolutePath) ?: 'image/jpeg';
        $imageData = base64_encode(Storage::disk('local')->get($imagePath));

        $response = Http::withToken(config('services.openai.key'))
            ->connectTimeout(5)
            ->timeout(45)
            ->acceptJson()
            ->post('https://api.openai.com/v1/responses', [
                'model' => config('services.openai.model', 'gpt-5.4-mini'),
                'input' => [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->prompt(),
                        ],
                        [
                            'type' => 'input_image',
                            'image_url' => 'data:'.$mimeType.';base64,'.$imageData,
                        ],
                    ],
                ]],
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'event_lead_capture',
                        'strict' => false,
                        'schema' => $this->schema(),
                    ],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('OpenAI extraction failed: '.$response->body());
        }

        return $this->normalize($this->decodeResponse($response->json()));
    }

    public function normalize(array $payload): array
    {
        $fullName = $this->cleanString(Arr::get($payload, 'full_name'));
        $firstName = $this->cleanString(Arr::get($payload, 'first_name'));
        $lastName = $this->cleanString(Arr::get($payload, 'last_name'));

        if ($fullName && (! $firstName || ! $lastName)) {
            [$derivedFirst, $derivedLast] = $this->splitName($fullName);
            $firstName = $firstName ?: $derivedFirst;
            $lastName = $lastName ?: $derivedLast;
        }

        if (! $fullName) {
            $fullName = trim(($firstName ?? '').' '.($lastName ?? '')) ?: null;
        }

        $confidence = Arr::get($payload, 'confidence', []);
        if (! is_array($confidence)) {
            $confidence = ['overall' => (float) $confidence];
        }
        $insights = $this->cleanInsights(Arr::get($payload, 'insights', []));
        $payload['insights'] = $insights;

        return [
            'full_name' => $fullName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $this->cleanEmail(Arr::get($payload, 'email')),
            'phone' => $this->cleanString(Arr::get($payload, 'phone')),
            'title' => $this->cleanString(Arr::get($payload, 'title')),
            'organization' => $this->cleanString(Arr::get($payload, 'organization')),
            'city' => $this->cleanString(Arr::get($payload, 'city')),
            'state' => $this->cleanString(Arr::get($payload, 'state')),
            'raw_text' => $this->cleanString(Arr::get($payload, 'raw_text')),
            'confidence' => $confidence,
            'evidence' => Arr::wrap(Arr::get($payload, 'evidence', [])),
            'warnings' => Arr::wrap(Arr::get($payload, 'warnings', [])),
            'insights' => $insights,
            'ai_confidence' => round((float) Arr::get($confidence, 'overall', 0), 2),
            'extracted_payload' => $payload,
        ];
    }

    private function decodeResponse(array $body): array
    {
        $text = $body['output_text'] ?? null;

        if (! $text) {
            foreach ($body['output'] ?? [] as $item) {
                foreach ($item['content'] ?? [] as $content) {
                    $text = $content['text'] ?? $content['output_text'] ?? $text;
                }
            }
        }

        if (! is_string($text) || trim($text) === '') {
            return [];
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $text, $match)) {
            $decoded = json_decode($match[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return ['raw_text' => $text, 'confidence' => ['overall' => 0.2]];
    }

    private function prompt(): string
    {
        return implode("\n", [
            'Extract only information visibly present on this conference badge or business card.',
            'You may infer sales context only from visible clues such as title, organization name, email domain, role labels, event labels, and badge text.',
            'Do not identify a person from their face. Do not use public knowledge or web enrichment.',
            'Normalize obvious OCR spacing and casing, but leave fields null when not visible.',
            'For insights, explain useful lead context without overstating certainty.',
            'Return JSON matching the schema. Include short evidence snippets for non-null fields.',
        ]);
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => true,
            'properties' => [
                'full_name' => ['type' => ['string', 'null']],
                'first_name' => ['type' => ['string', 'null']],
                'last_name' => ['type' => ['string', 'null']],
                'email' => ['type' => ['string', 'null']],
                'phone' => ['type' => ['string', 'null']],
                'title' => ['type' => ['string', 'null']],
                'organization' => ['type' => ['string', 'null']],
                'city' => ['type' => ['string', 'null']],
                'state' => ['type' => ['string', 'null']],
                'raw_text' => ['type' => ['string', 'null']],
                'confidence' => ['type' => 'object'],
                'evidence' => ['type' => 'array', 'items' => ['type' => 'string']],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                'insights' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                    'properties' => [
                        'role_category' => ['type' => ['string', 'null']],
                        'seniority' => ['type' => ['string', 'null']],
                        'organization_type' => ['type' => ['string', 'null']],
                        'lead_priority' => ['type' => ['string', 'null']],
                        'buyer_relevance' => ['type' => ['string', 'null']],
                        'district_clues' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'suggested_follow_up' => ['type' => ['string', 'null']],
                        'missing_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'caveat' => ['type' => ['string', 'null']],
                    ],
                ],
            ],
        ];
    }

    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];
        $first = array_shift($parts) ?: null;
        $last = $parts ? implode(' ', $parts) : null;

        return [$first, $last];
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $clean = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');

        return $clean === '' ? null : $clean;
    }

    private function cleanEmail(mixed $value): ?string
    {
        $email = $this->cleanString($value);

        if (! $email) {
            return null;
        }

        $email = strtolower($email);

        return Capture::isUsableEmail($email) ? $email : null;
    }

    private function cleanInsights(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $scalarKeys = [
            'role_category',
            'seniority',
            'organization_type',
            'lead_priority',
            'buyer_relevance',
            'suggested_follow_up',
            'caveat',
        ];

        $insights = [];
        foreach ($scalarKeys as $key) {
            $insights[$key] = $this->cleanString(Arr::get($value, $key));
        }

        foreach (['district_clues', 'missing_fields'] as $key) {
            $insights[$key] = collect(Arr::wrap(Arr::get($value, $key)))
                ->map(fn ($item) => $this->cleanString($item))
                ->filter()
                ->values()
                ->all();
        }

        return collect($insights)
            ->reject(fn ($item) => blank($item))
            ->all();
    }
}
