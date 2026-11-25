<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

try {
    $db = app_db();
    $db->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            migration VARCHAR(190) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (migration)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
    );

    $files = glob(dirname(__DIR__) . '/database/migrations/*.sql');
    sort($files, SORT_STRING);

    foreach ($files as $file) {
        $name = basename($file);
        $check = $db->prepare('SELECT 1 FROM schema_migrations WHERE migration = :migration');
        $check->execute(['migration' => $name]);

        if ($check->fetchColumn()) {
            echo "Omitida (ya aplicada): {$name}" . PHP_EOL;
            continue;
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException("No se pudo leer la migración {$name}.");
        }

        $statements = preg_split('/;[ \t]*(?:\r?\n|$)/', trim($contents));
        foreach ($statements as $statement) {
            if (trim($statement) !== '') {
                $db->exec($statement);
            }
        }

        $record = $db->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');
        $record->execute(['migration' => $name]);
        echo "Aplicada: {$name}" . PHP_EOL;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Error al migrar: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
