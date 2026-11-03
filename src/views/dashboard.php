<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Explorer</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>

    <header>
        <?php include __DIR__ . '/app-brand.php'; ?>
        <?php include __DIR__ . '/account-menu.php'; ?>
    </header>

    <main class="dashboard-main">
        <div class="container">
            <?php if ($flash): ?>
                <div class="alert alert-success"><?= h($flash) ?></div>
            <?php endif; ?>

            <nav class="dashboard-toolbar" aria-label="Actions du tableau de bord">
            <button id="btn-toggle-upload" class="btn-upload">
                <svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                Nouveau fichier
            </button>
            <a class="dashboard-discover-button" href="/discover">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/>
                </svg>
                Découvrir
            </a>
            </nav>

                <div id="dashboard-content" class="dashboard-content">
                    <section class="dashboard-files">
                        <!-- Liste des dossiers et fichiers -->
                        <?php if (empty($folders)): ?>
                            <p style="text-align: center; color: var(--text-muted); margin-top: 48px;">Aucun fichier pour le moment.</p>
                        <?php else: ?>
                            <?php foreach ($folders as $folder => $entries): ?>
                        <div class="folder-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>
                            <?= $folder !== '' ? h($folder) . '/' : 'Racine' ?>
                        </div>

                        <div class="file-table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Nom du fichier</th>
                                        <th style="text-align: right;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($entries as $entry): ?>
                                    <?php $filename = basename($entry['path']); ?>
                                    <?php $encodedPath = implode('/', array_map('rawurlencode', explode('/', $entry['path']))); ?>
                                    <tr>
                                        <td>
                                            <a href="/download/<?= h($encodedPath) ?>" class="file-link" style="display: flex; align-items: center; gap: 8px; text-decoration: none;">
                                                <svg width="18" height="18" viewBox="0 0 24 24" fill="#5f6368"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
                                                <?= h($filename) ?>
                                            </a>
                                            <?php if (isHtmlFile($entry['path'])): ?>
                                                <a href="/view/<?= h($encodedPath) ?>" target="_blank" style="font-size: 12px; color: var(--primary); margin-left: 8px;">Voir</a>
                                            <?php endif; ?>

                                            <?php if (!empty($entry['description'])): ?>
                                                <div class="file-description"><?= nl2br(h($entry['description'])) ?></div>
                                            <?php endif; ?>

                                            <div class="badges">
                                                <?php if ($entry['private']): ?>
                                                    <span class="badge badge-private">Privé</span>
                                                <?php else: ?>
                                                    <span class="badge badge-public">Public</span>
                                                <?php endif; ?>
                                                <span class="badge badge-expiry">Expire : <?= h(formatExpiry($entry['expires'])) ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="file-actions">
                                                <!-- Toggle Public/Privé -->
                                                <form method="post" action="/toggle/<?= h($encodedPath) ?>" style="display:inline;">
                                                    <?= csrfField() ?>
                                                    <button type="submit" class="btn-action"><?= $entry['private'] ? 'Rendre public' : 'Rendre privé' ?></button>
                                                </form>

                                                <!-- Modifier expiration -->
                                                <form method="post" action="/expiry/<?= h($encodedPath) ?>" style="display:inline-flex;">
                                                    <?= csrfField() ?>
                                                    <select name="expiry" style="padding: 4px; font-size: 12px; border-radius: 4px 0 0 4px; border-right: none;">
                                                        <option value="">Garder</option>
                                                        <option value="never">Jamais</option>
                                                        <option value="1h">1h</option>
                                                        <option value="6h">6h</option>
                                                        <option value="24h">24h</option>
                                                        <option value="3d">3j</option>
                                                        <option value="7d">7j</option>
                                                        <option value="30d">30j</option>
                                                    </select>
                                                    <button type="submit" class="btn-action" style="border-radius: 0 4px 4px 0;">OK</button>
                                                </form>

                                                <!-- Supprimer -->
                                                <form method="post" action="/delete/<?= h($encodedPath) ?>" data-confirm="Supprimer <?= h($filename) ?> ?" style="display:inline;">
                                                    <?= csrfField() ?>
                                                    <button type="submit" class="btn-action btn-danger">Supprimer</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </section>

                    <section id="upload-section" class="dashboard-upload hidden">
                        <div class="form-card">
                            <h2 style="font-size: 18px; margin-bottom: 16px;">Envoyer un fichier</h2>
                            <form method="post" action="/upload" enctype="multipart/form-data">
                                <?= csrfField() ?>
                                <div class="form-group">
                                    <label>Fichier <small>(max <?= formatBytes(effectiveMaxUploadBytes()) ?>)</small></label>
                                    <input type="file" name="file" required>
                                </div>
                                <div class="form-group">
                                    <label for="description">Description <small>(obligatoire)</small></label>
                                    <textarea id="description" name="description" required rows="3" placeholder="Décrivez le contenu du fichier..."></textarea>
                                </div>
                                <div class="form-group">
                                    <label>Dossier <small>(optionnel)</small></label>
                                    <input type="text" name="folder" placeholder="ex: projets/2024">
                                </div>
                                <div style="display: flex; gap: 16px; align-items: center; margin-bottom: 16px;">
                                    <label style="display: flex; align-items: center; gap: 8px; font-size: 14px;">
                                        <input type="checkbox" name="private"> Privé
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 8px; font-size: 14px;">
                                        Expiration
                                        <select name="expiry">
                                            <option value="never">Jamais</option>
                                            <option value="1h">1 heure</option>
                                            <option value="6h">6 heures</option>
                                            <option value="24h">24 heures</option>
                                            <option value="3d">3 jours</option>
                                            <option value="7d">7 jours</option>
                                            <option value="30d">30 jours</option>
                                        </select>
                                    </label>
                                </div>
                                <button type="submit" class="btn-primary">Envoyer</button>
                            </form>
                        </div>
                    </section>
                </div>
        </div>
    </main>

    <!-- Script unique et sécurisé pour le bouton et les confirmations -->
    <script nonce="<?= CSP_NONCE ?>">
        // 1. Gestion du clic sur le bouton "Nouveau fichier"
        const btnUpload = document.getElementById('btn-toggle-upload');
        if (btnUpload) {
            btnUpload.addEventListener('click', function() {
                const uploadSection = document.getElementById('upload-section');
                uploadSection.classList.toggle('hidden');
                document.getElementById('dashboard-content').classList.toggle('upload-open', !uploadSection.classList.contains('hidden'));
            });
        }

        // 2. Gestion de la confirmation de suppression
        document.querySelectorAll('form[data-confirm]').forEach(function(form) {
            form.addEventListener('submit', function(e) {
                if (!confirm(form.dataset.confirm)) e.preventDefault();
            });
        });
    </script>
</body>
</html>