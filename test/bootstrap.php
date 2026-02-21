<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Omeka-S is not installed as a Composer dependency. Define lightweight stubs
// for the exception classes used by WebMCPProxyController so the test suite
// can load and exercise the controller without a full Omeka-S installation.
$stubDir = __DIR__ . '/WebMCPTest/Stub';
$stubs = [
    \Omeka\Api\Exception\PermissionDeniedException::class => $stubDir . '/PermissionDeniedException.php',
    \Omeka\Api\Exception\NotFoundException::class         => $stubDir . '/NotFoundException.php',
    \Omeka\Api\Exception\ValidationException::class       => $stubDir . '/ValidationException.php',
    \Omeka\Api\Exception\BadRequestException::class       => $stubDir . '/BadRequestException.php',
];

foreach ($stubs as $class => $file) {
    if (!class_exists($class)) {
        require $file;
    }
}
