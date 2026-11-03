<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Explorer</title>
    <!-- Lien vers notre CSS custom, autorisé par la CSP -->
    <link rel="stylesheet" href="/style.css">
</head>
<body class="login-body">

    <div class="login-card">
        <div class="app-brand-login">
            <?php include __DIR__ . '/app-brand.php'; ?>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($flash): ?>
            <div class="alert alert-success"><?= h($flash) ?></div>
        <?php endif; ?>

        <form method="post" action="/login">
            <?= csrfField() ?>

            <div class="form-group">
                <label>Nom d'utilisateur</label>
                <input type="text" name="username" required autofocus autocomplete="username">
            </div>

            <div class="form-group">
                <label>Mot de passe</label>
                <input type="password" name="password" required autocomplete="current-password">
            </div>

            <button type="submit" class="btn-primary" style="width: 100%; margin-top: 8px;">Se connecter</button>
        </form>
    </div>

</body>
</html>