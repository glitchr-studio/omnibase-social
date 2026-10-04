<?php

// Standalone (composer install in this checkout), inside a host application
// (vendor/omnibase/social), or against a host's vendor dir mounted elsewhere
// (/srv/app/vendor in the docker runner, where this checkout is a symlink's
// target): whichever autoloader exists is used, and the test namespace is
// registered by hand because a host's autoloader never reads a dependency's
// autoload-dev.
$candidates = [__DIR__.'/../vendor/autoload.php', __DIR__.'/../../../autoload.php', '/srv/app/vendor/autoload.php'];
foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $loader = require $candidate;
        $loader->addPsr4('Base\\Social\\Tests\\', __DIR__);
        // Prepended: the classes under test are this checkout's, even when a
        // host application has an installed copy of the bundle too.
        $loader->addPsr4('Base\\Social\\', __DIR__.'/../src', true);

        return;
    }
}

throw new RuntimeException('No autoloader found: run composer install in this checkout or install the bundle in an application.');
