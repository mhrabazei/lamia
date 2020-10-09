<?php

namespace Shop\Infra\Db\MySql\Migration;

use PDO;
use Exception;
use DirectoryIterator;

class Manager
{
    /**
     * @var PDO
     */
    private $connection;

    /**
     * @var string
     */
    private $dir;

    /**
     * @var string
     */
    private $table = 'migrations';

    /**
     * Manager constructor.
     * @param PDO $connection
     * @param string $dir
     */
    public function __construct(PDO $connection, string $dir)
    {
        $this->connection = $connection;
        $this->dir = $dir;
    }

    /**
     * @throws \Throwable
     */
    public function handle()
    {
        $this->prepareTable();
        $files = [];
        foreach (new DirectoryIterator($this->dir) as $fileInfo) {
            if($fileInfo->isDot() || !preg_match('/^m[0-9]{8}_[0-9]{6}.+\.php$/i', $fileInfo->getFilename())) {
                continue;
            }
            $files[substr($fileInfo->getFilename(), 0, -4)] = clone $fileInfo;
        }
        ksort($files);
        $migrated = $this->getMigrated();
        foreach ($files as $name => $fileInfo) {
            if (in_array($name, $migrated)) {
                continue;
            }
            require_once $fileInfo->getPathname();
            if (!is_subclass_of($name, AbstractMigration::class)) {
                throw new Exception();
            }
            /** @var AbstractMigration $migration */
            $migration = new $name($this->connection);
            $this->connection->beginTransaction();
            try {
                $migration->up();
                $this->addMigrated($name);
                $this->connection->commit();

            } catch (\Throwable $e) {
                $this->connection->rollBack();
                throw $e;
            }
        }
    }

    /**
     * @throws Exception
     */
    private function prepareTable()
    {
        $success = $this->connection->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$this->table} (
  name varchar(1024) NOT NULL PRIMARY KEY,
  migrated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
SQL
);
        if ($success === false) {
            throw new Exception($this->connection->errorCode(). ': ' . print_r($this->connection->errorInfo(), true));
        }
    }

    /**
     * @return array
     */
    private function getMigrated(): array
    {
        $query = $this->connection->prepare("SELECT name FROM {$this->table}");
        $query->execute();
        $names = [];
        while ($name = $query->fetchColumn()) {
            $names[] = $name;
        }
        return $names;
    }

    /**
     * @param string $name
     * @throws Exception
     */
    private function addMigrated(string $name)
    {
        $query = $this->connection->prepare("INSERT INTO {$this->table} (name) VALUES (:name)");
        $success = $query->execute([':name' => $name]);
        if ($success === false) {
            throw new Exception();
        }
    }
}