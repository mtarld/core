<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ApiPlatform\OpenApi\Tests;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\PropertyInfo\Type as LegacyType;
use Symfony\Component\TypeInfo\Type;

trait ApiPropertyTypeLegacyTrait
{
    /**
     * @param Type|list<LegacyType> $builtinTypes
     */
    private function apiPropertyWithPhpOrBuiltinType(Type|array $type, ApiProperty $apiProperty = new ApiProperty()): ApiProperty
    {
        if ($type instanceof Type) {
            return $apiProperty->withPhpType($type);
        }

        return $apiProperty->withBuiltinTypes($type);
    }
}
