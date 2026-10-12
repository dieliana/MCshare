/* 轻量富文本编辑器：工具栏 + contenteditable，提交时同步到隐藏 textarea */
(function () {
  'use strict';
  document.querySelectorAll('.rte').forEach(function (rte) {
    var area = rte.querySelector('.rte-area');
    var input = document.getElementById(rte.getAttribute('data-input'));
    if (!area || !input) return;
    area.innerHTML = input.value || '';

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

    var form = rte.closest('form');
    if (form) form.addEventListener('submit', function () { input.value = area.innerHTML; });
  });
})();
