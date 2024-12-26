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
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;

/**
 * @author Tomas Norkūnas <norkunas.tom@gmail.com>
 */
final class PropertySchemaGreaterThanOrEqualRestriction implements PropertySchemaRestrictionMetadataInterface
{
    /**
     * {@inheritdoc}
     *
     * @param GreaterThanOrEqual $constraint
     */
    public function create(Constraint $constraint, ApiProperty $propertyMetadata): array
    {
        return [
            'minimum' => $constraint->value,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function supports(Constraint $constraint, ApiProperty $propertyMetadata): bool
    {
        // BC layer for api-platform/metadata < 4.1
        if (!method_exists($propertyMetadata, 'getPhpType')) {
            $types = array_map(fn (LegacyType $type) => $type->getBuiltinType(), $propertyMetadata->getBuiltinTypes() ?? []);
            if ($propertyMetadata->getExtraProperties()['nested_schema'] ?? false) {
                $types = [LegacyType::BUILTIN_TYPE_INT];
            }

            return $constraint instanceof GreaterThanOrEqual && is_numeric($constraint->value) && \count($types) && array_intersect($types, [LegacyType::BUILTIN_TYPE_INT, LegacyType::BUILTIN_TYPE_FLOAT]);
        }

        if (!$constraint instanceof GreaterThanOrEqual || !is_numeric($constraint->value)) {
            return false;
        }

        if ($propertyMetadata->getExtraProperties()['nested_schema'] ?? false) {
            return true;
        }

        if (null === $type = $propertyMetadata->getPhpType()) {
            return false;
        }

        return $type->isIdentifiedBy(TypeIdentifier::INT, TypeIdentifier::FLOAT);
    }
}
