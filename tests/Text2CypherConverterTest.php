<?php

declare(strict_types=1);

namespace NeuronCore\GraphRetrieval\Tests;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronCore\GraphRetrieval\Text2CypherConverter;
use PHPUnit\Framework\TestCase;

class Text2CypherConverterTest extends TestCase
{
    public function test_convert_query_to_cypher(): void
    {
        $aiProvider = $this->createMock(AIProviderInterface::class);
        $aiProvider->expects($this->once())
            ->method('chat')
            ->willReturn(new AssistantMessage('MATCH (n:Entity {id: "John"}) RETURN n'));

        $schema = "Node Labels:\n  - Entity\nRelationship Types:\n  - KNOWS";

        $converter = new Text2CypherConverter($aiProvider, $schema);
        $result = $converter->convert('Tell me about John');

        $this->assertEquals('MATCH (n:Entity {id: "John"}) RETURN n', $result);
    }

    public function test_extract_cypher_from_markdown(): void
    {
        $aiProvider = $this->createMock(AIProviderInterface::class);
        $aiProvider->expects($this->once())
            ->method('chat')
            ->willReturn(new AssistantMessage("```cypher\nMATCH (n) RETURN n\n```"));

        $schema = "Node Labels:\n  - Entity";

        $converter = new Text2CypherConverter($aiProvider, $schema);
        $result = $converter->convert('Get all nodes');

        $this->assertEquals('MATCH (n) RETURN n', $result);
    }

    public function test_uses_custom_examples(): void
    {
        $aiProvider = $this->createMock(AIProviderInterface::class);
        $aiProvider->expects($this->once())
            ->method('chat')
            ->with($this->callback(function (array $messages): bool {
                $message = $messages[0];
                $this->assertInstanceOf(UserMessage::class, $message);
                $content = $message->getContent();
                // Should contain the custom example
                $this->assertStringContainsString('Custom question', $content);
                $this->assertStringContainsString('Custom cypher', $content);
                return true;
            }))
            ->willReturn(new AssistantMessage('MATCH (n) RETURN n'));

        $schema = "Node Labels:\n  - Entity";
        $examples = [
            ['question' => 'Custom question', 'query' => 'Custom cypher'],
        ];

        $converter = new Text2CypherConverter($aiProvider, $schema);
        $converter->convert('Test query', $examples);
    }

    public function test_uses_default_examples_when_none_provided(): void
    {
        $aiProvider = $this->createMock(AIProviderInterface::class);
        $aiProvider->expects($this->once())
            ->method('chat')
            ->with($this->callback(function (array $messages): bool {
                $message = $messages[0];
                $content = $message->getContent();
                // Should contain at least one default example pattern
                $this->assertStringContainsString('# Examples', $content);
                return true;
            }))
            ->willReturn(new AssistantMessage('MATCH (n) RETURN n'));

        $schema = "Node Labels:\n  - Entity";

        $converter = new Text2CypherConverter($aiProvider, $schema);
        $converter->convert('Test query'); // No examples provided
    }

    public function test_includes_graph_schema_in_prompt(): void
    {
        $schema = "Node Labels:\n  - Person\n  - Company\nRelationship Types:\n  - WORKS_AT";

        $aiProvider = $this->createMock(AIProviderInterface::class);
        $aiProvider->expects($this->once())
            ->method('chat')
            ->with($this->callback(function (array $messages) use ($schema): bool {
                $message = $messages[0];
                $content = $message->getContent();
                // Should include the schema in the prompt
                $this->assertStringContainsString($schema, $content);
                return true;
            }))
            ->willReturn(new AssistantMessage('MATCH (n) RETURN n'));

        $converter = new Text2CypherConverter($aiProvider, $schema);
        $converter->convert('Test query');
    }
}
