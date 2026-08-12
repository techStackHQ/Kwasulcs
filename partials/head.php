<?php
// Shared <head> block. Set $pageTitle (and optionally $extraCss = ['assets/x.css', ...])
// before including this file.
$pageTitle = $pageTitle ?? 'KWASU LCS';
$extraCss  = $extraCss ?? [];
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h($pageTitle); ?> — KWASU LCS</title>
<script>(function(){var t=localStorage.getItem('theme');if(t==='dark'||(!t&&window.matchMedia('(prefers-color-scheme:dark)').matches))document.documentElement.setAttribute('data-theme','dark')})();</script>
<link rel="stylesheet" href="assets/vendor/bootstrap-icons/bootstrap-icons.css">
<link rel="stylesheet" href="assets/style.css?v=<?php echo asset_version(); ?>">
<?php foreach ($extraCss as $__css): ?>
<link rel="stylesheet" href="<?php echo h($__css); ?>?v=<?php echo asset_version(); ?>">
<?php endforeach; ?>
<script src="assets/theme.js?v=<?php echo asset_version(); ?>" defer></script>
<script src="assets/status-loader.js?v=<?php echo asset_version(); ?>" defer></script>
