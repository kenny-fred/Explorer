#!/bin/bash

# Vérification de PHP
if ! command -v php &> /dev/null; then
    echo "Erreur : PHP n'est pas installé. Installez-le avec 'sudo apt install php-cli php-fileinfo'."
    exit 1
fi

while true; do
    echo ""
    echo "Explorer - LANCEUR"
    echo "1. Configurer un compte (.env)"
    echo "2. Demarrer le serveur"
    echo "3. Quitter"
    read -p "Choix (1-3) : " choix

    case $choix in
        1)
            echo ""
            read -p "Nom d'utilisateur : " username
            read -p "Mot de passe : " password
            php setup.php "$username" "$password"
            read -p "Appuyez sur Entree pour continuer..."
            ;;
        2)
            echo ""
            IP=$(hostname -I | awk '{print $1}')
            echo "Serveur accessible sur : http://$IP:8000 (ou localhost)"
            
            # Ouvre le navigateur (explorer.exe pour WSL, xdg-open pour Linux natif)
            explorer.exe "http://localhost:8000" 2>/dev/null || xdg-open "http://localhost:8000" 2>/dev/null &
            
            # Lancement du serveur avec 10Go
            php -d upload_max_filesize=10240M -d post_max_size=10240M -S 0.0.0.0:8000 -t src src/router.php
            ;;
        3)
            exit 0
            ;;
        *)
            echo "Choix invalide."
            ;;
    esac
done