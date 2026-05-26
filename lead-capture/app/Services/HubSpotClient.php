<?php

namespace App\Services;

use App\Models\Capture;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class HubSpotClient
{
    public function configured(): bool
    {
        return filled(config('services.hubspot.token'));
    }

    public function syncCapture(Capture $capture): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('HubSpot access token is not configured.');
        }

        if (! $capture->readyForHubSpot()) {
            throw new RuntimeException('Capture needs an email, organization, and confirmed district before HubSpot sync.');
        }

        $company = $this->findCompany($capture->organization, $capture->email)
            ?: $this->createCompany($capture);

        $contact = $this->findContactByEmail($capture->email);
        $incomingContact = $this->contactProperties($capture);

        if ($contact) {
            $missing = $this->missingOnlyProperties($contact['properties'] ?? [], $incomingContact);
            if ($missing !== []) {
                $contact = $this->updateContact($contact['id'], $missing);
            }
        } else {
            $contact = $this->createContact($incomingContact);
        }

        $this->associate('contact', $contact['id'], 'company', $company['id']);
        $note = $this->createNote($capture, $contact['id'], $company['id']);

        return [
            'contact_id' => (string) $contact['id'],
            'company_id' => (string) $company['id'],
            'note_id' => (string) $note['id'],
        ];
    }

    public function lookupLeadContext(?string $email, ?string $organization): array
    {
        if (! $this->configured()) {
            return ['contact' => null, 'company' => null];
        }

        $contact = $this->findContactByEmail($email);
        $company = $this->findCompany($organization ?: ($contact['properties']['company'] ?? null), $email);

        return [
            'contact' => $contact,
            'company' => $company,
        ];
    }

    public function findContactByEmail(?string $email): ?array
    {
        if (! $email || ! $this->configured()) {
            return null;
        }

        $payload = [
            'filterGroups' => [[
                'filters' => [[
                    'propertyName' => 'email',
                    'operator' => 'EQ',
                    'value' => strtolower($email),
                ]],
            ]],
            'properties' => ['email', 'firstname', 'lastname', 'jobtitle', 'phone', 'company'],
            'limit' => 1,
        ];

        return $this->firstResult($this->request()->post('/crm/v3/objects/contacts/search', $payload)->json());
    }

    public function findCompany(?string $name, ?string $email = null): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        $domain = $this->businessDomain($email);
        if ($domain) {
            $byDomain = $this->searchCompany('domain', $domain, 'EQ');
            if ($byDomain) {
                return $byDomain;
            }
        }

        if ($name) {
            return $this->searchCompany('name', $name, 'CONTAINS_TOKEN');
        }

        return null;
    }

    public function missingOnlyProperties(array $current, array $incoming): array
    {
        return collect($incoming)
            ->filter(fn ($value) => filled($value))
            ->reject(fn ($value, $key) => filled($current[$key] ?? null))
            ->all();
    }

    private function searchCompany(string $property, string $value, string $operator): ?array
    {
        $payload = [
            'filterGroups' => [[
                'filters' => [[
                    'propertyName' => $property,
                    'operator' => $operator,
                    'value' => $value,
                ]],
            ]],
            'properties' => ['name', 'domain'],
            'limit' => 1,
        ];

        return $this->firstResult($this->request()->post('/crm/v3/objects/companies/search', $payload)->json());
    }

    private function createCompany(Capture $capture): array
    {
        $properties = [
            'name' => $capture->organization ?: $capture->district?->name,
        ];

        if ($domain = $this->businessDomain($capture->email)) {
            $properties['domain'] = $domain;
        }

        return $this->request()
            ->post('/crm/v3/objects/companies', ['properties' => $properties])
            ->throw()
            ->json();
    }

    private function createContact(array $properties): array
    {
        return $this->request()
            ->post('/crm/v3/objects/contacts', ['properties' => $properties])
            ->throw()
            ->json();
    }

    private function updateContact(string $id, array $properties): array
    {
        return $this->request()
            ->patch('/crm/v3/objects/contacts/'.$id, ['properties' => $properties])
            ->throw()
            ->json();
    }

    private function createNote(Capture $capture, string $contactId, string $companyId): array
    {
        $note = $this->request()
            ->post('/crm/v3/objects/notes', [
                'properties' => [
                    'hs_timestamp' => now()->toIso8601String(),
                    'hs_note_body' => $this->noteBody($capture),
                ],
            ])
            ->throw()
            ->json();

        $this->associate('0-46', $note['id'], 'contact', $contactId);
        $this->associate('0-46', $note['id'], 'company', $companyId);

        return $note;
    }

    private function associate(string $fromType, string $fromId, string $toType, string $toId): void
    {
        $this->request()
            ->put("/crm/v4/objects/{$fromType}/{$fromId}/associations/default/{$toType}/{$toId}")
            ->throw();
    }

    private function contactProperties(Capture $capture): array
    {
        return [
            'email' => strtolower((string) $capture->email),
            'firstname' => $capture->first_name,
            'lastname' => $capture->last_name,
            'jobtitle' => $capture->title,
            'phone' => $capture->phone,
            'company' => $capture->organization,
        ];
    }

    private function noteBody(Capture $capture): string
    {
        return trim(implode("\n", array_filter([
            'Event: '.$capture->event->name,
            'District: '.$capture->district?->name,
            'Organization: '.$capture->organization,
            'Follow-up status: '.$capture->follow_up_status,
            $capture->match_confidence !== null ? 'District match confidence: '.$capture->match_confidence : null,
            $capture->ai_confidence !== null ? 'AI extraction confidence: '.$capture->ai_confidence : null,
            $capture->rep_notes ? 'Rep notes: '.$capture->rep_notes : null,
            $capture->aiInsightSummary() ? 'AI badge clues: '.implode(' | ', $capture->aiInsightSummary()) : null,
            $capture->publicEnrichmentSummary() ? 'Public email search: '.$capture->publicEnrichmentSummary() : null,
            $capture->raw_text ? 'Visible text: '.$capture->raw_text : null,
        ])));
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl('https://api.hubapi.com')
            ->withToken(config('services.hubspot.token'))
            ->acceptJson()
            ->asJson()
            ->timeout(30);
    }

    private function firstResult(?array $payload): ?array
    {
        $result = Arr::first($payload['results'] ?? []);

        return is_array($result) ? $result : null;
    }

    private function businessDomain(?string $email): ?string
    {
        if (! $email || ! Str::contains($email, '@')) {
            return null;
        }

        $domain = strtolower(Str::after($email, '@'));

        return in_array($domain, ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'icloud.com'], true)
            ? null
            : $domain;
    }
}
