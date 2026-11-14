<?php /** @var Portal\Web\Ctx $c @var string $title @var string $content @var string $inlineCss */ ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> | MA Hotel</title>
<?php if (!empty($inlineCss)) { ?><style><?= $inlineCss ?></style><?php } else { ?><link rel="icon" href="/assets/brand/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="/assets/app.css"><script src="/assets/app.js" defer></script><?php } ?>
</head>
<body class="bg-white text-ink-700">
<main class="container-page max-w-3xl py-8">
<?= $content ?>
</main>
</body>
</html>
