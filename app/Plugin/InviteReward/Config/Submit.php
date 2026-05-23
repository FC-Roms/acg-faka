<?php
declare(strict_types=1);

return [
    [
        'title' => '插件开关',
        'name' => 'STATUS',
        'type' => 'switch',
        'text' => '启用'
    ],
    [
        'title' => '邀请有效天数',
        'name' => 'invite_cookie_days',
        'type' => 'input',
        'placeholder' => '新人点击邀请链接后，邀请码 cookie 保留天数',
        'default' => 30
    ],
    [
        'title' => '邀请码长度',
        'name' => 'code_length',
        'type' => 'input',
        'placeholder' => '建议 6 到 16 位',
        'default' => 8
    ],
    [
        'title' => '邀请人奖励触发',
        'name' => 'inviter_reward_trigger',
        'type' => 'select',
        'dict' => [
            ['id' => 'none', 'name' => '不发放'],
            ['id' => 'register', 'name' => '新人注册后'],
            ['id' => 'first_paid_order', 'name' => '新人首单支付后']
        ],
        'default' => 'first_paid_order'
    ],
    [
        'title' => '邀请人优惠券',
        'name' => 'inviter_coupon_enabled',
        'type' => 'switch',
        'text' => '发放'
    ],
    [
        'title' => '邀请人券前缀',
        'name' => 'inviter_coupon_prefix',
        'type' => 'input',
        'default' => 'INV'
    ],
    [
        'title' => '邀请人券归属',
        'name' => 'inviter_coupon_owner',
        'type' => 'input',
        'placeholder' => '0 表示系统商品，商户商品需填商户用户ID',
        'default' => 0
    ],
    [
        'title' => '邀请人券金额',
        'name' => 'inviter_coupon_money',
        'type' => 'input',
        'default' => 5
    ],
    [
        'title' => '邀请人券模式',
        'name' => 'inviter_coupon_mode',
        'type' => 'select',
        'dict' => [
            ['id' => 0, 'name' => '固定金额'],
            ['id' => 1, 'name' => '按比例']
        ],
        'default' => 0
    ],
    [
        'title' => '邀请人券次数',
        'name' => 'inviter_coupon_life',
        'type' => 'input',
        'default' => 1
    ],
    [
        'title' => '邀请人券使用人群',
        'name' => 'inviter_coupon_user_limit',
        'type' => 'select',
        'dict' => [
            ['id' => 0, 'name' => '不限'],
            ['id' => 1, 'name' => '新客绑定后'],
            ['id' => 2, 'name' => '登录会员每人限用一次']
        ],
        'default' => 2
    ],
    [
        'title' => '邀请人券有效天数',
        'name' => 'inviter_coupon_expire_days',
        'type' => 'input',
        'default' => 7
    ],
    [
        'title' => '邀请人券分类ID',
        'name' => 'inviter_coupon_category_id',
        'type' => 'input',
        'placeholder' => '0 表示不限分类',
        'default' => 0
    ],
    [
        'title' => '邀请人券商品ID',
        'name' => 'inviter_coupon_commodity_id',
        'type' => 'input',
        'placeholder' => '0 表示不限商品',
        'default' => 0
    ],
    [
        'title' => '邀请人硬币',
        'name' => 'inviter_coin_enabled',
        'type' => 'switch',
        'text' => '发放'
    ],
    [
        'title' => '邀请人硬币数量',
        'name' => 'inviter_coin_amount',
        'type' => 'input',
        'default' => 0
    ],
    [
        'title' => '新人奖励触发',
        'name' => 'invitee_reward_trigger',
        'type' => 'select',
        'dict' => [
            ['id' => 'none', 'name' => '不发放'],
            ['id' => 'register', 'name' => '注册后'],
            ['id' => 'first_paid_order', 'name' => '首单支付后']
        ],
        'default' => 'register'
    ],
    [
        'title' => '新人优惠券',
        'name' => 'invitee_coupon_enabled',
        'type' => 'switch',
        'text' => '发放'
    ],
    [
        'title' => '新人券前缀',
        'name' => 'invitee_coupon_prefix',
        'type' => 'input',
        'default' => 'NEW'
    ],
    [
        'title' => '新人券归属',
        'name' => 'invitee_coupon_owner',
        'type' => 'input',
        'placeholder' => '0 表示系统商品，商户商品需填商户用户ID',
        'default' => 0
    ],
    [
        'title' => '新人券金额',
        'name' => 'invitee_coupon_money',
        'type' => 'input',
        'default' => 3
    ],
    [
        'title' => '新人券模式',
        'name' => 'invitee_coupon_mode',
        'type' => 'select',
        'dict' => [
            ['id' => 0, 'name' => '固定金额'],
            ['id' => 1, 'name' => '按比例']
        ],
        'default' => 0
    ],
    [
        'title' => '新人券次数',
        'name' => 'invitee_coupon_life',
        'type' => 'input',
        'default' => 1
    ],
    [
        'title' => '新人券使用人群',
        'name' => 'invitee_coupon_user_limit',
        'type' => 'select',
        'dict' => [
            ['id' => 0, 'name' => '不限'],
            ['id' => 1, 'name' => '新客绑定后'],
            ['id' => 2, 'name' => '登录会员每人限用一次']
        ],
        'default' => 2
    ],
    [
        'title' => '新人券有效天数',
        'name' => 'invitee_coupon_expire_days',
        'type' => 'input',
        'default' => 7
    ],
    [
        'title' => '新人券分类ID',
        'name' => 'invitee_coupon_category_id',
        'type' => 'input',
        'placeholder' => '0 表示不限分类',
        'default' => 0
    ],
    [
        'title' => '新人券商品ID',
        'name' => 'invitee_coupon_commodity_id',
        'type' => 'input',
        'placeholder' => '0 表示不限商品',
        'default' => 0
    ],
    [
        'title' => '新人硬币',
        'name' => 'invitee_coin_enabled',
        'type' => 'switch',
        'text' => '发放'
    ],
    [
        'title' => '新人硬币数量',
        'name' => 'invitee_coin_amount',
        'type' => 'input',
        'default' => 0
    ],
    [
        'title' => '硬币计入累计',
        'name' => 'bill_total',
        'type' => 'switch',
        'text' => '计入'
    ]
];
