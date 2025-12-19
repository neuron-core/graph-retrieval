<?php

declare(strict_types=1);

namespace NeuronCore\GraphRetrieval\Tests;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\GraphStore\GraphStoreInterface;
use NeuronCore\GraphRetrieval\GraphRetrieval;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use PHPUnit\Framework\TestCase;
use Exception;

use function array_map;
use function count;

class GraphRetrievalTest extends TestCase
{
    public function test_implements_retrieval_interface(): void
    {
        $vectorStore = $this->createMock(VectorStoreInterface::class);
        $embeddingProvider = $this->createMock(EmbeddingsProviderInterface::class);
        $graphStore = $this->createMock(GraphStoreInterface::class);
        $aiProvider = $this->createMock(AIProviderInterface::class);

        $retrieval = new GraphRetrieval(
            vectorStore: $vectorStore,
            embeddingProvider: $embeddingProvider,
            graphStore: $graphStore,
            aiProvider: $aiProvider
        );

        $this->assertInstanceOf(RetrievalInterface::class, $retrieval);
    }

    public function test_retrieve_combines_vector_and_graph_results(): void
    {
        // Mock vector store
        $vectorStore = $this->createMock(VectorStoreInterface::class);
        $vectorDoc = new Document('Vector result');
        $vectorDoc->sourceType = 'vector';
        $vectorStore->expects($this->once())
            ->method('similaritySearch')
            ->willReturn([$vectorDoc]);

        // Mock embedding provider
        $embeddingProvider = $this->createMock(EmbeddingsProviderInterface::class);
        $embeddingProvider->expects($this->once())
            ->method('embedText')
            ->willReturn([0.1, 0.2, 0.3]);

        // Mock graph store
        $graphStore = $this->createMock(GraphStoreInterface::class);
        $graphStore->expects($this->once())
            ->method('getSchema')
            ->willReturn('Node Labels:\n  - Entity');
        $graphStore->expects($this->once())
            ->method('query')
            ->willReturn([['name' => 'John', 'age' => 30]]);

        // Mock AI provider
        $aiProvider = $this->createMock(AIProviderInterface::class);
        $aiProvider->expects($this->once())
            ->method('chat')
            ->willReturn(new AssistantMessage('MATCH (n) RETURN n'));

        $retrieval = new GraphRetrieval(
            vectorStore: $vectorStore,
            embeddingProvider: $embeddingProvider,
            graphStore: $graphStore,
            aiProvider: $aiProvider
        );

        $query = new UserMessage('Tell me about John');
        $results = $retrieval->retrieve($query);

        // Should have results from both vector and graph
        $this->assertGreaterThan(0, count($results));

        // Check that we have at least the vector document
        $sourceTypes = array_map(fn (\NeuronAI\RAG\Document $doc): string => $doc->sourceType, $results);
        $this->assertContains('vector', $sourceTypes);
    }

    public function test_handles_graph_query_failure_gracefully(): void
    {
        // Mock vector store
        $vectorStore = $this->createMock(VectorStoreInterface::class);
        $vectorDoc = new Document('Vector result');
        $vectorStore->expects($this->once())
            ->method('similaritySearch')
            ->willReturn([$vectorDoc]);

        // Mock embedding provider
        $embeddingProvider = $this->createMock(EmbeddingsProviderInterface::class);
        $embeddingProvider->expects($this->once())
            ->method('embedText')
            ->willReturn([0.1, 0.2, 0.3]);

        // Mock graph store that throws exception
        $graphStore = $this->createMock(GraphStoreInterface::class);
        $graphStore->expects($this->once())
            ->method('getSchema')
            ->willReturn('Node Labels:\n  - Entity');
        $graphStore->expects($this->once())
            ->method('query')
            ->willThrowException(new Exception('Graph query failed'));

        // Mock AI provider
        $aiProvider = $this->createMock(AIProviderInterface::class);
        $aiProvider->expects($this->once())
            ->method('chat')
            ->willReturn(new AssistantMessage('MATCH (n) RETURN n'));

        $retrieval = new GraphRetrieval(
            vectorStore: $vectorStore,
            embeddingProvider: $embeddingProvider,
            graphStore: $graphStore,
            aiProvider: $aiProvider
        );

        $query = new UserMessage('Tell me about John');
        $results = $retrieval->retrieve($query);

        // Should still return vector results even if graph fails
        $this->assertCount(1, $results);
        $this->assertEquals('Vector result', $results[0]->getContent());
    }

    public function test_deduplicates_results(): void
    {
        // Mock vector store with duplicate content
        $vectorStore = $this->createMock(VectorStoreInterface::class);
        $doc1 = new Document('Duplicate content');
        $doc2 = new Document('Duplicate content');
        $vectorStore->expects($this->once())
            ->method('similaritySearch')
            ->willReturn([$doc1, $doc2]);

        // Mock embedding provider
        $embeddingProvider = $this->createMock(EmbeddingsProviderInterface::class);
        $embeddingProvider->expects($this->once())
            ->method('embedText')
            ->willReturn([0.1, 0.2, 0.3]);

        // Mock graph store
        $graphStore = $this->createMock(GraphStoreInterface::class);
        $graphStore->expects($this->once())
            ->method('getSchema')
            ->willReturn('Schema');
        $graphStore->expects($this->once())
            ->method('query')
            ->willReturn([]);

        // Mock AI provider
        $aiProvider = $this->createMock(AIProviderInterface::class);
        $aiProvider->expects($this->once())
            ->method('chat')
            ->willReturn(new AssistantMessage('MATCH (n) RETURN n'));

        $retrieval = new GraphRetrieval(
            vectorStore: $vectorStore,
            embeddingProvider: $embeddingProvider,
            graphStore: $graphStore,
            aiProvider: $aiProvider
        );

        $query = new UserMessage('Test query');
        $results = $retrieval->retrieve($query);

        // Duplicates should be removed
        $this->assertCount(1, $results);
    }

    public function test_converts_graph_results_to_documents(): void
    {
        // Mock vector store
        $vectorStore = $this->createMock(VectorStoreInterface::class);
        $vectorStore->expects($this->once())
            ->method('similaritySearch')
            ->willReturn([]);

        // Mock embedding provider
        $embeddingProvider = $this->createMock(EmbeddingsProviderInterface::class);
        $embeddingProvider->expects($this->once())
            ->method('embedText')
            ->willReturn([0.1, 0.2, 0.3]);

        // Mock graph store with structured data
        $graphStore = $this->createMock(GraphStoreInterface::class);
        $graphStore->expects($this->once())
            ->method('getSchema')
            ->willReturn('Schema');
        $graphStore->expects($this->once())
            ->method('query')
            ->willReturn([
                ['name' => 'John', 'age' => 30],
                ['name' => 'Jane', 'age' => 25],
            ]);

        // Mock AI provider
        $aiProvider = $this->createMock(AIProviderInterface::class);
        $aiProvider->expects($this->once())
            ->method('chat')
            ->willReturn(new AssistantMessage('MATCH (n) RETURN n'));

        $retrieval = new GraphRetrieval(
            vectorStore: $vectorStore,
            embeddingProvider: $embeddingProvider,
            graphStore: $graphStore,
            aiProvider: $aiProvider
        );

        $query = new UserMessage('Get all people');
        $results = $retrieval->retrieve($query);

        // Should have 2 documents from graph results
        $this->assertCount(2, $results);

        // Check that graph results are properly formatted
        foreach ($results as $doc) {
            $this->assertEquals('graph', $doc->sourceType);
            $this->assertEquals('knowledge_graph', $doc->sourceName);
            $this->assertNotEmpty($doc->getContent());
        }
    }
}
