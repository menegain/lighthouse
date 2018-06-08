<?php

namespace Tests\Unit\Schema;

use GraphQL\Language\Parser;
use GraphQL\Type\Definition\ScalarType;
use Nuwave\Lighthouse\Schema\Directives\Types\ScalarDirective;
use Nuwave\Lighthouse\Schema\Values\TypeValue;
use Nuwave\Lighthouse\Support\Exceptions\DirectiveException;
use Tests\TestCase;

class DirectiveRegistryTest extends TestCase
{
    /**
     * @test
     */
    public function itRegistersLighthouseDirectives()
    {
        $this->assertInstanceOf(ScalarDirective::class, directives()->handler(ScalarDirective::name()));
    }

    /**
     * @test
     */
    public function itGetsLighthouseHandlerForScalar()
    {
        $schema = 'scalar Email @scalar(class: "Email")';
        $document = Parser::parse($schema);
        $definition = $document->definitions[0];
        $scalar = directives()->typeResolverForNode($definition)
            ->resolveType(new TypeValue($definition));

        $this->assertInstanceOf(ScalarType::class, $scalar);
    }

    /**
     * @test
     */
    public function itThrowsErrorIfMultipleDirectivesAssignedToNode()
    {
        $this->expectException(DirectiveException::class);
        echo 'fix me';
//
//        $document = $this->buildSchemaFromString('
//            scalar DateTime @scalar @foo
//        ');
//        $handler = directives()->typeResolverForNode($document->);
    }

    /**
     * @test
     */
    public function itCanCheckIfFieldHasAResolverDirective()
    {
        $schema = '
        type Foo {
            bar: [Bar!]! @hasMany
        }
        ';

        $document = Parser::parse($schema);
        $hasResolver = directives()->hasResolver($document->definitions[0]->fields[0]);
        $this->assertTrue($hasResolver);
    }

    /**
     * @test
     */
    public function itThrowsExceptionsWhenMultipleFieldResolverDirectives()
    {
        $this->expectException(DirectiveException::class);

        $schema = '
        type Foo {
            bar: [Bar!]! @hasMany @hasMany
        }
        ';

        $document = Parser::parse($schema);
        directives()->fieldResolver($document->definitions[0]->fields[0]);
    }

    /**
     * @test
     */
    public function itCanGetCollectionOfFieldMiddleware()
    {
        $schema = '
        type Foo {
            bar: String @can(if: ["viewBar"]) @event
        }
        ';

        $document = Parser::parse($schema);
        $middleware = directives()->fieldMiddleware($document->definitions[0]->fields[0]);
        $this->assertCount(2, $middleware);
    }
}
