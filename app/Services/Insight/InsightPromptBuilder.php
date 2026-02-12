<?php

namespace App\Services\Insight;

use App\Enums\InsightCategory;

class InsightPromptBuilder
{
    /**
     * Prompt for extracting atomic facts per participant from a transcript.
     *
     * @param  array<string, string>  $participantMap  name => email
     */
    public function buildExtractionPrompt(
        string $transcript,
        array $participantMap,
        string $meetingTitle,
        string $meetingDate,
    ): string {
        $participantList = collect($participantMap)
            ->map(fn($email, $name) => "- \"{$name}\" => {$email}")
            ->implode("\n");

        $categories = implode(', ', InsightCategory::values());

        return <<<PROMPT
You are an expert behavioral analyst. Analyze the meeting transcript and extract behavioral insights for each participant.

## Meeting Info
Title: {$meetingTitle}
Date: {$meetingDate}

## Known Participants (name => email)
{$participantList}

Only extract insights for participants whose emails are listed above.
If a speaker is not in the list, skip them.

## Categories
{$categories}

Category definitions:
- communication_style: tone, listening behavior, preferred format, language patterns
- work_patterns: meeting role, decision style, deadline reliability, collaboration preference
- strengths: things the person is clearly good at, with evidence from this meeting
- development_areas: weaknesses or growth areas, with evidence from this meeting
- goals_motivations: current goals, what drives them, concerns they expressed
- psychological_profile: personality traits, stress indicators, trust level, conflict style

## Instructions
1. Extract only facts clearly supported by this transcript — do not guess.
2. Each fact must be a single, specific, atomic observation.
3. Confidence: 0.9 = very clear, 0.7 = likely, 0.5 = possible but uncertain.
4. For short_term, capture only what is true RIGHT NOW based on this meeting.
5. Relationships: only include pairs that had meaningful interaction in this meeting.
6. Use only the provided emails as identifiers.

## Required JSON Output Format
```json
{
  "participants": [
    {
      "email": "person@example.com",
      "name": "Person Name",
      "items": [
        {
          "category": "communication_style",
          "fact": "Speaks concisely, uses numbered lists to structure arguments",
          "confidence": 0.9
        }
      ],
      "short_term": [
        {
          "context_type": "emotional_state",
          "content": {
            "mood": "focused",
            "energy_level": "high",
            "stress_indicators": []
          }
        },
        {
          "context_type": "current_projects",
          "content": {
            "active": ["project name"],
            "recently_completed": []
          }
        },
        {
          "context_type": "recent_decisions",
          "content": {
            "decisions": ["decided to postpone feature X"]
          }
        }
      ]
    }
  ],
  "relationships": [
    {
      "email_a": "person_a@example.com",
      "email_b": "person_b@example.com",
      "observation": "A consistently supports B's proposals, they work well together",
      "relationship_type": "collaborative"
    }
  ]
}
```

Relationship types: collaborative, conflicting, hierarchical, neutral

Return ONLY valid JSON. No markdown, no explanation.

## Transcript
{$transcript}
PROMPT;
    }

    /**
     * Prompt for extracting behavioral insights from a Telegram conversation (single user).
     */
    public function buildTelegramExtractionPrompt(
        string $conversationText,
        string $email,
        string $userName,
        string $processedDate,
    ): string {
        $categories = implode(', ', InsightCategory::values());

        return <<<PROMPT
You are an expert behavioral analyst. Analyze the Telegram conversation below and extract behavioral insights about the user.

## User Info
Name: {$userName}
Email: {$email}
Conversation Date: {$processedDate}

## Instructions
1. Only extract facts about the USER (role: "User") — the Assistant messages are provided for context only.
2. Extract only facts clearly supported by the conversation — do not guess.
3. Each fact must be a single, specific, atomic observation.
4. Confidence: 0.9 = very clear, 0.7 = likely, 0.5 = possible but uncertain.
5. For short_term, capture only what appears to be true NOW based on this conversation.

## Categories
{$categories}

Category definitions:
- communication_style: tone, language patterns, how they express themselves in text
- work_patterns: work habits, how they describe their tasks and responsibilities
- strengths: things the person appears good at, with evidence from conversation
- development_areas: challenges or growth areas, with evidence from conversation
- goals_motivations: current goals, concerns, what drives them
- psychological_profile: personality traits, emotional patterns, stress indicators

## Required JSON Output Format
```json
{
  "participants": [
    {
      "email": "{$email}",
      "name": "{$userName}",
      "items": [
        {
          "category": "communication_style",
          "fact": "Writes in short, direct sentences without formalities",
          "confidence": 0.9
        }
      ],
      "short_term": [
        {
          "context_type": "emotional_state",
          "content": {
            "mood": "focused",
            "energy_level": "high",
            "stress_indicators": []
          }
        },
        {
          "context_type": "current_projects",
          "content": {
            "active": ["project name"],
            "recently_completed": []
          }
        },
        {
          "context_type": "recent_decisions",
          "content": {
            "decisions": ["decided to postpone feature X"]
          }
        }
      ]
    }
  ],
  "relationships": []
}
```

If no meaningful behavioral insights can be extracted, return:
```json
{"participants": [], "relationships": []}
```

Return ONLY valid JSON. No markdown, no explanation.

## Conversation
{$conversationText}
PROMPT;
    }

    /**
     * Prompt for evolving a long-term profile category with new facts.
     */
    public function buildEvolutionPrompt(
        string $category,
        array $existingContent,
        array $newFacts,
    ): string {
        $existingJson = empty($existingContent)
            ? 'No existing profile yet.'
            : json_encode($existingContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $factsList = collect($newFacts)
            ->map(fn($f, $i) => ($i + 1) . ". {$f}")
            ->implode("\n");

        $schema = $this->getCategorySchema($category);

        return <<<PROMPT
You are a Memory Synchronization Specialist. Update the user profile for the "{$category}" category.

## Current Profile
{$existingJson}

## New Facts to Integrate
{$factsList}

## Required JSON Schema for "{$category}"
{$schema}

## Rules
1. If a new fact CONTRADICTS the existing profile — overwrite the old value.
2. If a new fact is NEW information — add it logically.
3. If a new fact CONFIRMS existing info — no change needed.
4. Do not add speculation. Only integrate what is explicitly stated.
5. Keep the output concise — remove redundancy.

Return ONLY the updated JSON object matching the schema. No explanation, no markdown.
PROMPT;
    }

    /**
     * Prompt for evolving a relationship between two people.
     */
    public function buildRelationshipEvolutionPrompt(
        string $nameA,
        string $nameB,
        array $existingDynamics,
        string $newObservation,
        string $newRelationshipType,
    ): string {
        $existingJson = empty($existingDynamics)
            ? 'No existing relationship data yet.'
            : json_encode($existingDynamics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
You are a behavioral analyst. Update the relationship profile between {$nameA} and {$nameB}.

## Current Relationship Dynamics
{$existingJson}

## New Observation
{$newObservation}
Observed relationship type: {$newRelationshipType}

## Required JSON Schema
{
  "summary": "One sentence describing the current state of this relationship",
  "key_observations": ["array of key behavioral patterns observed"],
  "positive_interactions": ["array of positive moments"],
  "negative_interactions": ["array of tensions or conflicts"],
  "relationship_type": "collaborative|conflicting|hierarchical|neutral"
}

## Rules
1. Update summary to reflect the most current understanding.
2. Add new observations to the relevant arrays.
3. Do not remove old observations — they are historical context.
4. Update relationship_type to the most accurate current classification.

Return ONLY the updated JSON object. No explanation, no markdown.
PROMPT;
    }

    /**
     * Prompt for tiered retrieval — which categories are relevant to a query.
     */
    public function buildCategorySelectionPrompt(string $query, array $availableCategories): string
    {
        $categoryList = implode(', ', $availableCategories);

        return <<<PROMPT
Given the following query, select which profile categories are most relevant to answer it.

Query: {$query}
Available categories: {$categoryList}

Return ONLY a JSON array of relevant category names. Example: ["strengths", "work_patterns"]
No explanation, no markdown.
PROMPT;
    }

    private function getCategorySchema(string $category): string
    {
        return match ($category) {
            'communication_style' => <<<JSON
{
  "tone": "string (e.g. direct, formal, empathetic)",
  "listening": "string (e.g. active listener, interrupts often)",
  "preferred_format": "string (e.g. bullet points, narratives)",
  "language_patterns": ["array of specific speech patterns observed"]
}
JSON,
            'work_patterns' => <<<JSON
{
  "meeting_role": "string (e.g. facilitator, contributor, observer)",
  "decision_style": "string (e.g. data-driven, intuitive, consensus-seeking)",
  "deadline_reliability": "string (e.g. always on time, often delays)",
  "collaboration_preference": "string (e.g. async, sync, independent)"
}
JSON,
            'strengths' => <<<JSON
{
  "items": ["array of strength descriptions"],
  "evidence": ["array of specific observed examples"]
}
JSON,
            'development_areas' => <<<JSON
{
  "items": ["array of development area descriptions"],
  "evidence": ["array of specific observed examples"]
}
JSON,
            'goals_motivations' => <<<JSON
{
  "current_goals": ["array of current goals"],
  "motivators": ["array of things that drive this person"],
  "concerns": ["array of current concerns or anxieties"]
}
JSON,
            'psychological_profile' => <<<JSON
{
  "personality_traits": ["array of observed traits"],
  "stress_indicators": ["array of behaviors shown under stress"],
  "trust_level": "string (e.g. high — shares openly, low — guarded)",
  "conflict_style": "string (e.g. avoidant, assertive, collaborative)"
}
JSON,
            default => '{}',
        };
    }
}
