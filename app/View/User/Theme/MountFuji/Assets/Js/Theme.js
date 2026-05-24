!function () {
    const root = document.documentElement;
    const storageKey = root.getAttribute("data-theme-storage-key") || "mountfuji.theme.preference";
    const defaultPreference = root.getAttribute("data-theme-default-preference") || "auto";
    const media = window.matchMedia ? window.matchMedia("(prefers-color-scheme: light)") : null;
    let pjaxPending = false;
    let runtimeTimer = 0;
    let businessCommodityPopupIndex = null;

    function readStoredPreference() {
        try {
            const value = window.localStorage.getItem(storageKey);
            return value === "light" || value === "dark" ? value : null;
        } catch (error) {
            return null;
        }
    }

    function writeStoredPreference(value) {
        try {
            if (!value) {
                window.localStorage.removeItem(storageKey);
                return;
            }
            window.localStorage.setItem(storageKey, value);
        } catch (error) {
        }
    }

    function resolveMode(preference) {
        if (preference === "light" || preference === "dark") {
            return preference;
        }
        return media && media.matches ? "light" : "dark";
    }

    function updateThemeButtons(mode) {
        const storedPreference = readStoredPreference();
        document.querySelectorAll("[data-theme-toggle]").forEach(button => {
            const target = button.getAttribute("data-theme-toggle");
            const isActive = mode === target;
            button.classList.toggle("is-active", isActive);
            button.setAttribute("aria-pressed", isActive ? "true" : "false");
            if (storedPreference === target) {
                button.setAttribute("title", target === "light" ? "浅色（已缓存）" : "深色（已缓存）");
            } else {
                button.setAttribute("title", target === "light" ? "浅色" : "深色");
            }
        });
    }

    function syncTheme() {
        const storedPreference = readStoredPreference();
        const preference = storedPreference || defaultPreference;
        const mode = resolveMode(preference);

        root.setAttribute("data-theme-preference", preference);
        root.setAttribute("data-theme-user-override", storedPreference ? "1" : "0");
        root.setAttribute("data-theme-mode", mode);

        updateThemeButtons(mode);
        return mode;
    }

    function handleThemeToggle(event) {
        const target = event.currentTarget.getAttribute("data-theme-toggle");
        const storedPreference = readStoredPreference();

        if (storedPreference === target) {
            writeStoredPreference(null);
        } else {
            writeStoredPreference(target);
        }

        syncTheme();
    }

    function getUserMenuState() {
        return {
            toggle: document.querySelector("[data-mf-user-menu-toggle]"),
            menu: document.querySelector("[data-mf-user-menu]")
        };
    }

    function setUserMenuOpen(isOpen) {
        const state = getUserMenuState();

        if (!state.toggle || !state.menu) {
            return;
        }

        state.toggle.classList.toggle("is-open", isOpen);
        state.menu.classList.toggle("is-open", isOpen);
        state.toggle.setAttribute("aria-expanded", isOpen ? "true" : "false");
        state.menu.setAttribute("aria-hidden", isOpen ? "false" : "true");
    }

    function isCompactSidebarMode() {
        return window.innerWidth <= 1199;
    }

    function setMobileDrawerOpen(isOpen) {
        document.body.classList.toggle("site-mobile", isOpen);
    }

    function isCompactSidebarLink(link) {
        return !!(link && isCompactSidebarMode() && link.closest(".mf-sidebar"));
    }

    function canNavigateCompactSidebarLink(link, event) {
        if (!isCompactSidebarLink(link)) {
            return false;
        }

        const href = link.getAttribute("href") || "";

        if (!href || href === "#" || href.indexOf("javascript:") === 0) {
            return false;
        }

        if (event && (event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button > 0)) {
            return false;
        }

        try {
            return new URL(link.href, window.location.href).origin === window.location.origin;
        } catch (error) {
            return false;
        }
    }

    function navigateCompactSidebarLink(link, event) {
        if (!isCompactSidebarLink(link)) {
            return false;
        }

        if (!canNavigateCompactSidebarLink(link, event)) {
            return false;
        }

        setUserMenuOpen(false);
        window.setTimeout(() => {
            setMobileDrawerOpen(false);
        }, 48);
        return true;
    }

    function syncSidebarToggleLinks() {
        document.querySelectorAll("[data-mf-sidebar-toggle]").forEach(toggle => {
            const defaultHref = toggle.getAttribute("data-mf-default-href") || toggle.getAttribute("href") || "/user/dashboard/index";

            toggle.setAttribute("data-mf-default-href", defaultHref);

            if (isCompactSidebarMode()) {
                toggle.setAttribute("href", "javascript:void(0)");
            } else {
                toggle.setAttribute("href", defaultHref);
            }
        });
    }

    function bindUserMenu() {
        if (root.getAttribute("data-mf-user-menu-bound") === "1") {
            return;
        }

        root.setAttribute("data-mf-user-menu-bound", "1");

        document.addEventListener("click", event => {
            const state = getUserMenuState();

            if (!state.toggle || !state.menu) {
                return;
            }

            const target = event.target;

            if (state.toggle.contains(target)) {
                event.preventDefault();
                setUserMenuOpen(!state.menu.classList.contains("is-open"));
                return;
            }

            if (state.menu.contains(target)) {
                if (target.closest(".mf-user-dropdown-item")) {
                    setUserMenuOpen(false);
                }
                return;
            }

            setUserMenuOpen(false);
        });

        document.addEventListener("keydown", event => {
            if (event.key === "Escape") {
                setUserMenuOpen(false);
            }
        });
    }

    function bindMobileDrawer() {
        if (root.getAttribute("data-mf-mobile-drawer-bound") === "1") {
            return;
        }

        root.setAttribute("data-mf-mobile-drawer-bound", "1");

        syncSidebarToggleLinks();

        document.addEventListener("click", event => {
            const target = event.target;
            const sidebarToggle = target.closest("[data-mf-sidebar-toggle]");

            if (sidebarToggle && isCompactSidebarMode()) {
                event.preventDefault();
                event.stopPropagation();
                setUserMenuOpen(false);
                setMobileDrawerOpen(!document.body.classList.contains("site-mobile"));
                return;
            }

            if (target.closest(".site-mobile-shade")) {
                setMobileDrawerOpen(false);
                return;
            }

            if (isCompactSidebarMode() && document.body.classList.contains("site-mobile") && !target.closest(".mf-sidebar")) {
                setUserMenuOpen(false);
                setMobileDrawerOpen(false);
            }
        });

        document.addEventListener("click", event => {
            const target = event.target;
            const sidebarToggle = target.closest("[data-mf-sidebar-toggle]");

            if (sidebarToggle && isCompactSidebarMode()) {
                event.preventDefault();
                event.stopPropagation();
                setUserMenuOpen(false);
                setMobileDrawerOpen(!document.body.classList.contains("site-mobile"));
                return;
            }

            const sidebarLink = target.closest(".mf-sidebar a");

            if (sidebarLink) {
                navigateCompactSidebarLink(sidebarLink, event);
            }
        }, true);

        document.addEventListener("touchstart", event => {
            const sidebarToggle = event.target.closest("[data-mf-sidebar-toggle]");

            if (sidebarToggle && isCompactSidebarMode()) {
                event.preventDefault();
                event.stopPropagation();
                setUserMenuOpen(false);
                setMobileDrawerOpen(!document.body.classList.contains("site-mobile"));
            }
        }, {passive: false, capture: true});

        window.addEventListener("resize", () => {
            syncSidebarToggleLinks();
            if (!isCompactSidebarMode()) {
                setMobileDrawerOpen(false);
            }
        });
    }

    function hideGlobalLoading() {
        if (window.Loading && typeof window.Loading.hide === "function") {
            window.Loading.hide();
        }

        if (window.jQuery) {
            window.jQuery(".net-loading").hide();
        }
    }

    function finishLoadingState() {
        pjaxPending = false;
        hideGlobalLoading();
    }

    function getRuntimeShell() {
        return document.querySelector(".mf-page-shell[data-mf-controller][data-mf-table]");
    }

    function getRuntimeConfig() {
        const shell = getRuntimeShell();

        if (!shell) {
            return null;
        }

        return {
            shell: shell,
            controller: shell.getAttribute("data-mf-controller"),
            table: shell.getAttribute("data-mf-table")
        };
    }

    function isTableReady(selector) {
        if (!window.jQuery) {
            return false;
        }

        const $table = window.jQuery(selector);
        return $table.length > 0 && !!$table.data("bootstrap.table");
    }

    function cleanupTableArtifacts(selector) {
        if (!window.jQuery) {
            return;
        }

        const $table = window.jQuery(selector);

        if (!$table.length || isTableReady(selector)) {
            return;
        }

        const $scope = $table.closest(".mf-table-wrap, .form-body, .mf-panel, .content-body");

        if (!$scope.length) {
            return;
        }

        $scope.find(".bootstrap-table, .fixed-table-toolbar, .fixed-table-pagination, .fixed-table-container").remove();
        $scope.find('div[class$="-query"], form.table-search, .table-switch-state').filter(function () {
            return window.jQuery(this).closest(".bootstrap-table").length === 0;
        }).remove();
        $table.removeData("bootstrap.table");
        $table.empty();
    }

    function ensureDependency(src) {
        if (!src || document.querySelector('script[data-mf-dependency="' + src + '"], script[src*="' + src + '"]')) {
            return;
        }

        const script = document.createElement("script");
        script.src = src;
        script.async = true;
        script.setAttribute("data-mf-dependency", src);
        document.body.appendChild(script);
    }

    function injectRuntimeController(config, attempt) {
        if (!config || !config.controller || !config.table) {
            return;
        }

        config.shell.setAttribute("data-mf-runtime-pending", "1");
        cleanupTableArtifacts(config.table);

        const script = document.createElement("script");
        script.src = config.controller + (config.controller.indexOf("?") === -1 ? "?" : "&") + "mountfujiRetry=" + Date.now();
        script.async = true;
        script.setAttribute("data-mf-runtime-controller", config.controller);
        script.onload = () => {
            window.setTimeout(() => {
                config.shell.setAttribute("data-mf-runtime-pending", "0");

                if (!isTableReady(config.table) && attempt < 8) {
                    scheduleRuntimeRecovery(attempt + 1, 220);
                }
            }, 180);
        };
        script.onerror = () => {
            config.shell.setAttribute("data-mf-runtime-pending", "0");

            if (attempt < 8) {
                scheduleRuntimeRecovery(attempt + 1, 260);
            }
        };
        document.body.appendChild(script);
    }

    function recoverTableRuntime(attempt) {
        const config = getRuntimeConfig();

        if (!config || !window.jQuery) {
            return;
        }

        if (!window.jQuery(config.table).length || isTableReady(config.table)) {
            return;
        }

        if (config.shell.getAttribute("data-mf-runtime-pending") === "1") {
            return;
        }

        if (typeof window.Table !== "function") {
            ensureDependency("/assets/common/js/component/table.js");
        }

        if (typeof _Dict === "undefined") {
            ensureDependency("/assets/user/js/dict.js");
        }

        if (!window.jQuery.fn || typeof window.jQuery.fn.bootstrapTable !== "function") {
            ensureDependency("/assets/common/js/table/bootstrap-table.min.js");
        }

        if (typeof window.Table !== "function" || typeof _Dict === "undefined" || !window.jQuery.fn || typeof window.jQuery.fn.bootstrapTable !== "function") {
            if (attempt < 8) {
                scheduleRuntimeRecovery(attempt + 1, 180);
            }
            return;
        }

        injectRuntimeController(config, attempt);
    }

    function scheduleRuntimeRecovery(attempt, delay) {
        window.clearTimeout(runtimeTimer);
        runtimeTimer = window.setTimeout(() => {
            recoverTableRuntime(attempt);
        }, delay);
    }

    function normalizeCardText(node) {
        return (node ? node.textContent : "")
            .replace(/\s+/g, " ")
            .replace(/\u00a0/g, " ")
            .trim();
    }

    function extractPurchaseField(card) {
        const titleNode = card ? card.querySelector(".card-view-title, .title") : null;
        const valueNode = card ? card.querySelector(".card-view-value, .value") : null;

        return {
            title: normalizeCardText(titleNode),
            text: normalizeCardText(valueNode),
            html: valueNode ? valueNode.innerHTML.trim() : ""
        };
    }

    function hasMeaningfulPurchaseValue(field) {
        if (!field) {
            return false;
        }

        return !!field.text && field.text !== "-" && field.text !== "--" && field.text !== "暂无";
    }

    function getPurchaseFieldMarkup(field) {
        if (!field) {
            return "-";
        }

        return field.html || field.text || "-";
    }

    function buildPurchaseMetaItem(label, content, extraClass) {
        return [
            '<section class="MfPurchaseMetaItem',
            extraClass ? " " + extraClass : "",
            '">',
            '<span class="MfPurchaseFieldLabel">',
            label,
            '</span>',
            '<div class="MfPurchaseFieldValue">',
            content,
            "</div>",
            "</section>"
        ].join("");
    }

    function buildPurchaseExtraBlock(label, content, extraClass) {
        return [
            '<section class="MfPurchaseExtraBlock',
            extraClass ? " " + extraClass : "",
            '">',
            '<span class="MfPurchaseFieldLabel">',
            label,
            '</span>',
            '<div class="MfPurchaseFieldValue">',
            content,
            "</div>",
            "</section>"
        ].join("");
    }

    function removeMountedPurchaseCards(group) {
        Array.from(group.children).forEach(node => {
            if (node.classList && node.classList.contains("MfPurchaseCard")) {
                node.remove();
            }
        });
    }

    function mountPurchaseRecordCard(group, cards, fields) {
        const sku = fields[2];
        const message = fields[9];
        const secret = fields[10];
        const secretCard = cards[10];
        const hasSecretPayload = !!secretCard.querySelector(".secret, .secret-download") && hasMeaningfulPurchaseValue(secret);
        const meta = [
            buildPurchaseMetaItem("订单号", getPurchaseFieldMarkup(fields[0]), "MfPurchaseMetaItem--trade MfPurchaseMetaItem--mono"),
            buildPurchaseMetaItem("数量", getPurchaseFieldMarkup(fields[3]), "MfPurchaseMetaItem--count"),
            buildPurchaseMetaItem("付款状态", getPurchaseFieldMarkup(fields[7]), "MfPurchaseMetaItem--status"),
            buildPurchaseMetaItem("支付方式", getPurchaseFieldMarkup(fields[6]), "MfPurchaseMetaItem--pay"),
            buildPurchaseMetaItem("发货方式", getPurchaseFieldMarkup(fields[5]), "MfPurchaseMetaItem--delivery"),
            buildPurchaseMetaItem("发货状态", getPurchaseFieldMarkup(fields[8]), "MfPurchaseMetaItem--delivery-status")
        ];
        const extra = [];

        if (hasMeaningfulPurchaseValue(sku)) {
            meta.push(buildPurchaseMetaItem("SKU", getPurchaseFieldMarkup(sku), "MfPurchaseMetaItem--sku"));
        }

        if (hasMeaningfulPurchaseValue(message)) {
            extra.push(buildPurchaseExtraBlock("商家留言", getPurchaseFieldMarkup(message), "MfPurchaseExtraBlock--message"));
        }

        if (hasSecretPayload) {
            extra.push(buildPurchaseExtraBlock("宝贝信息", getPurchaseFieldMarkup(secret), "MfPurchaseExtraBlock--secret"));
        }

        removeMountedPurchaseCards(group);
        group.classList.remove("mf-order-cardviews");
        group.removeAttribute("data-mf-order-built");
        group.setAttribute("data-mf-built", "1");
        group.insertAdjacentHTML("beforeend", [
            '<article class="MfPurchaseCard">',
            '<header class="MfPurchaseHead">',
            '<section class="MfPurchaseProduct">',
            '<span class="MfPurchaseFieldLabel">商品</span>',
            '<div class="MfPurchaseProductValue">',
            getPurchaseFieldMarkup(fields[1]),
            "</div>",
            "</section>",
            '<section class="MfPurchaseAmount">',
            '<div class="MfPurchaseAmountPanel">',
            '<span class="MfPurchaseFieldLabel">金额</span>',
            '<div class="MfPurchaseAmountValue">',
            getPurchaseFieldMarkup(fields[4]),
            "</div>",
            "</div>",
            "</section>",
            "</header>",
            '<div class="MfPurchaseMeta">',
            meta.join(""),
            "</div>",
            extra.length ? '<div class="MfPurchaseExtra">' + extra.join("") + "</div>" : "",
            "</article>"
        ].join(""));

        const mountedCard = Array.from(group.children).find(node => {
            return node.classList && node.classList.contains("MfPurchaseCard");
        });

        if (!mountedCard) {
            return;
        }

        mountedCard.querySelectorAll("textarea.secret").forEach(textarea => {
            textarea.setAttribute("readonly", "readonly");
            textarea.setAttribute("spellcheck", "false");
        });
    }

    function tidyPurchaseRecordCards() {
        const scope = document.querySelector(".mf-page-shell-record");
        const layout = scope ? scope.getAttribute("data-mf-record-layout") || "native" : "native";
        let applied = false;

        if (!scope) {
            return applied;
        }

        scope.querySelectorAll(".bootstrap-table .card-views").forEach(group => {
            const cards = Array.from(group.children).filter(node => {
                return node.classList && node.classList.contains("card-view");
            });

            if (cards.length < 11) {
                return;
            }

            const fields = cards.map(extractPurchaseField);
            const sku = fields[2];
            const message = fields[9];
            const secretCard = cards[10];
            const hasSecretPayload = !!secretCard.querySelector(".secret, .secret-download");

            removeMountedPurchaseCards(group);
            group.removeAttribute("data-mf-built");
            group.classList.remove("mf-order-cardviews");
            group.removeAttribute("data-mf-order-built");

            cards.forEach(card => {
                card.classList.remove("is-mf-hidden", "is-mf-empty", "is-mf-secret-card");
                card.removeAttribute("aria-hidden");
                card.style.removeProperty("display");
            });

            if (!hasMeaningfulPurchaseValue(sku)) {
                cards[2].classList.add("is-mf-hidden", "is-mf-empty");
                cards[2].setAttribute("aria-hidden", "true");
                cards[2].style.setProperty("display", "none", "important");
            }

            if (!hasMeaningfulPurchaseValue(message)) {
                cards[9].classList.add("is-mf-hidden", "is-mf-empty");
                cards[9].setAttribute("aria-hidden", "true");
                cards[9].style.setProperty("display", "none", "important");
            }

            if (!hasSecretPayload) {
                cards[10].classList.add("is-mf-hidden", "is-mf-empty");
                cards[10].setAttribute("aria-hidden", "true");
                cards[10].style.setProperty("display", "none", "important");
            } else {
                cards[10].classList.add("is-mf-secret-card");
            }

            if (layout === "mounted") {
                mountPurchaseRecordCard(group, cards, fields);
            }
            applied = true;
        });

        return applied;
    }

    function schedulePurchaseRecordTidy(delay, retry) {
        window.setTimeout(() => {
            const success = tidyPurchaseRecordCards();
            if (!success && (retry || 0) > 0) {
                schedulePurchaseRecordTidy(180, retry - 1);
            }
        }, delay || 0);
    }

    function bindPurchaseRecordTidy() {
        if (!window.jQuery) {
            return;
        }

        const $document = window.jQuery(document);

        $document.on("post-body.bs.table", event => {
            const target = event && event.target;
            if (target && target.id && target.id !== "bill-table") {
                return;
            }
            schedulePurchaseRecordTidy(16, 6);
        });

        $document.on("pjax:complete pjax:end", () => {
            schedulePurchaseRecordTidy(80, 8);
        });
    }

    function parseRechargeNumber(value) {
        const sanitized = String(value == null ? "" : value).replace(/[^\d.]/g, "");
        const number = window.parseFloat(sanitized);
        return Number.isFinite(number) ? number : 0;
    }

    function formatRechargeMoney(value) {
        const normalized = parseRechargeNumber(value);
        const fixed = normalized.toFixed(2).replace(/(\.\d*?[1-9])0+|\.0*$/, "$1");
        return "￥" + fixed;
    }

    function updateRechargeProgress(shell) {
        shell.querySelectorAll("[data-mf-progress-current][data-mf-progress-target]").forEach(node => {
            const current = parseRechargeNumber(node.getAttribute("data-mf-progress-current"));
            const target = parseRechargeNumber(node.getAttribute("data-mf-progress-target"));
            const bar = node.querySelector("[data-mf-progress-bar]");

            if (!bar) {
                return;
            }

            if (target <= 0) {
                bar.style.width = "100%";
                return;
            }

            bar.style.width = Math.max(6, Math.min(100, current / target * 100)) + "%";
        });
    }

    function updateRechargeAmount(shell) {
        const input = shell.querySelector("[data-mf-recharge-input]");

        if (!input) {
            return;
        }

        const amount = parseRechargeNumber(input.value);
        const balance = parseRechargeNumber(shell.getAttribute("data-mf-balance"));
        const nextBalance = balance + amount;

        shell.querySelectorAll("[data-mf-recharge-amount-preview]").forEach(node => {
            node.textContent = formatRechargeMoney(amount);
        });

        shell.querySelectorAll("[data-mf-recharge-balance-preview]").forEach(node => {
            node.textContent = formatRechargeMoney(nextBalance);
        });

        shell.querySelectorAll("[data-mf-recharge-amount]").forEach(button => {
            const buttonAmount = parseRechargeNumber(button.getAttribute("data-mf-recharge-amount"));
            button.classList.toggle("is-active", Math.abs(buttonAmount - amount) < 0.001);
        });
    }

    function ensureRechargeDefaultPay(shell) {
        const payList = shell.querySelector(".pay-list");

        if (!payList || payList.querySelector(".checked")) {
            return;
        }

        const firstButton = payList.querySelector(".btn-pay");

        if (firstButton && typeof firstButton.click === "function") {
            firstButton.click();
        }
    }

    function bindRechargeInteractions(shell) {
        if (!shell || shell.getAttribute("data-mf-recharge-bound") === "1") {
            return;
        }

        shell.setAttribute("data-mf-recharge-bound", "1");
        updateRechargeProgress(shell);
        updateRechargeAmount(shell);
        ensureRechargeDefaultPay(shell);

        shell.addEventListener("click", event => {
            const preset = event.target.closest("[data-mf-recharge-amount]");
            const input = shell.querySelector("[data-mf-recharge-input]");

            if (!preset || !input) {
                return;
            }

            input.value = preset.getAttribute("data-mf-recharge-amount") || "";
            updateRechargeAmount(shell);
            input.dispatchEvent(new Event("input", {bubbles: true}));
        });

        const input = shell.querySelector("[data-mf-recharge-input]");

        if (input) {
            input.addEventListener("input", () => {
                updateRechargeAmount(shell);
            });
        }

        const payList = shell.querySelector(".pay-list");

        if (payList && window.MutationObserver) {
            const observer = new MutationObserver(() => {
                ensureRechargeDefaultPay(shell);
            });

            observer.observe(payList, {childList: true});
        }
    }

    function renderLayuiForms() {
        if (!window.layui || typeof window.layui.use !== "function") {
            return;
        }

        try {
            window.layui.use("form", () => {
                const form = window.layui.form;

                if (!form || typeof form.render !== "function") {
                    return;
                }

                form.render("select");
                form.render("radio");
                form.render("checkbox");
            });
        } catch (error) {
        }
    }

    function initRechargeExperience() {
        document.querySelectorAll(".mf-page-shell-recharge").forEach(shell => {
            bindRechargeInteractions(shell);
        });
    }

    function bindSettlementOptions(shell) {
        if (!shell || shell.getAttribute("data-mf-settlement-bound") === "1") {
            return;
        }

        const input = shell.querySelector("[data-mf-settlement-input]");
        const buttons = Array.from(shell.querySelectorAll("[data-mf-settlement]"));

        if (!input || !buttons.length) {
            return;
        }

        const syncButtons = value => {
            buttons.forEach(button => {
                button.classList.toggle("checked", button.getAttribute("data-mf-settlement") === String(value));
            });
        };

        syncButtons(input.value);
        shell.setAttribute("data-mf-settlement-bound", "1");
        shell.addEventListener("click", event => {
            const button = event.target.closest("[data-mf-settlement]");

            if (!button || !shell.contains(button)) {
                return;
            }

            input.value = button.getAttribute("data-mf-settlement") || "";
            syncButtons(input.value);
        });
    }

    function initSettlementOptions() {
        document.querySelectorAll(".mf-page-shell-personal").forEach(shell => {
            bindSettlementOptions(shell);
        });
    }

    function resetBusinessTableView(selector, delay) {
        if (!window.jQuery || !window.jQuery.fn || typeof window.jQuery.fn.bootstrapTable !== "function") {
            return;
        }

        window.setTimeout(() => {
            const $table = window.jQuery(selector);

            if (!$table.length || !$table.data("bootstrap.table")) {
                return;
            }

            try {
                $table.bootstrapTable("resetView");
            } catch (error) {
            }
        }, delay || 0);
    }

    function getBusinessPopupLayer() {
        if (window.layer && typeof window.layer.open === "function") {
            return window.layer;
        }

        if (window.layui && window.layui.layer && typeof window.layui.layer.open === "function") {
            return window.layui.layer;
        }

        return null;
    }

    function getBusinessPopupArea() {
        const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;

        if (viewportWidth <= 768) {
            return ["100%", ""];
        }

        const width = Math.min(980, Math.max(760, viewportWidth - 40));
        return [width + "px", ""];
    }

    function getBusinessPopupMaxHeight() {
        const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
        return Math.max(360, viewportHeight - 84);
    }

    function canUseBusinessPopupControls() {
        const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
        return viewportWidth > 768;
    }

    function toggleBusinessPopupWindowState(layero, isFull) {
        const popupNode = layero && layero[0] ? layero[0] : layero;

        if (!popupNode) {
            return;
        }

        popupNode.classList.toggle("is-mf-layer-full", !!isFull);
    }

    function switchBusinessTab(shell, key) {
        if (!shell) {
            return;
        }

        shell.querySelectorAll("[data-mf-business-tab-trigger]").forEach(trigger => {
            const isActive = trigger.getAttribute("data-mf-business-tab-trigger") === key;
            trigger.classList.toggle("is-active", isActive);
            trigger.setAttribute("aria-selected", isActive ? "true" : "false");
        });

        shell.querySelectorAll("[data-mf-business-tab-panel]").forEach(panel => {
            const isActive = panel.getAttribute("data-mf-business-tab-panel") === key;
            panel.classList.toggle("is-active", isActive);
            panel.setAttribute("aria-hidden", isActive ? "false" : "true");
        });

        if (key === "product") {
            resetBusinessTableView("#master_category", 60);
            resetBusinessTableView("#master_commodity", 120);
        }

        renderLayuiForms();
    }

    function extractBusinessCategoryName(trigger) {
        const row = trigger ? trigger.closest("tr") : null;
        const firstCell = row ? row.querySelector("td") : null;
        return normalizeCardText(firstCell);
    }

    function restoreBusinessCommoditySection(shell) {
        if (!shell) {
            return;
        }

        const anchor = shell.querySelector("[data-mf-business-commodity-anchor]");
        const section = document.querySelector("[data-mf-business-commodity-section]");

        if (!anchor || !section) {
            return;
        }

        if (anchor.nextElementSibling !== section) {
            anchor.insertAdjacentElement("afterend", section);
        }

        section.classList.remove("is-mf-business-popup-mounted");
        section.style.display = "";
        resetBusinessTableView("#master_commodity", 80);
    }

    function openBusinessCommodityPopup(shell, titleText) {
        const popupLayer = getBusinessPopupLayer();

        if (!shell || !popupLayer) {
            return;
        }

        const section = shell.querySelector("[data-mf-business-commodity-section]");
        const sectionObject = window.jQuery ? window.jQuery(section) : null;

        if (!section || !sectionObject || !sectionObject.length) {
            return;
        }

        restoreBusinessCommoditySection(shell);
        section.classList.add("is-mf-business-popup-mounted");
        section.style.display = "grid";

        if (businessCommodityPopupIndex !== null && typeof popupLayer.close === "function") {
            popupLayer.close(businessCommodityPopupIndex);
            businessCommodityPopupIndex = null;
        }

        const popupArea = getBusinessPopupArea();
        const popupMaxHeight = getBusinessPopupMaxHeight();
        const allowWindowControls = canUseBusinessPopupControls();

        businessCommodityPopupIndex = popupLayer.open({
            type: 1,
            skin: "mf-business-popup-layer",
            title: titleText ? "主站商品 · " + titleText : "主站商品",
            shadeClose: true,
            maxmin: allowWindowControls,
            area: popupArea,
            maxHeight: popupMaxHeight,
            content: sectionObject,
            success: layero => {
                const popupNode = layero && layero[0] ? layero[0] : layero;
                const maxButton = popupNode ? popupNode.querySelector(".layui-layer-max") : null;
                toggleBusinessPopupWindowState(popupNode, false);
                if (maxButton) {
                    maxButton.classList.remove("layui-layer-maxmin");
                }
                resetBusinessTableView("#master_commodity", 120);
            },
            full: layero => {
                toggleBusinessPopupWindowState(layero, true);
                resetBusinessTableView("#master_commodity", 120);
            },
            restore: layero => {
                toggleBusinessPopupWindowState(layero, false);
                resetBusinessTableView("#master_commodity", 120);
            },
            end: () => {
                businessCommodityPopupIndex = null;
                restoreBusinessCommoditySection(shell);
            }
        });

        resetBusinessTableView("#master_commodity", 260);
    }

    function findBusinessCommodityTrigger(target, shell) {
        if (!target || !shell || typeof target.closest !== "function") {
            return null;
        }

        const categoryTable = target.closest("#master_category");

        if (!categoryTable || !shell.contains(categoryTable)) {
            return null;
        }

        const button = target.closest(".a-badge-glass");
        const titleNode = button ? button.querySelector(".btn-title") : null;

        if (!button || !categoryTable.contains(button) || normalizeCardText(titleNode) !== "查看商品") {
            return null;
        }

        return button;
    }

    function bindBusinessTabs(shell) {
        if (!shell || shell.getAttribute("data-mf-business-bound") === "1") {
            return;
        }

        shell.setAttribute("data-mf-business-bound", "1");
        shell.addEventListener("click", event => {
            const trigger = event.target.closest("[data-mf-business-tab-trigger]");

            if (trigger && shell.contains(trigger)) {
                switchBusinessTab(shell, trigger.getAttribute("data-mf-business-tab-trigger") || "basic");
                return;
            }
        });

        shell.addEventListener("click", event => {
            const trigger = findBusinessCommodityTrigger(event.target, shell);

            if (!trigger) {
                return;
            }

            window.setTimeout(() => {
                openBusinessCommodityPopup(shell, extractBusinessCategoryName(trigger));
            }, 220);
        }, true);
    }

    function initBusinessExperience() {
        document.querySelectorAll(".mf-page-shell-business").forEach(shell => {
            bindBusinessTabs(shell);
            switchBusinessTab(shell, "basic");
        });
    }

    function bindLoadingCleanup() {
        if (!window.jQuery) {
            return;
        }

        const $document = window.jQuery(document);

        $document.on("pjax:send", () => {
            pjaxPending = true;
            setUserMenuOpen(false);
            setMobileDrawerOpen(false);
        });
        $document.on("pjax:complete pjax:end pjax:error", finishLoadingState);
        $document.on("pjax:timeout", event => {
            if (event && typeof event.preventDefault === "function") {
                event.preventDefault();
            }
            finishLoadingState();
        });

        $document.on("click", ".layui-nav-tree a", () => {
            window.setTimeout(() => {
                if (!pjaxPending) {
                    hideGlobalLoading();
                }
            }, 180);
        });
        $document.on("pjax:complete pjax:end pjax:error", () => {
            scheduleRuntimeRecovery(0, 180);
            window.setTimeout(() => {
                initRechargeExperience();
                renderLayuiForms();
                initSettlementOptions();
                initBusinessExperience();
            }, 120);
        });
        $document.on("pjax:timeout", () => {
            scheduleRuntimeRecovery(0, 220);
            window.setTimeout(() => {
                initRechargeExperience();
                renderLayuiForms();
                initSettlementOptions();
                initBusinessExperience();
            }, 140);
        });

        window.addEventListener("pageshow", finishLoadingState);
        window.addEventListener("load", finishLoadingState);
    }

    bindLoadingCleanup();
    bindPurchaseRecordTidy();
    bindUserMenu();
    bindMobileDrawer();

    document.addEventListener("DOMContentLoaded", () => {
        syncTheme();
        syncSidebarToggleLinks();
        setUserMenuOpen(false);
        setMobileDrawerOpen(false);
        hideGlobalLoading();
        scheduleRuntimeRecovery(0, 240);
        schedulePurchaseRecordTidy(240, 12);
        initRechargeExperience();
        renderLayuiForms();
        initSettlementOptions();
        initBusinessExperience();

        document.querySelectorAll(".mf-current-year").forEach(node => {
            node.textContent = new Date().getFullYear().toString();
        });

        document.querySelectorAll("[data-theme-toggle]").forEach(button => {
            button.addEventListener("click", handleThemeToggle);
        });
    });

    if (media) {
        const listener = () => {
            if (!readStoredPreference() && defaultPreference === "auto") {
                syncTheme();
            }
        };

        if (typeof media.addEventListener === "function") {
            media.addEventListener("change", listener);
        } else if (typeof media.addListener === "function") {
            media.addListener(listener);
        }
    }
}();
