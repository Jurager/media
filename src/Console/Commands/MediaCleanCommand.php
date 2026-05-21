<?php /** @noinspection PhpUnnecessaryCurlyVarSyntaxInspection */
/** @noinspection PhpUnnecessaryCurlyVarSyntaxInspection */
/** @noinspection PhpUnnecessaryCurlyVarSyntaxInspection */
/** @noinspection PhpUnnecessaryCurlyVarSyntaxInspection */
/** @noinspection PhpUnnecessaryCurlyVarSyntaxInspection */
/** @noinspection PhpUnnecessaryCurlyVarSyntaxInspection */
/** @noinspection PhpUnnecessaryCurlyVarSyntaxInspection */

/** @noinspection PhpUnnecessaryCurlyVarSyntaxInspection */

namespace Jurager\Media\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\SoftDeletes;
use Jurager\Media\Models\Media;

class MediaCleanCommand extends Command
{
    protected $signature = 'media:clean
                            {--dry-run : List orphaned records without deleting them}
                            {--chunk=100 : Number of records to process at a time}';

    protected $description = 'Delete Media records whose parent model no longer exists';

    public function handle(): int
    {
        $mediaClass = config('media.models.media', Media::class);
        $dryRun = (bool) $this->option('dry-run');
        $chunk = (int) $this->option('chunk');

        $deleted = 0;

        $mediaClass::query()
            ->select(['id', 'mediable_type', 'mediable_id', 'file_name', 'collection_name'])
            ->chunkById($chunk, function ($records) use ($dryRun, &$deleted) {
                // Group by mediable type to batch-check existence
                $records->groupBy('mediable_type')->each(function ($group, string $type) use ($dryRun, &$deleted) {
                    if (! class_exists($type)) {
                        $this->markOrphaned($group, $dryRun, $deleted, 'class does not exist');

                        return;
                    }

                    $ids = $group->pluck('mediable_id')->unique();

                    // Include soft-deleted records so we don't treat them as orphans
                    $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($type), true);
                    $query = $usesSoftDeletes
                        ? $type::withTrashed()->whereIn('id', $ids)
                        : $type::query()->whereIn('id', $ids);

                    $existingIds = $query->pluck('id')->flip();

                    $orphans = $group->filter(fn ($m) => ! isset($existingIds[$m->mediable_id]));

                    $this->markOrphaned($orphans, $dryRun, $deleted);
                });
            });

        if ($dryRun) {
            $this->info("Dry run: {$deleted} orphaned record(s) would be deleted.");
        } else {
            $this->info("Deleted {$deleted} orphaned media record(s).");
        }

        return self::SUCCESS;
    }

    protected function markOrphaned($records, bool $dryRun, int &$count, string $reason = ''): void
    {
        foreach ($records as $media) {
            $label = "{$media->mediable_type}#{$media->mediable_id} — {$media->file_name}";

            if ($reason) {
                $label .= " ({$reason})";
            }

            $this->line($dryRun ? "  [dry-run] orphan: {$label}" : "  Deleting: {$label}");

            if (! $dryRun) {
                // Load full model to trigger deleting observer (removes S3 files)
                $full = $media->fresh();
                $full?->delete();
            }

            $count++;
        }
    }
}
