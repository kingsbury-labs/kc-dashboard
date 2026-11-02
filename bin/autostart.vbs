' Card 120: runs bin\autostart.cjs with node, hidden (window style 0), not waiting. Started at logon by the Startup
' folder shortcut bin\install-autostart.cjs makes: wscript.exe //B bin\autostart.vbs
Set fso = CreateObject("Scripting.FileSystemObject")
here = fso.GetParentFolderName(WScript.ScriptFullName)
CreateObject("WScript.Shell").Run "node """ & here & "\autostart.cjs""", 0, False
