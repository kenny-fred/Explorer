<?php
// setup.php - Script pour configurer l'utilisateur et le mot de passe

if ($argc < 3) {
    echo "Erreur : Parametres manquants.\n";
    exit(1);
}

$username = $argv[1];
$password = $argv[2];

// 1. Générer le hash
$hash = password_hash($password, PASSWORD_BCRYPT);

// 2. Vérifier si le .env existe
$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    if (file_exists(__DIR__ . '/.env.example')) {
        copy(__DIR__ . '/.env.example', $envFile);
    } else {
        echo "Erreur : Impossible de trouver .env ou .env.example\n";
        exit(1);
    }
}

// 3. Lire et modifier le contenu
$content = file_get_contents($envFile);

// Remplacer ou ajouter USERNAME
if (preg_match('/^USERNAME=.*/m', $content)) {
    $content = preg_replace('/^USERNAME=.*/m', 'USERNAME=' . $username, $content);
} else {
    $content .= "\nUSERNAME=" . $username;
}

// Remplacer ou ajouter PASSWORD
if (preg_match('/^PASSWORD=.*/m', $content)) {
    $content = preg_replace('/^PASSWORD=.*/m', 'PASSWORD=' . $hash, $content);
} else {
    $content .= "\nPASSWORD=" . $hash;
}

// 4. Sauvegarder
file_put_contents($envFile, $content);

echo "\n\033[32m[SUCCES] Configuration mise a jour !\033[0m\n";
echo "Utilisateur : $username\n";
echo "Hash genere : $hash\n\n";