<?php
require_once 'config.php';

try {
    $tables = ['affiliate_networks', 'offers', 'landings', 'traffic_sources', 'campaigns'];

    foreach ($tables as $table) {
        try {
            $pdo->exec("ALTER TABLE $table ADD COLUMN is_archived INTEGER DEFAULT 0");
            $pdo->exec("ALTER TABLE $table ADD COLUMN archived_at DATETIME");
            echo "Added archive columns to $table\n";
        }
        catch (\Exception $e) {
            echo "Columns might already exist in $table: " . $e->getMessage() . "\n";
        }
    }

    echo "Migration complete.\n";
}
catch (\Exception $e) {
    die("Error: " . $e->getMessage());
}