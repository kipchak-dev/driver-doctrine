<?php

declare(strict_types=1);

namespace Kipchak\Driver\Doctrine\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Kipchak\Driver\Doctrine\ReopenableEntityManager;
use Kipchak\Driver\Doctrine\Tests\Fixtures\NameFilter;
use Kipchak\Driver\Doctrine\Tests\Fixtures\Widget;
use PHPUnit\Framework\TestCase;

final class ReopenableEntityManagerTest extends TestCase
{
    private function createEntityManager(): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfig([__DIR__ . '/Fixtures'], true);
        $config->addFilter('name_filter', NameFilter::class);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $em = new EntityManager($connection, $config);

        (new SchemaTool($em))->createSchema([$em->getClassMetadata(Widget::class)]);

        return $em;
    }

    public function testReopenIsANoOpWhileTheInnerEntityManagerIsOpen(): void
    {
        $inner = $this->createEntityManager();
        $em = new ReopenableEntityManager($inner);

        $this->assertFalse($em->reopenIfClosed());
        $this->assertSame($inner, $em->getWrappedEntityManager());
    }

    public function testReplacesAClosedInnerEntityManagerOverTheSameConnectionAndConfiguration(): void
    {
        $inner = $this->createEntityManager();
        $em = new ReopenableEntityManager($inner);

        $inner->close();
        $this->assertFalse($em->isOpen());

        $this->assertTrue($em->reopenIfClosed());
        $this->assertTrue($em->isOpen());
        $this->assertNotSame($inner, $em->getWrappedEntityManager());
        $this->assertSame($inner->getConnection(), $em->getConnection());
        $this->assertSame($inner->getConfiguration(), $em->getConfiguration());
    }

    public function testWritesWorkAgainAfterAUniqueConstraintViolationClosedTheEntityManager(): void
    {
        $em = new ReopenableEntityManager($this->createEntityManager());

        $em->persist(new Widget('alpha'));
        $em->flush();

        $em->persist(new Widget('alpha'));
        try {
            $em->flush();
            $this->fail('Expected a unique constraint violation');
        } catch (UniqueConstraintViolationException) {
        }

        $this->assertFalse($em->isOpen());
        $this->assertTrue($em->reopenIfClosed());

        $em->persist(new Widget('beta'));
        $em->flush();

        $names = array_map(
            static fn (Widget $w): string => $w->getName(),
            $em->getRepository(Widget::class)->findBy([], ['name' => 'ASC']),
        );
        $this->assertSame(['alpha', 'beta'], $names);
    }

    public function testRollsBackADanglingTransactionOnReopen(): void
    {
        $inner = $this->createEntityManager();
        $em = new ReopenableEntityManager($inner);

        $connection = $em->getConnection();
        $connection->beginTransaction();
        $inner->close();

        $this->assertTrue($em->reopenIfClosed());
        $this->assertFalse($connection->isTransactionActive());
    }

    public function testEnabledFiltersAndTheirParametersSurviveAReopen(): void
    {
        $em = new ReopenableEntityManager($this->createEntityManager());

        $em->persist(new Widget('blue'));
        $em->persist(new Widget('red'));
        $em->flush();
        $em->clear();

        $em->getFilters()->enable('name_filter')->setParameter('name', 'blue');

        $em->getWrappedEntityManager()->close();
        $this->assertTrue($em->reopenIfClosed());

        $this->assertTrue($em->getFilters()->isEnabled('name_filter'));

        $names = array_map(
            static fn (Widget $w): string => $w->getName(),
            $em->getRepository(Widget::class)->findAll(),
        );
        $this->assertSame(['blue'], $names);
    }

    public function testUsesTheCustomFactoryAndHandsItTheClosedEntityManager(): void
    {
        $inner = $this->createEntityManager();
        $replacement = new EntityManager($inner->getConnection(), $inner->getConfiguration());

        $received = null;
        $em = new ReopenableEntityManager($inner, function (EntityManagerInterface $closed) use (&$received, $replacement): EntityManagerInterface {
            $received = $closed;

            return $replacement;
        });

        $inner->close();
        $this->assertTrue($em->reopenIfClosed());

        $this->assertSame($inner, $received);
        $this->assertSame($replacement, $em->getWrappedEntityManager());
    }
}
