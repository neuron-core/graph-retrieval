# Retrieval for GraphRAG

This module implements the GraphRAG retrieval strategy for the Neuron AI PHP framework.

## Requirements

- PHP: ^8.1
- Neuron: ^2.10

## Installation

Install the latest version of the package:

```
composer require neuron-core/graph-retrieval
```

## How to use GraphRetrieval in your agent

Return an instance of `GraphRetrieval` from the RAG method `retrieval()`:

```php
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\GraphStore\Neo4jGraphStore;
use NeuronCore\GraphRetrieval\GraphRetrieval;

class GraphRAGAgent extends RAG
{
    protected function retrieval(): RetrievalInterface
    {
        return new GraphRetrieval(
            $this->resolveVectorStore(),
            $this->resolveEmbeddingsProvider(),
            $this->resolveProvider(),
            new Neo4jGraphStore()
        );
    }

    protected function embeddings(): EmbeddingsProviderInterface
    {
        return new ...
    }

    protected function vectorStore(): VectorStoreInterface
    {
        return new ...
    }
}
```

## What is Neuron?

Neuron is a PHP framework for creating and orchestrating AI Agents. It allows you to integrate AI entities in your existing
PHP applications with a powerful and flexible architecture. We provide tools for the entire agentic application development lifecycle, from LLM interfaces, to data loading, to multi-agent orchestration, to monitoring and debugging.

In addition, we provide tutorials and other educational content to help you get started using AI Agents in your projects.

**[Go to the official documentation](https://neuron.inspector.dev/)**

[**Video Tutorial**](https://www.youtube.com/watch?v=oSA1bP_j41w)

[![Neuron & Inspector](./docs/youtube.png)](https://www.youtube.com/watch?v=oSA1bP_j41w)

