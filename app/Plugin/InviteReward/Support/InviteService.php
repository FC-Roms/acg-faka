<?php
declare(strict_types=1);

namespace App\Plugin\InviteReward\Support;

use App\Model\Order;
use App\Model\User;
use App\Plugin\InviteReward\Model\InviteCode;
use App\Plugin\InviteReward\Model\InviteRelation;
use App\Plugin\InviteReward\Model\InviteRewardRecord;
use App\Util\Client;
use App\Util\Date;
use App\Util\Plugin as PluginUtil;
use App\Util\Str;

class InviteService
{
    public const PLUGIN = 'InviteReward';
    public const COOKIE = 'invite_reward_code';
    public const TRIGGER_REGISTER = 'register';
    public const TRIGGER_EMAIL_BOUND = 'email_bound';
    public const TRIGGER_FIRST_PAID_ORDER = 'first_paid_order';

    public function isEnabled(): bool
    {
        $config = PluginUtil::getConfig(self::PLUGIN);

        return (int)($config['STATUS'] ?? 0) === 1;
    }

    public function getOrCreateCode(int $userId): ?InviteCode
    {
        if (!SchemaService::ready()) {
            return null;
        }

        $code = InviteCode::query()->where('user_id', $userId)->first();
        if ($code instanceof InviteCode) {
            return $code;
        }

        $config = PluginUtil::getConfig(self::PLUGIN);
        $length = max(6, min(16, (int)($config['code_length'] ?? 8)));
        $date = Date::current();

        $code = new InviteCode();
        $code->user_id = $userId;
        $code->code = $this->generateCode($length);
        $code->status = 1;
        $code->create_time = $date;
        $code->update_time = $date;
        $code->save();

        return $code;
    }

    public function getInviteUrl(int $userId): string
    {
        $code = $this->getOrCreateCode($userId);
        if (!$code) {
            return '';
        }

        return Client::getUrl() . '/plugin/InviteReward/index/go?code=' . urlencode($code->code);
    }

    public function captureInviteCodeFromRequest(): void
    {
        if (!$this->isEnabled() || !SchemaService::ready()) {
            return;
        }

        $code = $this->normalizeCode((string)($_GET['invite'] ?? $_GET['invite_code'] ?? ''));
        if ($code === '' || !$this->findActiveCode($code)) {
            return;
        }

        $config = PluginUtil::getConfig(self::PLUGIN);
        $days = max(1, (int)($config['invite_cookie_days'] ?? 30));
        setcookie(self::COOKIE, $code, time() + $days * 86400, '/');
        $_COOKIE[self::COOKIE] = $code;
    }

    public function setInviteCookie(string $code): bool
    {
        if (!$this->isEnabled() || !SchemaService::ready()) {
            return false;
        }

        $code = $this->normalizeCode($code);
        if ($code === '' || !$this->findActiveCode($code)) {
            return false;
        }

        $config = PluginUtil::getConfig(self::PLUGIN);
        $days = max(1, (int)($config['invite_cookie_days'] ?? 30));
        setcookie(self::COOKIE, $code, time() + $days * 86400, '/');
        $_COOKIE[self::COOKIE] = $code;

        return true;
    }

    public function clearInviteCookie(): void
    {
        unset($_COOKIE[self::COOKIE]);
        setcookie(self::COOKIE, '', time() - 3600, '/');
    }

    public function bindInvitee(User $user): void
    {
        if (!$this->isEnabled() || !SchemaService::ready()) {
            return;
        }

        $code = $this->normalizeCode((string)($_COOKIE[self::COOKIE] ?? $_POST['invite_code'] ?? $_POST['invite'] ?? ''));
        if ($code === '') {
            return;
        }

        $inviteCode = $this->findActiveCode($code);
        if (!$inviteCode || (int)$inviteCode->user_id === (int)$user->id) {
            return;
        }

        if (InviteRelation::query()->where('invitee_user_id', $user->id)->exists()) {
            return;
        }

        $relation = new InviteRelation();
        $relation->inviter_user_id = $inviteCode->user_id;
        $relation->invitee_user_id = $user->id;
        $relation->invite_code = $inviteCode->code;
        $relation->source = 'register';
        $relation->register_ip = Client::getAddress();
        $relation->device_id = $this->deviceId();
        $relation->fingerprint = null;
        $relation->risk_level = 0;
        $relation->status = 1;
        $relation->create_time = Date::current();
        $relation->update_time = Date::current();
        $relation->save();

        $this->grantRelationRewards($relation, self::TRIGGER_REGISTER);

        if ($this->hasBoundEmail($user)) {
            $this->grantRelationRewards($relation, self::TRIGGER_EMAIL_BOUND);
        }
    }

    public function handleEmailBound(User $user): void
    {
        if (!$this->isEnabled() || !SchemaService::ready() || !$this->hasBoundEmail($user)) {
            return;
        }

        $relation = InviteRelation::query()->where('invitee_user_id', $user->id)->first();
        if (!$relation instanceof InviteRelation || (int)$relation->status !== 1) {
            return;
        }

        $this->grantRelationRewards($relation, self::TRIGGER_EMAIL_BOUND);
    }

    public function handleFirstPaidOrder(Order $order): void
    {
        if (!$this->isEnabled() || !SchemaService::ready()) {
            return;
        }

        if ((int)$order->owner <= 0 || (int)$order->status !== 1) {
            return;
        }

        $relation = InviteRelation::query()->where('invitee_user_id', $order->owner)->first();
        if (!$relation instanceof InviteRelation || (int)$relation->status !== 1) {
            return;
        }

        $firstOrder = Order::query()
            ->where('owner', $order->owner)
            ->where('status', 1)
            ->orderBy('pay_time', 'asc')
            ->orderBy('id', 'asc')
            ->first();

        if (!$firstOrder || (int)$firstOrder->id !== (int)$order->id) {
            return;
        }

        if (!$relation->first_paid_order_id) {
            $relation->first_paid_order_id = $order->id;
            $relation->first_paid_time = $order->pay_time ?: Date::current();
            $relation->update_time = Date::current();
            $relation->save();
        }

        $triggerId = (string)$order->id;
        $this->grantRelationRewards($relation, self::TRIGGER_FIRST_PAID_ORDER, $triggerId);
    }

    public function userSummary(int $userId): array
    {
        if (!SchemaService::ready()) {
            return $this->emptySummary();
        }

        $relation = InviteRelation::query()->where('inviter_user_id', $userId);
        $record = InviteRewardRecord::query()->where('user_id', $userId);

        return [
            'invite_count' => (clone $relation)->count(),
            'valid_count' => (clone $relation)->where('status', 1)->count(),
            'paid_count' => (clone $relation)->whereNotNull('first_paid_order_id')->count(),
            'reward_success_count' => (clone $record)->where('status', 1)->count(),
            'reward_failed_count' => (clone $record)->where('status', 2)->count()
        ];
    }

    public function adminSummary(): array
    {
        if (!SchemaService::ready()) {
            return $this->emptySummary();
        }

        return [
            'invite_count' => InviteRelation::query()->count(),
            'valid_count' => InviteRelation::query()->where('status', 1)->count(),
            'paid_count' => InviteRelation::query()->whereNotNull('first_paid_order_id')->count(),
            'reward_success_count' => InviteRewardRecord::query()->where('status', 1)->count(),
            'reward_failed_count' => InviteRewardRecord::query()->where('status', 2)->count()
        ];
    }

    public function userRelations(int $userId, int $limit = 20): array
    {
        if (!SchemaService::ready()) {
            return [];
        }

        $items = InviteRelation::query()
            ->where('inviter_user_id', $userId)
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();

        return $this->formatRelations($items);
    }

    public function adminRelations(int $limit = 50): array
    {
        if (!SchemaService::ready()) {
            return [];
        }

        $items = InviteRelation::query()
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();

        return $this->formatRelations($items, true);
    }

    public function userRewards(int $userId, int $limit = 20): array
    {
        if (!SchemaService::ready()) {
            return [];
        }

        return $this->formatRecords(
            InviteRewardRecord::query()
                ->where('user_id', $userId)
                ->orderBy('id', 'desc')
                ->limit($limit)
                ->get()
        );
    }

    public function adminRewards(int $limit = 50): array
    {
        if (!SchemaService::ready()) {
            return [];
        }

        return $this->formatRecords(
            InviteRewardRecord::query()
                ->orderBy('id', 'desc')
                ->limit($limit)
                ->get()
        );
    }

    private function findActiveCode(string $code): ?InviteCode
    {
        return InviteCode::query()
            ->where('code', $this->normalizeCode($code))
            ->where('status', 1)
            ->first();
    }

    private function generateCode(int $length): string
    {
        for ($i = 0; $i < 8; $i++) {
            $code = strtoupper(Str::generateRandStr($length));
            if (!InviteCode::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        return strtoupper(Str::generateRandStr($length + 4));
    }

    private function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?: '');
    }

    private function hasBoundEmail(User $user): bool
    {
        return trim((string)($user->email ?? '')) !== '';
    }

    private function grantRelationRewards(InviteRelation $relation, string $triggerType, string $triggerId = ''): void
    {
        $reward = new RewardService();
        $reward->grantConfiguredRewards($relation, (int)$relation->invitee_user_id, 'invitee', $triggerType, $triggerId);
        $reward->grantConfiguredRewards($relation, (int)$relation->inviter_user_id, 'inviter', $triggerType, $triggerId);
    }

    private function deviceId(): string
    {
        return sha1((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

    private function emptySummary(): array
    {
        return [
            'invite_count' => 0,
            'valid_count' => 0,
            'paid_count' => 0,
            'reward_success_count' => 0,
            'reward_failed_count' => 0
        ];
    }

    private function formatRelations(iterable $items, bool $withInviter = false): array
    {
        $result = [];

        foreach ($items as $item) {
            $invitee = User::query()->select(['id', 'username'])->find($item->invitee_user_id);
            $inviter = $withInviter ? User::query()->select(['id', 'username'])->find($item->inviter_user_id) : null;
            $result[] = [
                'id' => $item->id,
                'inviter_user_id' => $item->inviter_user_id,
                'inviter_username' => $inviter ? $inviter->username : '',
                'invitee_user_id' => $item->invitee_user_id,
                'invitee_username' => $invitee ? $invitee->username : '',
                'invite_code' => $item->invite_code,
                'status_text' => $this->relationStatusText((int)$item->status),
                'first_paid_text' => $item->first_paid_order_id ? '已首单' : '未首单',
                'first_paid_time' => $item->first_paid_time ?: '-',
                'create_time' => $item->create_time
            ];
        }

        return $result;
    }

    private function formatRecords(iterable $items): array
    {
        $result = [];

        foreach ($items as $item) {
            $rewardResult = (array)json_decode((string)$item->reward_result, true);
            $result[] = [
                'id' => $item->id,
                'user_id' => $item->user_id,
                'role_text' => $item->role === 'inviter' ? '邀请人' : '新人',
                'trigger_text' => $this->triggerText((string)$item->trigger_type),
                'reward_type_text' => $this->rewardTypeText((string)$item->reward_type),
                'reward_value' => $this->rewardValue((string)$item->reward_type, $rewardResult),
                'status_text' => $this->recordStatusText((int)$item->status),
                'remark' => $item->remark ?: '',
                'create_time' => $item->create_time,
                'grant_time' => $item->grant_time ?: '-'
            ];
        }

        return $result;
    }

    private function rewardValue(string $type, array $result): string
    {
        if ($type === 'coupon') {
            return (string)($result['code'] ?? '-');
        }

        if ($type === 'coin') {
            return (string)($result['amount'] ?? '0') . ' 硬币';
        }

        return '-';
    }

    private function rewardTypeText(string $type): string
    {
        return match ($type) {
            'coupon' => '优惠券',
            'coin' => '硬币',
            default => $type
        };
    }

    private function triggerText(string $type): string
    {
        return match ($type) {
            'register' => '注册后',
            'email_bound' => '注册并绑定邮箱后',
            'first_paid_order' => '首单支付后',
            default => $type
        };
    }

    private function relationStatusText(int $status): string
    {
        return match ($status) {
            1 => '有效',
            2 => '拒绝',
            3 => '作废',
            default => '待验证'
        };
    }

    private function recordStatusText(int $status): string
    {
        return match ($status) {
            1 => '成功',
            2 => '失败',
            3 => '拒绝',
            4 => '作废',
            default => '待发'
        };
    }
}
