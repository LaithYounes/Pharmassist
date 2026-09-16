<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class ResetDemoDatabase extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Rebuild only the dedicated local SQLite demo database';

    public function handle(): int
    {
        $expected = database_path('pharmassist_demo.sqlite');
        $configured = config('database.connections.sqlite.database');

        if (! in_array(app()->environment(), ['local', 'testing'], true)
            || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.url')
            || ! is_string($configured)
            || ! is_file($configured)
            || realpath($configured) !== realpath($expected)
            || DB::connection()->getDriverName() !== 'sqlite') {
            $this->error('Refused: use APP_ENV=local/testing and the exact database/pharmassist_demo.sqlite SQLite file with no DB_URL.');

            return self::FAILURE;
        }

        $this->info('Resetting only '.$expected);
        $exit = Artisan::call('migrate:fresh', ['--database' => 'sqlite', '--seed' => true, '--force' => true]);
        $this->output->write(Artisan::output());

        return $exit === 0 ? self::SUCCESS : self::FAILURE;
    }
}
