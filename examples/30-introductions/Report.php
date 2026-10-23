<?php

declare(strict_types=1);

namespace Demo\Intro;

// The target class. It does not implement Greeting: the introduction adds the interface when this file is compiled.
final class Report
{
    public string $title = 'untitled';

    public function render(): string
    {
        return "report: {$this->title}";
    }
}
