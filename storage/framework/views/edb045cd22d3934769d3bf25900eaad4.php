<!doctype html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
    <title>Livekarta - <?php echo e($settings['appTitle'] ?? config('app.name', 'Stuckbema Leverans')); ?></title>
    <?php echo \Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles(); ?>

    <?php echo app('Illuminate\Foundation\Vite')(['resources/js/livewire.js']); ?>
</head>
<body>
    <div class="app-shell">
        <header class="topbar">
            <div>
                <h1>Livekarta</h1>
                <div class="topbar-meta"><?php echo e($user['name'] ?: $user['email']); ?> · <?php echo e($user['role']); ?></div>
            </div>
            <div class="topbar-actions">
                <a class="plain-button" href="/">Till dashboard</a>
            </div>
        </header>

        <main class="main live-map-main">
            <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('live-map', []);

$__keyOuter = $__key ?? null;

$__key = null;
$__componentSlots = [];

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-3697950391-0', $__key);

$__html = app('livewire')->mount($__name, $__params, $__key, $__componentSlots);

echo $__html;

unset($__html);
unset($__key);
$__key = $__keyOuter;
unset($__keyOuter);
unset($__name);
unset($__params);
unset($__componentSlots);
unset($__split);
?>
        </main>
    </div>

    <?php echo \Livewire\Mechanisms\FrontendAssets\FrontendAssets::scripts(); ?>

</body>
</html>
<?php /**PATH /opt/www/resources/views/live-map.blade.php ENDPATH**/ ?>