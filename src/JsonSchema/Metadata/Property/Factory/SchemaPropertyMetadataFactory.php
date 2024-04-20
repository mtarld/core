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

namespace ApiPlatform\JsonSchema\Metadata\Property\Factory;

use ApiPlatform\Exception\PropertyNotFoundException;
use ApiPlatform\JsonSchema\Schema;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Metadata\ResourceClassResolverInterface;
use ApiPlatform\Metadata\Util\ResourceClassInfoTrait;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\PropertyInfo\Type as LegacyType;
use Symfony\Component\TypeInfo\Exception\LogicException;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\TypeIdentifier;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\IntersectionType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\Type\UnionType;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;

/**
 * Build ApiProperty::schema.
 */
final class SchemaPropertyMetadataFactory implements PropertyMetadataFactoryInterface
{
    use ResourceClassInfoTrait;

    public const JSON_SCHEMA_USER_DEFINED = 'user_defined_schema';

    public function __construct(ResourceClassResolverInterface $resourceClassResolver, private readonly ?PropertyMetadataFactoryInterface $decorated = null)
    {
        $this->resourceClassResolver = $resourceClassResolver;
    }

    public function create(string $resourceClass, string $property, array $options = []): ApiProperty
    {
        if (null === $this->decorated) {
            $propertyMetadata = new ApiProperty();
        } else {
            try {
                $propertyMetadata = $this->decorated->create($resourceClass, $property, $options);
            } catch (PropertyNotFoundException) {
                $propertyMetadata = new ApiProperty();
            }
        }

        $extraProperties = $propertyMetadata->getExtraProperties() ?? [];
        // see AttributePropertyMetadataFactory
        if (true === ($extraProperties[self::JSON_SCHEMA_USER_DEFINED] ?? false)) {
            // schema seems to have been declared by the user: do not override nor complete user value
            return $propertyMetadata;
        }

        $link = (($options['schema_type'] ?? null) === Schema::TYPE_INPUT) ? $propertyMetadata->isWritableLink() : $propertyMetadata->isReadableLink();
        $propertySchema = $propertyMetadata->getSchema() ?? [];

        if (null !== $propertyMetadata->getUriTemplate() || (!\array_key_exists('readOnly', $propertySchema) && false === $propertyMetadata->isWritable() && !$propertyMetadata->isInitializable())) {
            $propertySchema['readOnly'] = true;
        }

        if (!\array_key_exists('writeOnly', $propertySchema) && false === $propertyMetadata->isReadable()) {
            $propertySchema['writeOnly'] = true;
        }

        if (!\array_key_exists('description', $propertySchema) && null !== ($description = $propertyMetadata->getDescription())) {
            $propertySchema['description'] = $description;
        }

        // see https://github.com/json-schema-org/json-schema-spec/pull/737
        if (!\array_key_exists('deprecated', $propertySchema) && null !== $propertyMetadata->getDeprecationReason()) {
            $propertySchema['deprecated'] = true;
        }

        // externalDocs is an OpenAPI specific extension, but JSON Schema allows additional keys, so we always add it
        // See https://json-schema.org/latest/json-schema-core.html#rfc.section.6.4
        if (!\array_key_exists('externalDocs', $propertySchema) && null !== ($iri = $propertyMetadata->getTypes()[0] ?? null)) {
            $propertySchema['externalDocs'] = ['url' => $iri];
        }

        $types = $propertyMetadata->getBuiltinTypes() ?? [];

        // BC layer for symfony/property-info < 7.1
        if (is_array($types)) {
            if (!\array_key_exists('default', $propertySchema) && !empty($default = $propertyMetadata->getDefault()) && (!\count($types) || null === ($className = $types[0]->getClassName()) || !$this->isResourceClass($className))) {
                if ($default instanceof \BackedEnum) {
                    $default = $default->value;
                }
                $propertySchema['default'] = $default;
            }
        } else {
            if (!\array_key_exists('default', $propertySchema) && !empty($default = $propertyMetadata->getDefault()) && (!$types->getBaseType() instanceof ObjectType) || !$this->isResourceClass($types->getBaseType()->getClassName())) {
                if ($default instanceof \BackedEnum) {
                    $default = $default->value;
                }
                $propertySchema['default'] = $default;
            }
        }

        if (!\array_key_exists('example', $propertySchema) && !empty($example = $propertyMetadata->getExample())) {
            $propertySchema['example'] = $example;
        }

        if (!\array_key_exists('example', $propertySchema) && \array_key_exists('default', $propertySchema)) {
            $propertySchema['example'] = $propertySchema['default'];
        }

        // never override the following keys if at least one is already set
        if ([] === $types
            || ($propertySchema['type'] ?? $propertySchema['$ref'] ?? $propertySchema['anyOf'] ?? $propertySchema['allOf'] ?? $propertySchema['oneOf'] ?? false)
        ) {
            return $propertyMetadata->withSchema($propertySchema);
        }

        $valueSchema = [];

        if (is_array($types)) {
            foreach ($types as $type) {
                if ($isCollection = $type->isCollection()) {
                    $keyType = $type->getCollectionKeyTypes()[0] ?? null;
                    $valueType = $type->getCollectionValueTypes()[0] ?? null;
                } else {
                    $keyType = null;
                    $valueType = $type;
                }

                if (null === $valueType) {
                    $builtinType = 'string';
                    $className = null;
                } else {
                    $builtinType = $valueType->getBuiltinType();
                    $className = $valueType->getClassName();
                }

                if (!\array_key_exists('owl:maxCardinality', $propertySchema)
                    && !$isCollection
                    && null !== $className
                    && $this->resourceClassResolver->isResourceClass($className)
                ) {
                    $propertySchema['owl:maxCardinality'] = 1;
                }

                if ($isCollection && null !== $propertyMetadata->getUriTemplate()) {
                    $keyType = null;
                    $isCollection = false;
                }

                $propertyType = $this->getTypeLegacy(new LegacyType($builtinType, $type->isNullable(), $className, $isCollection, $keyType, $valueType), $link);
                if (!\in_array($propertyType, $valueSchema, true)) {
                    $valueSchema[] = $propertyType;
                }
            }
        } else {
            $nullable = $types->isNullable();
            $types = $types->asNonNullable();
            $types = $types instanceof UnionType || $types instanceof IntersectionType ? $types->getTypes() : [$types];

            foreach ($types as $type) {
                $keyType = null;
                $valueType = $type;
                $isCollection = false;
                $className = null;

                if ($type instanceof CollectionType) {
                    $keyType = $type->getCollectionKeyType();
                    $valueType = $type->getCollectionValueType();
                    $isCollection = true;
                }

                try {
                    $baseType = $type->getBaseType();
                    $className = $baseType instanceof ObjectType ? $baseType->getClassName() : null;
                    $typeIdentifier = $baseType->getTypeIdentifier();
                } catch (LogicException) {
                    $className = null;
                    $typeIdentifier = TypeIdentifier::STRING;
                }

                if (!\array_key_exists('owl:maxCardinality', $propertySchema)
                    && !$isCollection
                    && null !== $className
                    && $this->resourceClassResolver->isResourceClass($className)
                ) {
                    $propertySchema['owl:maxCardinality'] = 1;
                }

                if ($isCollection && null !== $propertyMetadata->getUriTemplate()) {
                    $keyType = null;
                    $isCollection = false;
                }

                $t = null !== $className ? Type::object($className) : Type::builtin($typeIdentifier);
                $t = $isCollection ? Type::collection($t, $valueType, $keyType) : $t;
                $t = $nullable ? Type::nullable($t) : $t;

                $propertyType = $this->getType($t, $link);
                if (!\in_array($propertyType, $valueSchema, true)) {
                    $valueSchema[] = $propertyType;
                }
            }
        }

        // only one builtInType detected (should be "type" or "$ref")
        if (1 === \count($valueSchema)) {
            return $propertyMetadata->withSchema($propertySchema + $valueSchema[0]);
        }

        // multiple builtInTypes detected: determine oneOf/allOf if union vs intersect types
        try {
            $reflectionClass = new \ReflectionClass($resourceClass);
            $reflectionProperty = $reflectionClass->getProperty($property);
            $composition = $reflectionProperty->getType() instanceof \ReflectionUnionType ? 'oneOf' : 'allOf';
        } catch (\ReflectionException) {
            // cannot detect types
            $composition = 'anyOf';
        }

        return $propertyMetadata->withSchema($propertySchema + [$composition => $valueSchema]);
    }

    private function getType(Type $type, bool $readableLink = null): array
    {
        if ($type->asNonNullable() instanceof CollectionType) {
            if ($type->asNonNullable()->getCollectionKeyType()->isA(TypeIdentifier::STRING)) {
                return $this->addNullabilityToTypeDefinition([
                    'type' => 'object',
                    'additionalProperties' => $this->getType($type->asNonNullable()->getCollectionValueType(), $readableLink),
                ], $type);
            }

            return $this->addNullabilityToTypeDefinition([
                'type' => 'array',
                'items' => $this->getType($type->asNonNullable()->getCollectionValueType(), $readableLink),
            ], $type);
        }

        return $this->addNullabilityToTypeDefinition($this->typeToArray($type, $readableLink), $type);
    }

    private function getTypeLegacy(LegacyType $type, bool $readableLink = null): array
    {
        if (!$type->isCollection()) {
            return $this->addNullabilityToTypeDefinition($this->typeToArrayLegacy($type, $readableLink), $type);
        }

        $keyType = $type->getCollectionKeyTypes()[0] ?? null;
        $subType = ($type->getCollectionValueTypes()[0] ?? null) ?? new LegacyType($type->getBuiltinType(), false, $type->getClassName(), false);

        if (null !== $keyType && LegacyType::BUILTIN_TYPE_STRING === $keyType->getBuiltinType()) {
            return $this->addNullabilityToTypeDefinition([
                'type' => 'object',
                'additionalProperties' => $this->getType($subType, $readableLink),
            ], $type);
        }

        return $this->addNullabilityToTypeDefinition([
            'type' => 'array',
            'items' => $this->getType($subType, $readableLink),
        ], $type);
    }

    private function typeToArray(Type $type, bool $readableLink = null): array
    {
        if ($type->isA(TypeIdentifier::INT)) {
            return ['type' => 'integer'];
        }

        if ($type->isA(TypeIdentifier::FLOAT)) {
            return ['type' => 'number'];
        }

        if ($type->isA(TypeIdentifier::BOOL)) {
            return ['type' => 'boolean'];
        }

        if ($type->isA(TypeIdentifier::OBJECT)) {
            return $this->getClassType($type->getClassName(), $type->isNullable(), $readableLink);
        }

        return ['type' => 'string'];
    }

    private function typeToArrayLegacy(LegacyType $type, bool $readableLink = null): array
    {
        return match ($type->getBuiltinType()) {
            LegacyType::BUILTIN_TYPE_INT => ['type' => 'integer'],
            LegacyType::BUILTIN_TYPE_FLOAT => ['type' => 'number'],
            LegacyType::BUILTIN_TYPE_BOOL => ['type' => 'boolean'],
            LegacyType::BUILTIN_TYPE_OBJECT => $this->getClassType($type->getClassName(), $type->isNullable(), $readableLink),
            default => ['type' => 'string'],
        };
    }

    /**
     * Gets the JSON Schema document which specifies the data type corresponding to the given PHP class, and recursively adds needed new schema to the current schema if provided.
     *
     * Note: if the class is not part of exceptions listed above, any class is considered as a resource.
     */
    private function getClassType(?string $className, bool $nullable, ?bool $readableLink): array
    {
        if (null === $className) {
            return ['type' => 'string'];
        }

        if (is_a($className, \DateTimeInterface::class, true)) {
            return [
                'type' => 'string',
                'format' => 'date-time',
            ];
        }

        if (is_a($className, \DateInterval::class, true)) {
            return [
                'type' => 'string',
                'format' => 'duration',
            ];
        }

        if (is_a($className, UuidInterface::class, true) || is_a($className, Uuid::class, true)) {
            return [
                'type' => 'string',
                'format' => 'uuid',
            ];
        }

        if (is_a($className, Ulid::class, true)) {
            return [
                'type' => 'string',
                'format' => 'ulid',
            ];
        }

        if (is_a($className, \SplFileInfo::class, true)) {
            return [
                'type' => 'string',
                'format' => 'binary',
            ];
        }

        if (!$this->isResourceClass($className) && is_a($className, \BackedEnum::class, true)) {
            $enumCases = array_map(static fn (\BackedEnum $enum): string|int => $enum->value, $className::cases());

            $type = \is_string($enumCases[0] ?? '') ? 'string' : 'int';

            if ($nullable) {
                $enumCases[] = null;
            }

            return [
                'type' => $type,
                'enum' => $enumCases,
            ];
        }

        if (true !== $readableLink && $this->isResourceClass($className)) {
            return [
                'type' => 'string',
                'format' => 'iri-reference',
            ];
        }

        // TODO: add propertyNameCollectionFactory and recurse to find the underlying schema? Right now SchemaFactory does the job so we don't compute anything here.
        return ['type' => Schema::UNKNOWN_TYPE];
    }

    /**
     * @param array<string, mixed> $jsonSchema
     *
     * @return array<string, mixed>
     */
    private function addNullabilityToTypeDefinition(array $jsonSchema, Type|LegacyType $type): array
    {
        // if (!$type->isNullable()) {
        //     return $jsonSchema;
        // }

        if (\array_key_exists('$ref', $jsonSchema)) {
            return ['anyOf' => [$jsonSchema, 'type' => 'null']];
        }

        return [...$jsonSchema, ...[
            'type' => \is_array($jsonSchema['type'])
                ? array_merge($jsonSchema['type'], ['null'])
                : [$jsonSchema['type'], 'null'],
        ]];
    }
}
