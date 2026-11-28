<?php

declare(strict_types=1);

$bundles = [
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true],
    Symfony\Bundle\MonologBundle\MonologBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class => ['all' => true],
];

if (class_exists(DAMA\DoctrineTestBundle\DAMADoctrineTestBundle::class)) {
    $bundles[DAMA\DoctrineTestBundle\DAMADoctrineTestBundle::class] = ['test' => true];
}

if (class_exists(Symfony\Bundle\WebProfilerBundle\WebProfilerBundle::class)) {
    $bundles[Symfony\Bundle\WebProfilerBundle\WebProfilerBundle::class] = ['dev' => true, 'test' => true];
}

return $bundles;
