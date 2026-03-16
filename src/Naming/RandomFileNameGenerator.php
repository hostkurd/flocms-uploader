<?php

namespace FloCMS\Uploader\Naming;

class RandomFileNameGenerator
{
    public function generate(string $extension = ''): string
    {
        $name = bin2hex(random_bytes(16));
        $extension = ltrim(strtolower($extension), '.');

        return $extension !== '' ? $name . '.' . $extension : $name;
    }
}