<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\MessageAttachment;
use App\Models\UserProfile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneOrphanedFiles extends Command
{
    protected $signature = 'storage:prune-orphaned-files {--dry-run : List files without deleting them}';

    protected $description = 'Delete MinIO objects that are no longer referenced by avatars or message attachments';

    public function handle(): int
    {
        $disk = Storage::disk('minio');

        $referenced = collect()
            ->merge(UserProfile::whereNotNull('avatar_path')->pluck('avatar_path'))
            ->merge(Group::whereNotNull('avatar_path')->pluck('avatar_path'))
            ->merge(MessageAttachment::query()->pluck('storage_path'))
            ->flip();

        // Only touch paths this application owns; a shared bucket may hold
        // unrelated objects that must never be removed here.
        $objects = collect($disk->allFiles())->filter(
            fn (string $path) => str_starts_with($path, 'avatars/') || str_starts_with($path, 'conversations/'),
        );

        $orphans = $objects->reject(fn (string $path) => $referenced->has($path))->values();
        $dryRun = (bool) $this->option('dry-run');

        foreach ($orphans as $path) {
            $this->line($path);
        }

        if (! $dryRun) {
            $disk->delete($orphans->all());
        }

        $this->info(sprintf(
            '%s %d orphaned file(s).',
            $dryRun ? 'Would delete' : 'Deleted',
            $orphans->count(),
        ));

        return self::SUCCESS;
    }
}
