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
        <div class="messages-header-title">
            <a class="messages-back-button" href="/discover" aria-label="Retour à Découvrir">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
                <span>Retour</span>
            </a>
        </div>
        <nav class="app-nav conversation-nav">
            <div class="conversation-contact">
                <img class="conversation-avatar" src="/peer-avatar/<?= rawurlencode($peer['username']) ?>" alt="">
                <span class="conversation-avatar-fallback" aria-hidden="true" hidden>
                    <svg viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm0 2c-4.42 0-8 2.24-8 5v1h16v-1c0-2.76-3.58-5-8-5z"/></svg>
                </span>
                <strong><?= h($peer['username']) ?></strong>
            </div>
        </nav>
    </header>
    <main class="messages-main">
        <div class="messages-container">
            <?php if ($flash): ?>
                <div class="alert alert-success"><?= h($flash) ?></div>
            <?php endif; ?>
            <?php if ($error !== null): ?>
                <div class="alert alert-error"><?= h($error) ?> Les messages déjà reçus restent visibles.</div>
            <?php endif; ?>

            <section class="message-conversation" aria-label="Conversation avec <?= h($peer['username']) ?>">
                <p id="message-refresh-status" role="status"></p>
                <ol id="message-list" class="message-list" aria-live="polite">
                    <?php foreach ($messages as $message): ?>
                        <li class="message-bubble <?= $message['from'] === APP_USERNAME ? 'message-outgoing' : 'message-incoming' ?>">
                            <div class="message-meta">
                                <strong><?= h($message['from']) ?></strong>
                                <small><?= date('d/m/Y H:i', $message['timestamp']) ?></small>
                            </div>
                            <p><?= nl2br(h($message['message'])) ?></p>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <div class="message-compose">
                    <form method="post" action="/messages/<?= rawurlencode($peer['username']) ?>">
                    <?= csrfField() ?>
                    <div class="form-group">
                        <label for="message">Nouveau message</label>
                        <textarea id="message" name="message" required maxlength="2000" rows="2"></textarea>
                    </div>
                    <button class="btn-primary" type="submit">Envoyer</button>
                    </form>
                </div>
            </section>
        </div>
    </main>
    <script nonce="<?= CSP_NONCE ?>">
        const conversationAvatar = document.querySelector('.conversation-avatar');
        const avatarFallback = document.querySelector('.conversation-avatar-fallback');
        conversationAvatar.addEventListener('error', () => {
            conversationAvatar.hidden = true;
            avatarFallback.hidden = false;
        }, { once: true });

        const messageList = document.getElementById('message-list');
        const refreshStatus = document.getElementById('message-refresh-status');
        const messagesUrl = '/api/messages/<?= rawurlencode($peer['username']) ?>';
        const currentUsername = <?= json_encode(APP_USERNAME, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        async function refreshMessages() {
            try {
                const response = await fetch(messagesUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const data = await response.json();
                if (!Array.isArray(data.messages)) throw new Error('Invalid response');

                messageList.replaceChildren(...data.messages.map(message => {
                    const item = document.createElement('li');
                    item.className = `message-bubble ${message.from === currentUsername ? 'message-outgoing' : 'message-incoming'}`;
                    const sender = document.createElement('strong');
                    sender.textContent = message.from;
                    const time = document.createElement('small');
                    time.textContent = new Date(message.timestamp * 1000).toLocaleString('fr-FR');
                    const meta = document.createElement('div');
                    meta.className = 'message-meta';
                    meta.append(sender, time);
                    const content = document.createElement('p');
                    content.textContent = message.message;
                    item.append(meta, content);
                    return item;
                }));
                refreshStatus.textContent = '';
                messageList.scrollTop = messageList.scrollHeight;
            } catch (error) {
                refreshStatus.textContent = 'Actualisation des messages impossible pour le moment.';
            }
        }

        messageList.scrollTop = messageList.scrollHeight;
        setInterval(refreshMessages, 5000);
    </script>
</body>
</html>
