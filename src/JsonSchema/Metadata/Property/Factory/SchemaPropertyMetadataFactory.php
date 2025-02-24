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

use ApiPlatform\JsonSchema\Schema;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\Exception\PropertyNotFoundException;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Metadata\ResourceClassResolverInterface;
use ApiPlatform\Metadata\Util\ResourceClassInfoTrait;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\BuiltinType;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\CompositeTypeInterface;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\Type\WrappingTypeInterface;
use Symfony\Component\TypeInfo\TypeIdentifier;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;

/**
 * Build ApiProperty::schema.
 */
final class SchemaPropertyMetadataFactory implements PropertyMetadataFactoryInterface
{
    use ResourceClassInfoTrait;

    public const JSON_SCHEMA_USER_DEFINED = 'user_defined_schema';

    public function __construct(
        ResourceClassResolverInterface $resourceClassResolver,
        private readonly ?PropertyMetadataFactoryInterface $decorated = null,
    ) {
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

        $type = $propertyMetadata->getPhpType();

        $typeIsResourceClass = function (Type $type) use (&$typeIsResourceClass): bool {
            return match (true) {
                $type instanceof CollectionType => $type->getCollectionValueType()->isSatisfiedBy($typeIsResourceClass),
                $type instanceof WrappingTypeInterface => $type->wrappedTypeIsSatisfiedBy($typeIsResourceClass),
                $type instanceof CompositeTypeInterface => $type->composedTypesAreSatisfiedBy($typeIsResourceClass),
                default => $type instanceof ObjectType && $this->isResourceClass($type->getClassName()),
            };
        };

        if (!\array_key_exists('default', $propertySchema) && !empty($default = $propertyMetadata->getDefault()) && !$type?->isSatisfiedBy($typeIsResourceClass)) {
            if ($default instanceof \BackedEnum) {
                $default = $default->value;
            }
            $propertySchema['default'] = $default;
        }

        if (!\array_key_exists('example', $propertySchema) && !empty($example = $propertyMetadata->getExample())) {
            $propertySchema['example'] = $example;
        }

        if (!\array_key_exists('example', $propertySchema) && \array_key_exists('default', $propertySchema)) {
            $propertySchema['example'] = $propertySchema['default'];
        }

        // never override the following keys if at least one is already set or if there's a custom openapi context
        if (null === $type
            || ($propertySchema['type'] ?? $propertySchema['$ref'] ?? $propertySchema['anyOf'] ?? $propertySchema['allOf'] ?? $propertySchema['oneOf'] ?? false)
            || \array_key_exists('type', $propertyMetadata->getOpenapiContext() ?? [])
        ) {
            return $propertyMetadata->withSchema($propertySchema);
        }

        $valueSchema = [];

        foreach ($type instanceof CompositeTypeInterface ? $type->getTypes() : [$type] as $t) {
            // nullability is handled in each part of the composite type
            if ($t instanceof BuiltinType && TypeIdentifier::NULL === $t->getTypeIdentifier()) {
                continue;
            }

            $propertyType = $t;

            if ($t instanceof CollectionType && null !== $propertyMetadata->getUriTemplate()) {
                $propertyType = $t->getCollectionValueType();
            }

            $propertySchemaType = $this->getType($propertyType, $type->isNullable(), $link);
            if (!\in_array($propertySchemaType, $valueSchema, true)) {
                $valueSchema[] = $propertySchemaType;
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

    private function getType(Type $type, bool $isNullable, ?bool $readableLink = null): array
    {
        if (!$type instanceof CollectionType) {
            return $this->addNullabilityToTypeDefinition($this->typeToArray($type, $isNullable, $readableLink), $isNullable);
        }

        if (!$type->getCollectionKeyType()->isIdentifiedBy(TypeIdentifier::INT)) {
            return $this->addNullabilityToTypeDefinition([
                'type' => 'object',
                'additionalProperties' => $this->getType($type->getCollectionValueType(), $isNullable, $readableLink),
            ], $isNullable);
        }

        return $this->addNullabilityToTypeDefinition([
            'type' => 'array',
            'items' => $this->getType($type->getCollectionValueType(), $isNullable, $readableLink),
        ], $isNullable);
    }

    private function typeToArray(Type $type, bool $isNullable, ?bool $readableLink = null): array
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

        while ($type instanceof WrappingTypeInterface) {
            $type = $type->getWrappedType();
        }

        if ($type instanceof ObjectType) {
            return $this->getClassType($type->getClassName(), $isNullable, $readableLink);
        }

        return ['type' => 'string'];
    }

    /**
     * Gets the JSON Schema document which specifies the data type corresponding to the given PHP class, and recursively adds needed new schema to the current schema if provided.
     *
     * Note: if the class is not part of exceptions listed above, any class is considered as a resource.
     *
     * @throws PropertyNotFoundException
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

        $isResourceClass = $this->isResourceClass($className);
        if (!$isResourceClass && is_a($className, \BackedEnum::class, true)) {
            $enumCases = array_map(static fn (\BackedEnum $enum): string|int => $enum->value, $className::cases());

            $type = \is_string($enumCases[0] ?? '') ? 'string' : 'integer';

            if ($nullable) {
                $enumCases[] = null;
            }

            return [
                'type' => $type,
                'enum' => $enumCases,
            ];
        }

        if (true !== $readableLink && $isResourceClass) {
            return [
                'type' => 'string',
                'format' => 'iri-reference',
                'example' => 'https://example.com/',
            ];
        }

        return ['type' => Schema::UNKNOWN_TYPE];
    }

    /**
     * @param array<string, mixed> $jsonSchema
     *
     * @return array<string, mixed>
     */
    private function addNullabilityToTypeDefinition(array $jsonSchema, bool $isNullable): array
    {
        if (!$isNullable) {
            return $jsonSchema;
        }

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
