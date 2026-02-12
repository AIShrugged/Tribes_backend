<?php

namespace App\Services\Insight;

use App\Domain\DTO\AI\MessageDTO;
use App\Domain\DTO\Insight\InsightExtractedDataDTO;
use App\Models\InsightSource;
use App\Models\TelegramChatMessage;
use App\Models\TelegramUser;
use App\Services\OpenRouterClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class InsightTelegramService
{
    private const MIN_USER_MESSAGES = 10;
    private const MIN_HOURS_SINCE_LAST = 24;
    private const SOURCE_TYPE = 'telegram';

    public function __construct(
        private readonly OpenRouterClient $llm,
        private readonly InsightPromptBuilder $promptBuilder,
        private readonly InsightEvolutionService $evolutionService,
    ) {}

    /**
     * Process all eligible Telegram users and enrich their Insight profiles.
     * Returns count of users processed.
     */
    public function processAll(): int
    {
        $telegramUsers = TelegramUser::with('user')
            ->whereNotNull('user_id')
            ->get();

        $processed = 0;

        foreach ($telegramUsers as $telegramUser) {
            $email = $telegramUser->user?->email;
            if (! $email) {
                continue;
            }

            try {
                $wasProcessed = $this->processUser($telegramUser, $email);
                if ($wasProcessed) {
                    $processed++;
                }
            } catch (\Throwable $e) {
                Log::error('InsightTelegramService: error processing user', [
                    'telegram_user_id' => $telegramUser->telegram_user_id,
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $processed;
    }

    /**
     * Process a single Telegram user by telegram_user_id.
     */
    public function processOne(int $telegramUserId): bool
    {
        $telegramUser = TelegramUser::with('user')->find($telegramUserId);

        if (! $telegramUser) {
            Log::warning('InsightTelegramService: TelegramUser not found', [
                'telegram_user_id' => $telegramUserId,
            ]);
            return false;
        }

        $email = $telegramUser->user?->email;
        if (! $email) {
            Log::info('InsightTelegramService: no linked user account', [
                'telegram_user_id' => $telegramUserId,
            ]);
            return false;
        }

        return $this->processUser($telegramUser, $email);
    }

    private function processUser(TelegramUser $telegramUser, string $email): bool
    {
        $telegramUserId = $telegramUser->telegram_user_id;

        // Find last processed source for this user
        $lastSource = InsightSource::where('email', $email)
            ->where('source_type', self::SOURCE_TYPE)
            ->orderByDesc('source_id')
            ->first();

        $lastMessageId = $lastSource?->source_id ?? 0;

        // Get new messages since last processing (all roles for context)
        $newMessages = TelegramChatMessage::where('telegram_user_id', $telegramUserId)
            ->where('id', '>', $lastMessageId)
            ->orderBy('id')
            ->get();

        if ($newMessages->isEmpty()) {
            return false;
        }

        // Count only user messages for threshold check
        $userMessageCount = $newMessages->where('role', 'user')->count();

        if (! $this->shouldProcess($userMessageCount, $lastSource)) {
            Log::info('InsightTelegramService: threshold not met, skipping', [
                'email' => $email,
                'user_message_count' => $userMessageCount,
                'last_processed_at' => $lastSource?->processed_at,
            ]);
            return false;
        }

        Log::info('InsightTelegramService: processing user', [
            'email' => $email,
            'message_count' => $newMessages->count(),
            'user_message_count' => $userMessageCount,
        ]);

        // Extract insights via LLM
        $extractedData = $this->callLLM($email, $telegramUser, $newMessages);

        if ($extractedData === null || empty($extractedData->participants)) {
            Log::info('InsightTelegramService: no insights extracted', ['email' => $email]);
            return false;
        }

        // Persist and evolve
        $lastId = $newMessages->max('id');
        $this->persist($email, $lastId, $extractedData);

        return true;
    }

    private function shouldProcess(int $userMessageCount, ?InsightSource $lastSource): bool
    {
        if ($userMessageCount >= self::MIN_USER_MESSAGES) {
            return true;
        }

        if ($userMessageCount === 0) {
            return false;
        }

        // Check time since last processing
        $hoursSinceLast = $lastSource
            ? $lastSource->processed_at?->diffInHours(now()) ?? self::MIN_HOURS_SINCE_LAST
            : self::MIN_HOURS_SINCE_LAST;

        return $hoursSinceLast >= self::MIN_HOURS_SINCE_LAST;
    }

    private function callLLM(string $email, TelegramUser $telegramUser, Collection $messages): ?InsightExtractedDataDTO
    {
        $userName = $telegramUser->user->name ?? $telegramUser->telegram_username ?? 'User';
        $conversationText = $this->buildConversationText($messages);

        try {
            $prompt = $this->promptBuilder->buildTelegramExtractionPrompt(
                conversationText: $conversationText,
                email: $email,
                userName: $userName,
                processedDate: now()->toDateString(),
            );

            $json = $this->llm->chat(
                messages: [new MessageDTO('user', $prompt)],
                model: config('ai.providers.openrouter.models.insight'),
                maxTokens: 4096,
                forceJsonResponse: true,
            );

            $data = json_decode($json, true);

            if (! isset($data['participants'])) {
                Log::warning('InsightTelegramService: unexpected LLM response', ['email' => $email]);
                return null;
            }

            return InsightExtractedDataDTO::fromArray($data);
        } catch (\Throwable $e) {
            Log::error('InsightTelegramService: LLM call failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function buildConversationText(Collection $messages): string
    {
        return $messages->map(function (TelegramChatMessage $message) {
            $role = $message->role === 'user' ? 'User' : 'Assistant';
            $time = $message->created_at->format('Y-m-d H:i');

            return "[{$time}] {$role}: {$message->content}";
        })->implode("\n");
    }

    private function persist(string $email, int $lastMessageId, InsightExtractedDataDTO $data): void
    {
        $participant = $data->participants[0];

        // Create source record (tracks up to which message was processed)
        $source = InsightSource::create([
            'email' => $email,
            'source_type' => self::SOURCE_TYPE,
            'source_id' => $lastMessageId,
            'processed_at' => now(),
        ]);

        // Persist atomic facts (long-term)
        foreach ($participant->items as $item) {
            $source->items()->create([
                'email' => $email,
                'category' => $item->category,
                'fact' => $item->fact,
                'confidence' => $item->confidence,
            ]);
        }

        // Persist short-term memory
        foreach ($participant->shortTerm as $shortTerm) {
            $ttlDays = $shortTerm->contextType === 'emotional_state' ? 7 : 30;

            $source->shortTermMemories()->create([
                'email' => $email,
                'context_type' => $shortTerm->contextType,
                'content' => $shortTerm->content,
                'expires_at' => now()->addDays($ttlDays),
            ]);
        }

        // Trigger long-term profile evolution
        $this->evolutionService->evolveFromSource($email, $source);

        Log::info('InsightTelegramService: persisted insights', [
            'email' => $email,
            'items_count' => count($participant->items),
            'short_term_count' => count($participant->shortTerm),
            'last_message_id' => $lastMessageId,
        ]);
    }
}
