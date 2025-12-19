<?php

declare(strict_types=1);

namespace NeuronCore\GraphRetrieval;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;

use function trim;
use function preg_replace;

/**
 * Converts natural language queries to Cypher statements using an LLM.
 *
 * This service uses few-shot learning to guide the LLM in generating
 * accurate Cypher queries based on the graph schema and example queries.
 */
class Text2CypherConverter
{
    /**
     * Built-in default examples for common query patterns.
     *
     * @var array<array{question: string, query: string}>
     */
    protected array $defaultExamples;

    public function __construct(
        protected readonly AIProviderInterface $aiProvider,
        protected readonly string $graphSchema,
    ) {
    }

    /**
     * Get built-in default examples for common graph query patterns.
     *
     * @return array<array{question: string, query: string}>
     */
    protected function getDefaultExamples(): array
    {
        return $this->defaultExamples ?? $this->defaultExamples = [
            [
                'question' => 'What are the relationships of John?',
                'query' => "MATCH (n:Entity {id: 'John'})-[r]->(m) RETURN type(r) AS relationship, m.id AS connected_entity",
            ],
            [
                'question' => 'Who is connected to Sarah through KNOWS relationship?',
                'query' => "MATCH (n:Entity {id: 'Sarah'})-[:KNOWS]->(m) RETURN m.id AS connected_entity",
            ],
            [
                'question' => 'Show me all entities that are related to the concept',
                'query' => "MATCH (n:Entity)-[r:RELATED_TO]->(m:Entity {id: 'concept'}) RETURN n.id AS entity, type(r) AS relationship",
            ],
            [
                'question' => 'What is the path between Alice and Bob?',
                'query' => "MATCH path = shortestPath((a:Entity {id: 'Alice'})-[*..5]-(b:Entity {id: 'Bob'})) RETURN path",
            ],
            [
                'question' => 'List all entities connected within 2 hops from the topic',
                'query' => "MATCH (n:Entity {id: 'topic'})-[*1..2]-(m) RETURN DISTINCT m.id AS entity",
            ],
        ];
    }

    /**
     * Convert a natural language query to Cypher.
     *
     * @param array<array{question: string, query: string}> $examples Custom examples to guide the LLM (optional, uses defaults if empty)
     */
    public function convert(string $query, array $examples = []): string
    {
        // Use provided examples or fall back to defaults
        $examplesForPrompt = $examples !== [] ? $examples : $this->getDefaultExamples();

        $prompt = $this->buildPrompt($query, $examplesForPrompt);

        $message = new UserMessage($prompt);
        $response = $this->aiProvider->chat([$message]);

        return $this->extractCypherQuery($response->getContent());
    }

    /**
     * Build the prompt for the LLM.
     *
     * @param array<array{question: string, query: string}> $examples
     */
    protected function buildPrompt(string $query, array $examples): string
    {
        $prompt = <<<PROMPT
You are an expert at converting natural language questions into Cypher queries for Neo4j graph databases.

# Graph Schema
{$this->graphSchema}

# Task
Convert the following natural language question into a valid Cypher query.

# Guidelines
1. Use the graph schema above to understand available nodes and relationships
2. Generate queries that are efficient and return relevant results
3. Use MATCH clauses for retrieving data
4. Use WHERE clauses for filtering when needed
5. Return only the Cypher query without any explanation or markdown formatting
6. Do not include backticks, code fences, or the word "cypher"
7. Ensure the query is a single valid Cypher statement

PROMPT;

        // Add few-shot examples if available
        if ($examples !== []) {
            $prompt .= "\n# Examples\nHere are some example conversions:\n\n";

            foreach ($examples as $example) {
                $prompt .= "Question: {$example['question']}\n";
                $prompt .= "Cypher: {$example['query']}\n\n";
            }
        }

        return $prompt . "\n# Question\n{$query}\n\n# Cypher Query\n";
    }

    /**
     * Extract the Cypher query from the LLM response.
     *
     * Cleans up markdown formatting and extracts the actual query.
     */
    protected function extractCypherQuery(string $response): string
    {
        // Remove markdown code fences if present
        $query = preg_replace('/```(?:cypher)?\s*\n?/', '', $response);
        $query = preg_replace('/```\s*$/', '', (string) $query);

        // Trim whitespace
        $query = trim((string) $query);

        return $query;
    }
}
