<?php declare (strict_types=1);

namespace SomeNamespace;

use Symfony\Component\Process\ProcessBuilder;

$builder = new ProcessBuilder([]);
$process = $builder;

$commandLine = $builder->getCommandLine();
