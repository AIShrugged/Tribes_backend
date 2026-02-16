<?php

namespace App\Services\Insight;

use App\Domain\DTO\AI\MessageDTO;
use App\Domain\DTO\Insight\InsightExtractedDataDTO;
use App\Models\Channel;
use App\Models\InsightSource;
use App\Models\Profile;
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
     */
    public function processAll(): int
    {
        $telegramChannelId = Channel::idFor('telegram');

        if (!$telegramChannelId) {
            Log::warning('InsightTelegramService: telegram channel not found');
            return 0;
        }

        $processed = 0;

        foreach (TelegramUser::lazy() as $telegramUser) {
            $profile = Profile::where('channel_id', $telegramChannelId)
                ->where('channel_identifier', (string) $telegramUser->telegram_user_id)
                ->first();

            if (!$profile) {
                continue;
            }

            try {
                $wasProcessed = $this->processUser($telegramUser, $profile);
                if ($wasProcessed) {
                    $processed++;
                }
            } catch (\Throwable $e) {
                Log::error('InsightTelegramService: error processing user', [
                    'telegram_user_id' => $telegramUser->telegram_user_id,
                    'profile_id'       => $profile->id,
                    'error'            => $e->getMessage(),
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
        $telegramChannelId = Channel::idFor('telegram');

        if (!$telegramChannelId) {
            Log::warning('InsightTelegramService: telegram channel not found');
            return false;
        }

        $telegramUser = TelegramUser::find($telegramUserId);

        if (!$telegramUser) {
            Log::warning('InsightTelegramService: TelegramUser not found', [
                'telegram_user_id' => $telegramUserId,
            ]);
            return false;
        }

        $profile = Profile::where('channel_id', $telegramChannelId)
            ->where('channel_identifier', (string) $telegramUserId)
            ->first();

        if (!$profile) {
            Log::info('InsightTelegramService: no telegram profile found', [
                'telegram_user_id' => $telegramUserId,
            ]);
            return false;
        }

        return $this->processUser($telegramUser, $profile);
    }

    private function processUser(TelegramUser $telegramUser, Profile $profile): bool
    {
        $lastSource = InsightSource::where('profile_id', $profile->id)
            ->where('source_type', self::SOURCE_TYPE)
            ->orderByDesc('source_id')
            ->first();

        $lastMessageId = $lastSource?->source_id ?? 0;

        $newMessages = TelegramChatMessage::where('telegram_user_id', $telegramUser->telegram_user_id)
            ->where('id', '>', $lastMessageId)
            ->orderBy('id')
            ->get();

        if ($newMessages->isEmpty()) {
            return false;
        }

        $userMessageCount = $newMessages->where('role', 'user')->count();

        if (!$this->shouldProcess($userMessageCount, $lastSource)) {
            Log::info('InsightTelegramService: threshold not met, skipping', [
                'profile_id'         => $profile->id,
                'user_message_count' => $userMessageCount,
                'last_processed_at'  => $lastSource?->processed_at,
            ]);
            return false;
        }

        Log::info('InsightTelegramService: processing user', [
            'profile_id'         => $profile->id,
            'message_count'      => $newMessages->count(),
            'user_message_count' => $userMessageCount,
        ]);

        $userName        = $telegramUser->telegram_username ?? 'User';
        $extractedData   = $this->callLLM($profile, $userName, $newMessages);

        if ($extractedData === null || empty($extractedData->participants)) {
            Log::info('InsightTelegramService: no insights extracted', ['profile_id' => $profile->id]);
            return false;
        }

        $lastId = $newMessages->max('id');
        $this->persist($profile, $lastId, $extractedData);

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

        $hoursSinceLast = $lastSource
            ? $lastSource->processed_at?->diffInHours(now()) ?? self::MIN_HOURS_SINCE_LAST
            : self::MIN_HOURS_SINCE_LAST;

        return $hoursSinceLast >= self::MIN_HOURS_SINCE_LAST;
    }

    private function callLLM(Profile $profile, string $userName, Collection $messages): ?InsightExtractedDataDTO
    {
        $conversationText = $this->buildConversationText($messages);

        // Use channel_identifier as the identifier in the prompt (telegram_user_id for telegram)
        $identifier = $profile->channel_identifier;

        try {
            $prompt = $this->promptBuilder->buildTelegramExtractionPrompt(
                conversationText: $conversationText,
                identifier:       $identifier,
                userName:         $userName,
                processedDate:    now()->toDateString(),
            );

            $json = $this->llm->chat(
                messages:          [new MessageDTO('user', $prompt)],
                model:             config('ai.providers.openrouter.models.insight'),
                maxTokens:         4096,
                forceJsonResponse: true,
            );

            $data = json_decode($json, true);

            if (!isset($data['participants'])) {
                Log::warning('InsightTelegramService: unexpected LLM response', ['profile_id' => $profile->id]);
                return null;
            }

            return InsightExtractedDataDTO::fromArray($data);
        } catch (\Throwable $e) {
            Log::error('InsightTelegramService: LLM call failed', [
                'profile_id' => $profile->id,
                'error'      => $e->getMessage(),
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

    private function persist(Profile $profile, int $lastMessageId, InsightExtractedDataDTO $data): void
    {
        $participant = $data->participants[0];

        // create() instead of firstOrCreate(): processUser() already verified new messages
        // exist by querying messages after the last processed source_id, so there is no
        // risk of duplicate sources for this profile+type+lastMessageId combination.
        $source = InsightSource::create([
            'profile_id'   => $profile->id,
            'source_type'  => self::SOURCE_TYPE,
            'source_id'    => $lastMessageId,
            'processed_at' => now(),
        ]);

        foreach ($participant->items as $item) {
            $source->items()->create([
                'profile_id' => $profile->id,
                'category'   => $item->category,
                'fact'       => $item->fact,
                'confidence' => $item->confidence,
            ]);
        }

        foreach ($participant->shortTerm as $shortTerm) {
            $ttlDays = $shortTerm->contextType === 'emotional_state' ? 7 : 30;

            $source->shortTermMemories()->create([
                'profile_id'   => $profile->id,
                'context_type' => $shortTerm->contextType,
                'content'      => $shortTerm->content,
                'expires_at'   => now()->addDays($ttlDays),
            ]);
        }

        $this->evolutionService->evolveFromSource($source);

        Log::info('InsightTelegramService: persisted insights', [
            'profile_id'      => $profile->id,
            'items_count'     => count($participant->items),
            'short_term_count' => count($participant->shortTerm),
            'last_message_id' => $lastMessageId,
        ]);
    }
}
