<?php /** @var Portal\Web\Ctx $c @var string $title @var string $content @var string $inlineCss */ ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> | HarborLight Hotels</title>
<?php if (!empty($inlineCss)) { ?><style><?= $inlineCss ?></style><?php } else { ?><link rel="stylesheet" href="/assets/app.css"><script src="/assets/app.js" defer></script><?php } ?>
</head>
<body class="bg-white">
<main class="container-page max-w-3xl py-8">
<?= $content ?>
</main>
</body>
</html>
