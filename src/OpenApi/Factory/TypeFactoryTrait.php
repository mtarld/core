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

namespace ApiPlatform\OpenApi\Factory;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\TypeIdentifier;
use Symfony\Component\TypeInfo\Type\BackedEnumType;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\WrappingTypeInterface;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;

/**
 * @internal
 */
trait TypeFactoryTrait
{
    private function getType(Type $type): array
    {
        if (!$type instanceof CollectionType) {
            return $this->addNullabilityToTypeDefinition($this->makeBasicType($type), $type);
        }

        if (!$type->getCollectionKeyType()->isIdentifiedBy(TypeIdentifier::STRING)) {
            return $this->addNullabilityToTypeDefinition([
                'type' => 'array',
                'items' => $this->getType($type->getCollectionValueType()),
            ], $type);
        }

        return $this->addNullabilityToTypeDefinition([
            'type' => 'object',
            'additionalProperties' => $this->getType($type->getCollectionValueType()),
        ], $type);
    }

    private function makeBasicType(Type $type): array
    {
        if ($type->isIdentifiedBy(TypeIdentifier::INT)) {
            return ['type' => 'integer'];
        }

        if ($type->isIdentifiedBy(TypeIdentifier::FLOAT)) {
            return ['type' => 'number'];
        }

        if ($type->isIdentifiedBy(TypeIdentifier::BOOL)) {
            return ['type' => 'boolean'];
        }

        if ($type->isIdentifiedBy(\DateTimeInterface::class)) {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        if ($type->isIdentifiedBy(\DateInterval::class)) {
            return ['type' => 'string', 'format' => 'duration'];
        }

        if ($type->isIdentifiedBy(UuidInterface::class, Uuid::class)) {
            return ['type' => 'string', 'format' => 'uuid'];
        }

        if ($type->isIdentifiedBy(Ulid::class)) {
            return ['type' => 'string', 'format' => 'ulid'];
        }

        if ($type->isIdentifiedBy(\SplFileInfo::class)) {
            return ['type' => 'string', 'format' => 'binary'];
        }

        $nullable = $type->isNullable();

        while ($type instanceof WrappingTypeInterface) {
            $type = $type->getWrappedType();
        }

        if ($type instanceof BackedEnumType) {
            $enumCases = array_column($type->getClassName()::cases(), 'value');
            if ($nullable) {
                $enumCases[] = null;
            }

            return [
                'type' => $type->getBackingType()->isIdentifiedBy(TypeIdentifier::INT) ? 'integer' : 'string',
                'enum' => $enumCases,
            ];
        }

        return ['type' => 'string'];
    }

    /**
     * @param array<string, mixed> $jsonSchema
     *
     * @return array<string, mixed>
     */
    private function addNullabilityToTypeDefinition(array $jsonSchema, Type $type): array
    {
        if (!$type->isNullable() || $type->isIdentifiedBy(TypeIdentifier::MIXED)) {
            return $jsonSchema;
        }

        $typeDefinition = ['anyOf' => [$jsonSchema]];
        $typeDefinition['anyOf'][] = ['type' => 'null'];

        return $typeDefinition;
    }
}
