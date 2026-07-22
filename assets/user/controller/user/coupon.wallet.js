!function () {
    const amountFormatter = (value, row) => {
        if (row.mode == 1) {
            return format.badge((value * 10) + "折", "a-badge-success");
        }
        return format.badge("￥" + value, "a-badge-primary");
    };

    const statusFormatter = (value, row) => {
        if (row.available == 1) {
            return format.badge(value, "a-badge-success");
        }
        if (row.status == 2) {
            return format.badge(value, "a-badge-danger");
        }
        return format.badge(value, "a-badge-dark");
    };

    const copyText = text => {
        const input = $("<input>");
        $("body").append(input);
        input.val(text).select();
        document.execCommand("copy");
        input.remove();
        layer.msg("券码已复制");
    };

    const couponTable = new Table("/user/api/couponwallet/data", "#coupon-wallet-table");
    couponTable.setColumns([
        {field: "code", title: "券码"},
        {field: "source_label", title: "来源"},
        {field: "money", title: "面值", formatter: amountFormatter},
        {field: "scope_text", title: "使用范围"},
        {field: "life", title: "剩余次数"},
        {field: "use_life", title: "已使用"},
        {field: "expire_time", title: "到期时间", formatter: value => value || "永久"},
        {field: "status_text", title: "状态", formatter: statusFormatter},
        {
            field: "operation", title: "操作", type: "button", buttons: [
                {
                    icon: "fa-duotone fa-regular fa-copy",
                    class: "text-primary",
                    title: "复制券码",
                    click: (event, value, row) => copyText(row.code)
                }
            ]
        }
    ]);
    couponTable.setFloatMessage([
        {field: "target_email", title: "限定邮箱"},
        {field: "qq", title: "QQ"},
        {field: "create_time", title: "创建时间"},
        {field: "service_time", title: "最近使用时间"},
        {field: "trade_no", title: "最近订单号"},
        {field: "note", title: "备注"}
    ]);
    couponTable.render();

    const recordTable = new Table("/user/api/couponwallet/records", "#coupon-record-table");
    recordTable.setColumns([
        {field: "trade_no", title: "订单号"},
        {field: "coupon_code", title: "券码"},
        {field: "commodity_name", title: "商品"},
        {field: "amount", title: "订单金额", formatter: value => format.money(value, "green")},
        {field: "status", title: "支付状态", dict: "_order_status"},
        {field: "create_time", title: "下单时间"},
        {field: "pay_time", title: "支付时间"}
    ]);
    recordTable.render();
}();
