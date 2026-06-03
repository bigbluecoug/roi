<?php

namespace App\Services;

use App\Models\District;
use App\Models\Event;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class DistrictMatcher
{
    public function match(Event $event, array $lead): array
    {
        $organization = $this->normalize($lead['organization'] ?? '');
        $districtText = $this->leadDistrictText($lead);
        $emailDomain = $this->domainFromEmail($lead['email'] ?? null);
        $city = $this->normalize($lead['city'] ?? '');
        $stateCode = $this->districtStateCode($event->state_code);

        $best = null;
        $bestScore = 0.0;
        $bestReason = 'No district match candidate was strong enough.';

        District::query()
            ->where('state_code', $stateCode)
            ->orderByDesc('total_students')
            ->get()
            ->each(function (District $district) use ($organization, $districtText, $emailDomain, $city, &$best, &$bestScore, &$bestReason): void {
                [$score, $reason] = $this->scoreDistrict($district, $organization, $districtText, $emailDomain, $city);

                if ($score > $bestScore) {
                    $best = $district;
                    $bestScore = $score;
                    $bestReason = $reason;
                }
            });

        return [
            'district' => $bestScore >= 0.68 ? $best : null,
            'confidence' => round($bestScore, 2),
            'reason' => $bestReason,
        ];
    }

    public function normalize(string $value): string
    {
        $value = Str::ascii(Str::lower($value));
        $value = preg_replace('/[^a-z0-9\s]/', ' ', $value) ?? '';
        $value = preg_replace('/\b(school|schools|district|public|county|isd|usd|sd|no|number|the|of|and|state|board|education)\b/', ' ', $value) ?? '';
        $value = preg_replace('/\s+/', ' ', $value) ?? '';

        return trim($value);
    }

    private function scoreDistrict(District $district, string $organization, string $districtText, ?string $emailDomain, string $city): array
    {
        $names = array_filter([
            $this->normalize($district->name),
            $this->normalize((string) $district->short_name),
            $this->normalize((string) $district->nces_name),
            $this->normalize((string) $district->search_text),
        ]);

        $bestScore = 0.0;
        $reason = 'No strong text overlap.';

        foreach ($names as $name) {
            foreach ([
                ['text' => $organization, 'label' => 'Organization'],
                ['text' => $districtText, 'label' => 'Badge text'],
            ] as $candidate) {
                $text = $candidate['text'];

                if ($text !== '' && $name !== '') {
                    if (Str::contains($text, $name) || Str::contains($name, $text)) {
                        $score = min(0.96, 0.74 + min(strlen($name), strlen($text)) / 120);
                        if ($score > $bestScore) {
                            $bestScore = $score;
                            $reason = $candidate['label'].' closely matches '.$district->name.'.';
                        }
                    }

                    similar_text($text, $name, $percent);
                    $score = $percent / 100;
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $reason = $candidate['label'].' similarity matches '.$district->name.'.';
                    }
                }
            }

            if ($emailDomain && $name !== '' && Str::contains($emailDomain, Str::slug($name, ''))) {
                $bestScore = max($bestScore, 0.88);
                $reason = 'Email domain points to '.$district->name.'.';
            }
        }

        $districtCity = $this->normalize((string) $district->city);
        if ($city !== '' && $districtCity !== '' && ($city === $districtCity || Str::contains($city, $districtCity))) {
            $bestScore = min(0.99, $bestScore + 0.08);
            $reason .= ' City also matches.';
        }

        return [$bestScore, $reason];
    }

    private function leadDistrictText(array $lead): string
    {
        $pieces = [
            $lead['organization'] ?? null,
            $lead['raw_text'] ?? null,
        ];

        foreach (Arr::wrap($lead['evidence'] ?? []) as $item) {
            $pieces[] = $item;
        }

        foreach (Arr::wrap(Arr::get($lead, 'insights.district_clues', [])) as $item) {
            $pieces[] = $item;
        }

        $text = collect($pieces)
            ->filter(fn ($item) => is_scalar($item) && trim((string) $item) !== '')
            ->map(fn ($item) => (string) $item)
            ->implode(' ');

        return $this->normalize($text);
    }

    private function domainFromEmail(?string $email): ?string
    {
        if (! $email || ! Str::contains($email, '@')) {
            return null;
        }

        $domain = Str::after($email, '@');
        if (in_array($domain, ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'icloud.com'], true)) {
            return null;
        }

        return Str::slug(Str::before($domain, '.'), '');
    }

    private function districtStateCode(string $stateCode): string
    {
        if (Str::startsWith($stateCode, 'CA-')) {
            return 'CA';
        }

        return $stateCode;
    }
}
