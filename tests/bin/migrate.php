<?php

declare(strict_types=1);

/**
 * Runs migrations:up in its own process, for the race tests. The working directory is the project whose
 * migrations/database it applies. The DBAL connection parameters come as JSON in KIPCHAK_TEST_DBAL_PARAMS, so that no
 * password is on the command line. With --no-lock it runs the command with the lock switched off, the control.
 *
 * Prints the command's output and exits with its exit code.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\Console\EntityManagerProvider\SingleManagerProvider;
use Kipchak\Driver\Doctrine\Commands\MigrationsUpCommand;
use Kipchak\Driver\Doctrine\Tests\Support\UnlockedMigrationsUpCommand;
use Symfony\Component\Console\Tester\CommandTester;

$params = json_decode((string) getenv('KIPCHAK_TEST_DBAL_PARAMS'), true, flags: JSON_THROW_ON_ERROR);
$connection = DriverManager::getConnection($params);
$em = new EntityManager($connection, ORMSetup::createAttributeMetadataConfig([__DIR__ . '/../Fixtures'], true));

$class = in_array('--no-lock', $argv, true) ? UnlockedMigrationsUpCommand::class : MigrationsUpCommand::class;
$tester = new CommandTester(new $class(new SingleManagerProvider($em), ['lock_timeout' => 30]));
$code = $tester->execute([]);

echo $tester->getDisplay();
exit($code);
