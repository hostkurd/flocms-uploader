<?php

namespace FloCMS\Uploader;

class UploadResult
{
    public function __construct(private array $data)
    {
    }

    public function disk(): ?string
    {
        return $this->data['disk'] ?? null;
    }

    public function directory(): ?string
    {
        return $this->data['directory'] ?? null;
    }

    public function path(): ?string
    {
        return $this->data['path'] ?? null;
    }

    public function url(): ?string
    {
        return $this->data['url'] ?? null;
    }

    public function filename(): ?string
    {
        return $this->data['filename'] ?? null;
    }

    public function originalName(): ?string
    {
        return $this->data['original_name'] ?? null;
    }

    public function extension(): ?string
    {
        return $this->data['extension'] ?? null;
    }

    public function mime(): ?string
    {
        return $this->data['mime'] ?? null;
    }

    public function size(): ?int
    {
        return $this->data['size'] ?? null;
    }

    public function visibility(): ?string
    {
        return $this->data['visibility'] ?? null;
    }

    public function absolutePath(): ?string
    {
        return $this->data['absolute_path'] ?? null;
    }

    public function width(): ?int
    {
        return $this->data['width'] ?? null;
    }

    public function height(): ?int
    {
        return $this->data['height'] ?? null;
    }

    public function versions(): array
    {
        return $this->data['versions'] ?? [];
    }

    public function version(string $name): ?array
    {
        return $this->data['versions'][$name] ?? null;
    }

    public function versionUrl(string $name): ?string
    {
        return $this->data['versions'][$name]['url'] ?? null;
    }

    public function toArray(): array
    {
        return $this->data;
    }
}