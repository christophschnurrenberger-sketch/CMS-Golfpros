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

<script>
/*
 * Das Portal braucht kein Framework und auch nicht app.js: Zwei Handgriffe
 * genügen – Textfelder, die mitwachsen, und eine Rückfrage vor dem Absagen.
 */
document.addEventListener('input', function (e) {
  var f = e.target.closest('textarea[data-waechst]');
  if (!f) return;
  f.style.height = 'auto';
  f.style.height = f.scrollHeight + 'px';
});
document.addEventListener('submit', function (e) {
  var frage = e.target.getAttribute('data-bestaetigen');
  if (frage && !window.confirm(frage)) e.preventDefault();
});
document.querySelectorAll('[data-breite]').forEach(function (el) {
  el.style.width = el.dataset.breite;
});
</script>
</body>
</html>
