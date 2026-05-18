<?php

namespace App\Services;

class TemplateService
{
    private string $path;

    public function __construct()
    {
        $this->path = storage_path('templates.json');
    }

    /**
     * Return all templates, seeding from config if the file does not exist.
     */
    public function all(): array
    {
        if (!file_exists($this->path)) {
            $defaults = config('templates', []);
            $this->save($defaults);

            return $defaults;
        }

        $data = json_decode(file_get_contents($this->path), true);

        return is_array($data) ? array_values($data) : [];
    }

    /**
     * Return the number of templates.
     */
    public function count(): int
    {
        return count($this->all());
    }

    /**
     * Add a new template to the end of the list.
     */
    public function add(string $text): void
    {
        $templates   = $this->all();
        $templates[] = $text;
        $this->save($templates);
    }

    /**
     * Replace the template at the given index.
     */
    public function update(int $index, string $text): void
    {
        $templates = $this->all();

        if (!isset($templates[$index])) {
            return;
        }

        $templates[$index] = $text;
        $this->save($templates);
    }

    /**
     * Remove the template at the given index.
     */
    public function delete(int $index): void
    {
        $templates = $this->all();

        if (!isset($templates[$index])) {
            return;
        }

        array_splice($templates, $index, 1);
        $this->save($templates);
    }

    /**
     * Persist the template list to disk atomically.
     */
    public function save(array $templates): void
    {
        file_put_contents(
            $this->path,
            json_encode(array_values($templates), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }
}
