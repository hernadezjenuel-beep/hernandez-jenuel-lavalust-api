<?php
/**
 * Command: Migration
 *
 * Auto-discovered by the LavaLust CLI.
 * No registration needed — just drop this file in app/commands/.
 */
class Migration
{
    public static $command = 'migration';
    public static $description = 'Run database migrations';
    public static $arguments = [
        '[action]' => 'Action: run, create-migration, rollback, rollback-all, refresh, status',
        '[name]'   => 'Migration class name for create-migration',
    ];

    protected static $route_map = [
        'run'              => 'migrate',
        'create-migration' => 'create-migration',
        'rollback'         => 'rollback',
        'rollback-all'     => 'rollback-all',
        'refresh'          => 'refresh',
        'status'           => 'status',
    ];

    public function handle($action = null, array $flags = [], $name = null)
    {
        $action = $action ?? 'run';

        if (!isset(static::$route_map[$action])) {
            echo danger("Unknown migration action: \"{$action}\"") . PHP_EOL;
            echo "Available actions: " . implode(', ', array_keys(static::$route_map)) . PHP_EOL;
            exit(1);
        }

        if ($action === 'create-migration') {
            if (!$name) {
                echo danger("Migration name is required.") . PHP_EOL;
                echo "Example: php lava migration create-migration create_products_table" . PHP_EOL;
                exit(1);
            }

            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $name)) {
                echo danger("Migration name must contain only letters, numbers, and underscores, and start with a letter.") . PHP_EOL;
                exit(1);
            }

            $route = static::$route_map[$action] . '/' . $name;
        } else {
            $route = static::$route_map[$action];
        }

        $index = PUBLIC_DIR . 'index.php';
        if (!file_exists($index)) {
            echo danger("index.php not found at: {$index}") . PHP_EOL;
            exit(1);
        }

        $command = sprintf(
            '%s %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($index),
            escapeshellarg($route)
        );

        passthru($command, $exit_code);
        if ($exit_code !== 0) {
            exit($exit_code);
        }
    }
}