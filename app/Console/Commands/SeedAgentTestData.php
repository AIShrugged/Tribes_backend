<?php

namespace App\Console\Commands;

use App\Models\CalendarEvent;
use App\Models\Participant;
use App\Models\Profile;
use App\Models\Source;
use App\Models\TranscriptEntry;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SeedAgentTestData extends Command
{
    protected $signature = 'agent:seed-test-data {--fresh : Delete existing test data before seeding}';

    protected $description = 'Seed test data for agent tools testing (users, meetings, transcripts)';

    private const TEST_EMAIL_DOMAIN = 'test-agent.local';

    public function handle(): void
    {
        if ($this->option('fresh')) {
            $this->cleanTestData();
        }

        $this->info('Seeding agent test data...');

        $users = $this->seedUsers();
        $org = $this->seedOrganization($users);
        $teams = $this->seedTeams($org, $users);
        $sources = $this->seedSources($users);
        $profiles = $this->seedProfiles($users);
        $events = $this->seedCalendarEvents($sources);
        $participants = $this->seedParticipants($events, $profiles);
        $this->seedTranscriptEntries($events, $participants);

        $this->newLine();
        $this->info('Test data seeded successfully!');
        $this->newLine();

        $this->table(
            ['Entity', 'Count'],
            [
                ['Users', count($users)],
                ['Organization', 1],
                ['Teams', count($teams)],
                ['Sources', count($sources)],
                ['Calendar Events', count($events)],
                ['Profiles', count($profiles)],
                ['Participants', $this->countParticipants($participants)],
            ]
        );

        $this->newLine();
        $this->info('Test users:');
        foreach ($users as $key => $user) {
            $this->line("  {$key}: {$user->name} <{$user->email}> (ID: {$user->id})");
        }

        $this->newLine();
        $this->info('Test meetings:');
        foreach ($events as $key => $event) {
            $this->line("  {$key}: \"{$event->title}\" (ID: {$event->id}, {$event->starts_at})");
        }
    }

    private function seedUsers(): array
    {
        $this->comment('Creating users...');

        $userData = [
            'marina' => ['name' => 'Марина Соколова', 'email' => 'marina@' . self::TEST_EMAIL_DOMAIN],
            'artem' => ['name' => 'Артём Петров', 'email' => 'artem@' . self::TEST_EMAIL_DOMAIN],
            'daria' => ['name' => 'Дарья Ковалёва', 'email' => 'daria@' . self::TEST_EMAIL_DOMAIN],
            'igor' => ['name' => 'Игорь Волков', 'email' => 'igor@' . self::TEST_EMAIL_DOMAIN],
            'lena' => ['name' => 'Елена Морозова', 'email' => 'lena@' . self::TEST_EMAIL_DOMAIN],
        ];

        $users = [];

        foreach ($userData as $key => $data) {
            $users[$key] = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            );
        }

        return $users;
    }

    private function seedOrganization(array $users): object
    {
        $this->comment('Creating organization...');

        $org = DB::table('organizations')->where('slug', 'test-agent-org')->first();

        if (!$org) {
            $orgId = DB::table('organizations')->insertGetId([
                'name' => 'ТехноСфера',
                'slug' => 'test-agent-org',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $org = DB::table('organizations')->find($orgId);
        }

        $roles = ['marina' => 'manager', 'artem' => 'employee', 'daria' => 'employee', 'igor' => 'employee', 'lena' => 'employee'];

        foreach ($users as $key => $user) {
            DB::table('organization_user')->updateOrInsert(
                ['organization_id' => $org->id, 'user_id' => $user->id],
                ['role' => $roles[$key], 'created_at' => now(), 'updated_at' => now()]
            );
        }

        return $org;
    }

    private function seedTeams(object $org, array $users): array
    {
        $this->comment('Creating teams...');

        $teamsData = [
            'backend' => ['name' => 'Бэкенд', 'members' => ['marina', 'artem', 'igor']],
            'frontend' => ['name' => 'Фронтенд', 'members' => ['daria', 'lena']],
            'devops' => ['name' => 'DevOps', 'members' => ['igor', 'marina']],
        ];

        $teams = [];

        foreach ($teamsData as $key => $data) {
            $team = DB::table('teams')->where('slug', 'test-' . $key)->first();

            if (!$team) {
                $defaultMethodology = DB::table('methodologies')->where('is_default', true)->first();
                $teamId = DB::table('teams')->insertGetId([
                    'organization_id' => $org->id,
                    'methodology_id' => $defaultMethodology->id,
                    'name' => $data['name'],
                    'slug' => 'test-' . $key,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $team = DB::table('teams')->find($teamId);
            }

            foreach ($data['members'] as $memberKey) {
                DB::table('team_user')->updateOrInsert(
                    ['team_id' => $team->id, 'user_id' => $users[$memberKey]->id],
                    ['created_at' => now()]
                );
            }

            $teams[$key] = $team;
        }

        return $teams;
    }

    private function seedSources(array $users): array
    {
        $this->comment('Creating sources...');

        $sources = [];

        foreach ($users as $key => $user) {
            $sources[$key] = Source::firstOrCreate(
                ['user_id' => $user->id, 'type' => 'google_calendar'],
                [
                    'external_id' => 'test_ext_' . $key,
                    'identity' => $user->email,
                    'auth_type' => 'oauth2',
                    'is_connected' => true,
                ]
            );
        }

        return $sources;
    }

    private function seedProfiles(array $users): array
    {
        $this->comment('Creating profiles...');

        $profiles = [];

        foreach ($users as $key => $user) {
            $profiles[$key] = Profile::firstOrCreate(
                ['email' => $user->email]
            );
        }

        return $profiles;
    }

    private function seedCalendarEvents(array $sources): array
    {
        $this->comment('Creating calendar events...');

        $eventsData = [
            'standup' => [
                'source' => 'marina',
                'title' => 'Ежедневный стендап',
                'description' => 'Ежедневная встреча команды: статус задач, планы и блокеры',
                'starts_at' => Carbon::today()->setHour(10)->setMinute(0),
                'ends_at' => Carbon::today()->setHour(10)->setMinute(20),
            ],
            'sprint_planning' => [
                'source' => 'marina',
                'title' => 'Планирование спринта 42',
                'description' => 'Разбор бэклога, оценка задач и распределение по спринту',
                'starts_at' => Carbon::today()->subDays(2)->setHour(14)->setMinute(0),
                'ends_at' => Carbon::today()->subDays(2)->setHour(15)->setMinute(30),
            ],
            'arch_review' => [
                'source' => 'artem',
                'title' => 'Ревью архитектуры — миграция на микросервисы',
                'description' => 'Обсуждение плана миграции монолита на микросервисную архитектуру',
                'starts_at' => Carbon::today()->subDays(5)->setHour(11)->setMinute(0),
                'ends_at' => Carbon::today()->subDays(5)->setHour(12)->setMinute(0),
            ],
            'one_on_one' => [
                'source' => 'marina',
                'title' => '1:1 Марина и Артём',
                'description' => 'Регулярная встреча один на один',
                'starts_at' => Carbon::today()->subDays(1)->setHour(16)->setMinute(0),
                'ends_at' => Carbon::today()->subDays(1)->setHour(16)->setMinute(30),
            ],
            'retro' => [
                'source' => 'daria',
                'title' => 'Ретроспектива спринта 41',
                'description' => 'Ретро: что прошло хорошо, что можно улучшить',
                'starts_at' => Carbon::today()->subDays(7)->setHour(15)->setMinute(0),
                'ends_at' => Carbon::today()->subDays(7)->setHour(16)->setMinute(0),
            ],
            'incident' => [
                'source' => 'igor',
                'title' => 'Разбор инцидента — падение прода 7 февраля',
                'description' => 'Post-mortem по инциденту на продакшне',
                'starts_at' => Carbon::today()->subDays(3)->setHour(13)->setMinute(0),
                'ends_at' => Carbon::today()->subDays(3)->setHour(14)->setMinute(0),
            ],
        ];

        $events = [];

        foreach ($eventsData as $key => $data) {
            $events[$key] = CalendarEvent::firstOrCreate(
                ['external_id' => 'test_event_' . $key, 'source_id' => $sources[$data['source']]->id],
                [
                    'platform' => 'google_meet',
                    'title' => $data['title'],
                    'description' => $data['description'],
                    'starts_at' => $data['starts_at'],
                    'ends_at' => $data['ends_at'],
                    'url' => 'https://meet.google.com/test-' . $key,
                    'required_bot' => false,
                ]
            );
        }

        return $events;
    }

    private function seedParticipants(array $events, array $profiles): array
    {
        $this->comment('Creating participants...');

        $participantsMap = [
            'standup' => ['marina', 'artem', 'daria', 'igor', 'lena'],
            'sprint_planning' => ['marina', 'artem', 'daria', 'igor', 'lena'],
            'arch_review' => ['marina', 'artem', 'igor'],
            'one_on_one' => ['marina', 'artem'],
            'retro' => ['marina', 'artem', 'daria', 'igor', 'lena'],
            'incident' => ['marina', 'artem', 'igor'],
        ];

        $names = [
            'marina' => 'Марина Соколова',
            'artem' => 'Артём Петров',
            'daria' => 'Дарья Ковалёва',
            'igor' => 'Игорь Волков',
            'lena' => 'Елена Морозова',
        ];

        $participants = [];

        foreach ($participantsMap as $eventKey => $memberKeys) {
            $participants[$eventKey] = [];

            foreach ($memberKeys as $memberKey) {
                $participants[$eventKey][$memberKey] = Participant::firstOrCreate(
                    [
                        'calendar_event_id' => $events[$eventKey]->id,
                        'profile_id' => $profiles[$memberKey]->id,
                    ],
                    ['name' => $names[$memberKey]]
                );
            }
        }

        return $participants;
    }

    private function seedTranscriptEntries(array $events, array $participants): void
    {
        $this->comment('Creating transcript entries...');

        $transcripts = [
            // ===== СТЕНДАП =====
            'standup' => [
                ['speaker' => 'marina', 'offset' => 0,
                    'text' => 'Доброе утро, все на месте? Давайте начинать. Артём, расскажи что у тебя.'],
                ['speaker' => 'artem', 'offset' => 15,
                    'text' => 'Вчера закончил API для авторизации через OAuth, все тесты зелёные. Сегодня берусь за сброс пароля. Блокеров нет.'],
                ['speaker' => 'marina', 'offset' => 35,
                    'text' => 'Отлично. Дарья?'],
                ['speaker' => 'daria', 'offset' => 40,
                    'text' => 'Я продолжаю работу над новым дашбордом. Компонент графиков почти готов, но мне нужна помощь Игоря с WebSocket-интеграцией — мы это обсуждали на ретро на прошлой неделе, что реал-тайм обновления нужны.'],
                ['speaker' => 'igor', 'offset' => 65,
                    'text' => 'Да, помню. Могу подключиться после обеда. Вчера я закончил деплой нового стека мониторинга — того самого, который обсуждали после инцидента. Сегодня настраиваю алерты.'],
                ['speaker' => 'marina', 'offset' => 85,
                    'text' => 'Хорошо, это важно после падения прода. Лена?'],
                ['speaker' => 'lena', 'offset' => 90,
                    'text' => 'Закончила редизайн страницы входа, отправила на ревью. Сегодня начинаю страницу настроек. Кстати, Дарья, можем синхронизироваться по дизайн-системе — я заметила расхождения в компонентах.'],
                ['speaker' => 'daria', 'offset' => 110,
                    'text' => 'Да, давай после стендапа созвонимся на 10 минут.'],
                ['speaker' => 'marina', 'offset' => 120,
                    'text' => 'Отлично. После обеда синк по WebSocket — Дарья и Игорь. Всем продуктивного дня!'],
            ],

            // ===== РЕВЬЮ АРХИТЕКТУРЫ =====
            'arch_review' => [
                ['speaker' => 'artem', 'offset' => 0,
                    'text' => 'Итак, основная идея: разбить монолит на три сервиса — авторизация, встречи и уведомления. Я подготовил схему, сейчас покажу.'],
                ['speaker' => 'marina', 'offset' => 30,
                    'text' => 'А что с общей базой данных? Идём в сторону базы на каждый сервис или общая схема?'],
                ['speaker' => 'artem', 'offset' => 60,
                    'text' => 'Рекомендую базу на каждый сервис. Это даёт лучшую изоляцию и независимое масштабирование. Плюс, помните проблему с блокировками, которую Игорь нашёл месяц назад? С отдельными базами этого не будет.'],
                ['speaker' => 'igor', 'offset' => 100,
                    'text' => 'Со стороны инфраструктуры нам нужен service mesh. Предлагаю Istio — у меня уже есть опыт с ним на предыдущем проекте. По деплою: я думаю, стоит идти через strangler fig — мигрировать по одному сервису.'],
                ['speaker' => 'marina', 'offset' => 140,
                    'text' => 'А что с консистентностью данных? У нас сейчас есть транзакции, которые затрагивают несколько доменов.'],
                ['speaker' => 'artem', 'offset' => 170,
                    'text' => 'Будем использовать паттерн Saga для распределённых транзакций. Я уже сделал прототип, он хорошо работает с нашей шиной событий.'],
                ['speaker' => 'marina', 'offset' => 210,
                    'text' => 'Хорошо. Давайте начнём с сервиса авторизации — у него меньше всего зависимостей. Артём, подготовь детальный RFC к пятнице. Игорь, прикинь план по инфраструктуре.'],
                ['speaker' => 'artem', 'offset' => 240,
                    'text' => 'Сделаю. Кстати, это связано с тем, что я сейчас делаю по OAuth — можно будет сразу вынести в отдельный сервис.'],
                ['speaker' => 'igor', 'offset' => 260,
                    'text' => 'По инфре подготовлю. Нужно будет также обновить CI/CD пайплайны.'],
            ],

            // ===== 1:1 =====
            'one_on_one' => [
                ['speaker' => 'marina', 'offset' => 0,
                    'text' => 'Привет, Артём. Как дела? Есть что-нибудь по текущему спринту?'],
                ['speaker' => 'artem', 'offset' => 15,
                    'text' => 'В целом всё идёт по плану. Миграция на микросервисы — это интересно, но меня немного беспокоят сроки.'],
                ['speaker' => 'marina', 'offset' => 35,
                    'text' => 'Что конкретно со сроками?'],
                ['speaker' => 'artem', 'offset' => 45,
                    'text' => 'Мы оценили миграцию сервиса авторизации в 3 месяца, но я думаю, мы недооценили тестирование. У нас много интеграционных тестов, которые придётся переписывать. Плюс после инцидента на прошлой неделе Игорь добавил новые проверки — их тоже нужно учесть.'],
                ['speaker' => 'marina', 'offset' => 80,
                    'text' => 'Хороший поинт. Давай заложим буферный спринт на тестирование. Подниму это на следующем планировании.'],
                ['speaker' => 'artem', 'offset' => 100,
                    'text' => 'Это бы помогло. И ещё — хотел бы поехать на конференцию KubeConf в следующем месяце. Там будет секция по миграции монолитов, как раз наша тема.'],
                ['speaker' => 'marina', 'offset' => 120,
                    'text' => 'Конечно, одобряю. Это напрямую связано с тем, что мы делаем. Ещё что-нибудь?'],
                ['speaker' => 'artem', 'offset' => 135,
                    'text' => 'Да, хотел обсудить развитие. Мне интересно двигаться в сторону техлида. Как ты на это смотришь?'],
                ['speaker' => 'marina', 'offset' => 150,
                    'text' => 'Я давно об этом думаю. У тебя хороший потенциал. Давай составим план развития — определим, какие компетенции нужно подтянуть. Я подготовлю черновик к следующей нашей встрече.'],
                ['speaker' => 'artem', 'offset' => 175,
                    'text' => 'Отлично, спасибо, Марина!'],
            ],

            // ===== РАЗБОР ИНЦИДЕНТА =====
            'incident' => [
                ['speaker' => 'igor', 'offset' => 0,
                    'text' => 'Давайте пройдёмся по таймлайну. Инцидент начался в 14:23 UTC — основная база данных достигла 100% CPU.'],
                ['speaker' => 'marina', 'offset' => 20,
                    'text' => 'Что вызвало скачок нагрузки?'],
                ['speaker' => 'igor', 'offset' => 30,
                    'text' => 'Медленный запрос, который выполнялся в цикле. Это был поиск по встречам без пагинации — он тянул все записи из таблицы. Обнаружили через 12 минут, так как алертов на медленные запросы у нас не было.'],
                ['speaker' => 'artem', 'offset' => 60,
                    'text' => 'Это мой код с прошлой недели. Я не добавил LIMIT к поисковому запросу. Извиняюсь за это.'],
                ['speaker' => 'marina', 'offset' => 80,
                    'text' => 'Без обвинений — главное, что мы это починим и предотвратим в будущем. Какой план?'],
                ['speaker' => 'igor', 'offset' => 100,
                    'text' => 'Первое: я уже добавил лимит на время выполнения запросов на уровне базы — максимум 30 секунд. Второе: нужно обязательное ревью для всех запросов к БД.'],
                ['speaker' => 'artem', 'offset' => 130,
                    'text' => 'Я уже починил запрос и добавил LIMIT. Плюс добавил индекс на колонки поиска — производительность выросла в 10 раз. Также написал тест, который проверяет что запрос не возвращает больше 100 записей.'],
                ['speaker' => 'marina', 'offset' => 160,
                    'text' => 'Хорошо. Давайте добавим алерты на медленные запросы, чтобы ловить такие проблемы до того, как они станут инцидентами. Игорь, сможешь настроить?'],
                ['speaker' => 'igor', 'offset' => 180,
                    'text' => 'Да, это как раз часть нового стека мониторинга, который я деплою. Будет готово к концу недели. Также предлагаю добавить дашборд с метриками производительности запросов.'],
                ['speaker' => 'marina', 'offset' => 200,
                    'text' => 'Согласна. Артём, добавь в RFC по микросервисам секцию про observability — это будет важно при миграции. На этом всё, спасибо команде за быструю реакцию.'],
            ],

            // ===== РЕТРОСПЕКТИВА =====
            'retro' => [
                ['speaker' => 'daria', 'offset' => 0,
                    'text' => 'Начинаем ретро спринта 41. Давайте сначала пройдёмся по тому, что прошло хорошо.'],
                ['speaker' => 'artem', 'offset' => 15,
                    'text' => 'OAuth-интеграция заехала вовремя. Хорошо сработали с Игорем по деплою — параллельно катили бэкенд и инфру.'],
                ['speaker' => 'igor', 'offset' => 30,
                    'text' => 'Да, координация была нормальная. Ещё порадовало, что новый CI пайплайн сократил время билда с 15 до 4 минут.'],
                ['speaker' => 'lena', 'offset' => 45,
                    'text' => 'По фронту — мы с Дарьей наконец согласовали дизайн-систему. Теперь компоненты переиспользуются, а не дублируются.'],
                ['speaker' => 'daria', 'offset' => 60,
                    'text' => 'Да, это большой шаг вперёд. Теперь что можно улучшить?'],
                ['speaker' => 'marina', 'offset' => 70,
                    'text' => 'Мне кажется, нужно больше общения между фронтом и бэком. Дарья узнала про изменения в API только когда её код сломался.'],
                ['speaker' => 'daria', 'offset' => 85,
                    'text' => 'Да, это было неприятно. Артём поменял формат ответа, а я узнала через два дня, когда начала интегрировать.'],
                ['speaker' => 'artem', 'offset' => 100,
                    'text' => 'Виноват, нужно было сразу написать. Предлагаю ввести правило: любое изменение API — сразу сообщение в канал #api-changes.'],
                ['speaker' => 'igor', 'offset' => 115,
                    'text' => 'А ещё нам нужны реал-тайм обновления на дашборде. Сейчас данные обновляются только при перезагрузке страницы. Может WebSocket прикрутим?'],
                ['speaker' => 'daria', 'offset' => 130,
                    'text' => 'Поддерживаю. Лена, ты сможешь взять фронтовую часть WebSocket?'],
                ['speaker' => 'lena', 'offset' => 140,
                    'text' => 'Да, возьму. Но мне нужно будет время на исследование — раньше с WebSocket не работала. Может кто-нибудь из бэка поможет с серверной частью?'],
                ['speaker' => 'igor', 'offset' => 155,
                    'text' => 'Я помогу. У меня есть опыт с Laravel WebSocket.'],
                ['speaker' => 'marina', 'offset' => 165,
                    'text' => 'Отлично. Итого по экшен-айтемам: канал #api-changes для уведомлений об изменениях API, WebSocket-интеграция для дашборда. Запланируем в спринт 42.'],
            ],

            // ===== ПЛАНИРОВАНИЕ СПРИНТА =====
            'sprint_planning' => [
                ['speaker' => 'marina', 'offset' => 0,
                    'text' => 'Начинаем планирование спринта 42. У нас есть несколько приоритетных задач из бэклога, плюс экшен-айтемы с ретро.'],
                ['speaker' => 'marina', 'offset' => 20,
                    'text' => 'Первое: WebSocket-интеграция для дашборда — это мы обсудили на ретро. Игорь и Дарья, это ваша задача. Оценка?'],
                ['speaker' => 'igor', 'offset' => 40,
                    'text' => 'Серверная часть — 5 стори-поинтов. Нужно настроить Laravel Echo и каналы для обновлений.'],
                ['speaker' => 'daria', 'offset' => 55,
                    'text' => 'Фронтовая часть — тоже 5. Мне нужно переделать компонент графиков на реактивные обновления.'],
                ['speaker' => 'marina', 'offset' => 70,
                    'text' => 'Хорошо, 10 поинтов. Дальше: сброс пароля — Артём, ты это уже начал?'],
                ['speaker' => 'artem', 'offset' => 80,
                    'text' => 'Да, осталось на 3 поинта. Email-шаблон, подтверждение и тесты.'],
                ['speaker' => 'marina', 'offset' => 95,
                    'text' => 'Лена, страница настроек — сколько?'],
                ['speaker' => 'lena', 'offset' => 105,
                    'text' => 'Оцениваю в 8 поинтов. Там много секций: профиль, уведомления, интеграции, безопасность.'],
                ['speaker' => 'marina', 'offset' => 120,
                    'text' => 'И последнее на спринт: Артём, RFC по микросервисам. После нашего ревью архитектуры нужно оформить решения в документ.'],
                ['speaker' => 'artem', 'offset' => 135,
                    'text' => '3 поинта. Основное уже обсудили, нужно оформить и добавить секцию по observability — Марина попросила после разбора инцидента.'],
                ['speaker' => 'marina', 'offset' => 155,
                    'text' => 'Итого: 29 поинтов. Наш velocity 30, укладываемся. Есть возражения? Нет? Тогда спринт 42 стартовал. Удачи всем!'],
            ],
        ];

        foreach ($transcripts as $eventKey => $entries) {
            $event = $events[$eventKey];
            $eventParticipants = $participants[$eventKey];

            foreach ($entries as $entry) {
                $participant = $eventParticipants[$entry['speaker']];
                $startAbsolute = Carbon::parse($event->starts_at)->addSeconds($entry['offset']);

                TranscriptEntry::firstOrCreate(
                    [
                        'calendar_event_id' => $event->id,
                        'participant_id' => $participant->id,
                        'start_relative' => $entry['offset'],
                    ],
                    [
                        'text' => $entry['text'],
                        'end_relative' => $entry['offset'] + 10,
                        'start_absolute' => $startAbsolute,
                        'end_absolute' => $startAbsolute->copy()->addSeconds(10),
                    ]
                );
            }
        }
    }

    private function cleanTestData(): void
    {
        $this->comment('Cleaning existing test data...');

        $testUsers = User::where('email', 'like', '%@' . self::TEST_EMAIL_DOMAIN)->get();
        $testUserIds = $testUsers->pluck('id');

        $sourceIds = Source::whereIn('user_id', $testUserIds)->pluck('id');
        $eventIds = CalendarEvent::whereIn('source_id', $sourceIds)->pluck('id');

        TranscriptEntry::whereIn('calendar_event_id', $eventIds)->delete();
        Participant::whereIn('calendar_event_id', $eventIds)->delete();
        CalendarEvent::whereIn('id', $eventIds)->delete();
        Source::whereIn('id', $sourceIds)->delete();

        Profile::whereIn('user_id', $testUserIds)->delete();

        DB::table('team_user')->whereIn('user_id', $testUserIds)->delete();
        DB::table('organization_user')->whereIn('user_id', $testUserIds)->delete();

        $org = DB::table('organizations')->where('slug', 'test-agent-org')->first();
        if ($org) {
            DB::table('teams')->where('organization_id', $org->id)->delete();
            DB::table('organizations')->where('id', $org->id)->delete();
        }

        User::whereIn('id', $testUserIds)->delete();

        $this->info('Test data cleaned.');
    }

    private function countParticipants(array $participants): int
    {
        $count = 0;
        foreach ($participants as $eventParticipants) {
            $count += count($eventParticipants);
        }
        return $count;
    }
}
