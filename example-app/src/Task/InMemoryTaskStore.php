<?php

declare(strict_types=1);

namespace App\Task;

/**
 * Simple in-memory task storage for demonstration purposes.
 *
 * In a real application, replace this with a Doctrine repository
 * or any other persistence mechanism.
 */
final class InMemoryTaskStore
{
    /** @var array<string, array{id: string, title: string, completed: bool}> */
    private array $tasks = [];

    public function save(string $id, string $title, bool $completed = false): void
    {
        $this->tasks[$id] = [
            'id' => $id,
            'title' => $title,
            'completed' => $completed,
        ];
    }

    public function complete(string $id): void
    {
        if (!isset($this->tasks[$id])) {
            throw new \RuntimeException(\sprintf('Task "%s" not found.', $id));
        }

        $this->tasks[$id] = [
            ...$this->tasks[$id],
            'completed' => true,
        ];
    }

    /**
     * @return array{id: string, title: string, completed: bool}|null
     */
    public function findById(string $id): ?array
    {
        return $this->tasks[$id] ?? null;
    }

    /**
     * @return list<array{id: string, title: string, completed: bool}>
     */
    public function findAll(): array
    {
        return array_values($this->tasks);
    }
}
