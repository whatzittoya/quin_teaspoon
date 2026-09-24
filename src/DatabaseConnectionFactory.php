<?php

namespace App;

use PDO;

final class DatabaseConnectionFactory
{
    private array $connections = [];

    public function __construct(private array $config)
    {
    }

    public function default(): PDO
    {
        return $this->forDatabase($this->defaultDatabase());
    }

    public function forCategory(array $category): PDO
    {
        return $this->forDatabase($this->databaseForCategory($category));
    }

    public function defaultDatabase(): string
    {
        return trim($this->config['DB_DATABASE'] ?? 'db_parklife');
    }

    public function databaseForCategory(array $category): string
    {
        return EnvConfig::categoryDatabase($this->config, $category);
    }

    public function forDatabase(string $database): PDO
    {
        if (!preg_match('/^[A-Za-z0-9_$-]+$/', $database)) {
            throw new \InvalidArgumentException('Database names may contain only letters, numbers, underscores, hyphens, and dollar signs.');
        }
        if (isset($this->connections[$database])) {
            return $this->connections[$database];
        }

        $host = $this->config['DB_HOST'] ?? '127.0.0.1';
        $port = $this->config['DB_PORT'] ?? '3306';
        $user = $this->config['DB_USERNAME'] ?? 'root';
        $pass = $this->config['DB_PASSWORD'] ?? '';

        return $this->connections[$database] = new PDO(
            "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
}
