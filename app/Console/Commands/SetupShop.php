<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

class SetupShop extends Command
{
    protected $signature = 'shop:setup {--fresh : Drop all tables first}';

    protected $description = 'Create the database (MySQL) if missing, run migrations and seed sample products';

    public function handle(): int
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        if ($connection === 'mysql') {
            $db = $config['database'];
            try {
                $pdo = new PDO(
                    "mysql:host={$config['host']};port={$config['port']}",
                    $config['username'],
                    $config['password'] ?? '',
                );
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (Throwable $e) {
                $this->error('Could not connect to MySQL: '.$e->getMessage());
                $this->line('Check DB_HOST, DB_PORT, DB_USERNAME and DB_PASSWORD in .env');

                return self::FAILURE;
            }
            $this->info("MySQL database `{$db}` is ready.");
        }

        DB::purge();
        $this->call($this->option('fresh') ? 'migrate:fresh' : 'migrate', ['--force' => true]);
        $this->call('db:seed', ['--force' => true]);

        $this->info("Connected to [{$connection}]. Tables: ".implode(', ', collect(DB::select(
            $connection === 'mysql' ? 'SHOW TABLES' : "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
        ))->map(fn ($r) => array_values((array) $r)[0])->all()));

        return self::SUCCESS;
    }
}
