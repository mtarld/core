<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\Elasticsearch\Tests\Filter;

use ApiPlatform\Elasticsearch\Filter\ConstantScoreFilterInterface;
use ApiPlatform\Elasticsearch\Filter\TermFilter;
use ApiPlatform\Elasticsearch\Tests\ApiPropertyTypeLegacyTrait;
use ApiPlatform\Elasticsearch\Tests\Fixtures\Foo;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\Exception\InvalidArgumentException;
use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Metadata\Property\Factory\PropertyNameCollectionFactoryInterface;
use ApiPlatform\Metadata\Property\PropertyNameCollection;
use ApiPlatform\Metadata\ResourceClassResolverInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\PropertyInfo\Type as LegacyType;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Symfony\Component\TypeInfo\Type;

class TermFilterTest extends TestCase
{
    use ProphecyTrait;
    use ApiPropertyTypeLegacyTrait;

    public function testConstruct(): void
    {
        self::assertInstanceOf(
            ConstantScoreFilterInterface::class,
            new TermFilter(
                $this->prophesize(PropertyNameCollectionFactoryInterface::class)->reveal(),
                $this->prophesize(PropertyMetadataFactoryInterface::class)->reveal(),
                $this->prophesize(ResourceClassResolverInterface::class)->reveal(),
                $this->prophesize(IriConverterInterface::class)->reveal(),
                $this->prophesize(PropertyAccessorInterface::class)->reveal(),
                $this->prophesize(NameConverterInterface::class)->reveal()
            )
        );
    }

    public function testApply(): void
    {
        // BC layer for api-platform/metadata < 4.1
        if (method_exists(ApiProperty::class, 'getPhpType')) {
            $idType = Type::int();
            $nameType = Type::string();
        } else {
            $idType = [new LegacyType(LegacyType::BUILTIN_TYPE_INT)];
            $nameType = [new LegacyType(LegacyType::BUILTIN_TYPE_STRING)];
        }

        $propertyNameCollectionFactoryProphecy = $this->prophesize(PropertyNameCollectionFactoryInterface::class);
        $propertyNameCollectionFactoryProphecy->create(Foo::class)->willReturn(new PropertyNameCollection(['id', 'name', 'bar']))->shouldBeCalled();

        $propertyMetadataFactoryProphecy = $this->prophesize(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactoryProphecy->create(Foo::class, 'id')->willReturn($this->apiPropertyWithPhpOrBuiltinType($idType))->shouldBeCalled();
        $propertyMetadataFactoryProphecy->create(Foo::class, 'name')->willReturn($this->apiPropertyWithPhpOrBuiltinType($nameType))->shouldBeCalled();

        $foo = new Foo();
        $foo->setName('Xavier');
        $foo->setBar('Thévenard');

        $iriConverterProphecy = $this->prophesize(IriConverterInterface::class);
        $iriConverterProphecy->getResourceFromIri('/foos/1', ['fetch_data' => false])->willReturn($foo)->shouldBeCalled();

        $propertyAccessorProphecy = $this->prophesize(PropertyAccessorInterface::class);
        $propertyAccessorProphecy->getValue($foo, 'id')->willReturn(1)->shouldBeCalled();

        $nameConverterProphecy = $this->prophesize(NameConverterInterface::class);
        $nameConverterProphecy->normalize('id', Foo::class, null, Argument::type('array'))->willReturn('id')->shouldBeCalled();
        $nameConverterProphecy->normalize('name', Foo::class, null, Argument::type('array'))->willReturn('name')->shouldBeCalled();

        $termFilter = new TermFilter(
            $propertyNameCollectionFactoryProphecy->reveal(),
            $propertyMetadataFactoryProphecy->reveal(),
            $this->prophesize(ResourceClassResolverInterface::class)->reveal(),
            $iriConverterProphecy->reveal(),
            $propertyAccessorProphecy->reveal(),
            $nameConverterProphecy->reveal()
        );

        self::assertEquals(
            ['bool' => ['must' => [['term' => ['id' => 1]], ['terms' => ['name' => ['Caroline', 'Xavier']]]]]],
            $termFilter->apply([], Foo::class, null, ['filters' => ['id' => '/foos/1', 'name' => ['Caroline', 'Xavier']]])
        );
    }

    public function testApplyWithNestedArrayProperty(): void
    {
        // BC layer for api-platform/metadata < 4.1
        if (method_exists(ApiProperty::class, 'getPhpType')) {
            $fooType = Type::list(Type::object(Foo::class));
            $barType = Type::string();
        } else {
            $fooType = [new LegacyType(LegacyType::BUILTIN_TYPE_ARRAY, false, Foo::class, true, new LegacyType(LegacyType::BUILTIN_TYPE_INT), new LegacyType(LegacyType::BUILTIN_TYPE_OBJECT, false, Foo::class))];
            $barType = [new LegacyType(LegacyType::BUILTIN_TYPE_STRING)];
        }

        $propertyMetadataFactoryProphecy = $this->prophesize(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactoryProphecy->create(Foo::class, 'foo')->willReturn($this->apiPropertyWithPhpOrBuiltinType($fooType))->shouldBeCalled();
        $propertyMetadataFactoryProphecy->create(Foo::class, 'bar')->willReturn($this->apiPropertyWithPhpOrBuiltinType($barType))->shouldBeCalled();

        $resourceClassResolverProphecy = $this->prophesize(ResourceClassResolverInterface::class);
        $resourceClassResolverProphecy->isResourceClass(Foo::class)->willReturn(true)->shouldBeCalled();

        $nameConverterProphecy = $this->prophesize(NameConverterInterface::class);
        $nameConverterProphecy->normalize('foo.bar', Foo::class, null, Argument::type('array'))->willReturn('foo.bar')->shouldBeCalled();
        $nameConverterProphecy->normalize('foo', Foo::class, null, Argument::type('array'))->willReturn('foo')->shouldBeCalled();

        $termFilter = new TermFilter(
            $this->prophesize(PropertyNameCollectionFactoryInterface::class)->reveal(),
            $propertyMetadataFactoryProphecy->reveal(),
            $resourceClassResolverProphecy->reveal(),
            $this->prophesize(IriConverterInterface::class)->reveal(),
            $this->prophesize(PropertyAccessorInterface::class)->reveal(),
            $nameConverterProphecy->reveal(),
            ['foo.bar' => null]
        );

        self::assertEquals(
            ['bool' => ['must' => [['nested' => ['path' => 'foo', 'query' => ['term' => ['foo.bar' => 'Krupicka']]]]]]],
            $termFilter->apply([], Foo::class, null, ['filters' => ['foo.bar' => 'Krupicka']])
        );
    }

    public function testApplyWithNestedObjectProperty(): void
    {
        // BC layer for api-platform/metadata < 4.1
        if (method_exists(ApiProperty::class, 'getPhpType')) {
            $fooType = Type::object(Foo::class);
            $barType = Type::string();
        } else {
            $fooType = [new LegacyType(LegacyType::BUILTIN_TYPE_OBJECT, false, Foo::class)];
            $barType = [new LegacyType(LegacyType::BUILTIN_TYPE_STRING)];
        }

        $propertyMetadataFactoryProphecy = $this->prophesize(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactoryProphecy->create(Foo::class, 'foo')->willReturn($this->apiPropertyWithPhpOrBuiltinType($fooType))->shouldBeCalled();
        $propertyMetadataFactoryProphecy->create(Foo::class, 'bar')->willReturn($this->apiPropertyWithPhpOrBuiltinType($barType))->shouldBeCalled();

        $resourceClassResolverProphecy = $this->prophesize(ResourceClassResolverInterface::class);
        $resourceClassResolverProphecy->isResourceClass(Foo::class)->willReturn(true)->shouldBeCalled();

        $nameConverterProphecy = $this->prophesize(NameConverterInterface::class);
        $nameConverterProphecy->normalize('foo.bar', Foo::class, null, Argument::type('array'))->willReturn('foo.bar')->shouldBeCalled();

        $termFilter = new TermFilter(
            $this->prophesize(PropertyNameCollectionFactoryInterface::class)->reveal(),
            $propertyMetadataFactoryProphecy->reveal(),
            $resourceClassResolverProphecy->reveal(),
            $this->prophesize(IriConverterInterface::class)->reveal(),
            $this->prophesize(PropertyAccessorInterface::class)->reveal(),
            $nameConverterProphecy->reveal(),
            ['foo.bar' => null]
        );

        self::assertSame(
            ['bool' => ['must' => [['term' => ['foo.bar' => 'Krupicka']]]]],
            $termFilter->apply([], Foo::class, null, ['filters' => ['foo.bar' => 'Krupicka']])
        );
    }

    public function testApplyWithInvalidFilters(): void
    {
        // BC layer for api-platform/metadata < 4.1
        if (method_exists(ApiProperty::class, 'getPhpType')) {
            $type = Type::int();
        } else {
            $type = [new LegacyType(LegacyType::BUILTIN_TYPE_INT)];
        }

        $propertyNameCollectionFactoryProphecy = $this->prophesize(PropertyNameCollectionFactoryInterface::class);
        $propertyNameCollectionFactoryProphecy->create(Foo::class)->willReturn(new PropertyNameCollection(['id', 'bar']))->shouldBeCalled();

        $propertyMetadataFactoryProphecy = $this->prophesize(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactoryProphecy->create(Foo::class, 'id')->willReturn($this->apiPropertyWithPhpOrBuiltinType($type))->shouldBeCalled();
        $propertyMetadataFactoryProphecy->create(Foo::class, 'bar')->willReturn(new ApiProperty())->shouldBeCalled();

        $iriConverterProphecy = $this->prophesize(IriConverterInterface::class);
        $iriConverterProphecy->getResourceFromIri('/invalid_iri_foos/1', ['fetch_data' => false])->willThrow(new InvalidArgumentException())->shouldBeCalled();

        $termFilter = new TermFilter(
            $propertyNameCollectionFactoryProphecy->reveal(),
            $propertyMetadataFactoryProphecy->reveal(),
            $this->prophesize(ResourceClassResolverInterface::class)->reveal(),
            $iriConverterProphecy->reveal(),
            $this->prophesize(PropertyAccessorInterface::class)->reveal(),
            $this->prophesize(NameConverterInterface::class)->reveal()
        );

        self::assertEquals(
            [],
            $termFilter->apply([], Foo::class, null, ['filters' => ['id' => '/invalid_iri_foos/1', 'bar' => 'Chaverot']])
        );
    }

    public function testGetDescription(): void
    {
        // BC layer for api-platform/metadata < 4.1
        if (method_exists(ApiProperty::class, 'getPhpType')) {
            $idType = Type::int();
            $nameType = Type::string();
            $dateType = Type::object(\DateTimeImmutable::class);
            $weirdType = Type::resource();
        } else {
            $idType = [new LegacyType(LegacyType::BUILTIN_TYPE_INT)];
            $nameType = [new LegacyType(LegacyType::BUILTIN_TYPE_STRING)];
            $dateType = [new LegacyType(LegacyType::BUILTIN_TYPE_OBJECT, false, \DateTimeImmutable::class)];
            $weirdType = [new LegacyType(LegacyType::BUILTIN_TYPE_RESOURCE)];
        }

        $propertyNameCollectionFactoryProphecy = $this->prophesize(PropertyNameCollectionFactoryInterface::class);
        $propertyNameCollectionFactoryProphecy->create(Foo::class)->willReturn(new PropertyNameCollection(['id', 'name', 'bar', 'date', 'weird']))->shouldBeCalled();

        $propertyMetadataFactoryProphecy = $this->prophesize(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactoryProphecy->create(Foo::class, 'id')->willReturn($this->apiPropertyWithPhpOrBuiltinType($idType))->shouldBeCalled();
        $propertyMetadataFactoryProphecy->create(Foo::class, 'name')->willReturn($this->apiPropertyWithPhpOrBuiltinType($nameType))->shouldBeCalled();
        $propertyMetadataFactoryProphecy->create(Foo::class, 'bar')->willReturn(new ApiProperty())->shouldBeCalled();
        $propertyMetadataFactoryProphecy->create(Foo::class, 'date')->willReturn($this->apiPropertyWithPhpOrBuiltinType($dateType))->shouldBeCalled();
        $propertyMetadataFactoryProphecy->create(Foo::class, 'weird')->willReturn($this->apiPropertyWithPhpOrBuiltinType($weirdType))->shouldBeCalled();

        $resourceClassResolverProphecy = $this->prophesize(ResourceClassResolverInterface::class);
        $resourceClassResolverProphecy->isResourceClass(\DateTimeImmutable::class)->willReturn(false)->shouldBeCalled();

        $termFilter = new TermFilter(
            $propertyNameCollectionFactoryProphecy->reveal(),
            $propertyMetadataFactoryProphecy->reveal(),
            $resourceClassResolverProphecy->reveal(),
            $this->prophesize(IriConverterInterface::class)->reveal(),
            $this->prophesize(PropertyAccessorInterface::class)->reveal(),
            $this->prophesize(NameConverterInterface::class)->reveal()
        );

        self::assertEquals(
            [
                'id' => [
                    'property' => 'id',
                    'type' => 'int',
                    'required' => false,
                    'is_collection' => false,
                ],
                'id[]' => [
                    'property' => 'id',
                    'type' => 'int',
                    'required' => false,
                    'is_collection' => true,
                ],
                'name' => [
                    'property' => 'name',
                    'type' => 'string',
                    'required' => false,
                    'is_collection' => false,
                ],
                'name[]' => [
                    'property' => 'name',
                    'type' => 'string',
                    'required' => false,
                    'is_collection' => true,
                ],
                'date' => [
                    'property' => 'date',
                    'type' => \DateTimeInterface::class,
                    'required' => false,
                    'is_collection' => false,
                ],
                'date[]' => [
                    'property' => 'date',
                    'type' => \DateTimeInterface::class,
                    'required' => false,
                    'is_collection' => true,
                ],
                'weird' => [
                    'property' => 'weird',
                    'type' => 'string',
                    'required' => false,
                    'is_collection' => false,
                ],
                'weird[]' => [
                    'property' => 'weird',
                    'type' => 'string',
                    'required' => false,
                    'is_collection' => true,
                ],
            ],
            $termFilter->getDescription(Foo::class)
        );
    }
}
