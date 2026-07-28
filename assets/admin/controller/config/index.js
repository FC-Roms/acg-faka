!function () {
    const _notice = document.getElementById('md-config-notice-source')?.value ?? '';
    let _themes = [];
    try {
        const parsedThemes = JSON.parse(document.getElementById('md-config-themes-source')?.value || '[]');
        if (Array.isArray(parsedThemes)) _themes = parsedThemes;
    } catch (error) {}
    const namespace = '.mdConfigIndexController';
    const htmlEntities = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => htmlEntities[character]);
    let controllerActive = true;
    let noticeEditor = null;
    let formReady = false;
    let formDirty = false;
    let dirtyRevision = 0;
    let saveInFlight = false;

    if (typeof window.__mdConfigIndexDestroy === 'function') window.__mdConfigIndexDestroy();

    function formElement() {
        return document.getElementById('data-form');
    }

    function formRevision() {
        const form = formElement();
        return form && window.AdminMobile?.pageWorkflows?.getRevision ? window.AdminMobile.pageWorkflows.getRevision(form) : null;
    }

    function emitFormState(name, revision) {
        const form = formElement();
        if (!form) return;
        document.dispatchEvent(new CustomEvent(name, {detail: {form: form, revision: revision}}));
    }

    function markFormDirty() {
        if (!controllerActive || !formReady) return;
        formDirty = true;
        dirtyRevision += 1;
        emitFormState('admin:mobile:form-dirty');
    }

    function mobileGuardEnabled() {
        return window.AdminMobile?.isEnabled?.() === true;
    }

    function setMainSaveBusy(busy) {
        const $button = $('#data-form .save-data');
        $button.prop('disabled', busy).toggleClass('disabled', busy);
        if (busy) {
            $button.attr({'aria-busy': 'true', 'aria-disabled': 'true'});
        } else {
            $button.removeAttr('aria-busy aria-disabled');
        }
    }

    function setPopupSaveBusy(index, busy) {
        const $button = $(`#layui-layer${index} .layui-layer-btn0`);
        $button.toggleClass('layui-disabled', busy).css('pointer-events', busy ? 'none' : '');
        if (busy) {
            $button.attr({'aria-busy': 'true', 'aria-disabled': 'true'});
        } else {
            $button.removeAttr('aria-busy aria-disabled');
        }
    }

    function destroy() {
        if (!controllerActive) return;
        formReady = false;
        formDirty = false;
        saveInFlight = false;
        setMainSaveBusy(false);
        controllerActive = false;
        if (noticeEditor && typeof noticeEditor.destroy === 'function') noticeEditor.destroy();
        noticeEditor = null;
        $('#data-form, #data-form input[name="admin_entrance_secret"], #data-form input[name="admin_entrance_clear"], .theme-setting, .theme-mobile-setting, .theme-user-setting, .theme-user-mobile-setting, .save-data').off(namespace);
        $(window).off('beforeunload' + namespace);
        $(document)
            .off('pjax:beforeReplace' + namespace)
            .off('pjax:click' + namespace)
            .off('pjax:beforeSend' + namespace);
        if (window.__mdConfigIndexDestroy === destroy) delete window.__mdConfigIndexDestroy;
    }

    window.__mdConfigIndexDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);

    function _NoticeEditor() {
        ['basePath', 'workerPath', 'modePath', 'themePath'].forEach(name => {
            ace.config.set(name, '/assets/common/js/editor/code/lib');
        });
        const wangEditor = window.wangEditor, uploadUrl = '/admin/api/upload/send';
        const editor = new wangEditor(`.editor-container`);
        const textarea = $(`.text-container`);
        const editorContent = $('.editor-content');
        const editorWrapper = $('.editor-wrapper');
        const htmlContainer = $('.html-container');

        editor.config.onchange = function (html) {
            textarea.val(html);
            markFormDirty();
        }

        editor.config.zIndex = 0;
        editor.config.uploadFileName = 'file';
        editor.config.uploadImgServer = uploadUrl + "?mime=image";
        editor.config.uploadImgAccept = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
        editor.config.uploadImgMaxLength = 1;
        editor.config.uploadImgTimeout = 60 * 1000;
        editor.config.uploadImgMaxSize = 50 * 1024 * 1024;  //50M
        editor.config.uploadImgHooks = {
            customInsert: function (insertImgFn, result) {
                if (result.code != 200) {
                    layer.msg(result.msg);
                    return;
                }
                insertImgFn(result.data.url);
            },
            error: function (xhr, editor, resData) {
                layer.msg("图片上传失败，文件可能过大");
            },
        }
        editor.config.uploadVideoServer = uploadUrl + "?mime=video";
        editor.config.uploadVideoName = 'file'
        editor.config.uploadVideoHooks = {
            customInsert: function (insertVideoFn, result) {
                if (result.code != 200) {
                    layer.msg(result.msg);
                    return;
                }
                insertVideoFn(result.data.url);
            },
            error: function (xhr, editor, resData) {
                layer.msg("视频上传失败，文件可能过大");
            },
        }
        editor.config.height = 480;
        editor.create();
        noticeEditor = {
            destroy: () => {
                if (typeof editor.destroy === 'function') editor.destroy();
            },
            getHTML: () => {
                const html = editor.txt.html();
                textarea.val(html);
                return html;
            }
        };

        editor.txt.html(_notice);
        textarea.val(_notice);


        $('.button-switch-notice').off(namespace).on('click' + namespace, function () {
            let _obj = $(this);
            let type = _obj.attr("data-type");
            if (type == 0) {
                const toolbarWidth = $(`.editor-container .w-e-toolbar`).width();
                const heightDifference = toolbarWidth > 1000 ? 40 : 80;

                _obj.attr("data-type", 1);
                _obj.html('<i class="fa-duotone fa-regular fa-pen-paintbrush me-1"></i>' + i18n("写作"));
                editorWrapper.append(`<div id="notice-tmp-html" style="margin-top:10px;width:100%;height: ${480 + heightDifference}px"></div>`);
                const editor = ace.edit(`notice-tmp-html`, {
                    theme: "ace/theme/chrome",
                    mode: "ace/mode/html"
                });
                editor.getSession().setUseWrapMode(true);
                editor.setOption("showPrintMargin", false);
                editor.setValue(textarea.val());
                editor.getSession().on('change', function (delta) {
                    const currentContent = editor.getValue();
                    textarea.val(currentContent);
                });
                editorContent.hide();
                htmlContainer.fadeIn(150);
            } else {
                _obj.attr("data-type", 0);
                _obj.html('<i class="fa-duotone fa-regular fa-code me-1"></i>HTML');
                editor.txt.html(textarea.val());
                $(`#notice-tmp-html`).remove();
                editorContent.fadeIn(150);
            }
        });
    }


    function _UploadLogoAndBackground() {

        util.bindButtonUpload(".upload-logo", "/admin/api/upload/send?mime=image", data => {
            if (!controllerActive) return;
            $('input[name=logo]').val(data.url);
            markFormDirty();
            layer.msg('图标上传成功，但需要保存后才会生效');
            $('.image-input-wrapper').css({
                "background-image": `url(${data.url})`
            });
        });

        util.bindButtonUpload(".background-upload", "/admin/api/upload/send?mime=image", data => {
            if (!controllerActive) return;
            layer.msg('背景图片上传成功，需要保存才会生效');
            $('input[name=background_url]').val(data.url);
            markFormDirty();
        });

        util.bindButtonUpload(".background-mobile-upload", "/admin/api/upload/send?mime=image", data => {
            if (!controllerActive) return;
            layer.msg('手机背景图片上传成功，需要保存才会生效');
            $('input[name=background_mobile_url]').val(data.url);
            markFormDirty();
        });
    }


    function _ThemeSetting() {
        let themes = {};
        _themes.forEach(item => {
            themes[item.info.KEY] = item;
        });

        function modal(values = {}, contextLabel = '模板设置') {
            const themeKey = String(values?.info?.KEY || '');
            if (!/^[A-Za-z][A-Za-z0-9_]{0,63}$/.test(themeKey)) {
                layer.msg('模板信息不完整，请刷新页面后重试');
                return;
            }
            let submit = [];
            if (Array.isArray(values.submit)) {
                submit = [
                    {
                        name: `<i class="fa-duotone fa-regular fa-gear-code"></i> ${escapeHtml(values.info.NAME)}`,
                        form: values.submit
                    },
                ];
            } else if (typeof values.submit === "string" && values.submit.trim() != "") {
                try {
                    submit = eval(values.submit) ?? [];
                } catch (error) {
                    layer.msg('模板设置定义无法解析，请联系模板作者');
                    return;
                }
            }

            if (!Array.isArray(submit) || submit.length === 0) {
                layer.msg("该模板暂时没有可设置的选项");
                return;
            }

            const endpoint = `/admin/api/plugin/setThemeConfig?id=${encodeURIComponent(themeKey)}`;
            let themeSaveInFlight = false;
            component.popup({
                mobileTitle: `${contextLabel} · ${String(values.info.NAME || '模板')}`,
                submitRoute: endpoint,
                submit: (data, index) => {
                    if (themeSaveInFlight || !controllerActive) return;
                    themeSaveInFlight = true;
                    setPopupSaveBusy(index, true);
                    util.post({
                        url: endpoint,
                        data: data,
                        done: () => {
                            if (!controllerActive) return;
                            layer.close(index);
                            window.location.reload();
                        },
                        error: res => {
                            if (!controllerActive) return;
                            themeSaveInFlight = false;
                            setPopupSaveBusy(index, false);
                            message.error(res?.msg || '模板设置保存失败');
                        },
                        fail: () => {
                            if (!controllerActive) return;
                            themeSaveInFlight = false;
                            setPopupSaveBusy(index, false);
                            message.error('网络异常，模板设置未保存');
                        }
                    });
                },
                tab: submit,
                autoPosition: true,
                height: "auto",
                assign: values.setting,
                width: "660px"
            });
        }


        $('.theme-setting').off(namespace).on('click' + namespace, function () {
            let userTheme = $('select[name=user_theme]').val();
            modal(themes[userTheme], 'PC 网站模板');
        });

        $('.theme-mobile-setting').off(namespace).on('click' + namespace, function () {
            let userTheme = $('select[name=user_mobile_theme]').val();
            if (userTheme == 0) {
                layer.msg("当前手机模板正在跟随 PC 模板，请先选择独立的手机模板后再配置");
                return;
            }
            modal(themes[userTheme], '手机网站模板');
        });

        $('.theme-user-setting').off(namespace).on('click' + namespace, function () {
            let userTheme = $('select[name=user_center_theme]').val();
            if (userTheme == 0) {
                layer.msg("当前未选择可配置的 PC 会员中心模板，请先选择模板");
                return;
            }
            modal(themes[userTheme], 'PC 会员中心模板');
        });

        $('.theme-user-mobile-setting').off(namespace).on('click' + namespace, function () {
            let userTheme = $('select[name=user_center_mobile_theme]').val();
            if (userTheme == 0) {
                userTheme = $('select[name=user_center_theme]').val();
            }
            modal(themes[userTheme], '手机会员中心模板');
        });
    }

    function _EntranceControl() {
        const $input = $('#data-form input[name="admin_entrance_secret"]');
        const $clear = $('#data-form input[name="admin_entrance_clear"]');
        const sync = () => {
            const clearing = $clear.prop('checked') === true;
            if (clearing) $input.val('');
            $input.prop('readonly', clearing).attr('aria-disabled', clearing ? 'true' : 'false');
        };
        $clear.off(namespace).on('change' + namespace, sync);
        $input.off(namespace).on('input' + namespace, function () {
            if (this.value !== '' && $clear.prop('checked')) {
                $clear.prop('checked', false);
                sync();
            }
        });
        sync();
    }


    function _Save() {
        $('#data-form').off(namespace).on('input' + namespace + ' change' + namespace, 'input, textarea, select', markFormDirty);
        $(window).off('beforeunload' + namespace).on('beforeunload' + namespace, event => {
            if (!formDirty) return;
            event.preventDefault();
            if (event.originalEvent) event.originalEvent.returnValue = '';
            return '';
        });
        const guardDesktopPjaxLeave = event => {
            if (!formDirty || mobileGuardEnabled()) return;
            if (window.confirm('当前网站设置还有未保存的修改，确定离开吗？')) {
                formDirty = false;
                return;
            }
            event.preventDefault();
            event.stopImmediatePropagation();
            return false;
        };
        $(document)
            .off('pjax:click' + namespace)
            .off('pjax:beforeSend' + namespace)
            .on('pjax:click' + namespace, guardDesktopPjaxLeave)
            .on('pjax:beforeSend' + namespace, guardDesktopPjaxLeave);
        const submitSettings = () => {
            if (saveInFlight || !controllerActive) return;
            if (noticeEditor && typeof noticeEditor.getHTML === 'function') noticeEditor.getHTML();
            const revision = formRevision();
            const localRevision = dirtyRevision;
            const entranceInput = document.querySelector('#data-form input[name="admin_entrance_secret"]');
            const entranceClear = document.querySelector('#data-form input[name="admin_entrance_clear"]');
            const submittedEntrance = entranceInput?.value ?? '';
            const submittedEntranceClear = entranceClear?.checked === true;
            const payload = util.arrayToObject($("#data-form").serializeArray());
            saveInFlight = true;
            setMainSaveBusy(true);
            util.post({
                url: "/admin/api/config/setting",
                data: payload,
                done: res => {
                    if (!controllerActive) return;
                    saveInFlight = false;
                    setMainSaveBusy(false);
                    if (dirtyRevision === localRevision) formDirty = false;
                    if (entranceInput?.isConnected && entranceInput.value === submittedEntrance) entranceInput.value = '';
                    if (entranceClear?.isConnected && entranceClear.checked === submittedEntranceClear) {
                        entranceClear.checked = false;
                        if (entranceInput?.isConnected) {
                            entranceInput.removeAttribute('readonly');
                            entranceInput.removeAttribute('aria-disabled');
                        }
                    }
                    layer.msg(res.msg);
                    emitFormState('admin:mobile:form-saved', revision);
                },
                error: res => {
                    if (!controllerActive) return;
                    saveInFlight = false;
                    setMainSaveBusy(false);
                    if (mobileGuardEnabled()) window.AdminMobile?.pageWorkflows?.focusFormError?.(formElement(), res?.msg);
                    message.error(res?.msg || '网站设置保存失败');
                },
                fail: () => {
                    if (!controllerActive) return;
                    saveInFlight = false;
                    setMainSaveBusy(false);
                    message.error('网络异常，网站设置未保存');
                }
            });
        };
        $('.save-data').off(namespace).on('click' + namespace, function () {
            if (saveInFlight || !controllerActive) return;
            const entranceClear = document.querySelector('#data-form input[name="admin_entrance_clear"]');
            if (entranceClear?.checked === true) {
                message.ask(
                    '关闭后，后台安全入口将立即失效。请确认这不是为保存其他设置而误勾选。',
                    submitSettings,
                    '确认关闭后台安全入口？',
                    '确认关闭并保存'
                );
                return;
            }
            submitSettings();
        });
    }

    _NoticeEditor();
    _UploadLogoAndBackground();
    _ThemeSetting();
    _EntranceControl();
    _Save();
    formReady = true;
}();
