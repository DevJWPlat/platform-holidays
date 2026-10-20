<?php

namespace App\Console\Commands;

use App\Models\Organisation;
use App\Models\User;
use App\Services\ClickUpService;
use Illuminate\Console\Command;
use Throwable;

class TestClickUpConnection extends Command
{
    protected $signature = 'holidays:clickup-test';

    protected $description = 'Check the ClickUp connection, target List, and Platform staff mapping';

    public function handle(ClickUpService $clickUp): int
    {
        try {
            $account = $clickUp->authorizedUser();
            $list = $clickUp->list();
            $members = $clickUp->workspaceMembers();
        } catch (Throwable $exception) {
            $this->error('ClickUp connection failed: ' . $exception->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('ClickUp connection OK');
        $this->line('Authenticated as: ' . ($account['user']['username'] ?? $account['user']['email'] ?? 'Unknown'));
        $this->line('Target list: ' . ($list['name'] ?? 'Unknown') . ' (' . $clickUp->listId() . ')');
        $this->line('ClickUp members visible to API: ' . count($members));

        $organisation = Organisation::query()
            ->where('slug', 'platform')
            ->first();

        if (! $organisation) {
            $this->warn('Platform organisation not found in this database.');
            return self::SUCCESS;
        }

        $users = User::query()
            ->where('organisation_id', $organisation->id)
            ->where('is_archived', false)
            ->orderBy('name')
            ->get(['name', 'email']);

        $rows = [];
        $missing = 0;

        foreach ($users as $user) {
            $match = $clickUp->memberForPlatformUser($user->email, $user->name);

            if (! $match) {
                $missing++;
            }

            $rows[] = [
                $user->name,
                $user->email,
                $match ? 'MATCHED' : 'MISSING',
                $match['id'] ?? '—',
                $match
                    ? (($match['username'] ?? '—') . ' / ' . ($match['email'] ?? '—'))
                    : '—',
            ];
        }

        $this->newLine();
        $this->table(
            ['Platform person', 'Platform email', 'Status', 'ClickUp user ID', 'ClickUp account'],
            $rows,
        );

        if ($missing > 0) {
            $this->warn(
                $missing . ' Platform user(s) could not be matched by email. ' .
                'No tasks have been created or changed.'
            );
            return self::SUCCESS;
        }

        $this->info('Every active Platform user matched to a ClickUp account.');
        $this->comment('No ClickUp tasks were created or changed by this test.');

        return self::SUCCESS;
    }
}
