<?php

declare(strict_types=1);

$tests = [
    __DIR__ . '/../tests/Unit/CapabilityContractTest.php',
    __DIR__ . '/../tests/Unit/LicensingFailsClosedTest.php',
];

foreach ($tests as $test) {
    require $test;
}

echo "Larena Licensing contract tests passed.\n";
