<?php
if (!defined('GP_ROOT')) {
    exit;
}
?>
  </main>
</div>
</div>

<script>
window.gpBasis = <?= Util::json(App::basis()) ?>;
window.gpCsrf  = <?= Util::json(Auth::csrf()) ?>;
window.gpBefehle = <?= Util::json(Befehle::liste()) ?>;
</script>
<script src="<?= Util::attr(App::asset('assets/js/app.js')) ?>"></script>
<?php if (!empty($skripte)) { echo $skripte; } ?>
</body>
</html>
