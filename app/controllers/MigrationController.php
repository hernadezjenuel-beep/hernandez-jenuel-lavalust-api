<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class MigrationController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->config->load('migration');
        $this->call->library('migration');
    }

    public function create_migration($migration_class)
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $migration_class)) {
            http_response_code(400);
            echo "Migration name must contain only letters, numbers, and underscores, and start with a letter.";
            return;
        }

        $this->migration->create_migration($migration_class);
    }

    public function migrate()
    {
        $this->migration->migrate();
    }

    public function rollback()
    {
        $this->migration->rollback();
    }

    public function rollback_all()
    {
        $this->migration->rollback_all();
    }

    public function refresh()
    {
        $this->migration->refresh();
    }

    public function status()
    {
        $this->migration->status();
    }
}