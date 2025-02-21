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

namespace ApiPlatform\Symfony\Validator\Metadata\Property\Restriction;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\PropertyInfo\Type as LegacyType;
use Symfony\Component\TypeInfo\TypeIdentifier;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Choice;

/**
 * @author Tomas Norkūnas <norkunas.tom@gmail.com>
 */
final class PropertySchemaChoiceRestriction implements PropertySchemaRestrictionMetadataInterface
{
    /**
     * {@inheritdoc}
     *
     * @param Choice $constraint
     */
    public function create(Constraint $constraint, ApiProperty $propertyMetadata): array
    {
        $choices = [];

        if (\is_callable($constraint->callback)) {
            $choices = ($constraint->callback)();
        } elseif (\is_array($constraint->choices)) {
            $choices = $constraint->choices;
        }

        if (!$choices) {
            return [];
        }

        $restriction = [];

        if (!$constraint->multiple) {
            $restriction['enum'] = $choices;

            return $restriction;
        }

        $restriction['type'] = 'array';

        $types = array_values(array_unique(array_map(fn (mixed $choice) => \is_string($choice) ? 'string' : 'number', $choices)));

        if ($count = \count($types)) {
            if (1 === $count) {
                $types = $types[0];
            }

            $restriction['items'] = ['type' => $types, 'enum' => $choices];
        }

        if (null !== $constraint->min) {
            $restriction['minItems'] = $constraint->min;
        }

        if (null !== $constraint->max) {
            $restriction['maxItems'] = $constraint->max;
        }

        return $restriction;
    }

    /**
     * {@inheritdoc}
     */
    public function supports(Constraint $constraint, ApiProperty $propertyMetadata): bool
    {
        // BC layer for api-platform/metadata < 4.1
        if (method_exists($propertyMetadata, 'getPhpType')) {
            if (!$constraint instanceof Choice) {
                return false;
            }

            if ($propertyMetadata->getExtraProperties()['nested_schema'] ?? false) {
                return true;
            }

            if (null === $type = $propertyMetadata->getPhpType()) {
                return false;
            }

            if ($type instanceof CollectionType && $type->getCollectionValueType()->isIdentifiedBy(TypeIdentifier::STRING, TypeIdentifier::INT, TypeIdentifier::FLOAT)) {
                return true;
            }

            return $type->isIdentifiedBy(TypeIdentifier::STRING, TypeIdentifier::INT, TypeIdentifier::FLOAT);
        }

        $types = array_map(static fn (LegacyType $type) => $type->getBuiltinType(), $propertyMetadata->getBuiltinTypes() ?? []);
        if ($propertyMetadata->getExtraProperties()['nested_schema'] ?? false) {
            $types = [LegacyType::BUILTIN_TYPE_STRING];
        }

        if (
            null !== ($builtinType = $propertyMetadata->getBuiltinTypes()[0] ?? null)
            && $builtinType->isCollection()
            && \count($builtinType->getCollectionValueTypes())
        ) {
            $types = array_unique(array_merge($types, array_map(static fn (LegacyType $type) => $type->getBuiltinType(), $builtinType->getCollectionValueTypes())));
        }

        return $constraint instanceof Choice && \count($types) && array_intersect($types, [LegacyType::BUILTIN_TYPE_STRING, LegacyType::BUILTIN_TYPE_INT, LegacyType::BUILTIN_TYPE_FLOAT]);
    }
}
