<?php
declare(strict_types=1);

namespace App\Plugin\ApiNotification\Support;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;

class CardExtract
{
    public const EXTRACT_RECORD_TABLE = 'api_notification_extract_record';

    public static function ensureExtractRecordTable(): void
    {
        if (Manager::schema()->hasTable(self::EXTRACT_RECORD_TABLE)) {
            return;
        }

        Manager::schema()->create(self::EXTRACT_RECORD_TABLE, function (Blueprint $blueprint) {
            $blueprint->increments('id');
            $blueprint->string('trade_no', 64)->nullable(true)->index();
            $blueprint->string('order_id', 64)->nullable(true);
            $blueprint->string('commodity_id', 64)->nullable(true)->index();
            $blueprint->string('card', 191)->unique();
            $blueprint->tinyInteger('status')->default(1);
            $blueprint->dateTime('extracted_at')->nullable(true)->index();
            $blueprint->string('source', 64)->nullable(true);
            $blueprint->text('raw')->nullable(true);
            $blueprint->dateTime('created_at')->nullable(true);
            $blueprint->dateTime('updated_at')->nullable(true);
        });
    }

    public static function splitSecretCards(string $secret): array
    {
        $cards = preg_split('/[\r\n|]+/', $secret) ?: [];
        $cards = array_map('trim', $cards);
        $cards = array_filter($cards, static fn(string $card) => $card !== '');

        return array_values(array_unique($cards));
    }

    public static function getPrefixes(array $config): array
    {
        $prefixText = trim((string)($config['card_extract_prefixes'] ?? ''));
        if ($prefixText === '') {
            return [];
        }

        $prefixes = preg_split('/[,\r\n|]+/', $prefixText) ?: [];
        $prefixes = array_map(static fn(string $prefix) => strtolower(trim(trim($prefix), '-')), $prefixes);
        $prefixes = array_filter($prefixes, static fn(string $prefix) => $prefix !== '');

        return array_values(array_unique($prefixes));
    }

    public static function isUniversalCard(string $card, array $config): bool
    {
        $card = trim($card);
        if ($card === '') {
            return false;
        }

        $prefixes = self::getPrefixes($config);
        if (count($prefixes) > 0) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with(strtolower($card), $prefix . '-')) {
                    return preg_match('/^[a-z0-9_]+-[A-Za-z0-9]{16,64}$/', $card) === 1;
                }
            }
            return false;
        }

        return preg_match('/^[a-z0-9_]+-[A-Za-z0-9]{16,64}$/', $card) === 1;
    }

    public static function filterUniversalCards(array $cards, array $config): array
    {
        $cards = array_filter($cards, static fn(string $card) => self::isUniversalCard($card, $config));

        return array_values(array_unique($cards));
    }

    public static function normalizeDateTime(?string $value): string
    {
        $time = strtotime(trim((string)$value));
        if ($time === false) {
            $time = time();
        }

        return date('Y-m-d H:i:s', $time);
    }
}
