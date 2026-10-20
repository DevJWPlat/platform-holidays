<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ClickUpService
{
    private function client(): PendingRequest
    {
        $token = trim((string) config('services.clickup.token'));

        if ($token === '') {
            throw new RuntimeException('CLICKUP_API_TOKEN is not configured.');
        }

        return Http::baseUrl(rtrim((string) config('services.clickup.base_url'), '/'))
            ->acceptJson()
            ->withHeaders([
                'Authorization' => $token,
            ])
            ->timeout(20)
            ->retry(2, 300);
    }

    public function listId(): string
    {
        $listId = trim((string) config('services.clickup.list_id'));

        if ($listId === '') {
            throw new RuntimeException('CLICKUP_LIST_ID is not configured.');
        }

        return $listId;
    }

    public function authorizedUser(): array
    {
        return $this->client()
            ->get('/user')
            ->throw()
            ->json();
    }

    public function list(): array
    {
        return $this->client()
            ->get('/list/' . $this->listId())
            ->throw()
            ->json();
    }

    public function workspaces(): array
    {
        return $this->client()
            ->get('/team')
            ->throw()
            ->json('teams', []);
    }

    public function listMembers(): array
    {
        return $this->client()
            ->get('/list/' . $this->listId() . '/member')
            ->throw()
            ->json('members', []);
    }

    public function workspaceMembers(): array
    {
        $members = [];

        foreach ($this->workspaces() as $workspace) {
            foreach (($workspace['members'] ?? []) as $member) {
                $user = $member['user'] ?? $member;

                if (! empty($user['id'])) {
                    $members[(string) $user['id']] = $user;
                }
            }
        }

        if ($members === []) {
            foreach ($this->listMembers() as $member) {
                $user = $member['user'] ?? $member;

                if (! empty($user['id'])) {
                    $members[(string) $user['id']] = $user;
                }
            }
        }

        return array_values($members);
    }

    public function memberByEmail(string $email): ?array
    {
        $needle = mb_strtolower(trim($email));

        foreach ($this->workspaceMembers() as $member) {
            if (mb_strtolower(trim((string) ($member['email'] ?? ''))) === $needle) {
                return $member;
            }
        }

        return null;
    }

    public function memberForPlatformUser(string $platformEmail, ?string $name = null): ?array
    {
        $platformEmail = mb_strtolower(trim($platformEmail));

        $emailMap = (array) config('services.clickup.user_email_map', []);
        $normalisedMap = [];

        foreach ($emailMap as $from => $to) {
            $normalisedMap[mb_strtolower(trim((string) $from))] =
                mb_strtolower(trim((string) $to));
        }

        $mappedEmail = $normalisedMap[$platformEmail] ?? $platformEmail;

        $match = $this->memberByEmail($mappedEmail);

        if ($match) {
            return $match;
        }

        // Safe fallback for accounts whose email changes again later:
        // only accept an exact ClickUp username/name match.
        $needleName = mb_strtolower(trim((string) $name));

        if ($needleName !== '') {
            foreach ($this->workspaceMembers() as $member) {
                $username = mb_strtolower(trim((string) ($member['username'] ?? '')));

                if ($username === $needleName) {
                    return $member;
                }
            }
        }

        return null;
    }
}
