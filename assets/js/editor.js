/* 富文本编辑器：富文本 / HTML / Markdown 三模式，提交时按当前模式写入隐藏 textarea */
(function () {
  'use strict';

  /* ---- 轻量 Markdown → HTML 转换（无外部依赖） ---- */
  function escapeHtml(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function mdInline(s) {
    s = escapeHtml(s);
    s = s.replace(/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/g, '<img src="$2" alt="$1" />');
    s = s.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, '<a href="$2">$1</a>');
    s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
    s = s.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
    s = s.replace(/__([^_\n]+)__/g, '<strong>$1</strong>');
    s = s.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');
    s = s.replace(/(^|[^_])_([^_\n]+)_/g, '$1<em>$2</em>');
    return s;
  }
  function mdToHtml(src) {
    if (!src) return '';
    var text = String(src).replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    var lines = text.split('\n'), out = [], i = 0, n = lines.length;
    var inCode = false, codeBuf = [];
    for (i = 0; i < n; i++) {
      var ln = lines[i];
      if (/^```/.test(ln)) {
        if (inCode) { out.push('<pre><code>' + escapeHtml(codeBuf.join('\n')) + '</code></pre>'); codeBuf = []; inCode = false; }
        else inCode = true;
        continue;
      }
      if (inCode) { codeBuf.push(ln); continue; }
      var t = ln.trim();
      if (t === '') continue;
      if (/^#{1,6}\s/.test(ln)) {
        var level = ln.match(/^#{1,6}/)[0].length;
        out.push('<h' + level + '>' + mdInline(ln.replace(/^#{1,6}\s*/, '')) + '</h' + level + '>');
        continue;
      }
      if (/^(?:[-*+]|\d+\.)\s+/.test(ln)) {
        var isUl = /^(?:[-*+])\s+/.test(ln);
        out.push(isUl ? '<ul>' : '<ol>');
        while (i < n) {
          var cl = lines[i].trim();
          if (cl === '') { i++; continue; }
          if (/^(?:[-*+]|\d+\.)\s+/.test(cl)) {
            out.push('<li>' + mdInline(cl.replace(/^(?:[-*+]|\d+\.)\s+/, '')) + '</li>');
            i++;
          } else break;
        }
        i--;
        out.push(isUl ? '</ul>' : '</ol>');
        continue;
      }
      if (/^>\s?/.test(ln)) {
        var q = [];
        while (i < n && /^>\s?/.test(lines[i])) { q.push(mdInline(lines[i].replace(/^>\s?/, ''))); i++; }
        i--;
        out.push('<blockquote>' + q.join('<br>') + '</blockquote>');
        continue;
      }
      if (/^(-{3,}|\*{3,}|_{3,})$/.test(t)) { out.push('<hr>'); continue; }
      out.push('<p>' + mdInline(ln) + '</p>');
    }
    if (inCode) out.push('<pre><code>' + escapeHtml(codeBuf.join('\n')) + '</code></pre>');
    return out.join('\n');
  }

  /* ---- 三模式编辑器 ---- */
  document.querySelectorAll('.rte').forEach(function (rte) {
    var area = rte.querySelector('.rte-area');
    var input = document.getElementById(rte.getAttribute('data-input'));
    if (!area || !input) return;

    // 动态构建模式栏
    var bar = document.createElement('div');
    bar.className = 'rte-modebar';
    var defs = [['wysiwyg', '富文本'], ['md', 'Markdown'], ['html', 'HTML']];
    var btns = {};
    defs.forEach(function (d) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'rte-modebtn'; b.textContent = d[1];
      b.setAttribute('data-mode', d[0]);
      bar.appendChild(b); btns[d[0]] = b;
    });
    rte.insertBefore(bar, rte.firstChild);

    // 动态构建源码 textarea
    var src = document.createElement('textarea');
    src.className = 'rte-src';
    src.placeholder = '支持 HTML 或 Markdown…';
    rte.insertBefore(src, area);

    var toolbar = rte.querySelector('.rte-toolbar');
    area.innerHTML = input.value || '';
    src.value = input.value || '';
    var mode = 'wysiwyg';

    function setMode(m) {
      if (m === mode) return;
      var prev = mode;
      if (prev === 'wysiwyg') src.value = area.innerHTML;
      mode = m;
      if (m === 'wysiwyg') {
        if (prev === 'md') area.innerHTML = mdToHtml(src.value);
        else if (prev === 'html') area.innerHTML = src.value;
        area.style.display = 'block'; src.style.display = 'none';
        if (toolbar) toolbar.style.display = 'flex';
      } else {
        if (m === 'md') { if (prev === 'wysiwyg') src.value = area.innerText; }
        else if (m === 'html') { if (prev === 'wysiwyg') src.value = area.innerHTML; }
        area.style.display = 'none'; src.style.display = 'block';
        if (toolbar) toolbar.style.display = 'none';
        src.focus();
      }
      Object.keys(btns).forEach(function (k) { btns[k].classList.toggle('active', k === mode); });
    }
    defs.forEach(function (d) {
      btns[d[0]].addEventListener('click', function () { setMode(d[0]); });
    });

    // 富文本工具栏命令
    toolbar = toolbar || document.createElement('div');
    rte.querySelectorAll('.rte-toolbar button[data-cmd]').forEach(function (btn) {
      btn.addEventListener('mousedown', function (e) { e.preventDefault(); area.focus(); });
      btn.addEventListener('click', function () {
        var cmd = btn.getAttribute('data-cmd');
        var val = btn.getAttribute('data-val');
        if (cmd === 'createLink') {
          var u = window.prompt('输入链接地址（https://…）');
          if (u && u.trim()) document.execCommand(cmd, false, u.trim());
        } else if (cmd === 'insertImage') {
          var s = window.prompt('输入图片地址（https://…）');
          if (s && s.trim()) document.execCommand('insertImage', false, s.trim());
        } else {
          document.execCommand(cmd, false, val || null);
        }
        area.focus();
      });
    });

    // 提交：按当前模式写回
    var form = rte.closest('form');
    if (form) form.addEventListener('submit', function () {
      if (mode === 'wysiwyg') input.value = area.innerHTML;
      else if (mode === 'html') input.value = src.value;
      else input.value = mdToHtml(src.value);
    });
  });
})();
