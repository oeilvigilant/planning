</main><!-- /main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<?php if (isset($extraJs)) echo $extraJs; ?>
<script>
// Auto-dismiss flash messages after 5s
setTimeout(() => {
    document.querySelectorAll('#flashContainer .alert').forEach(el => {
        bootstrap.Alert.getOrCreateInstance(el)?.close();
    });
}, 5000);
</script>
</body>
</html>
