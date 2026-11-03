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
        <div class="header-brand-group">
            <a class="messages-back-button" href="/" aria-label="Retour à l’accueil">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
                <span>Mes Fichiers</span>
            </a>
        </div>
        <nav class="app-nav"><?php include __DIR__ . '/account-menu.php'; ?></nav>
    </header>
    <main>
        <div class="container">
            <?php if ($flash): ?>
                <div class="alert alert-success"><?= h($flash) ?></div>
            <?php endif; ?>

            <div class="discover-layout">
                <section class="discover-peers">
                    <h2>Correspondants configurés</h2>
                    <br>
                    <?php if (empty($peers)): ?>
                        <p>Aucun correspondant pour le moment.</p>
                    <?php else: ?>
                        <div class="file-table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Utilisateur</th>
                                        <th>URL</th>
                                        <th>Configuration</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($peers as $peer): ?>
                                        <tr>
                                            <td><?= h($peer['username']) ?></td>
                                            <td><?= h($peer['url']) ?></td>
                                            <td><?= $peer['dynamic'] ? 'Ajouté ici' : 'Défini dans .env' ?></td>
                                            <td>
                                                <?php if ($peer['dynamic']): ?>
                                                    <form method="post" action="/peers/delete">
                                                        <?= csrfField() ?>
                                                        <input type="hidden" name="username" value="<?= h($peer['username']) ?>">
                                                        <button class="btn-action btn-danger" type="submit">Retirer</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php foreach ($peers as $peer): ?>
                            <section class="form-card">
                                <div class="peer-card-header">
                                    <h3 class="peer-heading" data-peer-username="<?= h($peer['username']) ?>">
                                        <span class="peer-avatar" aria-hidden="true">
                                            <svg viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm0 2c-4.42 0-8 2.24-8 5v1h16v-1c0-2.76-3.58-5-8-5z"/></svg>
                                        </span>
                                        <?= h($peer['username']) ?>
                                        <?php if ($peer['has_unread_messages']): ?>
                                            <span class="peer-unread-dot" title="Nouveau message non lu" aria-label="Nouveau message non lu"></span>
                                        <?php endif; ?>
                                    </h3>
                                    <?php if ($peer['available']): ?>
                                    <a class="peer-message-button" href="/messages/<?= rawurlencode($peer['username']) ?>">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2zm-2 11H6v-2h12v2zm0-4H6V7h12v2z"/></svg>
                                        <span>Message</span>
                                    </a>
                                    <?php else: ?>
                                    <button class="peer-message-button" type="button" disabled
                                        title="<?= h($peer['message_error'] ?? 'Correspondant indisponible') ?>"
                                        aria-label="Message indisponible : <?= h($peer['message_error'] ?? 'Correspondant indisponible') ?>">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2zm-2 11H6v-2h12v2zm0-4H6V7h12v2z"/></svg>
                                        <span>Message</span>
                                    </button>
                                    <?php endif; ?>
                                </div>
                                <div class="peer-public-files">
                                    <h4 class="peer-folder-heading">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>
                                        Fichiers publics
                                    </h4>
                                    <?php if ($peer['error'] !== null): ?>
                                        <p class="alert alert-error"><?= h($peer['error']) ?></p>
                                    <?php elseif (empty($peer['files'])): ?>
                                        <p>Aucun fichier public.</p>
                                    <?php else: ?>
                                        <ul>
                                            <?php foreach ($peer['files'] as $file): ?>
                                                <?php $encodedPath = implode('/', array_map('rawurlencode', explode('/', ltrim($file['path'], '/')))); ?>
                                                <li>
                                                    <a href="<?= h($peer['url'] . '/download/' . $encodedPath) ?>"><?= h(basename($file['path'])) ?></a>
                                                    <?php if ($file['description'] !== ''): ?>
                                                        <div class="file-description peer-file-description"><?= nl2br(h($file['description'])) ?></div>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>

                <div class="discover-add-peer">
                    <section class="form-card">
                        <h2>Ajouter un correspondant</h2>
                        <br>
                        <form method="post" action="/peers/add">
                            <?= csrfField() ?>
                            <div class="form-group">
                                <label for="peer-username">Nom d’utilisateur</label>
                                <input id="peer-username" type="text" name="username" required pattern="[A-Za-z0-9._-]{1,64}" maxlength="64">
                            </div>
                            <div class="form-group">
                                <label for="peer-url">URL de l’instance</label>
                                <input id="peer-url" type="url" name="url" required placeholder="http://192.168.1.50:8000">
                            </div>
                            <div class="form-group">
                                <label for="peer-token">Token partagé (au moins 32 caractères)</label>
                                <input id="peer-token" type="password" name="token" required minlength="32" maxlength="256" autocomplete="off">
                            </div>
                            <button class="btn-primary" type="submit">Ajouter ce pair</button>
                        </form>
                    </section>
                </div>
            </div>
        </div>
    </main>
    <p id="peer-unread-status" class="hidden" role="status"></p>
    <script nonce="<?= CSP_NONCE ?>">
        const peerHeadings = [...document.querySelectorAll('[data-peer-username]')];
        const unreadStatus = document.getElementById('peer-unread-status');

        async function refreshUnreadPeers() {
            try {
                const response = await fetch('/api/messages/unread', {
                    headers: { Accept: 'application/json' },
                    cache: 'no-store'
                });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const data = await response.json();
                if (!Array.isArray(data.unread) || data.unread.some(username => typeof username !== 'string')) {
                    throw new Error('Invalid response');
                }

                const unreadPeers = new Set(data.unread);
                for (const heading of peerHeadings) {
                    const hasUnread = unreadPeers.has(heading.dataset.peerUsername);
                    let dot = heading.querySelector('.peer-unread-dot');
                    if (hasUnread && !dot) {
                        dot = document.createElement('span');
                        dot.className = 'peer-unread-dot';
                        dot.title = 'Nouveau message non lu';
                        dot.setAttribute('aria-label', 'Nouveau message non lu');
                        heading.append(dot);
                    } else if (!hasUnread && dot) {
                        dot.remove();
                    }
                }
                unreadStatus.classList.add('hidden');
                unreadStatus.textContent = '';
            } catch (error) {
                unreadStatus.classList.remove('hidden');
                unreadStatus.textContent = 'Impossible de vérifier les nouveaux messages pour le moment.';
            }
        }

        refreshUnreadPeers();
        setInterval(refreshUnreadPeers, 5000);
    </script>
</body>
</html>
