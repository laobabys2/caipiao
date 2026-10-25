/* 管理后台：随机生成模拟注数 + 单组查询 */

(function () {
    'use strict';

    var csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    var generateForm = document.getElementById('generateForm');
    var generateMessage = document.getElementById('generateMessage');
    var generateResult = document.getElementById('generateResult');
    var generateBtn = document.getElementById('generateBtn');
    var nonceInput = document.getElementById('generateNonce');

    var lookupForm = document.getElementById('lookupForm');
    var lookupMessage = document.getElementById('lookupMessage');
    var lookupResult = document.getElementById('lookupResult');

    function setMessage(element, text, type) {
        element.className = 'message' + (type ? ' ' + type : '');
        element.textContent = text || '';
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    function postJson(url, payload) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().catch(function () {
                return { success: false, message: '服务器返回格式异常' };
            }).then(function (body) {
                return { status: response.status, body: body };
            });
        });
    }

    if (generateForm) {
        generateForm.addEventListener('submit', function (event) {
            event.preventDefault();

            if (generateForm.dataset.initialized !== '1') {
                setMessage(generateMessage, '组合表未初始化完成，禁止生成', 'error');
                return;
            }

            var groups = parseInt(document.getElementById('groupsInput').value, 10);
            var minNotes = parseInt(document.getElementById('minInput').value, 10);
            var maxNotes = parseInt(document.getElementById('maxInput').value, 10);

            if (!Number.isInteger(groups) || groups < 1) {
                setMessage(generateMessage, '随机组数必须是正整数', 'error');
                return;
            }
            if (!Number.isInteger(minNotes) || !Number.isInteger(maxNotes) || minNotes < 1 || maxNotes < minNotes) {
                setMessage(generateMessage, '每组注数范围不合法', 'error');
                return;
            }

            var preview = '本次将随机挑选 ' + groups + ' 组不同号码，每组随机生成 ' + minNotes + '~' + maxNotes +
                ' 注，累加到「模拟注数」（不影响真实注数）。确认执行？';
            if (!window.confirm(preview)) {
                return;
            }

            generateBtn.disabled = true;
            generateBtn.textContent = '生成中…';
            setMessage(generateMessage, '正在生成，请稍候…', 'info');
            generateResult.hidden = true;

            postJson('/admin/api/generate.php', {
                groups: groups,
                min_notes: minNotes,
                max_notes: maxNotes,
                nonce: nonceInput.value
            }).then(function (result) {
                var body = result.body || {};
                if (result.status === 401) {
                    window.location.href = '/admin/login.php';
                    return;
                }
                if (body.success) {
                    var data = body.data;
                    nonceInput.value = data.nonce;
                    setMessage(generateMessage, '生成完成', 'success');
                    generateResult.hidden = false;
                    generateResult.innerHTML =
                        '<h3>本次生成结果</h3>' +
                        '<div class="result-grid">' +
                        resultCell('随机组数（请求）', data.requested_groups) +
                        resultCell('成功更新组数', data.processed_groups) +
                        resultCell('生成注数总和', data.generated_notes) +
                        resultCell('失败组数', data.failed_groups) +
                        resultCell('每组注数范围', data.min_notes + ' ~ ' + data.max_notes) +
                        resultCell('耗时（秒）', data.elapsed_seconds) +
                        '</div>';
                } else {
                    setMessage(generateMessage, body.message || '生成失败', 'error');
                    if (body.data) {
                        var partial = body.data;
                        generateResult.hidden = false;
                        generateResult.innerHTML =
                            '<h3>部分执行结果</h3>' +
                            '<div class="result-grid">' +
                            resultCell('请求组数', partial.requested_groups) +
                            resultCell('已处理组数', partial.processed_groups) +
                            resultCell('已生成注数', partial.generated_notes) +
                            resultCell('失败组数', partial.failed_groups) +
                            '</div>';
                    }
                }
            }).catch(function () {
                setMessage(generateMessage, '网络异常，生成请求失败', 'error');
            }).finally(function () {
                generateBtn.disabled = false;
                generateBtn.textContent = '预览并确认生成';
            });
        });
    }

    function resultCell(label, value) {
        return '<div><span>' + escapeHtml(label) + '</span><strong>' + escapeHtml(value) + '</strong></div>';
    }

    if (lookupForm) {
        lookupForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var red = document.getElementById('lookupRed').value.trim();
            var blue = document.getElementById('lookupBlue').value.trim();

            setMessage(lookupMessage, '查询中…', 'info');
            lookupResult.hidden = true;

            fetch('/api/combination.php?red=' + encodeURIComponent(red) + '&blue=' + encodeURIComponent(blue), {
                headers: { 'Accept': 'application/json' }
            }).then(function (response) {
                return response.json().catch(function () {
                    return { success: false, message: '服务器返回格式异常' };
                });
            }).then(function (body) {
                if (!body.success) {
                    setMessage(lookupMessage, body.message || '查询失败', 'error');
                    return;
                }
                var item = body.data;
                setMessage(lookupMessage, '查询成功', 'success');
                lookupResult.hidden = false;
                lookupResult.innerHTML =
                    '<h3>' + escapeHtml(item.combination) + '</h3>' +
                    '<div class="result-grid">' +
                    resultCell('真实注数', item.real_count) +
                    resultCell('模拟注数', item.generated_count) +
                    resultCell('总注数', item.total_count) +
                    '</div>';
            }).catch(function () {
                setMessage(lookupMessage, '网络异常，查询失败', 'error');
            });
        });
    }
})();
