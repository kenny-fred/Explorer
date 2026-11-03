<?php
$instanceAddress = currentInstanceAddress();
$profileAvatar = profileAvatarFile();
$avatarUrl = $profileAvatar !== null
    ? '/account/avatar?v=' . (string)filemtime($profileAvatar)
    : null;
$profileMenuOpen = ($_GET['account'] ?? '') === 'profile';
$passwordDialogOpen = ($_GET['account'] ?? '') === 'password';
?>
<details class="account-menu" <?= $profileMenuOpen ? 'open' : '' ?>>
    <summary class="account-trigger">
        <?php if ($avatarUrl !== null): ?>
            <img class="account-avatar account-avatar-photo" src="<?= h($avatarUrl) ?>" alt="">
        <?php else: ?>
            <svg class="account-avatar" viewBox="0 0 24 24" aria-hidden="true">
                <path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm0 2c-4.42 0-8 2.24-8 5v1h16v-1c0-2.76-3.58-5-8-5z"/>
            </svg>
        <?php endif; ?>
        <span><?= h(APP_USERNAME) ?></span>
        <svg class="account-chevron" viewBox="0 0 24 24" aria-hidden="true">
            <path d="m7 10 5 5 5-5z"/>
        </svg>
    </summary>
    <div class="account-panel">
        <div class="account-panel-heading">
            <span class="account-avatar-wrap">
                <?php if ($avatarUrl !== null): ?>
                    <img class="account-avatar account-avatar-photo" src="<?= h($avatarUrl) ?>" alt="">
                <?php else: ?>
                    <svg class="account-avatar" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm0 2c-4.42 0-8 2.24-8 5v1h16v-1c0-2.76-3.58-5-8-5z"/>
                    </svg>
                <?php endif; ?>
            </span>
            <div>
                <strong><?= h(APP_USERNAME) ?></strong>
                <p>Profil Explorer</p>
            </div>
        </div>
        <form id="avatar-upload-form" class="avatar-form" method="post" action="/account/avatar" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input id="profile-avatar" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required>
            <small>JPEG, PNG ou WebP · 5 Mo maximum</small>
        </form>
        <div class="avatar-actions-row">
            <button class="btn-primary" type="submit" form="avatar-upload-form">Mettre à jour la photo</button>
            <?php if ($avatarUrl !== null): ?>
                <form class="avatar-reset-form" method="post" action="/account/avatar">
                    <?= csrfField() ?>
                    <input type="hidden" name="remove_avatar" value="1">
                    <button class="account-text-button" type="submit" style="color: black; padding: 8px 12px; border: 1px solid var(--border-color); border-radius: 5px; text-decoration: none;">Réinitialiser la photo</button>
                </form>
            <?php endif; ?>
        </div>
        <button class="account-text-button password-dialog-trigger" type="button" style="background: white; color: black; padding: 8px 12px; border: 1px solid var(--border-color); border-radius: 5px; text-decoration: none; font-size: 12px;" data-open-password-dialog>
            Modifier le mot de passe
        </button> &nbsp;&nbsp;&nbsp;ou &nbsp;&nbsp;
        <a class="account-logout" href="/logout" style="padding: 8px 12px; border: 1px solid var(--border-color); border-radius: 5px; text-decoration: none; font-size: 12px;">Se déconnecter</a>
    </div>
</details>

<dialog class="account-dialog" data-password-dialog aria-labelledby="password-dialog-title">
    <div class="account-dialog-heading">
        <div>
            <h2 id="password-dialog-title">Modifier le mot de passe</h2>
            <p>Choisissez un nouveau mot de passe d’au moins 12 caractères.</p>
        </div>
        <button class="dialog-close" type="button" aria-label="Fermer" data-close-password-dialog>&times;</button>
    </div>
    <form method="post" action="/account/password">
        <?= csrfField() ?>
        <div class="form-group">
            <label for="current-password">Mot de passe actuel</label>
            <input id="current-password" type="password" name="current_password" required autocomplete="current-password">
        </div>
        <div class="form-group">
            <label for="new-password">Nouveau mot de passe</label>
            <input id="new-password" type="password" name="new_password" required minlength="12" maxlength="1024" autocomplete="new-password">
        </div>
        <div class="form-group">
            <label for="confirm-password">Confirmer le nouveau mot de passe</label>
            <input id="confirm-password" type="password" name="confirm_password" required minlength="12" maxlength="1024" autocomplete="new-password">
        </div>
        <div class="account-dialog-actions">
            <button class="dialog-cancel" type="button" data-close-password-dialog>Annuler</button>
            <button class="btn-primary" type="submit">Enregistrer</button>
        </div>
    </form>
</dialog>
<script nonce="<?= CSP_NONCE ?>">
    const accountMenu = document.querySelector('.account-menu');
    const passwordDialog = document.querySelector('[data-password-dialog]');

    document.querySelector('[data-open-password-dialog]')?.addEventListener('click', () => {
        accountMenu.open = false;
        passwordDialog.showModal();
    });

    document.querySelectorAll('[data-close-password-dialog]').forEach(button => {
        button.addEventListener('click', () => passwordDialog.close());
    });

    passwordDialog.addEventListener('click', event => {
        if (event.target === passwordDialog) passwordDialog.close();
    });

    if (<?= $passwordDialogOpen ? 'true' : 'false' ?>) passwordDialog.showModal();
</script>
