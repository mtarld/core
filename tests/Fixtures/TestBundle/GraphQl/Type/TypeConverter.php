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

namespace ApiPlatform\Tests\Fixtures\TestBundle\GraphQl\Type;

use ApiPlatform\GraphQl\Type\TypeConverterInterface;
use ApiPlatform\Metadata\GraphQl\Operation;
use ApiPlatform\Tests\Fixtures\TestBundle\Document\Dummy as DummyDocument;
use ApiPlatform\Tests\Fixtures\TestBundle\Entity\Dummy;
use GraphQL\Type\Definition\Type as GraphQLType;
use Symfony\Component\PropertyInfo\Type as LegacyType;
use Symfony\Component\TypeInfo\Exception\LogicException;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\ObjectType;

/**
 * Converts a built-in type to its GraphQL equivalent.
 *
 * @author Alan Poulain <contact@alanpoulain.eu>
 */
final class TypeConverter implements TypeConverterInterface
{
    public function __construct(private readonly TypeConverterInterface $defaultTypeConverter)
    {
    }

    public function convertPhpType(Type $type, bool $input, Operation $rootOperation, string $resourceClass, string $rootResource, ?string $property, int $depth): GraphQLType|string|null
    {
        if ('dummyDate' === $property && \in_array($rootResource, [Dummy::class, DummyDocument::class], true)) {
            try {
                $baseType = $type->asNonNullable()->getBaseType();
                if ($baseType instanceof ObjectType && is_a($type->getClassName(), \DateTimeInterface::class, true)) {
                    return \DateTime::class;
                }
            } catch (LogicException) {
            }
        }

        return $this->defaultTypeConverter->convertPhpType($type, $input, $rootOperation, $resourceClass, $rootResource, $property, $depth);
    }

    /**
     * {@inheritdoc}
     */
    public function convertType(LegacyType $type, bool $input, Operation $rootOperation, string $resourceClass, string $rootResource, ?string $property, int $depth): GraphQLType|string|null
    {
        if ('dummyDate' === $property
            && \in_array($rootResource, [Dummy::class, DummyDocument::class], true)
            && Type::BUILTIN_TYPE_OBJECT === $type->getBuiltinType()
            && is_a($type->getClassName(), \DateTimeInterface::class, true)
        ) {
            return \DateTime::class;
        }

        return $this->defaultTypeConverter->convertType($type, $input, $rootOperation, $resourceClass, $rootResource, $property, $depth);
    }

    /**
     * {@inheritdoc}
     */
    public function resolveType(string $type): ?GraphQLType
    {
        return $this->defaultTypeConverter->resolveType($type);
    }
}
