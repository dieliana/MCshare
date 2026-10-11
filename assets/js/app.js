/* 前台交互脚本 */
(function () {
  'use strict';

  /* ---------- 主题切换 ---------- */
  var THEMES = ['emerald', 'ocean', 'violet', 'forest', 'light'];

  function applyTheme(t) {
    document.documentElement.setAttribute('data-theme', t);
    try { localStorage.setItem('mc_theme', t); } catch (e) {}
  }

  function currentTheme() {
    var saved = null;
    try { saved = localStorage.getItem('mc_theme'); } catch (e) {}
    if (saved && THEMES.indexOf(saved) !== -1) return saved;
    var cur = document.documentElement.getAttribute('data-theme');
    return THEMES.indexOf(cur) !== -1 ? cur : 'emerald';
  }

  var current = currentTheme();
  applyTheme(current); // 用访客记忆（或后台默认）覆盖

  // 全局主题切换函数（按钮通过内联 onclick 调用）
  window.cycleTheme = function () {
    var idx = THEMES.indexOf(current);
    current = THEMES[(idx + 1) % THEMES.length];
    applyTheme(current);
  };

  /* ---------- 主页自动刷新 ---------- */
  var refresh = parseInt(document.body.getAttribute('data-refresh') || '0', 10);
  if (refresh > 0) {
    setTimeout(function () { location.reload(); }, refresh * 1000);
  }

  /* ---------- 封面加载失败兜底 ---------- */
  document.querySelectorAll('.card .thumb img, .detail-cover img').forEach(function (img) {
    img.addEventListener('error', function () {
      var holder = document.createElement('div');
      holder.className = 'thumb-ph' + (img.closest('.detail-cover') ? ' big' : '');
      holder.textContent = '⛏';
      if (img.parentNode) img.parentNode.replaceChild(holder, img);
    });
  });
})();

/* 抖音式点赞/收藏飘浮动画 */
function burstIcon(el, cls, count, color) {
  if (!el) return;
  el.classList.add('pop-bounce');
  setTimeout(function () { el.classList.remove('pop-bounce'); }, 320);
  var rect = el.getBoundingClientRect();
  var n = count || 3;
  for (var i = 0; i < n; i++) {
    var s = document.createElement('i');
    s.className = 'fa-solid ' + cls + ' burst-icon';
    s.style.left = (rect.left + rect.width / 2 + (Math.random() * 40 - 20)) + 'px';
    s.style.top = (rect.top + rect.height / 2) + 'px';
    s.style.fontSize = (14 + Math.random() * 10) + 'px';
    if (color) s.style.color = color;
    document.body.appendChild(s);
    (function (node) { setTimeout(function () { node.remove(); }, 900); })(s);
  }
}

/* 站内信未读红点轮询：有新站内信自动亮红点，无需刷新页面（30 秒） */
(function () {
  var bell = document.querySelector('.bell-btn');
  if (!bell) return; // 未登录 / 无铃铛则不轮询
  var badge = bell.querySelector('.bell-badge');
  function refresh() {
    fetch('/messages.php?api=unread')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok || !d.logged) return;
        if (d.unread > 0) {
          if (!badge) { badge = document.createElement('span'); badge.className = 'bell-badge'; bell.appendChild(badge); }
          badge.textContent = d.unread > 99 ? '99+' : d.unread;
        } else if (badge) { badge.remove(); badge = null; }
      })
      .catch(function () {});
  }
  setInterval(refresh, 30000);
})();