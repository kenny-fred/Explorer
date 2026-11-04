Option Explicit

Dim fso, wsh, dir, batPath, rc
Set fso = CreateObject("Scripting.FileSystemObject")
Set wsh = CreateObject("WScript.Shell")

dir = fso.GetParentFolderName(WScript.ScriptFullName)
batPath = fso.BuildPath(dir, "start.bat")

If Not fso.FileExists(batPath) Then
    MsgBox "start.bat introuvable dans :" & vbCrLf & dir, vbCritical, "Explorer - Erreur"
    WScript.Quit 1
End If

wsh.CurrentDirectory = dir
rc = wsh.Run("cmd /c start.bat", 1, True)

' Si le code de retour n'est pas 0 (erreur), on affiche une popup
If rc <> 0 Then
    MsgBox "Le lanceur s'est termine avec le code d'erreur : " & rc & vbCrLf & _
           "Consultez la fenetre noire du terminal pour voir ce qui a bloque.", _
           vbExclamation, "Explorer - Attention"
End If