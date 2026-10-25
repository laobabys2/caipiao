<?php

declare(strict_types=1);

/**
 * 前台单页：/index.php
 * 号码选择、注数登记、三种分页列表；所有数据通过 JSON API 获取。
 */

require_once __DIR__ . '/lib/http.php';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="双色球号码组合注数统计与开奖查询：查看今日是否开奖、期号、未被购买的双色球号码组合、已购买注数排行，每日 22:00 清零。">
    <meta name="keywords" content="双色球,双色球注数统计,双色球开奖查询,开奖统计,双色球号码组合,未被购买号码,冷门号码">
    <title>以统计学打败统计学 - 双色球号码组合注数统计与开奖查询</title>
    <link rel="stylesheet" href="/assets/app.css?v=3">
</head>
<body>
<header class="topbar">
    <div class="brand">双色球注数统计</div>
    <nav class="topnav">
        <a href="/" class="active">首页</a>
        <a href="/admin/login.php">管理后台</a>
    </nav>
</header>

<main class="page">
    <section class="hero">
        <h1 class="hero-title">以统计学打败统计学</h1>
        <p class="hero-sub">双色球号码组合注数统计 · 今日开奖查询 · 未被购买号码组合查询</p>
    </section>

    <section class="card status-card">
        <div class="status-main">
            <div class="status-item">
                <span class="label">北京时间</span>
                <strong id="metaDate">--</strong>
                <span id="metaWeekday" class="sub">--</span>
            </div>
            <div class="status-item">
                <span class="label">开奖状态</span>
                <strong id="metaDraw" class="draw-state">--</strong>
                <span id="metaIssue" class="sub">--</span>
            </div>
            <div class="status-item">
                <span class="label">下个开奖日</span>
                <strong id="metaNext">--</strong>
                <span class="sub">期号为本站按开奖日推算，非官方期号</span>
            </div>
        </div>
        <p class="hint" id="metaNotice">每日 22:00（北京时间）清零全部注数；双色球开奖日为每周二、周四、周日，显示期号为本站按开奖日规则推算。</p>
    </section>

    <section class="card picker-card">
        <div class="preview">
            <div class="preview-group">
                <span class="label">已选红球</span>
                <div id="redPreview" class="ball-row empty-hint">未选择</div>
            </div>
            <div class="preview-group">
                <span class="label">已选蓝球</span>
                <div id="bluePreview" class="ball-row empty-hint">未选择</div>
            </div>
        </div>
        <p class="hint picker-note">提示：购买后的数据将统计在数据库中，仅供查询参考，不代表彩票官方数据。</p>

        <div class="picker-block">
            <div class="block-head">
                <h2>红球 <small>双色球红球 01~33，选 6 个</small></h2>
                <span class="counter" id="redCounter">0 / 6</span>
            </div>
            <div id="redGrid" class="number-grid red-grid"></div>
        </div>

        <div class="picker-block">
            <div class="block-head">
                <h2>蓝球 <small>双色球蓝球 01~16，选 1 个</small></h2>
                <span class="counter" id="blueCounter">0 / 1</span>
            </div>
            <div id="blueGrid" class="number-grid blue-grid"></div>
        </div>

        <div class="register-bar">
            <label class="field inline">
                <span>登记注数</span>
                <input id="countInput" type="number" min="1" max="100000" step="1" value="1">
            </label>
            <button id="registerBtn" class="primary" disabled>登记注数</button>
            <button id="queryBtn" class="ghost" type="button" disabled>查询注数</button>
            <button id="clearBtn" class="ghost" type="button">清空选择</button>
            <span class="hint" id="selectionInfo">请选择 6 个红球和 1 个蓝球</span>
        </div>

        <div id="message" class="message" role="status"></div>
    </section>

    <section class="card list-card">
        <div class="block-head">
            <h2>双色球号码组合注数</h2>
        </div>
        <p class="hint zero-tip" id="zeroTip" hidden>当前数据库注数为 0 的组合共 <strong id="zeroTipCount">--</strong> 条</p>
        <div class="tabs" id="modeTabs">
            <button type="button" class="active" data-mode="most">买过最多</button>
            <button type="button" data-mode="least">买过最少</button>
            <button type="button" data-mode="unregistered">没被登记过</button>
        </div>

        <div class="table-wrap">
            <table class="combinations">
                <thead>
                <tr>
                    <th>红球</th>
                    <th>蓝球</th>
                    <th class="num">已购买注数</th>
                </tr>
                </thead>
                <tbody id="listBody">
                <tr><td colspan="3" class="empty">加载中…</td></tr>
                </tbody>
            </table>
        </div>

        <div class="pager">
            <button id="prevBtn" type="button" class="ghost" disabled>上一页</button>
            <span id="pageInfo" class="hint">第 1 页</span>
            <button id="nextBtn" type="button" class="ghost" disabled>下一页</button>
        </div>
    </section>

    <footer class="footer">
        <p class="slogan">以统计学打败统计学</p>
        <p>本站致力于让广大彩民尽可能知晓开奖前最后半小时还有哪些双色球号码组合没有被买过，方便大家避开热门号码、关注冷门组合。</p>
        <p>本站注数为彩民登记与统计结果，仅供参考，不代表彩票官方数据；双色球开奖日为每周二、周四、周日，显示期号为本站按开奖日规则推算。</p>
    </footer>
</main>

<div id="captchaModal" class="modal" hidden>
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="captchaTitle">
        <h3 id="captchaTitle">人机验证</h3>
        <p class="hint" id="captchaTip">完成验证后才会提交登记</p>
        <div id="captchaBody" class="captcha-body"></div>
        <div class="modal-actions">
            <button type="button" id="captchaRefresh" class="ghost small">换一个</button>
            <button type="button" id="captchaCancel" class="ghost">取消</button>
            <button type="button" id="captchaConfirm" class="primary">确认并登记</button>
        </div>
        <div id="captchaMessage" class="message" role="status"></div>
    </div>
</div>

<script src="/assets/app.js?v=3"></script>
</body>
</html>
