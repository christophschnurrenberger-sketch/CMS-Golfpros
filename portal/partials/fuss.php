<?php
if (!defined('GP_ROOT')) {
    exit;
}
?>
</main>

<footer class="portal__fuss">
  <span><?= Util::h(Tenant::name()) ?></span>
  <span class="portal__fuss-trenner">·</span>
  <a href="<?= Util::attr(App::url('/?w=' . rawurlencode((string) Tenant::workspace()['slug']))) ?>">Zur Website</a>
  <span class="portal__fuss-trenner">·</span>
  <a href="<?= Util::attr(App::url('/portal/?abmelden=1')) ?>">Abmelden</a>
</footer>

<script src="<?= Util::attr(App::asset('assets/js/app.js')) ?>" defer></script>
</body>
</html>
