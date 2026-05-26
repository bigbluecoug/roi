<?php

namespace Database\Seeders;

use App\Models\District;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DistrictSeeder extends Seeder
{
    private const STATE_NAMES = [
        'CO' => 'Colorado',
        'UT' => 'Utah',
        'TX' => 'Texas',
        'CA' => 'California',
        'IL' => 'Illinois',
        'GA' => 'Georgia',
        'FL' => 'Florida',
        'MO' => 'Missouri',
        'OK' => 'Oklahoma',
    ];

    public function run(): void
    {
        foreach ($this->districtsFromRoiTool() as $stateCode => $districts) {
            if (! array_key_exists($stateCode, self::STATE_NAMES)) {
                continue;
            }

            foreach ($districts as $district) {
                $leaId = $district['leaId'] ?? $stateCode.'-'.Str::slug($district['name'] ?? Str::random(8));

                District::updateOrCreate(
                    ['state_code' => $stateCode, 'lea_id' => $leaId],
                    [
                        'state_name' => self::STATE_NAMES[$stateCode],
                        'name' => $district['name'] ?? $district['ncesName'] ?? 'Unnamed District',
                        'short_name' => $district['short'] ?? null,
                        'nces_name' => $district['ncesName'] ?? null,
                        'city' => $district['city'] ?? null,
                        'lea_type' => $district['leaType'] ?? null,
                        'total_students' => (int) ($district['total'] ?? 0),
                        'secondary_students' => (int) ($district['secondary'] ?? 0),
                        'latitude' => $district['lat'] ?? null,
                        'longitude' => $district['lon'] ?? null,
                        'search_text' => $this->searchText($district),
                    ],
                );
            }
        }
    }

    private function districtsFromRoiTool(): array
    {
        $path = collect([
            base_path('colorado-event-roi.html'),
            base_path('../colorado-event-roi.html'),
        ])->first(fn (string $candidate) => is_file($candidate));

        if (! is_file($path)) {
            return [];
        }

        $html = file_get_contents($path);
        if (! is_string($html)) {
            return [];
        }

        $start = strpos($html, 'const NCES_DISTRICTS = ');
        if ($start === false) {
            return [];
        }

        $jsonStart = strpos($html, '{', $start);
        if ($jsonStart === false) {
            return [];
        }

        $jsonEnd = $this->closingBracePosition($html, $jsonStart);
        if ($jsonEnd === null) {
            return [];
        }

        $json = substr($html, $jsonStart, $jsonEnd - $jsonStart + 1);
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function closingBracePosition(string $source, int $start): ?int
    {
        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($source);

        for ($i = $start; $i < $length; $i++) {
            $char = $source[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;

                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;

                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;

                continue;
            }

            if ($char === '{') {
                $depth++;
            }

            if ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    private function searchText(array $district): string
    {
        return trim(implode(' ', array_filter([
            $district['leaId'] ?? null,
            $district['name'] ?? null,
            $district['short'] ?? null,
            $district['ncesName'] ?? null,
            $district['city'] ?? null,
            $district['leaType'] ?? null,
        ])));
    }
}
