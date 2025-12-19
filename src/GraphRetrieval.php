<?php

declare(strict_types=1);

namespace NeuronCore\GraphRetrieval;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\GraphStore\GraphStoreInterface;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use Exception;
use JsonException;

use function array_values;
use function implode;
use function is_array;
use function is_object;
use function json_encode;
use function md5;

use const JSON_THROW_ON_ERROR;

class GraphRetrieval implements RetrievalInterface
{
    /**
     * @param array<array{question: string, query: string}> $examples Few-shot examples for Text2Cypher conversion
     */
    public function __construct(
        protected readonly VectorStoreInterface $vectorStore,
        protected readonly EmbeddingsProviderInterface $embeddingProvider,
        protected readonly AIProviderInterface $aiProvider,
        protected readonly GraphStoreInterface $graphStore,
        protected readonly array $examples = [],
    ) {
    }

    /**
     * @return Document[]
     */
    public function retrieve(Message $query): array
    {
        $queryText = $query->getContent();

        // Step 1: Vector similarity search for relevant documents
        $vectorDocuments = $this->retrieveFromVectorStore($queryText);

        // Step 2: Convert query to Cypher and retrieve from graph
        $graphDocuments = $this->retrieveFromGraph($queryText);

        // Step 3: Combine and deduplicate results
        return $this->combineResults($vectorDocuments, $graphDocuments);
    }

    /**
     * Retrieve documents from vector store using similarity search.
     *
     * @return Document[]
     */
    protected function retrieveFromVectorStore(string $query): array
    {
        $embedding = $this->embeddingProvider->embedText($query);
        return $this->vectorStore->similaritySearch($embedding);
    }

    /**
     * Retrieve structured information from graph database.
     *
     * Uses Text2Cypher to convert natural language query to Cypher,
     * then executes it on the graph database.
     *
     * @return Document[]
     */
    protected function retrieveFromGraph(string $query): array
    {
        // Convert query to Cypher using provided examples
        $converter = new Text2CypherConverter(
            $this->aiProvider,
            $this->graphStore->getSchema()
        );

        $cypherQuery = $converter->convert($query, $this->examples);

        // Execute Cypher query
        try {
            $results = $this->graphStore->query($cypherQuery);
            return $this->convertGraphResultsToDocuments($results, $query);
        } catch (Exception) {
            // If Cypher query fails, return empty array
            // In production, you might want to log this error
            return [];
        }
    }

    /**
     * Convert graph query results to Document objects.
     *
     * @param mixed $results Raw results from graph database
     * @return Document[]
     * @throws JsonException
     */
    protected function convertGraphResultsToDocuments(mixed $results, string $originalQuery): array
    {
        if (!is_array($results) || $results === []) {
            return [];
        }

        $documents = [];

        foreach ($results as $index => $record) {
            // Convert each record to a formatted string
            $content = $this->formatGraphRecord($record);

            if ($content !== '') {
                $document = new Document($content);
                $document->sourceType = 'graph';
                $document->sourceName = 'knowledge_graph';
                $document->addMetadata('query', $originalQuery);
                $document->addMetadata('record_index', $index);

                $documents[] = $document;
            }
        }

        return $documents;
    }

    /**
     * Format a graph database record into readable text.
     * @throws JsonException
     */
    protected function formatGraphRecord(array $record): string
    {
        $parts = [];

        foreach ($record as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_THROW_ON_ERROR);
            } elseif (is_object($value)) {
                $value = json_encode($value, JSON_THROW_ON_ERROR);
            }

            $parts[] = "{$key}: {$value}";
        }

        return implode(', ', $parts);
    }

    /**
     * Combine and deduplicate results from vector and graph sources.
     *
     * @param Document[] $vectorDocs
     * @param Document[] $graphDocs
     * @return Document[]
     */
    protected function combineResults(array $vectorDocs, array $graphDocs): array
    {
        // Start with vector documents (typically more relevant for semantic search)
        $combined = $vectorDocs;

        // Add graph documents
        foreach ($graphDocs as $graphDoc) {
            $combined[] = $graphDoc;
        }

        // Deduplicate by content hash
        $unique = [];
        foreach ($combined as $doc) {
            $hash = md5($doc->getContent());
            if (!isset($unique[$hash])) {
                $unique[$hash] = $doc;
            }
        }

        return array_values($unique);
    }
}
