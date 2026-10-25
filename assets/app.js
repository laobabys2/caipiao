/* 前台单页交互：选号、登记注数、三种模式分页列表 */

(function () {
    'use strict';

    var RED_MAX = 33;
    var BLUE_MAX = 16;
    var PAGE_SIZE = 50;

    var state = {
        reds: [],
        blue: null,
        mode: 'most',
        pages: [],
        pageIndex: 0,
        loading: false,
        loadedMeta: null
    };

    var els = {
        redGrid: document.getElementById('redGrid'),
        blueGrid: document.getElementById('blueGrid'),
        redPreview: document.getElementById('redPreview'),
        bluePreview: document.getElementById('bluePreview'),
        redCounter: document.getElementById('redCounter'),
        blueCounter: document.getElementById('blueCounter'),
        countInput: document.getElementById('countInput'),
        registerBtn: document.getElementById('registerBtn'),
        queryBtn: document.getElementById('queryBtn'),
        clearBtn: document.getElementById('clearBtn'),
        selectionInfo: document.getElementById('selectionInfo'),
        message: document.getElementById('message'),
        metaDate: document.getElementById('metaDate'),
        metaWeekday: document.getElementById('metaWeekday'),
        metaDraw: document.getElementById('metaDraw'),
        metaIssue: document.getElementById('metaIssue'),
        metaNext: document.getElementById('metaNext'),
        metaNotice: document.getElementById('metaNotice'),
        modeTabs: document.getElementById('modeTabs'),
        zeroTip: document.getElementById('zeroTip'),
        zeroTipCount: document.getElementById('zeroTipCount'),
        listBody: document.getElementById('listBody'),
        prevBtn: document.getElementById('prevBtn'),
        nextBtn: document.getElementById('nextBtn'),
        pageInfo: document.getElementById('pageInfo'),
        captchaModal: document.getElementById('captchaModal'),
        captchaBody: document.getElementById('captchaBody'),
        captchaTip: document.getElementById('captchaTip'),
        captchaMessage: document.getElementById('captchaMessage'),
        captchaConfirm: document.getElementById('captchaConfirm'),
        captchaRefresh: document.getElementById('captchaRefresh'),
        captchaCancel: document.getElementById('captchaCancel')
    };

    function pad2(value) {
        return String(value).padStart(2, '0');
    }

    function showMessage(text, type) {
        els.message.className = 'message' + (type ? ' ' + type : '');
        els.message.textContent = text || '';
    }

    function fetchJson(url, options) {
        var opts = options || {};
        opts.headers = Object.assign({ 'Accept': 'application/json' }, opts.headers || {});
        return fetch(url, opts).then(function (response) {
            return response.json().catch(function () {
                return { success: false, message: '服务器返回格式异常' };
            }).then(function (body) {
                return { status: response.status, body: body };
            });
        });
    }

    /* ---------- 号码选择 ---------- */

    function buildGrids() {
        var redFragment = document.createDocumentFragment();
        for (var red = 1; red <= RED_MAX; red++) {
            redFragment.appendChild(createNumberButton(red, 'red'));
        }
        els.redGrid.appendChild(redFragment);

        var blueFragment = document.createDocumentFragment();
        for (var blue = 1; blue <= BLUE_MAX; blue++) {
            blueFragment.appendChild(createNumberButton(blue, 'blue'));
        }
        els.blueGrid.appendChild(blueFragment);
    }

    function createNumberButton(number, kind) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'number-btn';
        button.dataset.number = String(number);
        button.dataset.kind = kind;
        button.textContent = pad2(number);
        button.addEventListener('click', function () {
            if (kind === 'red') {
                toggleRed(number);
            } else {
                toggleBlue(number);
            }
        });
        return button;
    }

    function toggleRed(number) {
        var index = state.reds.indexOf(number);
        if (index >= 0) {
            state.reds.splice(index, 1);
        } else {
            if (state.reds.length >= 6) {
                showMessage('红球最多选择 6 个，请先取消已选号码', 'error');
                return;
            }
            state.reds.push(number);
            state.reds.sort(function (a, b) { return a - b; });
        }
        showMessage('');
        renderSelection();
    }

    function toggleBlue(number) {
        state.blue = state.blue === number ? null : number;
        showMessage('');
        renderSelection();
    }

    function renderSelection() {
        Array.prototype.forEach.call(els.redGrid.children, function (button) {
            var number = Number(button.dataset.number);
            button.classList.toggle('selected', state.reds.indexOf(number) >= 0);
        });
        Array.prototype.forEach.call(els.blueGrid.children, function (button) {
            var number = Number(button.dataset.number);
            button.classList.toggle('selected', state.blue === number);
        });

        els.redCounter.textContent = state.reds.length + ' / 6';
        els.blueCounter.textContent = (state.blue === null ? 0 : 1) + ' / 1';

        renderPreview(els.redPreview, state.reds, 'red');
        renderPreview(els.bluePreview, state.blue === null ? [] : [state.blue], 'blue');

        var complete = state.reds.length === 6 && state.blue !== null;
        els.registerBtn.disabled = !complete;
        els.queryBtn.disabled = !complete;
        if (complete) {
            els.selectionInfo.textContent = '组合：' + state.reds.map(pad2).join(',') + ' | ' + pad2(state.blue);
            queryCombination();
        } else {
            els.selectionInfo.textContent = '请选择 6 个红球和 1 个蓝球';
        }
    }

    function renderPreview(container, numbers, kind) {
        container.innerHTML = '';
        container.classList.toggle('empty-hint', numbers.length === 0);
        if (numbers.length === 0) {
            container.textContent = '未选择';
            return;
        }
        numbers.forEach(function (number) {
            var ball = document.createElement('span');
            ball.className = 'ball md ' + kind;
            ball.textContent = pad2(number);
            container.appendChild(ball);
        });
    }

    var queryToken = 0;

    function queryCombination() {
        if (state.reds.length !== 6 || state.blue === null) {
            return null;
        }
        var token = ++queryToken;
        var url = '/api/combination.php?red=' + encodeURIComponent(state.reds.map(pad2).join(',')) +
            '&blue=' + encodeURIComponent(pad2(state.blue)) + '&_t=' + Date.now();

        return fetchJson(url).then(function (result) {
            if (token !== queryToken) {
                return;
            }
            if (result.status === 200 && result.body.success) {
                var item = result.body.data;
                showMessage('组合 ' + item.combination + ' 当前注数：' + item.total_count + ' 注', 'info');
            } else if (result.status === 404) {
                showMessage('该组合尚未在组合表中（组合表可能仍在初始化）', 'error');
            } else {
                showMessage(result.body.message || '查询失败', 'error');
            }
        }).catch(function () {
            showMessage('网络异常，无法查询该组合', 'error');
        });
    }

    function makeRequestId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        return 'req-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
    }

    /* ---------- 人机验证 + 登记 ---------- */

    var captcha = {
        challenge: null,
        sliderPos: 0,
        requestId: null,
        submitting: false
    };

    function validateBeforeSubmit() {
        if (state.reds.length !== 6 || state.blue === null) {
            showMessage('请先选择 6 个红球和 1 个蓝球', 'error');
            return null;
        }
        var count = parseInt(els.countInput.value, 10);
        if (!Number.isInteger(count) || count < 1 || count > 100000) {
            showMessage('注数必须是 1~100000 之间的正整数', 'error');
            return null;
        }
        return count;
    }

    function openCaptcha() {
        if (validateBeforeSubmit() === null) {
            return;
        }

        showMessage('');
        captcha.requestId = makeRequestId();
        els.registerBtn.disabled = true;
        els.captchaMessage.className = 'message';
        els.captchaMessage.textContent = '验证码加载中…';
        els.captchaModal.hidden = false;

        fetchCaptcha();
    }

    function fetchCaptcha() {
        captcha.challenge = null;
        captcha.sliderPos = 0;
        els.captchaBody.innerHTML = '<p class="hint">加载中…</p>';
        els.captchaConfirm.disabled = true;

        fetchJson('/api/captcha.php').then(function (result) {
            if (result.status !== 200 || !result.body.success) {
                els.captchaBody.innerHTML = '<p class="hint">验证码加载失败，请点击「换一个」重试</p>';
                return;
            }
            captcha.challenge = result.body.data;
            renderCaptcha(captcha.challenge);
        }).catch(function () {
            els.captchaBody.innerHTML = '<p class="hint">网络异常，验证码加载失败</p>';
        });
    }

    function renderCaptcha(challenge) {
        els.captchaMessage.className = 'message';
        els.captchaMessage.textContent = '';
        els.captchaConfirm.disabled = false;

        if (challenge.type === 'slider') {
            renderSliderCaptcha(challenge);
            els.captchaTip.textContent = '按住滑块，拖动到虚线位置（允许少量误差）';
        } else {
            els.captchaBody.innerHTML =
                '<div class="captcha-question">' + escapeHtml(challenge.question) + '</div>' +
                '<input type="text" id="captchaInput" class="captcha-input" inputmode="numeric" maxlength="3" ' +
                'placeholder="请输入计算结果" autocomplete="off">';
            var input = document.getElementById('captchaInput');
            if (input) {
                input.focus();
                input.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        confirmCaptcha();
                    }
                });
            }
            els.captchaTip.textContent = challenge.question || '请回答下面的问题';
        }
    }

    function renderSliderCaptcha(challenge) {
        var target = parseInt((challenge.payload || {}).target, 10);
        els.captchaBody.innerHTML =
            '<div class="slider-track" id="sliderTrack">' +
            '<div class="slider-fill" id="sliderFill"></div>' +
            '<div class="slider-marker" id="sliderMarker"></div>' +
            '<div class="slider-handle" id="sliderHandle">›</div>' +
            '</div>' +
            '<p class="hint slider-hint">把滑块拖到蓝色虚线位置</p>';

        var track = document.getElementById('sliderTrack');
        var handle = document.getElementById('sliderHandle');
        var fill = document.getElementById('sliderFill');
        var marker = document.getElementById('sliderMarker');
        if (!track || !handle) {
            return;
        }

        var maxLeft = Math.max(1, track.clientWidth - handle.offsetWidth);
        marker.style.left = Math.round(target / 100 * maxLeft + handle.offsetWidth / 2) + 'px';

        var dragging = false;
        var startX = 0;
        var startLeft = 0;

        function paint(left) {
            left = Math.max(0, Math.min(maxLeft, left));
            handle.style.left = left + 'px';
            fill.style.width = (left + handle.offsetWidth / 2) + 'px';
            captcha.sliderPos = Math.round(left / maxLeft * 100);
        }

        handle.addEventListener('pointerdown', function (event) {
            dragging = true;
            startX = event.clientX;
            startLeft = parseFloat(handle.style.left) || 0;
            if (handle.setPointerCapture) {
                handle.setPointerCapture(event.pointerId);
            }
            event.preventDefault();
        });
        handle.addEventListener('pointermove', function (event) {
            if (!dragging) {
                return;
            }
            paint(startLeft + event.clientX - startX);
        });
        handle.addEventListener('pointerup', function () {
            dragging = false;
        });
        handle.addEventListener('pointercancel', function () {
            dragging = false;
        });
    }

    function confirmCaptcha() {
        var count = validateBeforeSubmit();
        if (count === null || captcha.challenge === null || captcha.submitting) {
            return;
        }

        var answer;
        if (captcha.challenge.type === 'slider') {
            answer = String(captcha.sliderPos);
        } else {
            var input = document.getElementById('captchaInput');
            answer = input ? input.value.trim() : '';
        }

        if (!/^\d{1,3}$/.test(answer)) {
            els.captchaMessage.className = 'message error';
            els.captchaMessage.textContent = '请先完成验证';
            return;
        }

        captcha.submitting = true;
        els.captchaConfirm.disabled = true;
        els.captchaConfirm.textContent = '提交中…';

        fetchJson('/api/register.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                red: state.reds.map(pad2),
                blue: pad2(state.blue),
                count: count,
                request_id: captcha.requestId,
                captcha_id: captcha.challenge.captcha_id,
                captcha_answer: answer
            })
        }).then(function (result) {
            var body = result.body || {};
            if (result.status === 200 && body.success) {
                var item = body.data.item;
                closeCaptcha();
                if (body.data.duplicate) {
                    showMessage('该提交已处理过，注数未重复累加。组合 ' + item.combination + ' 当前注数：' + item.total_count + ' 注', 'info');
                } else {
                    showMessage('登记成功，本次 +' + body.data.added_count + ' 注；组合 ' + item.combination +
                        ' 当前注数：' + item.total_count + ' 注', 'success');
                }
                loadList(true);
                return;
            }

            // 验证码错误或过期：换一个继续验证，其他错误保留弹层提示
            if (result.status === 400) {
                fetchCaptcha();
            }
            els.captchaMessage.className = 'message error';
            els.captchaMessage.textContent = body.message || '登记失败，请重试';
        }).catch(function () {
            els.captchaMessage.className = 'message error';
            els.captchaMessage.textContent = '网络异常，登记失败，请稍后重试';
        }).finally(function () {
            captcha.submitting = false;
            els.captchaConfirm.disabled = false;
            els.captchaConfirm.textContent = '确认并登记';
        });
    }

    function closeCaptcha() {
        els.captchaModal.hidden = true;
        els.captchaBody.innerHTML = '';
        captcha.challenge = null;
        captcha.sliderPos = 0;
        captcha.requestId = null;
        els.captchaMessage.className = 'message';
        els.captchaMessage.textContent = '';
        els.registerBtn.disabled = !(state.reds.length === 6 && state.blue !== null);
    }

    /* ---------- 顶部日期 / 开奖信息 ---------- */

    function loadMeta() {
        fetchJson('/api/meta.php').then(function (result) {
            if (result.status !== 200 || !result.body.success) {
                return;
            }
            var meta = result.body.data;
            state.loadedMeta = meta;
            els.metaDate.textContent = meta.date;
            els.metaWeekday.textContent = meta.weekday;
            els.metaDraw.textContent = meta.draw_label;
            els.metaDraw.className = 'draw-state ' + (meta.is_draw_day ? 'drawing' : 'not-drawing');
            els.metaIssue.textContent = meta.issue ? ('期号：' + meta.issue + '（推算）') : '期号：今日无开奖';
            els.metaNext.textContent = meta.next_draw_date + ' ' + meta.next_draw_weekday +
                (meta.next_issue ? '（第 ' + meta.next_issue + ' 期）' : '');
            els.metaNotice.textContent = '每日 ' + String(meta.reset_hour).padStart(2, '0') +
                ':00（北京时间）清零全部注数；期号为本站按开奖日推算，非官方期号。';
        }).catch(function () {
            els.metaDate.textContent = '加载失败';
        });
    }

    /* ---------- 列表与分页 ---------- */

    function buildListQuery(cursor) {
        var params = new URLSearchParams();
        params.set('mode', state.mode);
        params.set('limit', String(PAGE_SIZE));
        if (cursor) {
            if (state.mode !== 'unregistered' && cursor.real !== null && cursor.real !== undefined) {
                params.set('after_real_count', String(cursor.real));
            }
            if (cursor.id !== null && cursor.id !== undefined) {
                params.set('after_id', String(cursor.id));
            }
        }
        return '/api/combinations/list.php?' + params.toString();
    }

    function setMode(mode) {
        if (state.mode === mode) {
            return;
        }
        state.mode = mode;
        Array.prototype.forEach.call(els.modeTabs.children, function (button) {
            button.classList.toggle('active', button.dataset.mode === mode);
        });
        els.zeroTip.hidden = true;
        loadList(true);
    }

    function loadList(reset) {
        if (state.loading) {
            return;
        }
        if (reset) {
            state.pages = [];
            state.pageIndex = 0;
            els.listBody.innerHTML = '<tr><td colspan="3" class="empty">加载中…</td></tr>';
        }

        var hasCachedNext = state.pageIndex + 1 < state.pages.length;
        if (hasCachedNext) {
            state.pageIndex++;
            renderPage();
            return;
        }

        var lastPage = state.pages.length > 0 ? state.pages[state.pages.length - 1] : null;
        if (lastPage && !lastPage.hasMore) {
            return;
        }
        var cursor = lastPage ? { real: lastPage.nextReal, id: lastPage.nextId } : null;

        state.loading = true;
        fetchJson(buildListQuery(cursor)).then(function (result) {
            if (result.status !== 200 || !result.body.success) {
                els.listBody.innerHTML = '<tr><td colspan="3" class="empty">' +
                    escapeHtml(result.body.message || '列表加载失败') + '</td></tr>';
                return;
            }
            var data = result.body.data;
            if (data.mode === 'unregistered' && data.zero_real_total !== undefined && data.zero_real_total !== null) {
                els.zeroTipCount.textContent = Number(data.zero_real_total).toLocaleString('zh-CN');
                els.zeroTip.hidden = state.mode !== 'unregistered';
            }
            state.pages.push({
                items: data.items,
                hasMore: data.has_more,
                nextReal: data.next_after_real_count,
                nextId: data.next_after_id,
                count: data.count
            });
            state.pageIndex = state.pages.length - 1;
            renderPage();
        }).catch(function () {
            els.listBody.innerHTML = '<tr><td colspan="3" class="empty">网络异常，列表加载失败</td></tr>';
        }).finally(function () {
            state.loading = false;
        });
    }

    function renderPage() {
        var page = state.pages[state.pageIndex];
        if (!page) {
            return;
        }
        if (page.items.length === 0) {
            var emptyText = state.mode === 'unregistered'
                ? '当前没有注数为 0 的组合'
                : '暂无登记记录';
            els.listBody.innerHTML = '<tr><td colspan="3" class="empty">' + emptyText + '</td></tr>';
        } else {
            var html = '';
            page.items.forEach(function (item) {
                html += '<tr>' +
                    '<td><div class="ball-cell">' + item.red.map(function (number) {
                        return '<span class="ball sm red">' + escapeHtml(number) + '</span>';
                    }).join('') + '</div></td>' +
                    '<td><span class="ball sm blue">' + escapeHtml(item.blue) + '</span></td>' +
                    '<td class="num">' + escapeHtml(item.total_count) + '</td>' +
                    '</tr>';
            });
            els.listBody.innerHTML = html;
        }

        els.pageInfo.textContent = '第 ' + (state.pageIndex + 1) + ' 页 · 本页 ' + page.items.length + ' 条';
        els.prevBtn.disabled = state.pageIndex === 0;
        els.nextBtn.disabled = !page.hasMore;
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    /* ---------- 初始化 ---------- */

    document.addEventListener('DOMContentLoaded', function () {
        buildGrids();
        renderSelection();
        loadMeta();
        loadList(true);

        els.registerBtn.addEventListener('click', openCaptcha);
        els.queryBtn.addEventListener('click', function () {
            if (state.reds.length !== 6 || state.blue === null) {
                showMessage('请先选择 6 个红球和 1 个蓝球', 'error');
                return;
            }
            els.queryBtn.disabled = true;
            els.queryBtn.textContent = '查询中…';

            var request = queryCombination();
            var restore = function () {
                els.queryBtn.textContent = '查询注数';
                els.queryBtn.disabled = !(state.reds.length === 6 && state.blue !== null);
            };
            if (request && typeof request.finally === 'function') {
                request.finally(restore);
            } else {
                restore();
            }
        });
        els.captchaRefresh.addEventListener('click', fetchCaptcha);
        els.captchaCancel.addEventListener('click', closeCaptcha);
        els.captchaConfirm.addEventListener('click', confirmCaptcha);
        els.clearBtn.addEventListener('click', function () {
            state.reds = [];
            state.blue = null;
            showMessage('');
            renderSelection();
        });

        Array.prototype.forEach.call(els.modeTabs.children, function (button) {
            button.addEventListener('click', function () {
                setMode(button.dataset.mode);
            });
        });

        els.prevBtn.addEventListener('click', function () {
            if (state.pageIndex > 0) {
                state.pageIndex--;
                renderPage();
            }
        });

        els.nextBtn.addEventListener('click', function () {
            loadList(false);
        });

        // 每分钟刷新一次日期显示，避免跨零点后信息过期
        setInterval(loadMeta, 60000);
    });
})();
