<?php

namespace App\Console\Commands;

use App\Services\HomepageTransfer\HomepageExporter;
use App\Services\HomepageTransfer\HomepageTransferException;
use Illuminate\Console\Command;

/** Writes the homepage package of one client to a file. Read-only: nothing in the database or storage changes. */
class ExportHomepage extends Command
{
    protected $signature = 'homepage:export {client : The homepage client key, e.g. unity} {--output= : Where to write the ZIP (default: current directory)}';

    protected $description = 'Export one client\'s homepage as a portable package (for import on another Gateway)';

    public function handle(HomepageExporter $exporter): int
    {
        try {
            $package = $exporter->export((string) $this->argument('client'));
        } catch (HomepageTransferException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $target = $this->option('output') ?: getcwd().DIRECTORY_SEPARATOR.$package['filename'];

        if (is_dir($target)) {
            $target = rtrim($target, '/\\').DIRECTORY_SEPARATOR.$package['filename'];
        }
        if (file_exists($target)) {
            @unlink($package['path']);
            $this->error("Refusing to overwrite $target");

            return self::FAILURE;
        }
        if (! @rename($package['path'], $target) && ! (@copy($package['path'], $target) && @unlink($package['path']))) {
            @unlink($package['path']);
            $this->error("Could not write $target");

            return self::FAILURE;
        }

        $this->info("Exported to $target");
        foreach ($package['manifest']['includes'] as $what => $count) {
            $this->line(sprintf('  %-22s %d', str_replace('_', ' ', $what), $count));
        }
        foreach ($package['manifest']['warnings'] as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
