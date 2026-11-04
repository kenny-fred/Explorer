<?php
// setup.php - Configuration du compte

if ($argc < 3) {
    echo "Erreur : Parametres manquants.\n";
    exit(1);
}

$username = trim($argv[1]);
$password = $argv[2];

if ($username === '') {
    echo "[ERREUR] Le nom d'utilisateur ne peut pas etre vide.\n";
    exit(1);
}
if ($password === '') {
    echo "[ERREUR] Le mot de passe ne peut pas etre vide.\n";
    exit(1);
}

// Génération du hash
$hash = password_hash($password, PASSWORD_BCRYPT);

// Vérification immédiate que le hash est valide
if (!password_verify($password, $hash)) {
    echo "[ERREUR] Generation du hash invalide.\n";
    exit(1);
}

// Vérifier l'existence du .env
$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    if (file_exists(__DIR__ . '/.env.example')) {
        copy(__DIR__ . '/.env.example', $envFile);
    } else {
        echo "Erreur : Impossible de trouver .env ou .env.example\n";
        exit(1);
    }
}

// Lire le fichier ligne par ligne (SANS preg_replace pour preserver les $)
$lines = file($envFile, FILE_IGNORE_NEW_LINES);
$usernameFound = false;
$passwordFound = false;

foreach ($lines as $i => $line) {
    if (str_starts_with($line, 'USERNAME=')) {
        $lines[$i] = 'USERNAME=' . $username;
        $usernameFound = true;
    } elseif (str_starts_with($line, 'PASSWORD=')) {
        $lines[$i] = 'PASSWORD=' . $hash;
        $passwordFound = true;
    }
}

// Si les lignes n'existent pas, on les ajoute à la fin
if (!$usernameFound) {
    $lines[] = 'USERNAME=' . $username;
}
if (!$passwordFound) {
    $lines[] = 'PASSWORD=' . $hash;
}

// Sauvegarder
file_put_contents($envFile, implode("\n", $lines) . "\n");

// Vérification finale : lire le .env et s'assurer que le hash écrit correspond
$checkContent = file_get_contents($envFile);
if (preg_match('/^PASSWORD=(.+)$/m', $checkContent, $m)) {
    $writtenHash = trim($m[1]);
    if (!password_verify($password, $writtenHash)) {
        echo "[ERREUR] Le hash ecrit dans .env ne correspond pas au mot de passe.\n";
        echo "Hash ecrit : $writtenHash\n";
        echo "Longueur : " . strlen($writtenHash) . " (attendu: 60)\n";
        exit(1);
    }
}

echo "\n[SUCCES] Configuration mise a jour !\n";
echo "Utilisateur : $username\n";
echo "Hash        : $hash\n\n";