<?php

declare(strict_types=1);

use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\FinderConfig;
use Testo\Application\Config\SuiteConfig;

return new ApplicationConfig(
    src: ['src'],
    suites: [
        new SuiteConfig(
            name: 'Unit',
            location: new FinderConfig(include: ['tests'], exclude: ['tests/Integration']),
        ),
        new SuiteConfig(
            name: 'Integration',
            location: ['tests/Integration'],
        ),
        new SuiteConfig(
            name: 'Benchmarks',
            location: ['benchmarks'],
        ),
    ],
);
