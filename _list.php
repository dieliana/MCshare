<div id="listRegion">
  <?php if ($activeTag || $activeCat || $q !== '' || $loader !== ''): ?>
  <div class="filter-status">
    <?php if ($activeCat): ?><span>分类：<b><?= e($activeCat['name']) ?></b></span><?php endif; ?>
    <?php if ($activeTag): ?><span>标签：<b><?= e($activeTag['name']) ?></b></span><?php endif; ?>
    <?php if ($loader !== ''): ?><span>加载器：<b><?= e(loader_label($loader)) ?></b></span><?php endif; ?>
    <?php if ($q !== ''): ?><span>关键词：<b><?= e($q) ?></b></span><?php endif; ?>
    <a href="/"><i class="fa-solid fa-xmark"></i> 清除筛选</a>
  </div>
  <?php endif; ?>

  <?php if (empty($modpacks)): ?>
    <div class="empty">暂时没有整合包，去看看别的分类吧。</div>
  <?php else: ?>
  <div class="grid">
    <?php foreach ($modpacks as $m): ?>
    <a class="card" href="/modpack/<?= e($m['slug']) ?>">
      <div class="thumb">
        <?php if ($m['cover']): ?>
          <img src="<?= e(thumb_url($m['cover'])) ?>" alt="<?= e($m['title']) ?>" loading="lazy">
        <?php else: ?>
          <div class="thumb-ph"><i class="fa-solid fa-cube"></i></div>
        <?php endif; ?>
        <?php if ($m['category_name']): ?><span class="badge"><?= e($m['category_name']) ?></span><?php endif; ?>
      </div>
      <div class="card-body">
        <h3><?= e($m['title']) ?></h3>
        <?php if ($m['tagline']): ?><p class="tagline"><?= e($m['tagline']) ?></p><?php endif; ?>
        <div class="card-meta">
          <?php if ($m['mc_version']): ?><span class="ver">MC <?= e($m['mc_version']) ?></span><?php endif; ?>
          <?php if (!empty($m['mod_loader'])): ?><span class="ver"><?= e(loader_label($m['mod_loader'])) ?></span><?php endif; ?>
          <span><?= icon('eye', 13) ?> <?= num($m['views']) ?></span>
          <span><?= icon('download', 13) ?> <?= num($m['downloads']) ?></span>
        </div>
        <?php
          $tagSt = db()->prepare(
            'SELECT t.name FROM tags t JOIN modpack_tags mt ON mt.tag_id = t.id WHERE mt.modpack_id = ? ORDER BY t.id ASC'
          );
          $tagSt->execute([$m['id']]);
          $mtags = $tagSt->fetchAll();
        ?>
        <?php if ($mtags): ?>
        <div class="card-tags">
          <?php foreach ($mtags as $tg): ?><span class="tag">#<?= e($tg['name']) ?></span><?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </a>
    <?php endforeach; ?>
  </div>

  <?php if ($pages > 1): ?>
  <nav class="pager">
    <?php if ($page > 1): ?><a class="pbtn" href="<?= e(buildQuery(['page' => $page - 1])) ?>">上一页</a><?php endif; ?>
    <span class="pinfo"><?= $page ?> / <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="pbtn" href="<?= e(buildQuery(['page' => $page + 1])) ?>">下一页</a><?php endif; ?>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</div>
