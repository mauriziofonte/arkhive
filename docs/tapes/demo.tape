# README demo: ArkHive at a terminal, from no repository to a checked backup.
# Rendered by .github/workflows/demos.yml from the repository root, with arkhive and restic in
# PATH and /srv writable (docs/tapes/setup.sh). CI is unset: with it ArkHive runs unattended.
Output docs/assets/demo.gif

Set Shell "bash"
Set FontSize 15
Set Width 1200
Set Height 660
Set Padding 18
Set Theme "Catppuccin Mocha"
Set TypingSpeed 40ms
Env LANG "C.UTF-8"
Env LC_ALL "C.UTF-8"

Hide
Type `unset CI INVOCATION_ID JOURNAL_STREAM && docs/tapes/setup.sh fresh && cd ~ && export PS1='$ ' && clear`
Enter
Sleep 5s
Show

Type "arkhive init"
Sleep 300ms
Enter
Sleep 4s

Type "arkhive backup"
Sleep 300ms
Enter
Sleep 4s

Type "clear; arkhive status"
Sleep 300ms
Enter
Sleep 4s

Type "clear; arkhive doctor"
Sleep 300ms
Enter
Sleep 6s
