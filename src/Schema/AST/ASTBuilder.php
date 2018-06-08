<?php

namespace Nuwave\Lighthouse\Schema\AST;

use GraphQL\Language\AST\FieldDefinitionNode;
use GraphQL\Language\AST\InputValueDefinitionNode;
use GraphQL\Language\AST\NamedTypeNode;
use GraphQL\Language\AST\NodeList;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use GraphQL\Language\AST\TypeExtensionDefinitionNode;
use Nuwave\Lighthouse\Schema\Directives\Args\ArgManipulator;
use Nuwave\Lighthouse\Schema\Directives\Fields\FieldManipulator;
use Nuwave\Lighthouse\Schema\Directives\Types\TypeManipulator;

class ASTBuilder
{
    /**
     * @param string $schema
     *
     * @return DocumentAST
     */
    public static function generate($schema)
    {
        $document = DocumentAST::parse($schema);

        // Node manipulators may be defined on type extensions
        $document = self::applyNodeManipulators($document);
        // After they have been applied, we can safely merge them
        $document = self::mergeTypeExtensions($document);

        $document = self::applyFieldManipulators($document);
        $document = self::applyArgManipulators($document);

        $document = self::addNodeSupport($document);

        return $document;
    }

    protected static function mergeTypeExtensions(DocumentAST $document)
    {
        $document->objectTypes()->each(function (ObjectTypeDefinitionNode $objectType) use ($document) {
            $name = $objectType->name->value;

            if ($typeExtension = $document->getTypeExtension($name)) {
                /** @var NodeList $fields */
                $fields = $objectType->fields;
                $objectType->fields = $fields->merge($typeExtension->definition->fields);
                // Modify the original document by overwriting the definition with the merged one
                $document->setDefinition($objectType);
            }
        });

        return $document;
    }

    /**
     * Inject the node type and a node field into Query.
     *
     * @param DocumentAST $document
     *
     * @return DocumentAST
     */
    protected static function addNodeSupport(DocumentAST $document)
    {
        $hasTypeImplementingNode = $document->objectTypes()->contains(function (ObjectTypeDefinitionNode $objectType) {
            return collect($objectType->interfaces)->contains(function (NamedTypeNode $interface) {
                return 'Node' === $interface->name->value;
            });
        });

        // Only add the node type and node field if a type actually implements them
        // Otherwise, a validation error is thrown
        if (! $hasTypeImplementingNode) {
            return $document;
        }

        $globalId = config('lighthouse.global_id_field', '_id');

        // Double slashes to escape the slashes in the namespace.
        $interface = DocumentAST::parseInterfaceDefinition("
            # Node global interface
            interface Node @interface(resolver: \"Nuwave\\\\Lighthouse\\\\Support\\\\Http\\\\GraphQL\\\\Interfaces\\\\NodeInterface@resolve\") {
              # Global identifier that can be used to resolve any Node implementation.
              $globalId: ID!
            }
        ");
        $document->setDefinition($interface);

        $nodeQuery = DocumentAST::parseFieldDefinition('node(id: ID!): Node @field(resolver: "Nuwave\\\Lighthouse\\\Support\\\Http\\\GraphQL\\\Queries\\\NodeQuery@resolve")');
        $document->addFieldToQueryType($nodeQuery);

        return $document;
    }

    /**
     * @param DocumentAST $document
     *
     * @return DocumentAST
     */
    protected static function applyNodeManipulators(DocumentAST $document)
    {
        $originalDocument = $document;

        // Unwrap extended types so they can be treated same as other types
        $extendedTypes = $document->typeExtensions()->map(function (TypeExtensionDefinitionNode $typeExtension) {
            return $typeExtension->definition;
        });
        $objectTypes = $document->objectTypes()->concat($extendedTypes);

        return $objectTypes->reduce(function (DocumentAST $document, ObjectTypeDefinitionNode $objectType) use ($originalDocument) {
            $nodeManipulators = directives()->nodeManipulators($objectType);

            return $nodeManipulators->reduce(function (DocumentAST $document, TypeManipulator $nodeManipulator) use ($originalDocument, $objectType) {
                return $nodeManipulator->manipulateSchema($objectType, $document, $originalDocument);
            }, $document);
        }, $document);
    }

    /**
     * @param DocumentAST $document
     *
     * @return DocumentAST
     */
    protected static function applyFieldManipulators(DocumentAST $document)
    {
        $originalDocument = $document;

        return $document->objectTypes()->reduce(function (DocumentAST $document, ObjectTypeDefinitionNode $objectType) use ($originalDocument) {
            return collect($objectType->fields)->reduce(function (DocumentAST $document, FieldDefinitionNode $fieldDefinition) use ($objectType, $originalDocument) {
                $fieldManipulators = directives()->fieldManipulators($fieldDefinition);

                return $fieldManipulators->reduce(function (DocumentAST $document, FieldManipulator $fieldManipulator) use ($fieldDefinition, $objectType, $originalDocument) {
                    return $fieldManipulator->manipulateSchema($fieldDefinition, $objectType, $document, $originalDocument);
                }, $document);
            }, $document);
        }, $document);
    }

    /**
     * @param DocumentAST $document
     *
     * @return DocumentAST
     */
    protected static function applyArgManipulators(DocumentAST $document)
    {
        $originalDocument = $document;

        return $document->objectTypes()->reduce(
            function (DocumentAST $document, ObjectTypeDefinitionNode $parentType) use ($originalDocument) {
                return collect($parentType->fields)->reduce(
                    function (DocumentAST $document, FieldDefinitionNode $parentField) use ($parentType, $originalDocument) {
                        return collect($parentField->arguments)->reduce(
                            function (DocumentAST $document, InputValueDefinitionNode $argDefinition) use ($parentType, $parentField, $originalDocument) {
                                $argManipulators = directives()->argManipulators($argDefinition);

                                return $argManipulators->reduce(
                                    function (DocumentAST $document, ArgManipulator $argManipulator) use ($argDefinition, $parentField, $parentType, $originalDocument) {
                                        return $argManipulator->manipulateSchema($argDefinition, $parentField, $parentType, $document, $originalDocument);
                                    }, $document);
                            }, $document);
                    }, $document);
            }, $document);
    }
}
