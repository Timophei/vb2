<?php
// Скопируйте этот файл в config.php и заполните.
// config.php в git не попадает (.gitignore) — ключи остаются только на сервере.
// Всё, чего нет в config.php, берётся по умолчанию.

return [
    // Часовой пояс админки, таймеров и разбора дат («завтра в 10») — в базе время хранится в UTC
    'timezone'         => 'Europe/Moscow',

    // --- OpenAI (одна модель и для разбора реплик, и для ответов) ---
    'openai_api_key'   => 'sk-...',
    'openai_base_url'  => 'https://api.openai.com/v1', // если OpenAI блокирует сервер — адрес прокси/шлюза
    'chat_model'       => 'gpt-5-mini',
    'reasoning_effort' => 'minimal',  // gpt-5*: minimal | low | medium; null — не передавать
    'embedding_model'  => 'text-embedding-3-small',
    'use_embeddings'   => true,       // false — только полнотекстовый поиск по материалам
    'max_output_tokens'=> 4000,

    // --- Материалы (база знаний) ---
    'top_k'            => 6,
    'chunk_chars'      => 1200,
    'chunk_overlap'    => 200,
    'history_turns'    => 8,          // сколько последних пар реплик видит модель

    // --- Telegram (новый бот от @BotFather) ---
    'telegram_token'        => '',
    'telegram_bot_username' => '',    // имя бота без @ — для кнопки «Войти через Telegram» на сайте
    'telegram_secret'       => 'придумайте-длинную-строку', // проверяется в заголовке вебхука

    // Куда слать уведомления «передано менеджеру»: id Telegram-чатов (узнать: написать боту /id)
    'manager_chat_ids' => [],

    // --- Битрикс24 ---
    // Входящий вебхук с правами crm (позже для открытых линий: imbot, imopenlines, im)
    'bitrix_webhook'   => '',         // https://портал/rest/1/xxxxxxxx/
    // Режим записи в CRM: 'live' — пишем по-настоящему, 'dry' — только журнал «что бы отправили»
    'bitrix_mode'      => 'dry',

    // --- Сайт и виджет ---
    'public_url'       => 'https://vb2.b-integration.ru',
    'site_title'       => 'Milli Mind — консультант',
    'accent_color'     => '#1f4e8c',
    'privacy_url'      => '',         // политика обработки ПД (152-ФЗ) — ссылка под чатом
    'allowed_origins'  => [],         // сайты, где стоит виджет, напр. ['https://milli-mind.ru']
    'rate_limit_per_min' => 20,
    'max_upload_mb'    => 15,         // файлы от клиентов (резюме)

    // --- Админка ---
    'admin_password_hash' => '',      // устаревшее, пользователи заводятся в самой админке

    'data_dir' => __DIR__ . '/data',
];
