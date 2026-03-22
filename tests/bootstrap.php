<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

// Register Symfony's ErrorHandler BEFORE PHPUnit starts tracking handler state.
// Without this, PHPUnit 11 flags every KernelTestCase test as "risky" because
// Symfony's kernel boot registers exception/error handlers that persist after shutdown.
if (class_exists(Symfony\Component\ErrorHandler\ErrorHandler::class)) {
    Symfony\Component\ErrorHandler\ErrorHandler::register();
}
