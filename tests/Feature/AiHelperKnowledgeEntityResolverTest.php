<?php

namespace Tests\Feature;

use App\Models\AiHelperKnowledgeEntity;
use App\Models\AiHelperKnowledgeEntry;
use App\Services\AiHelperKnowledgeEntityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiHelperKnowledgeEntityResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolution_is_limited_to_the_authorized_entry_ids(): void
    {
        $shared = $this->entry('Shared response guide');
        $private = $this->entry('Private response guide');
        $this->entity($shared, 'Tactical Response Team Member', ['TRT', 'TRT member']);
        $this->entity($private, 'Confidential Tactical Response Team', ['TRT']);

        $result = app(AiHelperKnowledgeEntityResolver::class)->resolve(
            'What is the role of a TRT member?',
            [$shared->id],
        );

        $this->assertCount(1, $result['matches']);
        $this->assertSame($shared->id, $result['matches'][0]['knowledge_entry_id']);
        $this->assertSame('Tactical Response Team Member', $result['matches'][0]['canonical_name']);
        $this->assertSame([], $result['ambiguous_aliases']);
    }

    public function test_two_character_aliases_do_not_match_ordinary_lowercase_words(): void
    {
        $entry = $this->entry('Incident guide');
        $this->entity($entry, 'Incident Scenario', ['IS']);
        $resolver = app(AiHelperKnowledgeEntityResolver::class);

        $ordinarySentence = $resolver->resolve('What is the role?', [$entry->id]);
        $explicitAcronym = $resolver->resolve('What does IS require?', [$entry->id]);

        $this->assertSame([], $ordinarySentence['matches']);
        $this->assertCount(1, $explicitAcronym['matches']);
    }

    public function test_word_like_acronyms_require_explicit_uppercase(): void
    {
        $entry = $this->entry('Health guide');
        $this->entity($entry, 'World Health Organization', ['WHO']);
        $resolver = app(AiHelperKnowledgeEntityResolver::class);

        $ordinaryQuestion = $resolver->resolve('Who owns this role?', [$entry->id]);
        $explicitAcronym = $resolver->resolve('What does WHO recommend?', [$entry->id]);

        $this->assertSame([], $ordinaryQuestion['matches']);
        $this->assertCount(1, $explicitAcronym['matches']);
    }

    public function test_aid_alias_does_not_capture_ordinary_mutual_aid_language(): void
    {
        $entry = $this->entry('Medical guide');
        $this->entity($entry, 'Assistance Instruction Detail', ['AID']);
        $resolver = app(AiHelperKnowledgeEntityResolver::class);

        $ordinaryQuestion = $resolver->resolve('Who should call for mutual aid?', [$entry->id]);
        $explicitAcronym = $resolver->resolve('What does AID require?', [$entry->id]);

        $this->assertSame([], $ordinaryQuestion['matches']);
        $this->assertCount(1, $explicitAcronym['matches']);
    }

    private function entry(string $title): AiHelperKnowledgeEntry
    {
        return AiHelperKnowledgeEntry::create([
            'knowledge_type' => AiHelperKnowledgeEntry::KNOWLEDGE_REFERENCE_DOCUMENT,
            'title' => $title,
            'content' => 'test',
            'source_filename' => str($title)->slug().'.md',
            'source_mime' => 'text/markdown',
            'source_path' => 'seed:test:'.str($title)->slug(),
            'scope_type' => AiHelperKnowledgeEntry::SCOPE_GLOBAL,
            'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'active' => true,
        ]);
    }

    /** @param array<int, string> $aliases */
    private function entity(AiHelperKnowledgeEntry $entry, string $name, array $aliases): void
    {
        $entity = AiHelperKnowledgeEntity::create([
            'knowledge_entry_id' => $entry->id,
            'canonical_name' => $name,
            'normalized_name' => str($name)->lower()->toString(),
            'entity_type' => 'role',
            'confidence' => 1,
            'ingestion_version' => 1,
            'active' => true,
        ]);

        foreach ($aliases as $alias) {
            $entity->aliases()->create([
                'alias' => $alias,
                'normalized_alias' => str($alias)->lower()->toString(),
                'alias_type' => 'test',
            ]);
        }
    }
}
