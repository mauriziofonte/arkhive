# README demo: the guided restore of a deleted file, found by name in the latest snapshot.
# Rendered by .github/workflows/demos.yml from the repository root, with arkhive and restic in
# PATH and /srv writable (docs/tapes/setup.sh). CI is unset: with it ArkHive runs unattended.
Output docs/assets/restore.gif

Set Shell "bash"
Set FontSize 15
Set Width 1200
Set Height 760
Set Padding 18
Set Theme "Catppuccin Mocha"
Set TypingSpeed 40ms
Env LANG "C.UTF-8"
Env LC_ALL "C.UTF-8"

Hide
Type `unset CI INVOCATION_ID JOURNAL_STREAM && docs/tapes/setup.sh history && cd ~ && export PS1='$ ' && clear`
Enter
Sleep 20s
Show

Type "ls /srv/www/shop/storage/uploads"
Sleep 300ms
Enter
Sleep 2s

Type "arkhive restore"
Sleep 300ms
Enter
Sleep 3s

# the snapshot: Enter takes the latest
Enter
Sleep 1.5s

# what: pick files and folders
Type "2"
Sleep 300ms
Enter
Sleep 2s

Type "/product-3"
Sleep 300ms
Enter
Sleep 2s

Type "1"
Sleep 300ms
Enter
Sleep 2s

# done selecting, then the default target: a new directory
Enter
Sleep 1.5s
Enter
Sleep 3.5s

Type "y"
Sleep 300ms
Enter
Sleep 7s
