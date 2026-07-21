<?php

namespace App\Services;

final readonly class AiHelperQueryPlan
{
    /**
     * @param  array<int, string>  $terms
     * @param  array<int, string>  $expandedTerms
     * @param  array<int, string>  $topicKeys
     * @param  array<int, string>  $operationKeys
     * @param  array<int, string>  $taskKeys
     * @param  array<int, string>  $subqueries
     * @param  array<int, int>  $annexNumbers
     * @param  array<int, string>  $revisions
     * @param  array<int, string>  $documentCodes
     */
    public function __construct(
        public string $intent,
        public string $sourceMode,
        public string $contextDependency,
        public string $queryScope,
        public string $language,
        public string $message,
        public string $query,
        public string $normalizedQuery,
        public array $terms,
        public array $expandedTerms,
        public array $topicKeys,
        public array $operationKeys,
        public array $taskKeys,
        public array $subqueries,
        public array $annexNumbers,
        public array $revisions,
        public array $documentCodes,
        public bool $followUp,
        public bool $requiresMultipleDocuments,
        public bool $sensitiveRequest,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'intent' => $this->intent,
            'source_mode' => $this->sourceMode,
            'context_dependency' => $this->contextDependency,
            'query_scope' => $this->queryScope,
            'language' => $this->language,
            'message' => $this->message,
            'query' => $this->query,
            'normalized_query' => $this->normalizedQuery,
            'terms' => $this->terms,
            'expanded_terms' => $this->expandedTerms,
            'routing_terms' => array_values(array_unique(array_merge($this->terms, $this->expandedTerms))),
            'topic_keys' => $this->topicKeys,
            'operation_keys' => $this->operationKeys,
            'task_keys' => $this->taskKeys,
            'subqueries' => $this->subqueries,
            'annex_numbers' => $this->annexNumbers,
            'revisions' => $this->revisions,
            'document_codes' => $this->documentCodes,
            'follow_up' => $this->followUp,
            'requires_multiple_documents' => $this->requiresMultipleDocuments,
            'sensitive_request' => $this->sensitiveRequest,
        ];
    }
}
