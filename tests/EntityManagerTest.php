<?php

declare(strict_types=1);

namespace Kipchak\Driver\Doctrine\Tests;

use DI\Container;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Tools\SchemaTool;
use Kipchak\Driver\Config\Config;
use Kipchak\Driver\Doctrine\Doctrine;
use Kipchak\Driver\Doctrine\Tests\Fixtures\Shout;
use Kipchak\Driver\Doctrine\Tests\Fixtures\Widget;
use Kipchak\Driver\Logger\Logger;
use PHPUnit\Framework\TestCase;

/**
 * The entity manager the driver builds from its configuration, against the doctrine/orm 4.0.x-dev commit in
 * composer.lock: attribute mapping from metadata_dirs, the repository, DQL, the QueryBuilder and custom DQL functions.
 * Each behaviour has a control that shows the assertion fails without what it checks.
 */
final class EntityManagerTest extends TestCase
{
    private string $database;

    protected function setUp(): void
    {
        $this->database = sys_get_temp_dir() . '/doctrine-em-' . bin2hex(random_bytes(4)) . '.db';
    }

    protected function tearDown(): void
    {
        @unlink($this->database);
    }

    /**
     * @param array<string, mixed> $entityManager overrides of the entity manager's configuration
     */
    private function entityManager(array $entityManager = []): EntityManagerInterface
    {
        $container = new Container();
        Config::initialise($container);
        $container->set('drivers.config', [
            'kipchak.api' => ['name' => 'test', 'loglevel' => \Monolog\Level::Error],
            'kipchak.doctrine' => [
                'dbal' => ['enabled' => true, 'connections' => ['local' => ['driver' => 'pdo_sqlite', 'path' => $this->database]]],
                'orm' => ['enabled' => true, 'entity_managers' => ['primary' => $entityManager + [
                    'connection' => 'local',
                    'dev_mode' => true,
                    'metadata_dirs' => [__DIR__ . '/Fixtures'],
                    'cache' => ['enabled' => false],
                    'cache_config' => ['file' => ['dir' => sys_get_temp_dir() . '/doctrine-em-cache']],
                ]]],
            ],
        ]);
        Logger::initialise($container);
        Doctrine::initialise($container);

        return Doctrine::get('entitymanager.primary');
    }

    private function withWidgets(EntityManagerInterface $em, string ...$names): void
    {
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(Widget::class)]);
        foreach ($names as $name) {
            $em->persist(new Widget($name));
        }
        $em->flush();
        $em->clear();
    }

    public function testEntitiesAreMappedFromTheirAttributesInMetadataDirs(): void
    {
        $metadata = $this->entityManager()->getClassMetadata(Widget::class);

        self::assertSame('widgets', $metadata->getTableName());
        self::assertSame(['id'], $metadata->getIdentifierFieldNames());
        self::assertTrue($metadata->usesIdGenerator());
        self::assertTrue($metadata->isUniqueField('name'));
    }

    public function testMetadataDirsAreWhereTheEntityManagerFindsItsEntities(): void
    {
        // The attribute driver maps any loadable #[ORM\Entity] class; metadata_dirs decide which ones the entity manager
        // lists, which is what schema tools and migrations diff against.
        self::assertContains(Widget::class, $this->entityManager()->getConfiguration()->getMetadataDriverImpl()->getAllClassNames());
        self::assertNotContains(Widget::class, $this->entityManager(['metadata_dirs' => [__DIR__ . '/../src']])
            ->getConfiguration()->getMetadataDriverImpl()->getAllClassNames(), 'Control: a directory without the entity');
    }

    public function testTheSchemaToolCreatesTheMappedTable(): void
    {
        $em = $this->entityManager();
        $this->withWidgets($em, 'saddle');

        self::assertSame(['id', 'name'], array_keys($em->getConnection()->createSchemaManager()->listTableColumns('widgets')));
    }

    public function testTheRepositoryFindsByIdentifierAndByField(): void
    {
        $em = $this->entityManager();
        $this->withWidgets($em, 'saddle', 'bridle', 'stirrup');
        $repository = $em->getRepository(Widget::class);

        self::assertSame('bridle', $repository->find(2)?->getName());
        self::assertSame(3, $repository->findOneBy(['name' => 'stirrup'])?->getId());
        self::assertSame(['bridle', 'saddle', 'stirrup'], array_map(static fn (Widget $w): string => $w->getName(), $repository->findBy([], ['name' => 'ASC'])));
        self::assertNull($repository->find(99), 'Control: an identifier no row has');
    }

    public function testDqlWithABoundParameter(): void
    {
        $em = $this->entityManager();
        $this->withWidgets($em, 'saddle', 'bridle', 'stirrup');
        $query = $em->createQuery('SELECT w FROM ' . Widget::class . ' w WHERE w.name LIKE :prefix ORDER BY w.id DESC');

        $found = $query->setParameter('prefix', 's%')->getResult();
        self::assertSame(['stirrup', 'saddle'], array_map(static fn (Widget $w): string => $w->getName(), $found));
        self::assertSame([], $query->setParameter('prefix', 'x%')->getResult(), 'Control: a prefix no name has');
    }

    public function testTheQueryBuilderWithConditionsOrderAndLimit(): void
    {
        $em = $this->entityManager();
        $this->withWidgets($em, 'saddle', 'bridle', 'stirrup', 'spur');
        $builder = $em->createQueryBuilder()->select('w')->from(Widget::class, 'w')
            ->where('w.id > :after')->orderBy('w.name', \SortDirection::Ascending)->setMaxResults(2);

        $names = array_map(static fn (Widget $w): string => $w->getName(), $builder->setParameter('after', 1)->getQuery()->getResult());
        self::assertSame(['bridle', 'spur'], $names);
        self::assertSame(1, (int) $em->createQueryBuilder()->select('COUNT(w.id)')->from(Widget::class, 'w')
            ->where('w.name = :name')->setParameter('name', 'spur')->getQuery()->getSingleScalarResult());
        self::assertSame([], $builder->setParameter('after', 4)->getQuery()->getResult(), 'Control: no identifier is above 4');
    }

    public function testOrm4OrdersByASortDirectionNotAString(): void
    {
        // A break from ORM 3, where orderBy() took 'ASC' or 'DESC'. If doctrine/orm 4 changes this again, this test says so.
        $builder = $this->entityManager()->createQueryBuilder()->select('w')->from(Widget::class, 'w');
        self::assertStringEndsWith('ORDER BY w.name DESC', $builder->orderBy('w.name', \SortDirection::Descending)->getDQL());

        $this->expectException(\TypeError::class);
        $builder->orderBy('w.name', 'DESC');
    }

    public function testACustomDqlFunctionFromTheConfiguration(): void
    {
        $em = $this->entityManager(['custom_dql_functions' => ['string' => ['SHOUT' => Shout::class]]]);
        $this->withWidgets($em, 'saddle');

        self::assertSame('SADDLE', $em->createQuery('SELECT SHOUT(w.name) FROM ' . Widget::class . ' w')->getSingleScalarResult());
    }

    public function testControlADqlFunctionThatIsNotConfiguredIsRejected(): void
    {
        $em = $this->entityManager();
        $this->withWidgets($em, 'saddle');

        $this->expectException(QueryException::class);
        $em->createQuery('SELECT SHOUT(w.name) FROM ' . Widget::class . ' w')->getSingleScalarResult();
    }
}
